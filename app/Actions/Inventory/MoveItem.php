<?php

namespace App\Actions\Inventory;

use App\Enums\MovementType;
use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\ItemStock;
use App\Models\Location;
use App\Models\User;
use App\Support\Extensions\ItemExtensions;
use App\Support\Inventory\Placement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Move an item to a location or into a box. The contents of a box move with it.
 *
 * A bulk item has no place of its own: moving it moves pieces from one of its places to another
 * (StockBook::transfer). No new number, no split off part.
 */
class MoveItem
{
    public function __construct(private readonly ItemExtensions $extensions, private readonly StockBook $stock) {}

    /**
     * @param  int|null  $quantity  pieces of a bulk item to move, null for all at the source
     * @param  string|null  $from  bulk item: key of the source place (item_stocks.place_key), null for the default
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Item $item, Location|Item $target, ?int $quantity = null, ?string $note = null, ?string $from = null): Item
    {
        if ($item->isRetired()) {
            throw ValidationException::withMessages(['item' => "{$item->number} ist ausgemustert."]);
        }
        if ($lock = $this->extensions->lockOf($item)) {
            throw ValidationException::withMessages(['item' => $lock]);
        }
        Placement::check($item, $target, 'target');

        if ($item->isBulk()) {
            $source = StockBook::sourceOf($item, $from);
            if (ItemStock::keyOf($source) === ItemStock::keyOf($target)) {
                throw ValidationException::withMessages(['target' => "{$item->number} liegt schon dort."]);
            }
            $quantity ??= ItemStock::for($item, $source)->quantity;
            $this->stock->transfer($actor, $item, $source, $target, $quantity, $note);

            return $item;
        }

        if ($item->place()?->is($target)) {
            throw ValidationException::withMessages(['target' => "{$item->number} liegt schon dort."]);
        }

        return DB::transaction(function () use ($actor, $item, $target, $note) {
            $from = $item->place();

            $item->setPlace($target);
            $item->save();
            ItemMovement::record($item, MovementType::Move, $from, $target, $actor, note: $note);

            return $item;
        });
    }

    /**
     * Take an item away from its place without a new place, e.g. when a module lends it out or it
     * goes missing. The movement log gets a row of the given type. Locks are not checked, the
     * caller owns the reason for taking it away. Pieces of a bulk item are never taken away here:
     * they leave a place through StockBook::takeOut() and the module counts them.
     *
     * @throws ValidationException
     */
    public function takeAway(User $actor, Item $item, MovementType $type, ?string $note = null): Item
    {
        if ($item->isRetired()) {
            throw ValidationException::withMessages(['item' => "{$item->number} ist ausgemustert."]);
        }

        return DB::transaction(function () use ($actor, $item, $type, $note) {
            $from = $item->place();

            $item->setPlace(null);
            $item->save();
            ItemMovement::record($item, $type, $from, null, $actor, note: $note);

            return $item;
        });
    }
}
