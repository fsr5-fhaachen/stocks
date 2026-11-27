<?php

namespace App\Actions\Inventory;

use App\Enums\MovementType;
use App\Enums\RetireReason;
use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\ItemStock;
use App\Models\Location;
use App\Models\User;
use App\Support\Inventory\Placement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change of the stock of a bulk item goes through here ("Bestand je Stelle", see
 * documentation/INVENTORY.md). A place is a location, a box or null for the virtual compartment
 * "Eingang". It always holds:
 *
 *     items.quantity = sum of item_stocks + pieces lent + pieces missing
 *
 * Lent and missing pieces are counted by modules (App\Support\Extensions\ItemExtensions::awayQuantity).
 * takeOut() and putBack() move pieces between a place and a loan, so the quantity stays; receive(),
 * remove() and count() change it. Every change writes a row of the movement log. The item row is
 * locked for the change, so two requests never take the same pieces.
 */
class StockBook
{
    /**
     * New pieces, by default into "Eingang" ("Wareneingang").
     *
     * @throws ValidationException
     */
    public function receive(User $actor, Item $item, int $quantity, Location|Item|null $to = null, ?string $note = null): ItemStock
    {
        return $this->change($item, function (Item $item) use ($actor, $quantity, $to, $note) {
            $this->checkQuantity($quantity);
            Placement::check($item, $to, 'to');
            $stock = $this->add($item, $to, $quantity);
            $this->addToTotal($item, $quantity);
            ItemMovement::record($item, MovementType::Receive, null, $to, $actor, $quantity, $note);

            return $stock;
        });
    }

    /**
     * Pieces from one place to another.
     *
     * @throws ValidationException
     */
    public function transfer(User $actor, Item $item, Location|Item|null $from, Location|Item|null $to, int $quantity, ?string $note = null): ItemStock
    {
        return $this->change($item, function (Item $item) use ($actor, $from, $to, $quantity, $note) {
            $this->checkQuantity($quantity);
            if (ItemStock::keyOf($from) === ItemStock::keyOf($to)) {
                throw ValidationException::withMessages(['to' => 'Quelle und Ziel sind gleich.']);
            }
            Placement::check($item, $to, 'to');
            $this->take($item, $from, $quantity);
            $stock = $this->add($item, $to, $quantity);
            ItemMovement::record($item, MovementType::Move, $from, $to, $actor, $quantity, $note);

            return $stock;
        });
    }

    /**
     * Pieces leave a place with a loan. The quantity stays, the module counts them as lent.
     *
     * @throws ValidationException
     */
    public function takeOut(User $actor, Item $item, Location|Item|null $from, int $quantity, ?string $note = null): void
    {
        $this->change($item, function (Item $item) use ($actor, $from, $quantity, $note) {
            $this->checkQuantity($quantity);
            $this->take($item, $from, $quantity);
            ItemMovement::record($item, MovementType::Issue, $from, null, $actor, $quantity, $note);
        });
    }

    /**
     * Pieces of a loan are put back at a place ("Einräumen").
     *
     * @throws ValidationException
     */
    public function putBack(User $actor, Item $item, Location|Item|null $to, int $quantity, ?string $note = null): ItemStock
    {
        return $this->change($item, function (Item $item) use ($actor, $to, $quantity, $note) {
            $this->checkQuantity($quantity);
            Placement::check($item, $to, 'code');
            $stock = $this->add($item, $to, $quantity);
            ItemMovement::record($item, MovementType::Return, null, $to, $actor, $quantity, $note);

            return $stock;
        });
    }

    /**
     * Pieces leave the inventory: used up ("verbraucht") or retired ("ausgemustert"). From a place,
     * or with $from = false from pieces that are not at a place (lent, used up during a loan).
     *
     * @throws ValidationException
     */
    public function remove(User $actor, Item $item, Location|Item|false|null $from, int $quantity, RetireReason $reason, ?string $note = null): void
    {
        $this->change($item, function (Item $item) use ($actor, $from, $quantity, $reason, $note) {
            $this->checkQuantity($quantity);
            if ($quantity > $item->quantity) {
                throw ValidationException::withMessages(['quantity' => "Von {$item->number} gibt es nur {$item->quantity} {$item->unit}."]);
            }
            if ($from !== false) {
                $this->take($item, $from, $quantity);
            }
            Item::query()->whereKey($item->id)->decrement('quantity', $quantity);
            $item->quantity -= $quantity;

            $type = $reason === RetireReason::Consumed ? MovementType::Consume : MovementType::Retire;
            $logNote = $type === MovementType::Consume ? $note : trim($reason->label().': '.trim((string) $note), ': ');
            ItemMovement::record($item, $type, $from === false ? null : $from, null, $actor, $quantity, $logNote);
        });
    }

