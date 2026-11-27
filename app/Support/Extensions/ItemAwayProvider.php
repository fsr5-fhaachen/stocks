<?php

namespace App\Support\Extensions;

use Illuminate\Database\Eloquent\Builder;

/**
 * Items a module has away on purpose, e.g. lent items of the loan module: they have no place, but
 * are not missing, so "Ohne Platz" leaves them out. Of a bulk item a module can have pieces away
 * while the item keeps its place (lent pieces): they are part of the stock but not "here", so
 * moving, using up or retiring a part stops at what is here. See ItemExtensions::addAway().
 */
interface ItemAwayProvider
{
    /**
     * Query that selects the ids of the items that are away (one column, used in whereNotIn).
     */
    public function awayItemIds(): Builder;

    /**
     * Pieces of bulk items that are away while the item keeps its place, by item id. Items with
     * nothing away are left out.
     *
     * @param  list<int>  $itemIds
     * @return array<int, int>
     */
    public function awayQuantities(array $itemIds): array;
}
