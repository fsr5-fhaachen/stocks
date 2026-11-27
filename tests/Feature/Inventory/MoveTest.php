<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Location;
use Tests\TestCase;

/**
 * Moving: movement log, boxes, pieces of bulk items between places, home.
 */
class MoveTest extends TestCase
{
    public function test_moving_changes_the_place_and_writes_the_log(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move');
        $from = Location::factory()->create(['code' => 'EU']);
        $to = Location::factory()->create(['code' => 'GO']);
        $item = Item::factory()->at($from)->create();

        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $item->id, 'target_code' => 'go', 'note' => 'Für die Party'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "{$item->number} liegt jetzt in GO.");

        $this->assertTrue($item->fresh()->location->is($to));
        $this->assertDatabaseHas('item_movements', [
            'item_id' => $item->id, 'type' => 'move', 'from_location_id' => $from->id, 'to_location_id' => $to->id, 'user_id' => $actor->id, 'note' => 'Für die Party',
        ]);
    }

    public function test_moving_does_not_change_the_home(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move');
        $home = Location::factory()->create(['code' => 'EU']);
        Location::factory()->create(['code' => 'GO']);
        $item = Item::factory()->at($home)->create(['home_location_id' => $home->id]);

        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $item->id, 'target_code' => 'GO']);

        $item->refresh();
        $this->assertTrue($item->homeLocation->is($home));
        $this->assertTrue($item->isAwayFromHome());

        $this->actingAs($actor)->post("/inventory/items/{$item->id}/return-home")->assertSessionHasNoErrors();
        $this->assertFalse($item->fresh()->isAwayFromHome());
    }

    public function test_the_contents_move_with_their_box(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move');
        $from = Location::factory()->create(['code' => 'EU']);
        $to = Location::factory()->create(['code' => 'GO']);
        $box = Item::factory()->container()->at($from)->create();
        $innerBox = Item::factory()->container()->at($box)->create();
        $cable = Item::factory()->at($innerBox)->create();

        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $box->id, 'target_code' => 'GO']);

        $this->assertTrue($cable->fresh()->effectiveLocation()->is($to));
        // only the box itself gets a row in the log
        $this->assertDatabaseMissing('item_movements', ['item_id' => $cable->id]);
    }

    public function test_items_can_be_put_into_a_box_by_its_number(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move');
        $box = Item::factory()->container()->create(['number' => 'FSR5-000117']);
        $item = Item::factory()->create();

        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $item->id, 'target_code' => 'fsr5000117'])->assertSessionHasNoErrors();

        $this->assertTrue($item->fresh()->container->is($box));
    }

    public function test_a_box_can_not_go_into_itself_or_its_own_contents(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move');
        $box = Item::factory()->container()->create(['number' => 'TEST-1']);
        $innerBox = Item::factory()->container()->at($box)->create(['number' => 'TEST-2']);

        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $box->id, 'target_code' => 'TEST-1'])->assertSessionHasErrors('target');
        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $box->id, 'target_code' => 'TEST-2'])->assertSessionHasErrors('target');
        $this->assertNull($box->fresh()->container_id);
        $this->assertTrue($innerBox->fresh()->container->is($box));
    }

    public function test_items_do_not_lie_directly_at_an_address(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move', 'inventory.edit');
        $address = Location::factory()->address()->create(['code' => 'EU']);
        Location::factory()->in($address)->create(['code' => '014']);
        $item = Item::factory()->create();

        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $item->id, 'target_code' => 'EU'])->assertSessionHasErrors('target');
        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $item->id, 'target_code' => 'EU-014'])->assertSessionHasNoErrors();
        $this->actingAs($actor)->post('/inventory/items', ['name' => 'Beamer', 'kind' => 'single', 'condition_id' => $this->conditionId('gut'), 'place_code' => 'EU'])->assertSessionHasErrors('place');
        $this->actingAs($actor)->put("/inventory/items/{$item->id}", ['name' => $item->name, 'kind' => 'single', 'home_code' => 'EU'])->assertSessionHasErrors('home');
    }

    public function test_items_only_go_into_boxes(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move');
        Item::factory()->create(['number' => 'TEST-1']);
        $item = Item::factory()->create();

        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $item->id, 'target_code' => 'TEST-1'])->assertSessionHasErrors('target_code');
    }

    public function test_moving_pieces_of_a_bulk_item_changes_the_stock_of_the_places(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move');
        $from = Location::factory()->create(['code' => 'EU']);
        $to = Location::factory()->create(['code' => 'GO']);
        $cables = Item::factory()->bulk(20)->stockedAt($from)->create(['name' => 'XLR-Kabel', 'label_printed_at' => now()]);

        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $cables->id, 'target_code' => 'GO', 'quantity' => 5])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "5 Stück von {$cables->number} liegen jetzt in GO.");

        // one item, no new number: its pieces lie at two places now
        $this->assertSame(1, Item::count());
        $this->assertSame(20, $cables->fresh()->quantity);
        $this->assertNotNull($cables->fresh()->label_printed_at);
        $this->assertSame(15, ItemStock::for($cables, $from)->quantity);
        $this->assertSame(5, ItemStock::for($cables, $to)->quantity);
        $this->assertDatabaseHas('item_movements', ['item_id' => $cables->id, 'type' => 'move', 'quantity' => 5, 'from_location_id' => $from->id, 'to_location_id' => $to->id]);
    }

    public function test_a_bulk_item_is_never_away_from_its_home(): void
    {
        $actor = $this->userWithPermissions('inventory.view');
        $home = Location::factory()->create(['code' => 'EU']);
        $cables = Item::factory()->bulk(10)->stockedAt($home)->create(['home_location_id' => $home->id]);

        // no place of its own: the home is only a suggestion
        $this->assertFalse($cables->isAwayFromHome());
        $this->actingAs($actor)->get("/inventory/items/{$cables->id}")->assertInertia(fn ($page) => $page->where('item.away_from_home', false));
        $this->actingAs($actor)->get('/inventory/items')->assertInertia(fn ($page) => $page->where('items.data.0.away_from_home', false));
    }

    public function test_pieces_at_several_places_need_the_place_they_come_from(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move');
        $first = Location::factory()->create(['code' => 'EU']);
        $second = Location::factory()->create(['code' => 'GO']);
        Location::factory()->create(['code' => 'KE']);
        $cables = Item::factory()->bulk(10)->stockedAt($first)->create();
        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $cables->id, 'target_code' => 'GO', 'quantity' => 4])->assertSessionHasNoErrors();

        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $cables->id, 'target_code' => 'KE', 'quantity' => 2])->assertSessionHasErrors('from');
        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $cables->id, 'target_code' => 'KE', 'quantity' => 2, 'from' => ItemStock::keyOf($second)])->assertSessionHasNoErrors();

        $this->assertSame(6, ItemStock::for($cables, $first)->quantity);
        $this->assertSame(2, ItemStock::for($cables, $second)->quantity);
        $this->actingAs($actor)->getJson("/inventory/lookup?code={$cables->number}")->assertJsonCount(3, 'stocks');
    }

    public function test_the_quantity_can_not_exceed_the_stock_of_the_place(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move');
        Location::factory()->create(['code' => 'GO']);
        $cables = Item::factory()->bulk(3)->stockedAt()->create();

        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $cables->id, 'target_code' => 'GO', 'quantity' => 4])
            ->assertSessionHasErrors(['quantity' => 'Im Eingang liegen nur 3 Stück.']);
    }

    public function test_the_misplaced_list_shows_items_without_place_or_away_from_home(): void
    {
        $actor = $this->userWithPermissions('inventory.view');
        $home = Location::factory()->create();
        $other = Location::factory()->create();
        Item::factory()->create(['name' => 'Ohne Ort']);
        Item::factory()->at($other)->create(['name' => 'Woanders', 'home_location_id' => $home->id]);
        Item::factory()->at($home)->create(['name' => 'Daheim', 'home_location_id' => $home->id]);
        Item::factory()->at($other)->create(['name' => 'Ohne Stammplatz']);

        $this->actingAs($actor)->get('/inventory/misplaced?sort=name')
            ->assertInertia(fn ($page) => $page->has('items.data', 2)->where('items.data.0.name', 'Ohne Ort')->where('items.data.1.name', 'Woanders'));
    }

    public function test_the_target_can_become_only_the_home(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move', 'inventory.edit');
        $from = Location::factory()->create(['code' => 'EU']);
        $shelf = Location::factory()->create(['code' => 'GO']);
        $item = Item::factory()->at($from)->create();
        $movements = $item->movements()->count();

        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $item->id, 'target_code' => 'GO', 'mode' => 'home'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "Stammplatz von {$item->number} ist jetzt GO.");

        $item->refresh();
        $this->assertTrue($item->location->is($from));
        $this->assertTrue($item->homeLocation->is($shelf));
        $this->assertSame($movements, $item->movements()->count());

        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $item->id, 'target_code' => 'GO', 'mode' => 'home'])
            ->assertSessionHasErrors('target_code');
    }

    public function test_the_target_can_become_place_and_home(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move', 'inventory.edit');
        $from = Location::factory()->create(['code' => 'EU']);
        $shelf = Location::factory()->create(['code' => 'GO']);
        $item = Item::factory()->at($from)->create(['home_location_id' => $from->id]);

        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $item->id, 'target_code' => 'GO', 'mode' => 'both'])
            ->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertTrue($item->location->is($shelf));
        $this->assertTrue($item->homeLocation->is($shelf));
        $this->assertFalse($item->isAwayFromHome());
    }

    public function test_a_new_home_needs_the_right_to_edit(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move');
        $from = Location::factory()->create(['code' => 'EU']);
        Location::factory()->create(['code' => 'GO']);
        $item = Item::factory()->at($from)->create();

        foreach (['home', 'both'] as $mode) {
            $this->actingAs($actor)->post('/inventory/move', ['item_id' => $item->id, 'target_code' => 'GO', 'mode' => $mode])
                ->assertSessionHasErrors('mode');
        }

        $item->refresh();
        $this->assertTrue($item->location->is($from));
        $this->assertNull($item->home_location_id);
    }

    public function test_a_home_follows_the_rules_of_a_place(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.move', 'inventory.edit');
        Location::factory()->address()->create(['code' => 'EU']);
        $item = Item::factory()->create();

        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $item->id, 'target_code' => 'EU', 'mode' => 'home'])
            ->assertSessionHasErrors('target');
        $this->assertNull($item->fresh()->home_location_id);
    }
}
