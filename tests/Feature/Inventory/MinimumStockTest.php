<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Location;
use Tests\TestCase;

/**
 * Minimum stock of a bulk item ("Mindestbestand") and the target quantity of a place set from the
 * box or location ("Sollmenge festlegen"); both make a "Mangel".
 */
class MinimumStockTest extends TestCase
{
    public function test_the_form_sets_the_minimum_stock_of_bulk_items_only(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit');
        $neu = $this->conditionId('neu');

        $this->actingAs($actor)->post('/inventory/items', ['name' => 'Gaffa', 'kind' => 'bulk', 'quantity' => 5, 'unit' => 'Rollen', 'min_quantity' => 10, 'condition_id' => $neu])->assertSessionHasNoErrors();
        $this->actingAs($actor)->post('/inventory/items', ['name' => 'Beamer', 'kind' => 'single', 'min_quantity' => 3, 'condition_id' => $neu])->assertSessionHasNoErrors();

        $tape = Item::firstWhere('name', 'Gaffa');
        $this->assertSame(10, $tape->min_quantity);
        $this->assertTrue($tape->isShort());
        $this->assertNull(Item::firstWhere('name', 'Beamer')->min_quantity);

        $this->actingAs($actor)->put("/inventory/items/{$tape->id}", ['name' => 'Gaffa', 'kind' => 'bulk', 'quantity' => 5, 'unit' => 'Rollen', 'min_quantity' => 4])->assertSessionHasNoErrors();
        $this->assertFalse($tape->fresh()->isShort());
        $this->actingAs($actor)->get("/inventory/items/{$tape->id}")->assertInertia(fn ($page) => $page->where('item.min_quantity', 4));
    }

    public function test_the_list_filters_and_the_dashboard_counts_what_is_short(): void
    {
        $actor = $this->userWithPermissions('inventory.view');
        $box = Item::factory()->container()->create();
        $below = Item::factory()->bulk(5)->stockedAt()->create(['name' => 'Kabelbinder', 'min_quantity' => 10]);
        $placeShort = Item::factory()->bulk(3)->stockedAt($box)->create(['name' => 'Batterien']);
        ItemStock::for($placeShort, $box)->forceFill(['target_quantity' => 8])->save();
        Item::factory()->bulk(20)->stockedAt()->create(['name' => 'Gaffa', 'min_quantity' => 10]);

        $this->actingAs($actor)->get('/inventory/items?short=1&sort=name')
            ->assertInertia(fn ($page) => $page->has('items.data', 2)
                ->where('items.data.0.name', 'Batterien')->where('items.data.0.short', true)
                ->where('items.data.1.id', $below->id));
        $this->actingAs($actor)->getJson('/inventory/stats')->assertJsonPath('short', 2);
    }

    public function test_a_target_is_set_at_a_box_also_for_an_item_not_there_yet(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit', 'inventory.move');
        $shelf = Location::factory()->create(['code' => 'R1']);
        $box = Item::factory()->container()->at($shelf)->create();
        $batteries = Item::factory()->bulk(12)->stockedAt()->create(['name' => 'AA-Batterie']);

        $this->actingAs($actor)->post('/inventory/stocks/target', ['code' => $batteries->number, 'place_code' => $box->number, 'target_quantity' => 8])
            ->assertSessionHasNoErrors();
        $stock = ItemStock::for($batteries, $box);
        $this->assertSame(0, $stock->quantity);
        $this->assertSame(8, $stock->target_quantity);
        $this->actingAs($actor)->get("/inventory/items/{$box->id}")
            ->assertInertia(fn ($page) => $page->where('item.contents.0.quantity', 0)->where('item.contents.0.stock.short', true));

        $this->actingAs($actor)->post('/inventory/stocks/fill', ['code' => $box->number])->assertSessionHas('success', 'Aufgefüllt: 8 Stück AA-Batterie.');
        $this->assertSame(8, ItemStock::for($batteries, $box)->quantity);

        // empty: no target any more
        $this->actingAs($actor)->post('/inventory/stocks/target', ['code' => $batteries->number, 'place_code' => 'R1', 'target_quantity' => 5])->assertSessionHasNoErrors();
        $this->actingAs($actor)->post('/inventory/stocks/target', ['code' => $batteries->number, 'place_code' => 'R1'])->assertSessionHasNoErrors();
        $this->assertFalse(ItemStock::for($batteries, $shelf)->exists);
        $this->actingAs($this->userWithPermissions('inventory.view', 'inventory.move'))
            ->post('/inventory/stocks/target', ['code' => $batteries->number, 'place_code' => 'R1', 'target_quantity' => 5])->assertForbidden();
    }
}
