<?php

namespace Tests\Feature\Inventory;

use App\Models\Location;
use App\Support\Inventory\CodeSequence;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * "Mehrere anlegen": several shelves or compartments in a row (App\Actions\Inventory\CreateLocations).
 */
class LocationBatchTest extends TestCase
{
    public function test_the_codes_count_on_in_their_own_pattern(): void
    {
        $this->assertSame(['1', '2', '3'], CodeSequence::from('1', 3));
        $this->assertSame(['R08', 'R09', 'R10'], CodeSequence::from('r08', 3));
        $this->assertSame(['R9', 'R10'], CodeSequence::from('R9', 2));
        $this->assertSame(['A', 'B', 'C'], CodeSequence::from('A', 3));
        $this->assertSame(['R03Y', 'R03Z'], CodeSequence::from('R03Y', 2));

        $this->expectException(ValidationException::class);
        CodeSequence::from('Y', 3);
    }

    public function test_several_compartments_are_created_at_once(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'locations.manage');
        $room = Location::factory()->create(['code' => '014']);
        $shelf = Location::factory()->in($room)->create(['code' => 'R03']);

        $this->actingAs($actor)->post('/inventory/locations', ['parent_id' => $shelf->id, 'code' => '1', 'count' => 4, 'no_label' => true])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '4 Standorte angelegt: 014-R03.1 bis 014-R03.4.');

        $this->assertSame(['014-R03.1', '014-R03.2', '014-R03.3', '014-R03.4'], $shelf->children()->orderBy('full_code')->pluck('full_code')->all());
        $this->assertSame(4, Location::where('parent_id', $shelf->id)->where('no_label', true)->where('type', 'compartment')->count());
    }

    public function test_a_taken_code_stops_the_whole_row(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'locations.manage');
        $room = Location::factory()->create(['code' => '014']);
        $shelf = Location::factory()->in($room)->create(['code' => 'R03']);
        Location::factory()->in($shelf)->create(['code' => '3']);
        Location::factory()->in($shelf)->create(['code' => '5']);

        $this->actingAs($actor)->post('/inventory/locations', ['parent_id' => $shelf->id, 'code' => '1', 'count' => 6])
            ->assertSessionHasErrors(['code' => 'Die Kennziffern 014-R03.3, 014-R03.5 gibt es schon.']);

        $this->assertSame(2, $shelf->children()->count());
    }

    public function test_only_shelves_and_compartments_come_in_rows(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'locations.manage');
        $address = Location::factory()->address()->create(['code' => 'EU']);

        $this->actingAs($actor)->post('/inventory/locations', ['parent_id' => $address->id, 'code' => '001', 'count' => 3])
            ->assertSessionHasErrors('count');
        $this->actingAs($actor)->post('/inventory/locations', ['code' => 'A', 'count' => 3])
            ->assertSessionHasErrors('count');

        $this->assertSame(1, Location::count());
    }

    public function test_locations_are_listed_as_people_count(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'locations.manage', 'labels.print');
        $room = Location::factory()->create(['code' => '014']);
        $shelf = Location::factory()->in($room)->create(['code' => 'R01']);
        foreach (['10', '2', '1', '11', '9'] as $code) {
            Location::factory()->in($shelf)->create(['code' => $code]);
        }
        $expected = ['014-R01.1', '014-R01.2', '014-R01.9', '014-R01.10', '014-R01.11'];

        $this->actingAs($actor)->get("/inventory/locations/{$shelf->id}")
            ->assertInertia(fn ($page) => $page->where('children', fn ($children) => collect($children)->pluck('full_code')->all() === $expected));
        $this->actingAs($actor)->get('/inventory/locations')
            ->assertInertia(fn ($page) => $page->where('locations', fn ($locations) => collect($locations)->pluck('full_code')->all() === ['014', '014-R01', ...$expected]));
        $this->actingAs($actor)->get("/labels/print?below={$shelf->id}")
            ->assertInertia(fn ($page) => $page->where('labels', fn ($labels) => collect($labels)->pluck('code')->reject(fn ($code) => $code === '014-R01')->values()->all() === $expected));
    }
}
