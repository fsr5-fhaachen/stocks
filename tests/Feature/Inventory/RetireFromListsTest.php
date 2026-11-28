<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Location;
use Tests\TestCase;

/**
 * "Verbraucht" and "Ausmustern" right in lists: the contents of a box, a location, the scan card.
 */
class RetireFromListsTest extends TestCase
{
    public function test_box_contents_and_locations_offer_the_actions_with_their_place(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move', 'inventory.retire');
        $shelf = Location::factory()->create(['code' => 'R1']);
        $box = Item::factory()->container()->at($shelf)->create();
        $ties = Item::factory()->bulk(10, 'Packungen')->stockedAt($box)->create();
        $cables = Item::factory()->bulk(6, 'Stück')->stockedAt($shelf)->create();

        $this->actingAs($actor)->get("/inventory/items/{$box->id}")
            ->assertInertia(fn ($page) => $page->where('item.contents.0.consumable', true)->where('item.contents.0.stock.key', ItemStock::keyOf($box))->has('retireReasons'));
        $this->actingAs($actor)->get("/inventory/locations/{$shelf->id}")
            ->assertInertia(fn ($page) => $page->where('stocks.0.consumable', false)->where('stocks.0.stock.key', ItemStock::keyOf($shelf))->has('retireReasons'));
        $this->actingAs($actor)->get('/inventory/scan')->assertInertia(fn ($page) => $page->has('retireReasons'));

        // the dialogs send the place along
        $this->actingAs($actor)->post("/inventory/items/{$ties->id}/consume", ['quantity' => 3, 'from' => ItemStock::keyOf($box)])->assertSessionHasNoErrors();
        $this->actingAs($actor)->post("/inventory/items/{$cables->id}/retire", ['reason' => 'defective', 'quantity' => 2, 'from' => ItemStock::keyOf($shelf)])->assertSessionHasNoErrors();

        $this->assertSame(7, ItemStock::for($ties, $box)->quantity);
        $this->assertSame(4, ItemStock::for($cables, $shelf)->quantity);
        $this->assertSame(4, $cables->fresh()->quantity);
    }
}
