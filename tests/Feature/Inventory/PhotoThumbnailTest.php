<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\ItemPhoto;
use App\Support\Inventory\PhotoThumbnail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Small previews of photos for lists, made at the upload or the first time they are shown.
 */
class PhotoThumbnailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ItemPhoto::DISK);
    }

    /**
     * Width and height of an image.
     *
     * @return array{int, int}
     */
    private function sizeOf(string $content): array
    {
        $image = imagecreatefromstring($content);

        return [imagesx($image), imagesy($image)];
    }

    public function test_an_upload_gets_a_small_preview_beside_the_photo(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit');
        $item = Item::factory()->create();

        $this->actingAs($actor)->post("/inventory/items/{$item->id}/photos", ['photos' => [UploadedFile::fake()->image('beamer.png', 1600, 800)]])->assertSessionHasNoErrors();

        $photo = $item->photos()->sole();
        $this->assertSame(PhotoThumbnail::pathFor($photo->path), $photo->thumb_path);
        $this->assertSame([PhotoThumbnail::MAX_SIZE, PhotoThumbnail::MAX_SIZE / 2], $this->sizeOf(Storage::disk(ItemPhoto::DISK)->get($photo->thumb_path)));

        $response = $this->actingAs($actor)->get($photo->thumbUrl())->assertOk();
        $this->assertSame([PhotoThumbnail::MAX_SIZE, PhotoThumbnail::MAX_SIZE / 2], $this->sizeOf($response->streamedContent()));
        // the photo itself stays as it is
        $this->assertSame([1600, 800], $this->sizeOf($this->actingAs($actor)->get($photo->url())->streamedContent()));
    }

    public function test_a_small_photo_is_not_made_bigger(): void
    {
        $this->assertSame([40, 20], $this->sizeOf(PhotoThumbnail::make(UploadedFile::fake()->image('klein.png', 40, 20)->getContent())));
        $this->assertNull(PhotoThumbnail::make('kein Bild'));
    }

    public function test_a_missing_preview_is_made_when_the_photo_is_shown(): void
    {
        $actor = $this->userWithPermissions('inventory.view');
        $item = Item::factory()->create();
        Storage::disk(ItemPhoto::DISK)->put("items/{$item->id}/alt.png", UploadedFile::fake()->image('alt.png', 600, 600)->getContent());
        $photo = new ItemPhoto(['path' => "items/{$item->id}/alt.png"]);
        $photo->item()->associate($item)->save();

        $this->actingAs($actor)->get($photo->thumbUrl())->assertOk();

        $this->assertSame("items/{$item->id}/thumbs/alt.jpg", $photo->fresh()->thumb_path);
        Storage::disk(ItemPhoto::DISK)->assertExists("items/{$item->id}/thumbs/alt.jpg");
    }

    public function test_the_photo_is_shown_while_no_preview_can_be_made(): void
    {
        $actor = $this->userWithPermissions('inventory.view');
        $item = Item::factory()->create();
        Storage::disk(ItemPhoto::DISK)->put("items/{$item->id}/kaputt.png", 'kein Bild');
        $photo = new ItemPhoto(['path' => "items/{$item->id}/kaputt.png"]);
        $photo->item()->associate($item)->save();

        $this->assertSame('kein Bild', $this->actingAs($actor)->get($photo->thumbUrl())->assertOk()->streamedContent());
        $this->assertNull($photo->fresh()->thumb_path);
    }

    public function test_deleting_a_photo_removes_the_preview_too(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit');
        $item = Item::factory()->create();
        $this->actingAs($actor)->post("/inventory/items/{$item->id}/photos", ['photos' => [UploadedFile::fake()->image('beamer.jpg')]]);
        $photo = $item->photos()->sole();

        $this->actingAs($actor)->delete("/inventory/items/{$item->id}/photos/{$photo->id}");

        Storage::disk(ItemPhoto::DISK)->assertMissing($photo->path);
        Storage::disk(ItemPhoto::DISK)->assertMissing($photo->thumb_path);
    }

    public function test_lists_and_the_contents_of_a_box_use_the_preview(): void
    {
        $actor = $this->userWithPermissions('inventory.view', 'inventory.edit');
        $box = Item::factory()->create(['name' => 'Box', 'is_container' => true]);
        $cable = Item::factory()->create(['name' => 'Kabel', 'container_id' => $box->id, 'home_container_id' => $box->id]);
        ItemPhoto::uploadAll($cable, [UploadedFile::fake()->image('kabel.jpg')]);
        $url = $cable->photos()->sole()->thumbUrl();

        $this->actingAs($actor)->get("/inventory/items/{$box->id}")->assertInertia(fn ($page) => $page->where('item.contents.0.photo_url', $url));
        $this->actingAs($actor)->get('/inventory/items?grouped=0&search=Kabel')->assertInertia(fn ($page) => $page->where('items.data.0.photo_url', $url));
    }
}
