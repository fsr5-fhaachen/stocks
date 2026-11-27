<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\Location;
use Tests\TestCase;

/**
 * "Kein Etikett nötig" (App\Enums\LabelStatus): mark, filter, batch prints, printing.
 */
class NoLabelTest extends TestCase
{
    public function test_the_filter_tells_missing_printed_and_not_needed_apart(): void
    {
        $member = $this->userWithRoles('FSR-Mitglied');
        $missing = Item::factory()->create();
        $printed = Item::factory()->create(['label_printed_at' => now()]);
        $none = Item::factory()->create(['no_label' => true]);

        foreach (['missing' => $missing, 'printed' => $printed, 'none' => $none] as $status => $item) {
            $this->actingAs($member)->get("/inventory/items?label={$status}")
                ->assertInertia(fn ($page) => $page
                    ->where('items.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$item->id])
                    ->where('items.data.0.label_status', $status));
        }

        // the older link of "Noch ohne Etikett" means "Etikett fehlt"
        $this->actingAs($member)->get('/inventory/items?unlabeled=1')
            ->assertInertia(fn ($page) => $page
                ->where('items.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$missing->id])
                ->where('filters.label', 'missing'));
    }

    public function test_the_mark_is_set_on_the_item_page_and_taken_away_again(): void
    {
        $this->enableModelAuditing();
        $editor = $this->userWithPermissions('inventory.view', 'inventory.edit');
        $item = Item::factory()->create();

        $this->actingAs($editor)->post("/inventory/items/{$item->id}/no-label", ['no_label' => true])->assertSessionHasNoErrors();
        $this->assertTrue($item->fresh()->no_label);
        $this->assertDatabaseHas('audits', ['auditable_type' => Item::class, 'auditable_id' => $item->id, 'event' => 'updated']);

        $this->actingAs($editor)->post("/inventory/items/{$item->id}/no-label", ['no_label' => false]);
        $this->assertFalse($item->fresh()->no_label);

        $this->actingAs($this->userWithPermissions('inventory.view'))
            ->post("/inventory/items/{$item->id}/no-label", ['no_label' => true])->assertForbidden();
    }

    public function test_the_item_form_and_the_location_form_take_the_mark(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit', 'locations.manage');
        $item = Item::factory()->create();
        $location = Location::factory()->address()->create(['code' => 'EU']);

        $this->actingAs($actor)->put("/inventory/items/{$item->id}", [
            'name' => $item->name, 'kind' => 'single', 'no_label' => true,
        ])->assertSessionHasNoErrors();
        $this->assertTrue($item->fresh()->no_label);

        $this->actingAs($actor)->put("/inventory/locations/{$location->id}", ['code' => 'EU', 'no_label' => true])->assertSessionHasNoErrors();
        $this->assertTrue($location->fresh()->no_label);
        // a change without the field keeps it
        $this->actingAs($actor)->put("/inventory/locations/{$location->id}", ['code' => 'EU', 'name' => 'Eupener Straße']);
        $this->assertTrue($location->fresh()->no_label);
    }

    public function test_printing_a_box_or_all_below_leaves_out_what_needs_no_label(): void
    {
        $member = $this->userWithRoles('FSR-Mitglied');
        $box = Item::factory()->container()->create();
        $cable = Item::factory()->at($box)->create(['no_label' => true]);
        $beamer = Item::factory()->at($box)->create();
        $room = Location::factory()->create(['code' => '014']);
        $shelf = Location::factory()->in($room)->create(['code' => 'R01']);
        $plain = Location::factory()->in($shelf)->create(['code' => '1']);
        $marked = Location::factory()->in($shelf)->create(['code' => '2', 'no_label' => true]);

        $this->actingAs($member)->get("/labels/print?box={$box->id}&below={$room->id}")
            ->assertInertia(fn ($page) => $page->where('labels', fn ($labels) => collect($labels)->pluck('code')->sort()->values()->all()
                === collect([$beamer->number, $shelf->full_code, $plain->full_code])->sort()->values()->all()));

        // chosen on purpose, it is printed anyway
        $this->actingAs($member)->get("/labels/print?items={$cable->id}&locations={$marked->id}")
            ->assertInertia(fn ($page) => $page->where('labels', fn ($labels) => count($labels) === 2));
    }

    public function test_a_printed_label_takes_the_mark_away(): void
    {
        $member = $this->userWithRoles('FSR-Mitglied');
        $item = Item::factory()->create(['no_label' => true]);
        $location = Location::factory()->create(['no_label' => true]);

        $this->actingAs($member)->post('/labels/printed', ['items' => [$item->id], 'locations' => [$location->id]]);

        $this->assertFalse($item->fresh()->no_label);
        $this->assertNotNull($item->fresh()->label_printed_at);
        $this->assertFalse($location->fresh()->no_label);
    }
}
