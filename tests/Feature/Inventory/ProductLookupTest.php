<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\ItemPhoto;
use App\Models\User;
use App\Support\Products\Gtin;
use App\Support\Products\ImageFetcher;
use App\Support\Products\UserAgent;
use App\Support\Settings;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Barcode of the packaging in the guided capture: sources, cache, limits, images, storing.
 */
class ProductLookupTest extends TestCase
{
    private const EAN = '4006381333931';

    private const UPC = '012345678905';

    public function test_barcodes_are_checked_and_stored_in_one_form(): void
    {
        $this->assertSame(self::EAN, Gtin::normalize(self::EAN));
        $this->assertNull(Gtin::normalize('4006381333932'));
        // UPC-A gets the leading 0 of its EAN-13
        $this->assertSame('0'.self::UPC, Gtin::normalize(self::UPC));
        $this->assertSame(self::UPC, Gtin::upcA('0'.self::UPC));
        // EAN-8
        $this->assertSame('96385074', Gtin::normalize('96385074'));
        // UPC-E is expanded: 04252614 → 042100005264
        $this->assertSame('0042100005264', Gtin::normalize('04252614', 'upc_e'));
        $this->assertNull(Gtin::normalize('FSR5-000018'));
    }

    public function test_the_sources_are_merged_with_upcitemdb_first(): void
    {
        $this->fakeSources();

        $this->actingAs($this->editor())->getJson('/inventory/capture/product?code='.self::EAN)
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('code', self::EAN)
            ->assertJsonPath('name', 'Xbox Wireless Controller')
            ->assertJsonPath('description', 'Microsoft · QAS-00002')
            ->assertJsonCount(3, 'images')
            ->assertJsonPath('images.0.source', 'UPCitemdb')
            ->assertJsonPath('images.0.host', 'shop.example')
            ->assertJsonPath('images.2.source', 'Open Food Facts')
            ->assertJsonPath('images.2.license', 'CC BY-SA 3.0')
            ->assertJsonPath('images.2.url', 'https://images.openfoodfacts.org/images/products/400/638/133/3931/front_de.5.full.jpg')
            ->assertJsonPath('sources.0.name', 'UPCitemdb')
            ->assertJsonPath('sources.1.license', 'ODbL')
            ->assertJsonPath('unavailable', []);

        // every request names Stocks
        Http::assertSent(fn (Request $request) => str_starts_with($request->header('User-Agent')[0] ?? '', 'Stocks/'));
    }

    public function test_a_second_scan_comes_from_the_cache(): void
    {
        $this->fakeSources();

        $this->actingAs($this->editor())->getJson('/inventory/capture/product?code='.self::EAN)->assertOk();
        $this->actingAs($this->editor())->getJson('/inventory/capture/product?code='.self::EAN)->assertOk();

        Http::assertSentCount(3);
    }

    public function test_nothing_found_is_kept_for_a_day_and_an_unreachable_source_not_at_all(): void
    {
        Http::fake([
            'api.upcitemdb.com/*' => Http::response(['code' => 'OK', 'total' => 0, 'items' => []]),
            '*' => Http::response(['status' => 0], 404),
        ]);
        $this->actingAs($this->editor())->getJson('/inventory/capture/product?code='.self::EAN)->assertJsonPath('found', false);
        $this->actingAs($this->editor())->getJson('/inventory/capture/product?code='.self::EAN);
        Http::assertSentCount(3);

        $this->travel(25)->hours();
        $this->actingAs($this->editor())->getJson('/inventory/capture/product?code='.self::EAN);
        Http::assertSentCount(6);
    }

    public function test_the_daily_limit_of_upcitemdb_pauses_it(): void
    {
        Http::fake([
            'api.upcitemdb.com/*' => Http::response(['code' => 'EXCEED_LIMIT'], 429, ['X-RateLimit-Reset' => (string) now()->addHours(5)->timestamp]),
            '*' => Http::response(['status' => 0], 404),
        ]);

        $this->actingAs($this->editor())->getJson('/inventory/capture/product?code='.self::EAN)
            ->assertJsonPath('found', false)
            ->assertJsonPath('unavailable', ['UPCitemdb']);
        // not kept: the next scan asks again, but without UPCitemdb until the reset
        $this->actingAs($this->editor())->getJson('/inventory/capture/product?code='.self::EAN)
            ->assertJsonPath('unavailable', ['UPCitemdb']);

        Http::assertSentCount(5);
    }

    public function test_wrong_codes_and_the_setting(): void
    {
        Http::fake();
        $this->actingAs($this->editor())->getJson('/inventory/capture/product?code=4006381333932')
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        app(Settings::class)->set(['inventory.product_lookup' => false]);
        $this->actingAs($this->editor())->getJson('/inventory/capture/product?code='.self::EAN)->assertNotFound();
        $this->actingAs($this->editor())->get('/inventory/capture')
            ->assertInertia(fn ($page) => $page->where('productLookup', false));
        Http::assertNothingSent();
    }

