<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Location;
use Tests\TestCase;

/**
 * "Wareneingang", "Einpacken", counting a place with its target ("Sollmenge") and "Auffüllen".
 */
class StockInTest extends TestCase
{
    public function test_new_pieces_are_booked_into_incoming_by_number_or_barcode(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit');
        $ties = Item::factory()->bulk(0, 'Packungen')->create(['name' => 'Kabelbinder', 'ean' => '4006381333931']);

        $this->actingAs($actor)->post('/inventory/stock-in', ['code' => $ties->number, 'quantity' => 5, 'note' => 'Bestellung Reichelt'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "5 Packungen von {$ties->number} Kabelbinder liegen im Eingang.");
        $this->actingAs($actor)->post('/inventory/stock-in', ['code' => '4006381333931', 'quantity' => 3])->assertSessionHasNoErrors();

        $this->assertSame(8, $ties->fresh()->quantity);
        $this->assertSame(8, ItemStock::for($ties, null)->quantity);
        $this->assertDatabaseHas('item_movements', ['item_id' => $ties->id, 'type' => 'receive', 'quantity' => 5, 'note' => 'Bestellung Reichelt']);
        $this->actingAs($actor)->get('/inventory/stock-in')
            ->assertInertia(fn ($page) => $page->component('Inventory/StockIn')->has('incoming', 1)->where('incoming.0.quantity', 8));
        $this->actingAs($actor)->getJson('/inventory/stats')->assertJsonPath('incoming.pieces', 8)->assertJsonPath('incoming.items', 1);
    }

    public function test_only_active_bulk_items_take_new_pieces(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit');
        $beamer = Item::factory()->create();
        $retired = Item::factory()->bulk(0)->create(['retired_at' => now()]);

        $this->actingAs($actor)->post('/inventory/stock-in', ['code' => $beamer->number, 'quantity' => 1])->assertSessionHasErrors('code');
        $this->actingAs($actor)->post('/inventory/stock-in', ['code' => $retired->number, 'quantity' => 1])->assertSessionHasErrors('code');
        $this->actingAs($actor)->post('/inventory/stock-in', ['code' => 'NICHTS-1', 'quantity' => 1])->assertSessionHasErrors('code');
        $this->actingAs($this->userWithPermissions('inventory.view', 'inventory.move'))->get('/inventory/stock-in')->assertForbidden();
    }

    public function test_pieces_are_packed_from_incoming_into_a_box(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move');
        $box = Item::factory()->container()->create();
        $batteries = Item::factory()->bulk(12)->stockedAt()->create();

        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $batteries->id, 'target_code' => $box->number, 'quantity' => 4, 'from' => ItemStock::INCOMING])
            ->assertSessionHasNoErrors();

        $this->assertSame(8, ItemStock::for($batteries, null)->quantity);
        $this->assertSame(4, ItemStock::for($batteries, $box)->quantity);
        $this->actingAs($actor)->get("/inventory/items/{$box->id}")->assertInertia(fn ($page) => $page->where('item.contents.0.quantity', 4));
    }

    public function test_counting_a_place_corrects_the_stock_and_sets_its_target(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit');
        $box = Item::factory()->container()->create(['number' => 'B-12']);
        $batteries = Item::factory()->bulk(10)->stockedAt($box)->create();

        $this->actingAs($actor)->post("/inventory/items/{$batteries->id}/stocks", ['place' => ItemStock::keyOf($box), 'quantity' => 7, 'target_quantity' => 8])
            ->assertSessionHasNoErrors();

        $stock = ItemStock::for($batteries, $box);
        $this->assertSame(7, $stock->quantity);
        $this->assertSame(8, $stock->target_quantity);
        $this->assertTrue($stock->isShort());
        $this->assertSame(7, $batteries->fresh()->quantity);
        $this->assertDatabaseHas('item_movements', ['item_id' => $batteries->id, 'type' => 'count', 'quantity' => 7]);

        // counted to 0 the place stays because of its target; without target it goes
        $this->actingAs($actor)->post("/inventory/items/{$batteries->id}/stocks", ['place' => ItemStock::keyOf($box), 'quantity' => 0, 'target_quantity' => 8]);
        $this->assertTrue(ItemStock::for($batteries, $box)->exists);
        $this->actingAs($actor)->post("/inventory/items/{$batteries->id}/stocks", ['place' => ItemStock::keyOf($box), 'quantity' => 0]);
        $this->assertFalse(ItemStock::for($batteries, $box)->exists);
        $this->actingAs($this->userWithPermissions('inventory.view', 'inventory.move'))
            ->post("/inventory/items/{$batteries->id}/stocks", ['place' => ItemStock::INCOMING, 'quantity' => 3])->assertForbidden();
    }

    public function test_filling_up_takes_the_missing_pieces_from_incoming(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move', 'inventory.edit');
        $shelf = Location::factory()->create(['code' => 'R1']);
        $box = Item::factory()->container()->at($shelf)->create();
        $batteries = Item::factory()->bulk(3, 'Stück')->stockedAt($box)->create(['name' => 'AA-Batterie']);
        $tape = Item::factory()->bulk(1, 'Rollen')->stockedAt($box)->create(['name' => 'Gaffa']);
        $this->actingAs($actor)->post("/inventory/items/{$batteries->id}/stocks", ['place' => ItemStock::keyOf($box), 'quantity' => 3, 'target_quantity' => 8]);
        $this->actingAs($actor)->post("/inventory/items/{$tape->id}/stocks", ['place' => ItemStock::keyOf($box), 'quantity' => 1, 'target_quantity' => 4]);
        $this->actingAs($actor)->post('/inventory/stock-in', ['code' => $batteries->number, 'quantity' => 20]);
        $this->actingAs($actor)->post('/inventory/stock-in', ['code' => $tape->number, 'quantity' => 1]);

        $this->actingAs($actor)->post('/inventory/stocks/fill', ['code' => $box->number])
            ->assertSessionHas('warning', 'Aufgefüllt: 5 Stück AA-Batterie, 1 Rollen Gaffa. Es fehlen noch 2 Rollen Gaffa.');

        $this->assertSame(8, ItemStock::for($batteries, $box)->quantity);
        $this->assertSame(15, ItemStock::for($batteries, null)->quantity);
        $this->assertSame(2, ItemStock::for($tape, $box)->quantity);
        $this->assertFalse(ItemStock::for($tape, null)->exists);
        $this->actingAs($actor)->post('/inventory/stocks/fill', ['code' => 'R1'])->assertSessionHas('success', 'In R1 fehlt nichts.');
    }
}
