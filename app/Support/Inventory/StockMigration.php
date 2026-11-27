<?php

namespace App\Support\Inventory;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One time move of bulk items from split off parts to stock per place (migration
 * 2026_11_30_000001). Works on the tables only, so later changes of the models do not change it.
 *
 * Every active bulk item belongs to a tree: its origin and the parts split off from it (split_from_id,
 * over several steps). Per tree:
 * - the root is the origin, or, if the origin is retired, the oldest active part (it keeps its
 *   number and label);
 * - the root gets the quantity of all active members of the tree;
 * - every member puts the pieces it has here (quantity minus lent minus missing) as stock at its
 *   place, into its box or, without place, into the virtual compartment "Eingang";
 * - rows of loans of the members point to the root;
 * - the other members are retired as merged (merged_into_id = root), with their history and
 *   number; scanning that number leads to the root;
 * - the root has no place any more, its home stays.
 *
 * Bulk rows of loans that came back but were not put away yet count as put away: in the old model
 * their pieces were at the place of the item already.
 */
class StockMigration
{
    public static function run(): void
    {
        DB::transaction(function () {
            $hasLoans = Schema::hasTable('loan_items') && Schema::hasTable('loans');
            if ($hasLoans) {
                self::stowReturnedBulkRows();
            }

            $items = DB::table('items')->where('kind', 'bulk')->get()->keyBy('id');
            $active = $items->whereNull('retired_at');
            [$lent, $missing] = $hasLoans ? self::awayPieces($active->keys()->all()) : [[], []];

            foreach ($active->groupBy(fn (object $item) => self::topOf($item, $items)) as $members) {
                self::mergeTree($members->sortBy('id')->values(), $lent, $missing, $hasLoans);
            }
        });
    }

    /**
     * Top of the tree of an item: follow split_from_id up to the item without origin.
     *
     * @param  Collection<int, object>  $items
     */
    private static function topOf(object $item, Collection $items): int
    {
        $seen = [];
        while ($item->split_from_id !== null && isset($items[$item->split_from_id]) && ! isset($seen[$item->id])) {
            $seen[$item->id] = true;
            $item = $items[$item->split_from_id];
        }

        return (int) $item->id;
    }

    /**
     * @param  Collection<int, object>  $members  active members of one tree, oldest first
     * @param  array<int, int>  $lent
     * @param  array<int, int>  $missing
     */
    private static function mergeTree(Collection $members, array $lent, array $missing, bool $hasLoans): void
    {
        // the origin is the member without an active ancestor; if it is retired, the oldest part
        $root = $members->first(fn (object $item) => $item->split_from_id === null || ! $members->contains('id', $item->split_from_id))
            ?? $members->first();
        $now = now();
        $stocks = [];

        foreach ($members as $member) {
            $here = (int) $member->quantity - ($lent[$member->id] ?? 0) - ($missing[$member->id] ?? 0);
            if ($here > 0) {
                $key = self::keyOf($member->location_id, $member->container_id);
                $stocks[$key] ??= ['location_id' => $member->location_id, 'container_id' => $member->container_id, 'quantity' => 0];
                $stocks[$key]['quantity'] += $here;
            }
        }

        foreach ($stocks as $key => $stock) {
            DB::table('item_stocks')->insert([
                'item_id' => $root->id,
                'location_id' => $stock['location_id'],
                'container_id' => $stock['container_id'],
                'place_key' => $key,
                'quantity' => $stock['quantity'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $others = $members->reject(fn (object $item) => $item->id === $root->id);
        DB::table('items')->where('id', $root->id)->update([
            'quantity' => (int) $members->sum('quantity'),
            'location_id' => null,
            'container_id' => null,
            'split_from_id' => null,
        ]);
        if ($others->isEmpty()) {
            return;
        }

        foreach ($others as $other) {
            DB::table('items')->where('id', $other->id)->update([
                'retired_at' => $now,
                'retired_reason' => 'merged',
                'retired_note' => "Bestand jetzt bei {$root->number}",
                'location_id' => null,
                'container_id' => null,
                'merged_into_id' => $root->id,
            ]);
            if ($hasLoans) {
                DB::table('loan_items')->where('item_id', $other->id)->update(['item_id' => $root->id]);
            }
        }

        DB::table('item_movements')->insert([
            'item_id' => $root->id,
            'type' => 'merge',
            'quantity' => (int) $others->sum('quantity'),
            'note' => 'Aus '.$others->pluck('number')->join(', ', ' und ').' zusammengeführt (Bestand je Platz).',
            'created_at' => $now,
        ]);
    }

    /**
     * Key of a place as in item_stocks.place_key (App\Models\ItemStock::keyOf).
     */
    private static function keyOf(?int $locationId, ?int $containerId): string
    {
        return match (true) {
            $locationId !== null => "L{$locationId}",
            $containerId !== null => "B{$containerId}",
            default => 'IN',
        };
    }

    /**
     * Pieces of bulk items that are lent (issued, not back, of an issued loan) and still missing
     * after a return, by item id.
     *
     * @param  list<int>  $itemIds
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private static function awayPieces(array $itemIds): array
    {
        $lent = DB::table('loan_items')
            ->join('loans', 'loans.id', '=', 'loan_items.loan_id')
            ->where('loans.status', 'issued')
            ->whereNotNull('loan_items.issued_at')
            ->whereNull('loan_items.returned_at')
            ->whereIn('loan_items.item_id', $itemIds)
            ->selectRaw('loan_items.item_id, SUM(loan_items.quantity) as pieces')
            ->groupBy('loan_items.item_id')
            ->pluck('pieces', 'item_id');
        $missing = DB::table('loan_items')
            ->whereNull('missing_resolution')
            ->where('missing_quantity', '>', 0)
            ->whereIn('item_id', $itemIds)
            ->selectRaw('item_id, SUM(missing_quantity) as pieces')
            ->groupBy('item_id')
            ->pluck('pieces', 'item_id');

        return [
            $lent->mapWithKeys(fn ($pieces, $id) => [(int) $id => (int) $pieces])->all(),
            $missing->mapWithKeys(fn ($pieces, $id) => [(int) $id => (int) $pieces])->all(),
        ];
    }

    /**
     * Bulk rows that came back (positions and contents of boxes, inspected or not): in the old
     * model their pieces were at the place of the item already, so they count as put away. The new
     * model counts pieces as away until stowed_at is set (Modules\Loans\Support\LoanAwayItems).
     */
    private static function stowReturnedBulkRows(): void
    {
        $bulkIds = DB::table('items')->where('kind', 'bulk')->pluck('id');
        DB::table('loan_items')
            ->whereNotNull('returned_at')
            ->whereNull('stowed_at')
            ->whereIn('item_id', $bulkIds)
            ->update(['stowed_at' => now()]);
    }
}