    public function test_items_with_the_barcode_are_shown(): void
    {
        $this->fakeSources();
        $item = Item::factory()->create(['ean' => self::EAN, 'name' => 'Controller 1']);

        $this->actingAs($this->editor())->getJson('/inventory/capture/product?code='.self::EAN)
            ->assertJsonPath('existing.0.id', $item->id)
            ->assertJsonPath('existing.0.number', $item->number);
    }

    public function test_only_images_of_a_cached_answer_are_downloaded(): void
    {
        $this->fakeSources();
        $this->app->bind(ImageFetcher::class, fn () => new ImageFetcher(app(UserAgent::class), fn (string $host) => ['93.184.216.34']));

        // nothing asked yet: no image
        $this->actingAs($this->editor())->get('/inventory/capture/product/'.self::EAN.'/images/0')->assertNotFound();

        $this->actingAs($this->editor())->getJson('/inventory/capture/product?code='.self::EAN);
        $this->actingAs($this->editor())->get('/inventory/capture/product/'.self::EAN.'/images/0')
            ->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($this->editor())->get('/inventory/capture/product/'.self::EAN.'/images/9')->assertNotFound();
    }

    public function test_images_from_the_internal_network_are_refused(): void
    {
        $this->fakeSources();
        $this->app->bind(ImageFetcher::class, fn () => new ImageFetcher(app(UserAgent::class), fn (string $host) => ['10.0.0.5']));

        $this->actingAs($this->editor())->getJson('/inventory/capture/product?code='.self::EAN);
        $this->actingAs($this->editor())->get('/inventory/capture/product/'.self::EAN.'/images/0')->assertNotFound();
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'shop.example'));
    }

    public function test_the_barcode_and_the_origin_of_photos_are_stored(): void
    {
        Storage::fake(ItemPhoto::DISK);
        $this->fakeSources();
        $this->actingAs($this->editor())->getJson('/inventory/capture/product?code='.self::EAN);

        $this->actingAs($this->editor())->post('/inventory/capture', [
            'name' => 'Xbox Wireless Controller', 'kind' => 'single', 'condition_id' => $this->conditionId('gut'),
            'ean' => self::EAN, 'count' => 2, 'capture_key' => 'p1',
            'photos' => [UploadedFile::fake()->image('eigen.jpg'), UploadedFile::fake()->image('shop.jpg'), UploadedFile::fake()->image('off.jpg')],
            'photo_refs' => [1 => self::EAN.':0', 2 => self::EAN.':2'],
        ], ['Accept' => 'application/json'])->assertCreated();

        $items = Item::orderBy('number')->get();
        $this->assertSame([self::EAN, self::EAN], $items->pluck('ean')->all());
        foreach ($items as $item) {
            $photos = $item->photos()->orderBy('sort')->get();
            $this->assertNull($photos[0]->source);
            $this->assertSame('UPCitemdb', $photos[1]->source);
            $this->assertSame('shop.example', $photos[1]->source_host);
            $this->assertSame('Open Food Facts', $photos[2]->source);
            $this->assertSame('CC BY-SA 3.0', $photos[2]->license);
            $this->assertSame('Open Food Facts contributors', $photos[2]->author);
        }
    }

    public function test_the_item_form_takes_the_barcode(): void
    {
        $item = Item::factory()->create();

        $this->actingAs($this->editor())->put("/inventory/items/{$item->id}", [
            'name' => $item->name, 'kind' => 'single', 'ean' => self::UPC,
        ])->assertSessionHasNoErrors();
        $this->assertSame('0'.self::UPC, $item->fresh()->ean);

        $this->actingAs($this->editor())->put("/inventory/items/{$item->id}", [
            'name' => $item->name, 'kind' => 'single', 'ean' => '12345',
        ])->assertSessionHasErrors('ean');
    }

    private function editor(): User
    {
        return $this->userWithPermissions('inventory.view', 'inventory.edit');
    }

    /**
     * UPCitemdb with two shop images, Open Food Facts with one image, Open Products Facts without
     * the product; images as small pngs.
     */
    private function fakeSources(): void
    {
        $png = UploadedFile::fake()->image('bild.png', 20, 20)->getContent();

        Http::fake([
            'api.upcitemdb.com/*' => Http::response(['code' => 'OK', 'total' => 1, 'items' => [[
                'ean' => self::EAN, 'title' => 'Xbox Wireless Controller', 'brand' => 'Microsoft', 'model' => 'QAS-00002',
                'images' => ['https://shop.example/a.jpg', 'https://shop.example/b.jpg', 'https://shop.example/a.jpg'],
            ]]], 200, ['X-RateLimit-Remaining' => '99']),
            'world.openfoodfacts.org/*' => Http::response(['status' => 1, 'product' => [
                'product_name_de' => 'Controller', 'brands' => 'Microsoft',
                'image_front_url' => 'https://images.openfoodfacts.org/images/products/400/638/133/3931/front_de.5.400.jpg',
            ]]),
            'world.openproductsfacts.org/*' => Http::response(['status' => 0, 'status_verbose' => 'product not found']),
            'shop.example/*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);
    }
}
