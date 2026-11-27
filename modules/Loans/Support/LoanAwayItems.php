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
 * Bulk items have no place of their own, their pieces lie at places (item_stocks). Pieces that left
 * a place with a loan are away until they lie at a place again (awayQuantities()): out, back but
 * not inspected, back but not put away, or still missing.
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
     * Rows of bulk items (positions and contents of boxes).
     *
     * @param  Builder<LoanItem>  $query
     * @return Builder<LoanItem>
     */
    public static function bulkRows(Builder $query): Builder
    {
        return $query->whereHas('item', fn (Builder $query) => $query->where('kind', ItemKind::Bulk));
    }

    /**
     * {@inheritDoc}
     *
     * Single items and boxes that left, with the contents of boxes; never bulk items, they count
     * by pieces.
     */
    public function awayItemIds(): Builder
    {
        return self::issuedRows()
            ->whereHas('item', fn (Builder $query) => $query->where('kind', '!=', ItemKind::Bulk))
            ->select('item_id');
    }

    /**
     * {@inheritDoc}
     *
     * Pieces of bulk rows that left a place and are not at one again (stowed_at is set when they
     * are: put away after the return, or back in their box at the inspection), plus the pieces still
     * missing after a return. Before the inspection the whole row counts, after it what came back.
     */
    public function awayQuantities(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $away = [];
        $rows = self::bulkRows(LoanItem::query())
            ->whereIn('item_id', $itemIds)
            ->whereNotNull('issued_at')
            ->whereNull('stowed_at')
            ->whereHas('loan', fn (Builder $query) => $query->where('status', '!=', LoanStatus::Cancelled))
            ->get(['item_id', 'quantity', 'returned_at', 'inspected_at', 'returned_quantity']);
        foreach ($rows as $row) {
            $pieces = $row->returned_at === null || $row->inspected_at === null ? $row->quantity : (int) ($row->returned_quantity ?? $row->quantity);
            $away[$row->item_id] = ($away[$row->item_id] ?? 0) + $pieces;
        }

        $missing = LoanItem::query()->stillMissing()->whereIn('item_id', $itemIds)
            ->selectRaw('item_id, SUM(missing_quantity) as pieces')->groupBy('item_id')->pluck('pieces', 'item_id');
        foreach ($missing as $id => $pieces) {
            $away[(int) $id] = ($away[(int) $id] ?? 0) + (int) $pieces;
        }

        return array_filter($away);
    }
}
