<?php

namespace Modules\Loans\Support;

use App\Support\Extensions\ItemAwayProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\LoanItem;

/**
 * Lent items: out with an issued loan and not back yet. They have no place on purpose, so
 * "Ohne Platz" leaves them out (App\Support\Extensions\ItemExtensions::addAway()). Back but not
 * put away, or missing after the return, they count again.
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
     * {@inheritDoc}
     *
     * The item that left: the split off part of a bulk item, otherwise the item itself.
     */
    public function awayItemIds(): Builder
    {
        return self::issuedRows()->select(DB::raw('COALESCE(issued_item_id, item_id)'));
    }
}