    /**
     * Correct the stock of a place after counting ("Zählen"); the quantity follows.
     *
     * @throws ValidationException
     */
    public function count(User $actor, Item $item, Location|Item|null $at, int $quantity, ?string $note = null): void
    {
        $this->change($item, function (Item $item) use ($actor, $at, $quantity, $note) {
            if ($quantity < 0) {
                throw ValidationException::withMessages(['quantity' => 'Die Menge darf nicht negativ sein.']);
            }
            $stock = ItemStock::for($item, $at);
            $difference = $quantity - $stock->quantity;
            if ($difference === 0) {
                return;
            }
            Placement::check($item, $at, 'at');
            $this->save($stock, $quantity);
            $this->addToTotal($item, $difference);
            ItemMovement::record($item, MovementType::Count, $at, $at, $actor, $quantity, trim(($difference > 0 ? '+' : '').$difference.' '.$note));
        });
    }

    /**
     * Set or clear the target quantity of a place ("Sollmenge"). A place with a target stays, also
     * at 0.
     *
     * @throws ValidationException
     */
    public function setTarget(Item $item, Location|Item|null $at, ?int $target): ItemStock
    {
        return $this->change($item, function (Item $item) use ($at, $target) {
            if ($target !== null && $target < 1) {
                throw ValidationException::withMessages(['target_quantity' => 'Die Sollmenge muss mindestens 1 sein.']);
            }
            Placement::check($item, $at, 'at');
            $stock = ItemStock::for($item, $at);
            $stock->target_quantity = $target;
            $this->save($stock, $stock->quantity);

            return $stock;
        });
    }

    /**
     * Run a change with the item row locked.
     *
     * @template T
     *
     * @param  callable(Item): T  $change
     * @return T
     *
     * @throws ValidationException
     */
    private function change(Item $item, callable $change): mixed
    {
        return DB::transaction(function () use ($item, $change) {
            $locked = Item::query()->lockForUpdate()->findOrFail($item->id);
            if (! $locked->isBulk()) {
                throw ValidationException::withMessages(['item' => "{$locked->number} ist kein Mengenartikel."]);
            }
            if ($locked->isRetired()) {
                throw ValidationException::withMessages(['item' => "{$locked->number} ist ausgemustert."]);
            }

            $result = $change($locked);
            $item->quantity = $locked->quantity;

            return $result;
        });
    }

    /**
     * @throws ValidationException
     */
    private function checkQuantity(int $quantity): void
    {
        if ($quantity < 1) {
            throw ValidationException::withMessages(['quantity' => 'Die Menge muss mindestens 1 sein.']);
        }
    }

    /**
     * Take pieces from a place; never below 0.
     *
     * @throws ValidationException
     */
    private function take(Item $item, Location|Item|null $from, int $quantity): void
    {
        $stock = ItemStock::for($item, $from);
        if ($stock->quantity < $quantity) {
            throw ValidationException::withMessages(['quantity' => self::notThereMessage($item, $from, $stock->quantity)]);
        }
        $this->save($stock, $stock->quantity - $quantity);
    }

    private function add(Item $item, Location|Item|null $to, int $quantity): ItemStock
    {
        $stock = ItemStock::for($item, $to);
        $this->save($stock, $stock->quantity + $quantity);

        return $stock;
    }

    /**
     * Save a row with its new quantity; a row at 0 without target quantity is deleted.
     */
    private function save(ItemStock $stock, int $quantity): void
    {
        $stock->quantity = $quantity;
        if ($quantity === 0 && $stock->target_quantity === null) {
            if ($stock->exists) {
                $stock->delete();
            }

            return;
        }
        $stock->save();
    }

    /**
     * Change the whole stock without an audit entry: the movement log has it.
     */
    private function addToTotal(Item $item, int $difference): void
    {
        Item::query()->whereKey($item->id)->increment('quantity', $difference);
        $item->quantity += $difference;
    }

    /**
     * Place to take pieces from: the place of a key (item_stocks.place_key, "IN" for "Eingang"), or
     * without key the only place with pieces, else "Eingang" if it has pieces.
     *
     * @throws ValidationException
     */
    public static function sourceOf(Item $item, ?string $key, string $field = 'from'): Location|Item|null
    {
        if ($key !== null && $key !== '') {
            $stock = $item->stocks()->where('place_key', $key)->first()
                ?? throw ValidationException::withMessages([$field => "Dort liegt nichts von {$item->number}."]);

            return $stock->place();
        }

        $stocks = $item->stocks()->where('quantity', '>', 0)->get();
        if ($stocks->count() === 1) {
            return $stocks->first()->place();
        }
        if ($stocks->contains('place_key', ItemStock::INCOMING)) {
            return null;
        }

        throw ValidationException::withMessages([$field => $stocks->isEmpty()
            ? "Von {$item->number} liegt gerade nichts an einem Platz."
            : "{$item->number} liegt an mehreren Stellen. Wähle, von welcher."]);
    }

    /**
     * "In B-12 liegen nur 3 Stück." – the message when a place has fewer pieces than asked for.
     */
    public static function notThereMessage(Item $item, Location|Item|null $place, int $there): string
    {
        $name = self::placeName($place);

        return $there === 0
            ? "{$name} liegt nichts von {$item->number}."
            : "{$name} liegen nur {$there} {$item->unit}.";
    }

    /**
     * "In EU-014-R03.4", "In B-12" or "Im Eingang".
     */
    public static function placeName(Location|Item|null $place): string
    {
        return $place === null ? 'Im Eingang' : 'In '.Item::placeSummary($place)['code'];
    }
}
