<?php

namespace App\Support\Extensions;

use Illuminate\Database\Eloquent\Builder;

/**
 * Items a module has away on purpose, e.g. lent items of the loan module: they have no place, but
 * are not missing, so "Ohne Platz" leaves them out. See ItemExtensions::addAway().
 */
interface ItemAwayProvider
{
    /**
     * Query that selects the ids of the items that are away (one column, used in whereNotIn).
     */
    public function awayItemIds(): Builder;
}
