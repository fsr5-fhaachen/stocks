<?php

namespace Tests\Feature\Demo;

use App\Enums\ItemKind;
use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Location;
use App\Models\ReservedNumber;
use App\Models\User;
use App\Support\Demo\DemoGenerator;
use App\Support\Demo\InventoryDemo;
use App\Support\Demo\TestSheet;
use App\Support\Products\Gtin;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Demo data of the core (documentation/DEMO.md). The parts of modules have their own tests.
 */
class DemoDataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    /**
     * One generator run for every check: it takes several seconds, so the cases, the test sheet and
     * the download share the data. A first run in a transaction proves that the seed gives the same numbers.
     */
    public function test_the_generator_creates_every_case_the_same_for_the_same_seed_with_a_sheet(): void
    {
        $user = $this->userWithPermissions('labels.print');
        $this->actingAs($user)->get('/labels/demo-sheet.pdf')->assertNotFound();

        DB::beginTransaction();
        app(DemoGenerator::class)->run();
        $numbers = Item::orderBy('id')->pluck('number')->all();
        DB::rollBack();
        Cache::flush();

        app(DemoGenerator::class)->run();
        $this->assertSame($numbers, Item::orderBy('id')->pluck('number')->all(), 'the same seed gives the same numbers');

        $this->assertGreaterThanOrEqual(InventoryDemo::ITEMS, Item::count());
        $this->assertSame(65, Location::count());
        $this->assertTrue(Location::where('full_code', 'EU-A-201')->exists(), 'room in a building in the code');
        $this->assertTrue(Location::where('full_code', 'EU-W01')->exists(), 'room in a building not in the code');
        $this->assertSame(InventoryDemo::BOXES, Item::where('is_container', true)->count());
        $this->assertTrue(Item::whereNotNull('container_id')->where('is_container', true)->exists(), 'box in box');
        $this->assertTrue(Item::where('kind', ItemKind::Bulk)->whereIn('unit', ['Rollen', 'Packungen'])->exists(), 'consumable');
        $this->assertTrue(ItemStock::where('quantity', '>', 0)->groupBy('item_id')->havingRaw('count(*) > 1')->exists(), 'bulk item at several places');
        $this->assertTrue(ItemStock::where('place_key', ItemStock::INCOMING)->where('quantity', '>', 0)->exists(), 'pieces in Eingang');
        $this->assertFalse(Item::where('kind', ItemKind::Bulk)->where(fn ($query) => $query->whereNotNull('location_id')->orWhereNotNull('container_id'))->exists(), 'bulk items have no place of their own');
        $this->assertTrue(Item::whereNotNull('retired_at')->exists(), 'retired');
        $this->assertTrue(Item::whereHas('condition', fn ($query) => $query->where('is_defect', true))->exists(), 'defective');
        $this->assertTrue(Item::active()->where('kind', '!=', ItemKind::Bulk)->whereNull('location_id')->whereNull('container_id')->exists(), 'without place');
        $this->assertTrue(Item::whereNull('label_printed_at')->where('no_label', false)->exists(), 'without label');
        $this->assertTrue(Item::where('no_label', true)->exists(), 'item needs no label');
        $this->assertTrue(Location::where('no_label', true)->exists(), 'location needs no label');
        $this->assertTrue(Item::whereNotNull('label_hint')->exists(), 'with hint');
        $this->assertTrue(Item::has('photos')->exists(), 'with photos');
        $this->assertTrue(Item::whereNotNull('ean')->exists(), 'with barcode of the packaging');
        $this->assertTrue(Item::whereNotNull('ean')->get()->every(fn (Item $item) => Gtin::normalize($item->ean) === $item->ean), 'valid barcodes');
        $this->assertTrue(ReservedNumber::open()->exists(), 'reserved number');
        // one account per start role besides the admin (the account of the download above does not count)
        $this->assertSame(count(config('stocks.start_roles')) + 1, User::where('email', 'like', '%@example.com')->whereKeyNot($user->id)->count());
        $this->assertNotNull(app(Settings::class)->get('demo.seeded_at'));

        $labels = app(Settings::class)->get('demo.labels');
        $this->assertNotEmpty($labels);
        $unknown = collect($labels)->firstWhere('purpose', 'Unbekannte Nummer');
        foreach ($labels as $label) {
            // the unknown number, and labels with an address of their own (borrower page)
            if ($label === $unknown || isset($label['url'])) {
                continue;
            }
            $known = Item::where('number', $label['code'])->exists() || Location::where('full_code', $label['code'])->exists() || ReservedNumber::where('number', $label['code'])->exists();
            $this->assertTrue($known, "{$label['code']} ({$label['purpose']}) gibt es nicht.");
        }
        $this->assertFalse(Item::where('number', $unknown['code'])->exists());
        $this->assertStringStartsWith('%PDF', app(TestSheet::class)->pdf());

        $this->actingAs($user)->get('/labels/demo-sheet.pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
