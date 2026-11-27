<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Component of an item ("Bestandteil"): a line "quantity × name" that is no item of its own, e.g.
 * the hammer of a tool case or the knife of a cutlery set.
 *
 * Single items and boxes keep each component apart: what is missing after a return stays in
 * "missing" until someone checks the item as complete again (items.components_checked_at). At a
 * bulk item the list describes one set, "missing" counts over all sets.
 *
 * @property int $id
 * @property int $item_id
 * @property int $position
 * @property string $name
 * @property int $quantity
 * @property int $missing
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ItemComponent extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'position',
        'name',
        'quantity',
        'missing',
    ];

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Short summary for the frontend.
     *
     * @return array{id: int, name: string, quantity: int, missing: int}
     */
    public function summary(): array
    {
        return $this->only(['id', 'name', 'quantity', 'missing']);
    }
}
