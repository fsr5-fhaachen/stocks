<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\Location;
use App\Support\Table\ItemsTable;
use Tests\TestCase;

/**
 * "Nach Boxen gruppieren": the contents of boxes are shown below their box.
 */
class ItemsGroupedTest extends TestCase
{
    private Location $shelf;

    private Item $box;

    private Item $inner;

    private Item $loose;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shelf = Location::forceCreate(['type' => 'room', 'code' => 'R1', 'full_code' => 'R1']);
        $this->box = Item::factory()->create(['name' => 'Box Kabel', 'is_container' => true, 'location_id' => $this->shelf->id, 'home_location_id' => $this->shelf->id]);
        $this->inner = Item::factory()->create(['name' => 'Box Adapter', 'is_container' => true, 'container_id' => $this->box->id, 'home_container_id' => $this->box->id]);
        Item::factory()->create(['name' => 'XLR', 'container_id' => $this->box->id, 'home_container_id' => $this->box->id]);
        Item::factory()->create(['name' => 'Klinke', 'container_id' => $this->inner->id, 'home_container_id' => $this->inner->id]);
        $this->loose = Item::factory()->create(['name' => 'Beamer', 'location_id' => $this->shelf->id, 'home_location_id' => $this->shelf->id]);
    }

    /**
     * Names of the rows of a list.
     *
     * @return list<string>
     */
    private function names(string $url): array
    {
        return collect($this->actingAs($this->admin())->get($url)->viewData('page')['props']['items']['data'])->pluck('name')->sort()->values()->all();
    }

    public function test_the_list_shows_only_what_lies_outside_of_boxes(): void
    {
        $this->assertSame(['Beamer', 'Box Kabel'], $this->names('/inventory/items?grouped=1'));
        $this->actingAs($this->admin())->get('/inventory/items?grouped=1')
            ->assertInertia(fn ($page) => $page->where('filters.grouped', '1')->where('items.data.1.contents_count', 2));
        // flat without the switch
        $this->assertCount(5, $this->names('/inventory/items'));
    }

    public function test_search_and_filters_make_the_list_flat(): void
    {
        $this->assertSame(['Klinke'], $this->names('/inventory/items?grouped=1&search=Klinke'));
        $this->assertCount(5, $this->names('/inventory/items?grouped=1&unlabeled=1'));
    }

    public function test_a_box_delivers_its_contents_with_the_boxes_in_it(): void
    {
        $this->actingAs($this->admin())->getJson("/inventory/items/{$this->box->id}/contents")
            ->assertOk()
            ->assertJsonPath('more', 0)
            ->assertJsonPath('rows.0.name', 'Box Adapter')
            ->assertJsonPath('rows.0.contents_count', 1)
            ->assertJsonPath('rows.1.name', 'XLR');

        $this->actingAs($this->admin())->getJson("/inventory/items/{$this->loose->id}/contents")->assertNotFound();
        $this->actingAs($this->userWithPermissions())->getJson("/inventory/items/{$this->box->id}/contents")->assertForbidden();
    }

    public function test_a_big_box_delivers_the_first_rows_and_how_many_more(): void
    {
        Item::factory()->count(ItemsTable::CONTENTS_LIMIT + 3)->create(['container_id' => $this->inner->id]);

        $response = $this->actingAs($this->admin())->getJson("/inventory/items/{$this->inner->id}/contents");

        $this->assertCount(ItemsTable::CONTENTS_LIMIT, $response->json('rows'));
        $response->assertJsonPath('more', 4);
    }

    public function test_without_place_the_contents_of_a_box_without_place_lie_below_it(): void
    {
        // the box lies nowhere, the cable in it belongs on the shelf: both are "Ohne Platz",
        // grouped the cable is below the box
        $this->box->forceFill(['location_id' => null])->save();
        Item::where('name', 'XLR')->update(['home_container_id' => null, 'home_location_id' => $this->shelf->id]);

        $this->assertSame(['Box Kabel', 'XLR'], $this->names('/inventory/misplaced'));
        $this->assertSame(['Box Kabel'], $this->names('/inventory/misplaced?grouped=1'));
    }
}
