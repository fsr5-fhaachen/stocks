<?php

namespace Modules\Loans\Support;

use App\Enums\ItemKind;
use App\Support\Extensions\ItemAwayProvider;
use Illuminate\Database\Eloquent\Builder;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\LoanItem;

/**
 * Lent items: out with an issued loan and not back yet. They have no place on purpose, so
 * "Ohne Platz" leaves them out (App\Support\Extensions\ItemExtensions::addAway()). Back but not
 * put away, or missing after the return, they count again.
 *
 * A bulk item keeps its place during a loan: its lent pieces and the pieces still missing after a
 * return are away (awayQuantities()), the item itself is not.
 */
class LoanAwayItems implements ItemAwayProvider
{
    /**
     * Positions that are out: issued, not returned, of a loan that is issued (also overdue and
     * after a partial issue).
     *
     * @return Builder<LoanItem>
     */
    public static function issuedRows(): Builder
    {
        return LoanItem::query()->whereNotNull('issued_at')->whereNull('returned_at')
            ->whereHas('loan', fn (Builder $query) => $query->where('status', LoanStatus::Issued));
    }

    /**
     * Rows of a bulk item that keeps its place: a position of its own, not the contents of a box
     * (those travel inside the box).
     *
     * @param  Builder<LoanItem>  $query
     * @return Builder<LoanItem>
     */
    public static function bulkPositions(Builder $query): Builder
    {
        return $query->whereNull('loan_items.parent_id')->whereHas('item', fn (Builder $query) => $query->where('kind', ItemKind::Bulk));
    }

    /**
     * {@inheritDoc}
     *
     * Single items and boxes that left, with the contents of boxes; not bulk positions, their item
     * keeps its place.
     */
    public function awayItemIds(): Builder
    {
        return self::issuedRows()
            ->where(fn (Builder $query) => $query->whereNotNull('parent_id')->orWhereHas('item', fn (Builder $query) => $query->where('kind', '!=', ItemKind::Bulk)))
            ->select('item_id');
    }

    /**
     * {@inheritDoc}
     *
     * Lent pieces of bulk positions plus the pieces still missing after a return.
     */
    public function awayQuantities(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $away = [];
        $out = self::bulkPositions(self::issuedRows())->whereIn('item_id', $itemIds)
            ->selectRaw('item_id, SUM(quantity) as pieces')->groupBy('item_id')->pluck('pieces', 'item_id');
        // missing_quantity is only set on bulk rows, also on the contents of a box
        $missing = LoanItem::query()->stillMissing()->whereIn('item_id', $itemIds)
            ->selectRaw('item_id, SUM(missing_quantity) as pieces')->groupBy('item_id')->pluck('pieces', 'item_id');
        foreach ([$out, $missing] as $list) {
            foreach ($list as $id => $pieces) {
                $away[(int) $id] = ($away[(int) $id] ?? 0) + (int) $pieces;
            }
        }

        return $away;
    }
}
