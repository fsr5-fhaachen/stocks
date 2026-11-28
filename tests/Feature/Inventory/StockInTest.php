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

    public function test_the_target_of_a_place_is_set_from_its_row(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit');
        $box = Item::factory()->container()->create(['number' => 'B-12']);
        $batteries = Item::factory()->bulk(7)->stockedAt($box)->create();

        $this->actingAs($actor)->post("/inventory/items/{$batteries->id}/stocks", ['place' => ItemStock::keyOf($box), 'target_quantity' => 8])
            ->assertSessionHasNoErrors();
        $stock = ItemStock::for($batteries, $box);
        $this->assertSame(7, $stock->quantity);
        $this->assertSame(8, $stock->target_quantity);
        $this->assertTrue($stock->isShort());

        // "Eingang" has no target; empty clears it
        $this->actingAs($actor)->post('/inventory/stock-in', ['code' => $batteries->number, 'quantity' => 1]);
        $this->actingAs($actor)->post("/inventory/items/{$batteries->id}/stocks", ['place' => ItemStock::INCOMING, 'target_quantity' => 3])->assertSessionHasErrors('target_quantity');
        $this->actingAs($actor)->post("/inventory/items/{$batteries->id}/stocks", ['place' => ItemStock::keyOf($box)])->assertSessionHasNoErrors();
        $this->assertNull(ItemStock::for($batteries, $box)->target_quantity);
        $this->actingAs($this->userWithPermissions('inventory.view', 'inventory.move'))
            ->post("/inventory/items/{$batteries->id}/stocks", ['place' => ItemStock::keyOf($box), 'target_quantity' => 3])->assertForbidden();
    }

    public function test_plus_takes_pieces_out_of_incoming_or_books_new_ones_in(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit', 'inventory.move');
        $box = Item::factory()->container()->create();
        $batteries = Item::factory()->bulk(3)->stockedAt($box)->create();

        // nothing in "Eingang" yet
        $this->actingAs($actor)->post("/inventory/items/{$batteries->id}/stocks/add", ['place' => ItemStock::keyOf($box), 'quantity' => 1])->assertSessionHasErrors('quantity');
        $this->actingAs($actor)->post('/inventory/stock-in', ['code' => $batteries->number, 'quantity' => 2]);
        $this->actingAs($actor)->post("/inventory/items/{$batteries->id}/stocks/add", ['place' => ItemStock::keyOf($box), 'quantity' => 3])
            ->assertSessionHasErrors(['quantity' => 'Im Eingang liegen nur 2 Stück.']);
        $this->actingAs($actor)->post("/inventory/items/{$batteries->id}/stocks/add", ['place' => ItemStock::keyOf($box), 'quantity' => 2, 'note' => 'Für die Party'])
            ->assertSessionHasNoErrors();
        $this->assertSame(5, ItemStock::for($batteries, $box)->quantity);
        $this->assertFalse(ItemStock::for($batteries, null)->exists);
        $this->assertSame(5, $batteries->fresh()->quantity);
        $this->assertDatabaseHas('item_movements', ['item_id' => $batteries->id, 'type' => 'move', 'quantity' => 2, 'to_container_id' => $box->id, 'note' => 'Für die Party']);

        // "+" at "Eingang" itself: new pieces, needs inventory.edit
        $this->actingAs($actor)->post('/inventory/stock-in', ['code' => $batteries->number, 'quantity' => 1]);
        $this->actingAs($actor)->post("/inventory/items/{$batteries->id}/stocks/add", ['place' => ItemStock::INCOMING, 'quantity' => 3, 'note' => 'Nachlieferung'])
            ->assertSessionHasNoErrors();
        $this->assertSame(4, ItemStock::for($batteries, null)->quantity);
        $this->assertSame(9, $batteries->fresh()->quantity);
        $this->actingAs($this->userWithPermissions('inventory.view', 'inventory.move'))
            ->post("/inventory/items/{$batteries->id}/stocks/add", ['place' => ItemStock::INCOMING, 'quantity' => 1])->assertForbidden();
    }

    public function test_the_box_history_shows_its_bulk_items_and_the_item_the_total_used_up(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move');
        $box = Item::factory()->container()->create();
        $ties = Item::factory()->bulk(10, 'Packungen')->stockedAt($box)->create(['name' => 'Kabelbinder']);

        $this->actingAs($actor)->post("/inventory/items/{$ties->id}/consume", ['quantity' => 4, 'from' => ItemStock::keyOf($box), 'note' => 'Aufbau Party'])
            ->assertSessionHasNoErrors();

        $this->actingAs($actor)->get("/inventory/items/{$box->id}")
            ->assertInertia(fn ($page) => $page->where('history', fn ($history) => collect($history)->contains(fn ($entry) => str_contains($entry['title'], 'Kabelbinder') && $entry['text'] === 'Aufbau Party' && $entry['quantity'] === 4)));
        $this->actingAs($actor)->get("/inventory/items/{$ties->id}")->assertInertia(fn ($page) => $page->where('item.consumed_total', 4));
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
