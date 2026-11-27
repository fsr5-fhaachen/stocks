<?php

namespace App\Actions\Inventory;

use App\Enums\MovementType;
use App\Enums\RetireReason;
use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\User;
use App\Support\Extensions\ItemExtensions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Take an item out of the inventory. It keeps its number, photos and history, but has no place.
 *
 * Of a bulk item pieces are taken out of one place, e.g. used up cable ties from a box: its stock
 * there and its quantity go down (StockBook::remove), the item stays, also at 0. The whole bulk
 * item is retired only with nothing lent or missing; its remaining pieces leave their places.
 */
class RetireItem
{
    public function __construct(private readonly ItemExtensions $extensions, private readonly StockBook $stock) {}

    /**
     * @param  int|null  $quantity  pieces of a bulk item, null for the whole item
     * @param  string|null  $from  bulk item: key of the place the pieces come from, null for the default
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Item $item, RetireReason $reason, ?string $note, ?int $quantity = null, ?string $from = null): void
    {
        if ($item->isRetired()) {
            throw ValidationException::withMessages(['item' => "{$item->number} ist schon ausgemustert."]);
        }
        if ($lock = $this->extensions->lockOf($item)) {
            throw ValidationException::withMessages(['item' => $lock]);
        }

        if ($item->isBulk() && $quantity !== null) {
            $this->stock->remove($actor, $item, StockBook::sourceOf($item, $from, 'quantity'), $quantity, $reason, $note);

            return;
        }

        if ($item->contents()->exists() || $item->stocksInside()->where('quantity', '>', 0)->exists()) {
            throw ValidationException::withMessages(['item' => 'In der Box liegen noch Gegenstände. Räume sie erst aus.']);
        }
        if ($item->isBulk() && ($away = $this->extensions->awayQuantity($item)) > 0) {
            throw ValidationException::withMessages(['item' => "{$away} {$item->unit} von {$item->number} sind gerade weg (verliehen oder vermisst). Ganz ausmustern geht erst danach."]);
        }

        DB::transaction(function () use ($actor, $item, $reason, $note) {
            $from = $item->place();
            $quantity = $item->quantity;
            // used up consumables get their own kind of row, other reasons go into the note
            $type = $reason === RetireReason::Consumed ? MovementType::Consume : MovementType::Retire;
            $logNote = $type === MovementType::Consume ? $note : trim($reason->label().': '.trim((string) $note), ': ');

            $item->forceFill(['retired_at' => now(), 'retired_reason' => $reason, 'retired_note' => $note]);
            $item->setPlace(null);
            $item->save();
            // the remaining pieces of a bulk item leave their places; the quantity stays as it was
            $item->stocks()->delete();
            // places in a retired box with only a target quantity ("Sollmenge") go too
            $item->stocksInside()->delete();

            ItemMovement::record($item, $type, $from, null, $actor, $quantity, $logNote);
        });
    }
}
