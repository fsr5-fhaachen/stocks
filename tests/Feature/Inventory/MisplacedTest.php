<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\Location;
use App\Support\Extensions\ItemAwayProvider;
use App\Support\Extensions\ItemExtensions;
use Illuminate\Database\Eloquent\Builder;
use Tests\TestCase;

/**
 * "Ohne Platz" leaves out the items a module has away on purpose.
 */
class MisplacedTest extends TestCase
{
    public function test_items_away_on_purpose_are_not_misplaced(): void
    {
        $shelf = Location::forceCreate(['type' => 'room', 'code' => 'R1', 'full_code' => 'R1']);
        $away = Item::factory()->create(['home_location_id' => $shelf->id]);
        $lost = Item::factory()->create(['home_location_id' => $shelf->id]);
        Item::factory()->create(['location_id' => $shelf->id, 'home_location_id' => $shelf->id]);
        FakeAwayItems::$ids = [$away->id];
        app(ItemExtensions::class)->addAway(FakeAwayItems::class);

        $this->assertSame([$lost->id], app(ItemExtensions::class)->misplaced()->pluck('id')->all());
        $admin = $this->admin();
        $this->actingAs($admin)->getJson('/inventory/stats')->assertJsonPath('misplaced', 1);
        $this->actingAs($admin)->get('/inventory/misplaced')->assertInertia(fn ($page) => $page->has('items.data', 1)->where('items.data.0.id', $lost->id));
        // the list of all items still shows it
        $this->actingAs($admin)->get('/inventory/items')->assertInertia(fn ($page) => $page->has('items.data', 3));
    }

    public function test_pieces_away_are_part_of_the_stock_but_not_here(): void
    {
        $shelf = Location::forceCreate(['type' => 'room', 'code' => 'R1', 'full_code' => 'R1']);
        Location::forceCreate(['type' => 'room', 'code' => 'R2', 'full_code' => 'R2']);
        $cables = Item::factory()->bulk(10)->create(['location_id' => $shelf->id]);
        FakeAwayItems::$quantities = [$cables->id => 4];
        app(ItemExtensions::class)->addAway(FakeAwayItems::class);
        $admin = $this->admin();

        $this->assertSame(6, app(ItemExtensions::class)->quantityHere($cables));
        $this->actingAs($admin)->post('/inventory/move', ['item_id' => $cables->id, 'target_code' => 'R2', 'quantity' => 7])->assertSessionHasErrors('quantity');
        $this->actingAs($admin)->post("/inventory/items/{$cables->id}/retire", ['reason' => 'lost', 'quantity' => 7])->assertSessionHasErrors('quantity');
        $this->actingAs($admin)->put("/inventory/items/{$cables->id}", ['name' => $cables->name, 'kind' => 'bulk', 'quantity' => 3, 'unit' => $cables->unit, 'condition_id' => $cables->condition_id])->assertSessionHasErrors('quantity');
        $this->actingAs($admin)->post('/inventory/move', ['item_id' => $cables->id, 'target_code' => 'R2', 'quantity' => 6])->assertSessionHasNoErrors();
        $this->assertSame(4, $cables->refresh()->quantity);
        FakeAwayItems::$quantities = [];
    }
}

/**
 * Provider of the test: the items in $ids are away, and $quantities pieces of bulk items.
 */
class FakeAwayItems implements ItemAwayProvider
{
    /**
     * @var list<int>
     */
    public static array $ids = [];

    public function awayItemIds(): Builder
    {
        return Item::query()->whereIn('id', self::$ids)->select('id');
    }

    /**
     * @var array<int, int>
     */
    public static array $quantities = [];

    public function awayQuantities(array $itemIds): array
    {
        return array_intersect_key(self::$quantities, array_flip($itemIds));
    }
}
