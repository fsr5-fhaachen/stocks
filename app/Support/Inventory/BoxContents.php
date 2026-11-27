<?php

namespace App\Support\Inventory;

use App\Models\Item;
use App\Models\ItemStock;
use Illuminate\Support\Collection;

/**
 * What lies in a box: its items (single items and boxes) and the pieces of bulk items that lie in
 * it as stock ("4 × AA-Batterie", App\Models\ItemStock). Every list of a box's contents goes
 * through here, so bulk pieces never go missing in one of them.
 */
class BoxContents
{
    /**
     * Entries of a box: items first (by name), then bulk pieces (by name). A bulk place with only a
     * target quantity ("Sollmenge") and nothing in it is listed too, with quantity 0.
     *
     * @param  list<string>  $with  relations to load on the items
     * @return Collection<int, array{item: Item, quantity: int, stock: ItemStock|null}>
     */
    public static function of(Item $box, array $with = []): Collection
    {
        $items = $box->contents()->active()->with($with)->orderBy('name')->get()
            ->map(fn (Item $item) => ['item' => $item, 'quantity' => $item->quantity, 'stock' => null]);

        $stocks = $box->stocksInside()
            ->whereHas('item', fn ($query) => $query->whereNull('retired_at'))
            ->with(['item' => fn ($query) => $query->with($with)])
            ->get()
            ->sortBy(fn (ItemStock $stock) => $stock->item->name)
            ->map(fn (ItemStock $stock) => ['item' => $stock->item, 'quantity' => $stock->quantity, 'stock' => $stock]);

        return $items->concat($stocks)->values();
    }

    /**
     * Number of entries, for the "count" of a box label and the lists.
     */
    public static function count(Item $box): int
    {
        return $box->contents()->active()->count()
            + $box->stocksInside()->where('quantity', '>', 0)->whereHas('item', fn ($query) => $query->whereNull('retired_at'))->count();
    }

    /**
     * Fields of an entry for the frontend: like an item, the quantity of a bulk entry is the one in
     * this box; "stock" marks bulk pieces with their target quantity.
     *
     * @param  array{item: Item, quantity: int, stock: ItemStock|null}  $entry
     * @return array<string, mixed>
     */
    public static function fields(array $entry): array
    {
        $item = $entry['item'];

        return [
            'key' => $entry['stock'] ? "stock-{$entry['stock']->id}" : "item-{$item->id}",
            ...$item->only(['id', 'number', 'name', 'unit', 'is_container']),
            'quantity' => $entry['quantity'],
            'kind' => $item->kind->value,
            'stock' => $entry['stock'] ? [
                'key' => $entry['stock']->place_key,
                'target_quantity' => $entry['stock']->target_quantity,
                'short' => $entry['stock']->isShort(),
            ] : null,
        ];
    }
}
