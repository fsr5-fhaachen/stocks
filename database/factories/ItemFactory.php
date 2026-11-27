<?php

namespace Database\Factories;

use App\Enums\ItemKind;
use App\Models\Item;
use App\Models\ItemCondition;
use App\Models\ItemStock;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Items made by the factory get a test number and no movement log. Tests of the rules use the
 * actions (App\Actions\Inventory) instead.
 *
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    /**
     * Define the model's default state: a single piece without place.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'TEST-'.fake()->unique()->numerify('######'),
            'name' => ucfirst(fake()->words(2, true)),
            'kind' => ItemKind::Single,
            'quantity' => 1,
            'condition_id' => fn () => ItemCondition::where('name', 'gut')->value('id'),
        ];
    }

    /**
     * A box.
     */
    public function container(): static
    {
        return $this->state(['is_container' => true]);
    }

    /**
     * A bulk item.
     */
    public function bulk(int $quantity = 10, string $unit = 'Stück'): static
    {
        return $this->state(['kind' => ItemKind::Bulk, 'quantity' => $quantity, 'unit' => $unit]);
    }

    /**
     * A bulk item whose whole quantity lies at one place ("Eingang" without place). Bulk items have
     * no place of their own, their stock lies in item_stocks.
     */
    public function stockedAt(Location|Item|null $place = null): static
    {
        return $this->afterCreating(function (Item $item) use ($place) {
            $stock = ItemStock::for($item, $place);
            $stock->quantity = $item->quantity;
            $stock->save();
        });
    }

    /**
     * An item at a location or in a box.
     */
    public function at(Location|Item $place): static
    {
        return $this->state($place instanceof Location ? ['location_id' => $place->id] : ['container_id' => $place->id]);
    }
}
