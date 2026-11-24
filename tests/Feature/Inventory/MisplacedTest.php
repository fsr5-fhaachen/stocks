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
}

/**
 * Provider of the test: the items in $ids are away.
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
}
