<?php

namespace App\Actions\Inventory;

use App\Enums\ItemKind;
use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Location;
use App\Models\User;
use App\Support\Extensions\ItemExtensions;
use App\Support\Inventory\Placement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Change the data and the home of an item. The quantity of a bulk item does not change here: its
 * stock changes through StockBook (Wareneingang, Zählen, Verbraucht). Turning a single item into a
 * bulk item puts the piece as stock at its place.
 *
 * Place and condition do not change here, see MoveItem and ChangeItemCondition.
 */
class UpdateItem
{
    public function __construct(private readonly ItemExtensions $extensions) {}

    /**
     * @param  array{name: string, description?: string|null, category_id?: int|null, kind: string, quantity?: int|null, unit?: string|null, is_container?: bool, public_contents?: bool, notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Item $item, array $data, Location|Item|null $home): Item
    {
        Placement::check($item, $home, 'home');

        $wasBulk = $item->isBulk();
        $oldQuantity = $item->quantity;
        $place = $item->place();
        $item->fill($data);

        // the stock of a bulk item changes through StockBook (Wareneingang, Zählen, Verbraucht),
        // never through the form
        if ($wasBulk && $item->isBulk()) {
            $item->quantity = $oldQuantity;
        }
        if ($wasBulk && ! $item->isBulk() && ($oldQuantity > 1 || $item->stocks()->count() > 1 || $this->extensions->awayQuantity($item) > 0)) {
            throw ValidationException::withMessages(['kind' => "{$item->number} ist ein Mengenartikel mit {$oldQuantity} {$item->unit}. Zum Einzelstück wird er erst, wenn höchstens ein Stück an einer Stelle übrig ist."]);
        }
        if ($item->kind === ItemKind::Single) {
            $item->quantity = 1;
            $item->unit = null;
        }

        if (! $item->is_container && ($item->contents()->exists() || $item->stocksInside()->exists())) {
            throw ValidationException::withMessages(['is_container' => 'In der Box liegen noch Gegenstände. Räume sie erst aus.']);
        }

        return DB::transaction(function () use ($item, $home, $wasBulk, $place) {
            $item->setHome($home);
            // single → bulk: the piece becomes the stock at its place; bulk → single: the piece
            // left (if any) gets the place of its stock
            if (! $wasBulk && $item->isBulk()) {
                $item->setPlace(null);
            }
            if ($wasBulk && ! $item->isBulk()) {
                $stock = $item->stocks()->first();
                $item->setPlace($stock?->place());
                $item->quantity = 1;
            }
            $item->save();

            if (! $wasBulk && $item->isBulk() && $item->quantity > 0) {
                $stock = ItemStock::for($item, $place);
                $stock->quantity = $item->quantity;
                $stock->save();
            }
            if ($wasBulk && ! $item->isBulk()) {
                $item->stocks()->delete();
            }

            return $item;
        });
    }
}
