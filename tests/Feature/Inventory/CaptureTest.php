<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\CreateItem;
use App\Models\Item;
use App\Models\ItemCondition;
use App\Models\ItemPhoto;
use App\Models\Location;
use App\Models\ReservedNumber;
use App\Models\User;
use App\Support\Inventory\ItemNumbers;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Guided capture: page, label preview, creating one or several pieces, repeated requests.
 */
class CaptureTest extends TestCase
{
    public function test_the_page_needs_the_right_to_edit_items(): void
    {
        $this->actingAs($this->userWithPermissions('inventory.view'))->get('/inventory/capture')->assertForbidden();

        $this->actingAs($this->userWithPermissions('inventory.view', 'inventory.edit'))->get('/inventory/capture')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inventory/Capture')
                ->has('conditions')
                ->where('maxCount', 50)
                ->where('printing', null));

        $this->actingAs($this->userWithPermissions('inventory.view', 'inventory.edit', 'labels.print'))->get('/inventory/capture')
            ->assertInertia(fn ($page) => $page->has('printing.templates')
                ->has('printing.printers')
                ->has('printing.variables.item')
                ->has('printing.variables.box')
                ->has('printing.baseUrlConfirmed'));
    }

    public function test_the_entry_is_in_the_bar_at_the_bottom_only_with_the_right(): void
    {
        $entry = fn ($navigation) => collect($navigation)->flatMap(fn ($section) => $section['entries'])->firstWhere('href', '/inventory/capture');

        $this->actingAs($this->userWithPermissions('inventory.view', 'inventory.edit'))->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('navigation', fn ($navigation) => ($entry($navigation)['mobile'] ?? false) === true));
        $this->actingAs($this->userWithPermissions('inventory.view'))->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('navigation', fn ($navigation) => $entry($navigation) === null));
    }

    public function test_the_preview_has_a_placeholder_number_and_creates_nothing(): void
    {
        app(Settings::class)->set(['inventory.number_prefix' => 'FSR5']);
        $room = Location::factory()->create(['code' => 'EU', 'full_code' => 'EU']);

        $this->actingAs($this->userWithPermissions('inventory.view', 'inventory.edit'))->postJson('/inventory/capture/preview', [
            'name' => 'Stativ', 'kind' => 'single', 'condition_id' => $this->conditionId('gut'), 'place_code' => 'EU', 'label_hint' => 'Vorsicht',
        ])
            ->assertOk()
            ->assertJsonPath('label.key', 'draft')
            ->assertJsonPath('label.target', 'item')
            ->assertJsonPath('label.code', 'FSR5-000000')
            ->assertJsonPath('label.values.name', 'Stativ')
            ->assertJsonPath('label.values.location', 'EU')
            ->assertJsonPath('label.values.home', '')
            ->assertJsonPath('label.values.hint', 'Vorsicht');

        $this->assertSame(0, Item::count());
        $this->assertSame(0, $room->items()->count());
    }

    public function test_the_preview_of_a_box_uses_the_box_target(): void
    {
        $this->actingAs($this->userWithPermissions('inventory.view', 'inventory.edit'))->postJson('/inventory/capture/preview', [
            'name' => 'Kabelkiste', 'kind' => 'single', 'is_container' => true, 'condition_id' => $this->conditionId('gut'),
        ])->assertOk()->assertJsonPath('label.target', 'box')->assertJsonPath('label.values.count', '0');
    }

    public function test_the_preview_rejects_an_unknown_place(): void
    {
        $this->actingAs($this->userWithPermissions('inventory.view', 'inventory.edit'))->postJson('/inventory/capture/preview', [
            'name' => 'Stativ', 'kind' => 'single', 'condition_id' => $this->conditionId('gut'), 'place_code' => 'XX-999',
        ])->assertUnprocessable()->assertJsonValidationErrors('place_code');
    }

    public function test_a_single_piece_gets_its_place_as_home(): void
    {
        $room = Location::factory()->create(['code' => 'EU', 'full_code' => 'EU']);

        $response = $this->actingAs($this->userWithPermissions('inventory.view', 'inventory.edit', 'labels.print'))->postJson('/inventory/capture', [
            'name' => 'Beamer', 'kind' => 'single', 'condition_id' => $this->conditionId('gut'),
            'place_code' => 'EU', 'home_code' => 'EU', 'capture_key' => 'a1',
        ])->assertCreated()->assertJsonCount(1, 'items')->assertJsonCount(1, 'labels');

        $item = Item::sole();
        $this->assertTrue($item->location->is($room));
        $this->assertTrue($item->homeLocation->is($room));
        $this->assertNull($item->label_printed_at);
        $response->assertJsonPath('items.0.number', $item->number)
            ->assertJsonPath('labels.0.key', "item-{$item->id}")
            ->assertJsonPath('labels.0.values.home', 'EU');
    }

    public function test_the_home_can_differ_from_the_place_and_both_can_be_empty(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit');
        $room = Location::factory()->create(['code' => 'EU', 'full_code' => 'EU']);
        $store = Location::factory()->create(['code' => 'LA', 'full_code' => 'LA']);

        $this->actingAs($actor)->postJson('/inventory/capture', [
            'name' => 'Kabeltrommel', 'kind' => 'single', 'condition_id' => $this->conditionId('gut'),
            'place_code' => 'EU', 'home_code' => 'LA', 'capture_key' => 'b1',
        ])->assertCreated();
        $this->actingAs($actor)->postJson('/inventory/capture', [
            'name' => 'Funkmikro', 'kind' => 'single', 'condition_id' => $this->conditionId('gut'), 'capture_key' => 'b2',
        ])->assertCreated()->assertJsonPath('items.0.place', null);

        $drum = Item::firstWhere('name', 'Kabeltrommel');
        $this->assertTrue($drum->location->is($room));
        $this->assertTrue($drum->homeLocation->is($store));
        $this->assertNull(Item::firstWhere('name', 'Funkmikro')->place());
    }

    public function test_labels_are_only_answered_with_the_right_to_print(): void
    {
        $this->actingAs($this->userWithPermissions('inventory.view', 'inventory.edit'))->postJson('/inventory/capture', [
            'name' => 'Beamer', 'kind' => 'single', 'condition_id' => $this->conditionId('gut'), 'capture_key' => 'c1',
        ])->assertCreated()->assertJsonCount(1, 'items')->assertJsonCount(0, 'labels');
    }

    public function test_several_pieces_get_numbers_in_a_row_and_each_its_photos(): void
    {
        Storage::fake(ItemPhoto::DISK);
        app(Settings::class)->set(['inventory.number_prefix' => 'FSR5']);

        $this->actingAs($this->userWithPermissions('inventory.view', 'inventory.edit', 'labels.print'))->post('/inventory/capture', [
            'name' => 'Stativ', 'kind' => 'single', 'condition_id' => $this->conditionId('gut'), 'count' => 5, 'capture_key' => 'd1',
            'photos' => [UploadedFile::fake()->image('stativ.jpg')],
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonCount(5, 'items')->assertJsonCount(5, 'labels');

        $items = Item::orderBy('number')->get();
        $this->assertCount(5, $items);
        $this->assertSame(['FSR5-000018', 'FSR5-000026', 'FSR5-000034', 'FSR5-000042', 'FSR5-000059'], $items->pluck('number')->all());
        foreach ($items as $item) {
            $this->assertSame('Stativ', $item->name);
            $this->assertCount(1, $item->photos);
            Storage::disk(ItemPhoto::DISK)->assertExists($item->photos->first()->path);
        }
        $this->assertCount(5, $items->map(fn (Item $item) => $item->photos->first()->path)->unique());
    }

    public function test_several_pieces_are_created_all_or_none(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit');
        // a place that does not hold items fails before any piece is saved
        $address = Location::factory()->address()->create(['code' => 'BAY', 'full_code' => 'BAY']);

        $this->actingAs($actor)->postJson('/inventory/capture', [
            'name' => 'Stativ', 'kind' => 'single', 'condition_id' => $this->conditionId('gut'), 'count' => 3, 'place_code' => $address->full_code, 'capture_key' => 'e1',
        ])->assertUnprocessable()->assertJsonValidationErrors('place');

        // the third piece fails: the first two are dropped too
        $this->app->bind(CreateItem::class, fn () => new class(app(ItemNumbers::class)) extends CreateItem
        {
            private int $calls = 0;

            public function handle(User $actor, array $data, ItemCondition $condition, Location|Item|null $place, Location|Item|null $home, array $photos = [], ?ReservedNumber $reserved = null, array $photoSources = []): Item
            {
                if (++$this->calls === 3) {
                    throw ValidationException::withMessages(['name' => 'kaputt']);
                }

                return parent::handle($actor, $data, $condition, $place, $home, $photos, $reserved, $photoSources);
            }
        });

        $this->actingAs($actor)->postJson('/inventory/capture', [
            'name' => 'Stativ', 'kind' => 'single', 'condition_id' => $this->conditionId('gut'), 'count' => 3, 'capture_key' => 'e2',
        ])->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->assertSame(0, Item::count());
    }

    public function test_a_bulk_item_can_not_have_several_pieces(): void
    {
        $this->actingAs($this->userWithPermissions('inventory.view', 'inventory.edit'))->postJson('/inventory/capture', [
            'name' => 'Becher', 'kind' => 'bulk', 'quantity' => 200, 'unit' => 'Stück', 'condition_id' => $this->conditionId('gut'), 'count' => 4, 'capture_key' => 'f1',
        ])->assertUnprocessable()->assertJsonValidationErrors('count');

        $this->assertSame(0, Item::count());
    }

    public function test_a_repeated_request_with_the_same_key_creates_nothing_new(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit');
        $data = ['name' => 'Beamer', 'kind' => 'single', 'condition_id' => $this->conditionId('gut'), 'count' => 2, 'capture_key' => 'g1'];

        $first = $this->actingAs($actor)->postJson('/inventory/capture', $data)->assertCreated()->json('items');
        $again = $this->actingAs($actor)->postJson('/inventory/capture', $data)->assertCreated()->json('items');

        $this->assertSame(2, Item::count());
        $this->assertSame(array_column($first, 'id'), array_column($again, 'id'));

        // another run gets new items
        $this->actingAs($actor)->postJson('/inventory/capture', [...$data, 'capture_key' => 'g2'])->assertCreated();
        $this->assertSame(4, Item::count());
    }

    public function test_the_capture_key_is_required(): void
    {
        $this->actingAs($this->userWithPermissions('inventory.view', 'inventory.edit'))->postJson('/inventory/capture', [
            'name' => 'Beamer', 'kind' => 'single', 'condition_id' => $this->conditionId('gut'),
        ])->assertUnprocessable()->assertJsonValidationErrors('capture_key');
    }
}
