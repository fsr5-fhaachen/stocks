<?php

namespace App\Actions\Inventory;

use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Location;
use Illuminate\Validation\ValidationException;

/**
 * Delete a location with everything below it, as long as nothing lies there or has it as home.
 */
class DeleteLocation
{
    /**
     * @throws ValidationException
     */
    public function handle(Location $location): void
    {
        $ids = $location->subtreeIds();

        // also pieces of bulk items and places with a target quantity ("Sollmenge")
        $used = Item::whereIn('location_id', $ids)->orWhereIn('home_location_id', $ids)->exists()
            || ItemStock::whereIn('location_id', $ids)->exists();
        if ($used) {
            throw ValidationException::withMessages(['location' => "An {$location->full_code} oder darunter liegen noch Gegenstände oder haben dort ihren Stammplatz."]);
        }

        // the locations below are deleted by the database (cascade)
        $location->delete();
    }
}
