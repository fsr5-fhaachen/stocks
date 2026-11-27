<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Stock of a bulk item at one place ("Bestand je Stelle"): a location, a box, or the virtual
 * compartment "Eingang" (no place). Always changed through App\Actions\Inventory\StockBook, which
 * keeps items.quantity = sum of all places + lent + missing.
 *
 * A row at 0 is deleted, unless it has a target quantity ("Sollmenge").
 *
 * @property int $id
 * @property int $item_id
 * @property int|null $location_id
 * @property int|null $container_id
 * @property string $place_key
 * @property int $quantity
 * @property int|null $target_quantity
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Item $item
 * @property-read Location|null $location
 * @property-read Item|null $container
 */
class ItemStock extends Model
{
    /**
     * Key of the virtual compartment "Eingang".
     */
    public const INCOMING = 'IN';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'quantity',
        'target_quantity',
    ];

    /**
     * Key of a place as stored in place_key: "L<id>" for a location, "B<id>" for a box, "IN" for
     * the virtual compartment "Eingang".
     */
    public static function keyOf(Location|Item|null $place): string
    {
        return match (true) {
            $place instanceof Location => "L{$place->id}",
            $place instanceof Item => "B{$place->id}",
            default => self::INCOMING,
        };
    }

    /**
     * Place of a key, null for "Eingang" (and for a key whose place does not exist).
     */
    public static function placeOf(?string $key): Location|Item|null
    {
        return match (true) {
            $key === null, $key === self::INCOMING => null,
            str_starts_with($key, 'L') => Location::find((int) substr($key, 1)),
            str_starts_with($key, 'B') => Item::find((int) substr($key, 1)),
            default => null,
        };
    }

    /**
     * Rows that lie in a box or at a location.
     *
     * @param  Builder<ItemStock>  $query
     */
    public function scopeAt(Builder $query, Location|Item|null $place): void
    {
        $query->where('place_key', self::keyOf($place));
    }

    /**
     * Row of an item at a place, new (not saved) if there is none yet.
     */
    public static function for(Item $item, Location|Item|null $place): self
    {
        $stock = self::query()->where('item_id', $item->id)->at($place)->first();
        if ($stock !== null) {
            return $stock;
        }

        $stock = new self(['quantity' => 0]);
        $stock->item_id = $item->id;
        $stock->location_id = $place instanceof Location ? $place->id : null;
        $stock->container_id = $place instanceof Item ? $place->id : null;
        $stock->place_key = self::keyOf($place);

        return $stock;
    }

    /**
     * The place, null for "Eingang".
     */
    public function place(): Location|Item|null
    {
        return $this->location ?? $this->container;
    }

    /**
     * Below its target quantity ("Sollmenge").
     */
    public function isShort(): bool
    {
        return $this->target_quantity !== null && $this->quantity < $this->target_quantity;
    }

    /**
     * Short summary for the frontend.
     *
     * @return array{id: int, key: string, quantity: int, target_quantity: int|null, short: bool, place: array<string, mixed>|null}
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->place_key,
            'quantity' => $this->quantity,
            'target_quantity' => $this->target_quantity,
            'short' => $this->isShort(),
            // null: "Eingang"
            'place' => Item::placeSummary($this->place()),
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function container(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'container_id');
    }
}
