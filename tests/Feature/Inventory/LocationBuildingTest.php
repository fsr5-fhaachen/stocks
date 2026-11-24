<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\Location;
use Tests\TestCase;

/**
 * The optional level building between address and room, shown in the codes or not.
 */
class LocationBuildingTest extends TestCase
{
    private Location $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->address = Location::factory()->address()->create(['code' => 'EU']);
    }

    private function actor()
    {
        return $this->userWithPermissions('inventory.view', 'inventory.edit', 'locations.manage');
    }

    private function building(bool $showInCode = false, string $code = 'A'): Location
    {
        $this->actingAs($this->actor())->post('/inventory/locations', ['parent_id' => $this->address->id, 'type' => 'building', 'code' => $code, 'show_in_code' => $showInCode])->assertSessionHasNoErrors();

        return Location::where('type', 'building')->where('code', $code)->firstOrFail();
    }

    private function room(Location $parent, string $code = '014'): Location
    {
        $this->actingAs($this->actor())->post('/inventory/locations', ['parent_id' => $parent->id, 'code' => $code])->assertSessionHasNoErrors();

        return Location::where('parent_id', $parent->id)->where('code', $code)->firstOrFail();
    }

    public function test_a_room_lies_in_the_address_or_in_a_building(): void
    {
        // without a choice below an address it is a room, as before
        $direct = $this->room($this->address, '001');
        $this->assertSame('room', $direct->type->value);
        $this->assertSame('EU-001', $direct->full_code);

        $building = $this->building();
        $this->assertSame('EU-A', $building->full_code);
        $this->assertFalse($building->show_in_code);
        $room = $this->room($building);
        $this->assertSame('room', $room->type->value);
        $this->assertSame('EU-014', $room->full_code);
    }

    public function test_a_building_in_the_code_is_part_of_the_codes_below(): void
    {
        $building = $this->building(showInCode: true);
        $room = $this->room($building);
        $this->actingAs($this->actor())->post('/inventory/locations', ['parent_id' => $room->id, 'code' => 'R03']);
        $shelf = Location::firstWhere('code', 'R03');
        $this->actingAs($this->actor())->post('/inventory/locations', ['parent_id' => $shelf->id, 'code' => '4']);

        $this->assertDatabaseHas('locations', ['full_code' => 'EU-A-014-R03.4', 'type' => 'compartment']);
    }

    public function test_switching_the_code_recomputes_the_codes_below(): void
    {
        $building = $this->building();
        $room = $this->room($building);
        $shelf = Location::factory()->in($room)->create(['code' => 'R03']);
        $this->assertSame('EU-014-R03', $shelf->full_code);

        $this->actingAs($this->actor())->put("/inventory/locations/{$building->id}", ['parent_id' => $this->address->id, 'code' => 'A', 'show_in_code' => true])->assertSessionHasNoErrors();
        $this->assertSame('EU-A-014', $room->fresh()->full_code);
        $this->assertSame('EU-A-014-R03', $shelf->fresh()->full_code);

        $this->actingAs($this->actor())->put("/inventory/locations/{$building->id}", ['parent_id' => $this->address->id, 'code' => 'B', 'show_in_code' => true])->assertSessionHasNoErrors();
        $this->assertSame('EU-B-014-R03', $shelf->fresh()->full_code);
    }

    public function test_a_collision_stops_before_anything_changes(): void
    {
        $a = $this->building(showInCode: true, code: 'A');
        $this->room($a);
        $this->room($this->address);
        $this->assertDatabaseHas('locations', ['full_code' => 'EU-A-014']);

        // without the building in the code both rooms would be EU-014
        $this->actingAs($this->actor())->put("/inventory/locations/{$a->id}", ['parent_id' => $this->address->id, 'code' => 'A', 'show_in_code' => false])
            ->assertSessionHasErrors(['show_in_code' => 'Die Kennziffer EU-014 gibt es schon.']);
        $this->assertTrue($a->fresh()->show_in_code);
        $this->assertDatabaseHas('locations', ['full_code' => 'EU-A-014']);
    }

    public function test_an_existing_room_moves_into_a_building_and_back(): void
    {
        $room = $this->room($this->address);
        $shelf = Location::factory()->in($room)->create(['code' => 'R03']);
        $building = $this->building(showInCode: true);

        $this->actingAs($this->actor())->put("/inventory/locations/{$room->id}", ['parent_id' => $building->id, 'code' => '014'])->assertSessionHasNoErrors();
        $this->assertSame('EU-A-014-R03', $shelf->fresh()->full_code);

        $this->actingAs($this->actor())->put("/inventory/locations/{$room->id}", ['parent_id' => $this->address->id, 'code' => '014'])->assertSessionHasNoErrors();
        $this->assertSame('EU-014-R03', $shelf->fresh()->full_code);
    }

    public function test_the_levels_stay_in_order(): void
    {
        $building = $this->building();
        $room = $this->room($building);

        // a building lies in an address, nothing but a room in a building
        $this->actingAs($this->actor())->put("/inventory/locations/{$building->id}", ['parent_id' => $room->id, 'code' => 'A'])->assertSessionHasErrors(['parent_id' => 'Ein Gebäude liegt in einer Adresse.']);
        $this->actingAs($this->actor())->post('/inventory/locations', ['parent_id' => $building->id, 'type' => 'building', 'code' => 'X'])->assertSessionHasNoErrors();
        $this->assertSame('room', Location::firstWhere('code', 'X')->type->value);
        $shelf = Location::factory()->in($room)->create(['code' => 'R03']);
        $this->actingAs($this->actor())->put("/inventory/locations/{$room->id}", ['parent_id' => $shelf->id, 'code' => '014'])->assertSessionHasErrors(['parent_id' => 'Ein Raum liegt in einer Adresse oder einem Gebäude.']);
        // show_in_code is only for buildings
        $this->actingAs($this->actor())->put("/inventory/locations/{$room->id}", ['parent_id' => $building->id, 'code' => '014', 'show_in_code' => true]);
        $this->assertFalse($room->fresh()->show_in_code);
    }

    public function test_items_do_not_lie_in_a_building(): void
    {
        $building = $this->building();
        $item = Item::factory()->create();

        $actor = $this->userWithPermissions('inventory.view', 'inventory.move');
        $this->actingAs($actor)->post('/inventory/move', ['item_id' => $item->id, 'target_code' => 'EU-A'])
            ->assertSessionHasErrors(['target' => 'EU-A ist ein Gebäude. Gegenstände liegen in einem Raum, Lagerplatz oder Fach.']);
        $this->assertNull($item->fresh()->location_id);
        $this->assertSame([], $this->actingAs($actor)->getJson('/inventory/places?search=EU-A')->json());
    }

    public function test_existing_locations_keep_their_codes(): void
    {
        $room = $this->room($this->address);
        $this->actingAs($this->actor())->put("/inventory/locations/{$room->id}", ['parent_id' => $this->address->id, 'code' => '014'])->assertSessionHasNoErrors();

        $this->assertSame('EU-014', $room->fresh()->full_code);
        $this->assertFalse($room->fresh()->show_in_code);
    }

    public function test_the_tree_and_the_path_name_the_building(): void
    {
        $building = $this->building();
        $room = $this->room($building);

        $this->actingAs($this->actor())->get("/inventory/locations/{$room->id}")->assertInertia(fn ($page) => $page
            ->where('path.1.full_code', 'EU-A')->where('path.1.type', 'building')->where('location.full_code', 'EU-014'));
        $this->actingAs($this->actor())->get('/inventory/locations')->assertInertia(fn ($page) => $page->has('locations', 3));
    }
}
