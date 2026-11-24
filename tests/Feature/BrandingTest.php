<?php

namespace Tests\Feature;

use App\Support\Branding;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Logo and favicon of the instance.
 */
class BrandingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Branding::DISK);
    }

    public function test_a_logo_is_uploaded_and_shown_to_everybody(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/settings/branding', ['logo' => UploadedFile::fake()->image('logo.png', 300, 100)])
            ->assertSessionHasNoErrors();

        $path = app(Settings::class)->get('organization.logo_path');
        Storage::disk(Branding::DISK)->assertExists($path);

        $url = app(Branding::class)->url('logo');
        $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('organization.logo_url', $url));

        auth()->logout();
        $this->get($url)->assertOk();
        $this->get('/login')->assertInertia(fn ($page) => $page->where('organization.logo_url', $url));
    }

    public function test_a_logo_for_light_backgrounds_is_shared_next_to_the_other(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/settings/branding', ['logo_light' => UploadedFile::fake()->image('hell.png', 300, 100)])->assertSessionHasNoErrors();

        $url = app(Branding::class)->url('logo_light');
        $this->assertNotNull($url);
        $this->actingAs($admin)->get('/dashboard')->assertInertia(fn ($page) => $page->where('organization.logo_light_url', $url)->where('organization.logo_url', null));
        $this->actingAs($admin)->get('/admin/settings')->assertInertia(fn ($page) => $page->where('branding.logo_light', $url));

        $this->actingAs($admin)->post('/admin/settings/branding', ['remove' => ['logo_light']])->assertSessionHasNoErrors();
        $this->assertNull(app(Settings::class)->get('organization.logo_light_path'));
    }

    public function test_a_new_logo_replaces_the_old_file(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/settings/branding', ['logo' => UploadedFile::fake()->image('alt.png')]);
        $old = app(Settings::class)->get('organization.logo_path');

        $this->actingAs($admin)->post('/admin/settings/branding', ['logo' => UploadedFile::fake()->image('neu.png')]);

        Storage::disk(Branding::DISK)->assertMissing($old);
        $this->assertNotSame($old, app(Settings::class)->get('organization.logo_path'));
    }

    public function test_the_favicon_falls_back_to_the_default(): void
    {
        $this->assertSame('/favicon.ico', app(Branding::class)->url('favicon'));
        $this->get('/branding/favicon')->assertOk();
        $this->get('/branding/logo')->assertNotFound();

        $this->actingAs($this->admin())->post('/admin/settings/branding', ['favicon' => UploadedFile::fake()->image('icon.png', 64, 64)]);
        $this->assertStringStartsWith('/branding/favicon?v=', app(Branding::class)->url('favicon'));
        auth()->logout();
        $this->get('/login')->assertSee(app(Branding::class)->url('favicon'), false);
    }

    public function test_images_can_be_removed(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/settings/branding', ['logo' => UploadedFile::fake()->image('logo.png')]);
        $path = app(Settings::class)->get('organization.logo_path');

        $this->actingAs($admin)->post('/admin/settings/branding', ['remove' => ['logo']])->assertSessionHasNoErrors();

        $this->assertNull(app(Settings::class)->get('organization.logo_path'));
        Storage::disk(Branding::DISK)->assertMissing($path);
    }

    public function test_svg_is_not_allowed(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/settings/branding', ['logo' => UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml')])
            ->assertSessionHasErrors('logo');
    }

    public function test_changing_the_images_needs_the_settings_permission(): void
    {
        $this->actingAs($this->userWithRoles('FSR-Mitglied'))
            ->post('/admin/settings/branding', ['logo' => UploadedFile::fake()->image('logo.png')])
            ->assertForbidden();
    }

    public function test_labels_use_the_print_logo_and_fall_back_to_the_logo(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/settings/branding', ['logo' => UploadedFile::fake()->image('logo.png')]);
        $this->actingAs($admin)->get('/labels/print')->assertInertia(fn ($page) => $page->where('logoUrl', app(Branding::class)->url('logo')));

        $this->actingAs($admin)->post('/admin/settings/branding', ['print_logo' => UploadedFile::fake()->image('dark.png')])->assertSessionHasNoErrors();

        $url = app(Branding::class)->url('print_logo');
        $this->assertStringContainsString('/branding/print_logo', $url);
        $this->actingAs($admin)->get('/labels/print')->assertInertia(fn ($page) => $page->where('logoUrl', $url));
        $this->get($url)->assertOk();
    }

    public function test_a_palette_png_keeps_its_transparency_when_scaled(): void
    {
        // a palette png (PNG-8) with a transparent background and a red square
        $image = imagecreate(800, 800);
        $transparent = imagecolorallocate($image, 255, 255, 255);
        imagecolortransparent($image, $transparent);
        imagefilledrectangle($image, 300, 300, 500, 500, imagecolorallocate($image, 255, 0, 0));
        ob_start();
        imagepng($image);
        $png = Branding::scalePng((string) ob_get_clean(), 400);

        $scaled = imagecreatefromstring($png);
        $this->assertSame(400, imagesx($scaled));
        $corner = imagecolorsforindex($scaled, imagecolorat($scaled, 5, 5));
        $this->assertSame(127, $corner['alpha']);
        $middle = imagecolorsforindex($scaled, imagecolorat($scaled, 200, 200));
        $this->assertSame([255, 0, 0, 0], [$middle['red'], $middle['green'], $middle['blue'], $middle['alpha']]);
    }

    public function test_the_scaled_logo_is_cached_by_its_path(): void
    {
        $this->actingAs($this->admin())->post('/admin/settings/branding', ['print_logo' => UploadedFile::fake()->image('druck.png', 1200, 600)]);

        $png = app(Branding::class)->pngFor('print_logo');
        $this->assertSame(400, imagesx(imagecreatefromstring($png)));

        // the cache answers without S3
        Storage::disk(Branding::DISK)->delete(app(Settings::class)->get('organization.print_logo_path'));
        $this->assertSame($png, app(Branding::class)->pngFor('print_logo'));
        $this->assertNull(app(Branding::class)->pngFor('logo_light'));
    }
}
