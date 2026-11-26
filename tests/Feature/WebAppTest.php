<?php

namespace Tests\Feature;

use App\Support\Branding;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Manifest and icons of the web app.
 */
class WebAppTest extends TestCase
{
    public function test_the_manifest_names_the_organization_and_runs_in_full_screen(): void
    {
        app(Settings::class)->set(['organization.name' => 'FSR Informatik', 'organization.short_name' => 'FSR']);

        $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertJsonPath('name', 'FSR Informatik')
            ->assertJsonPath('short_name', 'FSR')
            ->assertJsonPath('display', 'fullscreen')
            ->assertJsonCount(3, 'icons');
    }

    public function test_icons_are_png_in_the_given_size(): void
    {
        $response = $this->get('/web-app/icon-192.png')->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->assertSame([192, 192], array_slice(getimagesizefromstring($response->getContent()), 0, 2));
        $this->get('/web-app/icon-100.png')->assertNotFound();
    }

    /**
     * Colour of a pixel of an icon as [red, green, blue].
     *
     * @return list<int>
     */
    private function pixel(string $png, int $x, int $y): array
    {
        $color = imagecolorsforindex($image = imagecreatefromstring($png), imagecolorat($image, $x, $y));

        return [$color['red'], $color['green'], $color['blue']];
    }

    public function test_without_light_logo_the_icon_is_a_white_s_on_the_accent_colour(): void
    {
        app(Settings::class)->set(['organization.accent_color' => '#ff0000']);

        $png = $this->get('/web-app/icon-512.png')->getContent();

        $this->assertSame([255, 0, 0], $this->pixel($png, 5, 5));
        // the middle of the "S" is white
        $this->assertSame([255, 255, 255], $this->pixel($png, 256, 256));
        $this->get('/manifest.webmanifest')->assertJsonPath('background_color', '#ff0000');
    }

    public function test_the_light_logo_becomes_the_icon_on_white(): void
    {
        Storage::fake(Branding::DISK);
        $before = $this->get('/manifest.webmanifest')->json('icons.0.src');
        $this->actingAs($this->admin())->post('/admin/settings/branding', ['logo_light' => UploadedFile::fake()->image('hell.png', 100, 100)])->assertSessionHasNoErrors();

        $manifest = $this->get('/manifest.webmanifest')->assertJsonPath('background_color', '#ffffff');
        // a new url, so devices do not keep the old icon
        $this->assertNotSame($before, $manifest->json('icons.0.src'));
        $this->assertSame([255, 255, 255], $this->pixel($this->get($manifest->json('icons.2.src'))->getContent(), 5, 5));
        auth()->logout();
        $this->get('/login')->assertSee('icon-180.png?v='.app(Branding::class)->iconVersion(), false);
    }

    public function test_an_own_icon_fills_the_whole_icon_before_the_light_logo(): void
    {
        Storage::fake(Branding::DISK);
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/settings/branding', ['logo_light' => UploadedFile::fake()->image('hell.png', 100, 100)]);
        $withLogo = $this->get('/manifest.webmanifest')->json('icons.2.src');

        // the fake image is black: without margin even the corner is black, cut to a square
        $this->actingAs($admin)->post('/admin/settings/branding', ['app_icon' => UploadedFile::fake()->image('icon.png', 600, 400)])->assertSessionHasNoErrors();
        $manifest = $this->get('/manifest.webmanifest')->assertJsonPath('background_color', '#ffffff');
        $this->assertNotSame($withLogo, $manifest->json('icons.2.src'));
        $png = $this->get($manifest->json('icons.2.src'))->assertOk()->getContent();
        $this->assertSame([512, 512], [imagesx($image = imagecreatefromstring($png)), imagesy($image)]);
        $this->assertSame([0, 0, 0], $this->pixel($png, 1, 1));
        $this->actingAs($admin)->get('/admin/settings')->assertInertia(fn ($page) => $page->where('branding.app_icon', app(Branding::class)->url('app_icon')));

        // removed, the light logo on white is back
        $this->actingAs($admin)->post('/admin/settings/branding', ['remove' => ['app_icon']])->assertSessionHasNoErrors();
        $this->assertSame([255, 255, 255], $this->pixel($this->get($this->get('/manifest.webmanifest')->json('icons.2.src'))->getContent(), 1, 1));
    }

    public function test_an_own_icon_that_can_not_be_opened_falls_back_to_the_light_logo(): void
    {
        Storage::fake(Branding::DISK);
        $this->actingAs($this->admin())->post('/admin/settings/branding', ['logo_light' => UploadedFile::fake()->image('hell.png', 100, 100)]);
        // e.g. stored before uploads were scaled down, too big to open
        Storage::disk(Branding::DISK)->put('branding/kaputt.png', 'kein Bild');
        app(Settings::class)->set(['organization.app_icon_path' => 'branding/kaputt.png']);

        $png = $this->get($this->get('/manifest.webmanifest')->json('icons.2.src'))->assertOk()->getContent();

        // the light logo on white, not the "S" on the accent colour
        $this->assertSame([255, 255, 255], $this->pixel($png, 1, 1));
    }

    public function test_the_dark_logo_alone_does_not_change_the_icon(): void
    {
        Storage::fake(Branding::DISK);
        $before = $this->get('/manifest.webmanifest')->json('icons.0.src');
        $this->actingAs($this->admin())->post('/admin/settings/branding', ['logo' => UploadedFile::fake()->image('dunkel.png', 100, 100)]);

        $this->assertSame($before, $this->get('/manifest.webmanifest')->json('icons.0.src'));
    }
}
