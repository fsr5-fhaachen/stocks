<?php

namespace App\Http\Controllers;

use App\Support\Branding;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Web app: manifest and icons, so Stocks can be added to the home screen of a phone and runs in full
 * screen there. The icons are made from the logo for light backgrounds, without it they show a white
 * "S" on the accent colour. There is no offline mode.
 */
class WebAppController extends Controller
{
    /**
     * Sizes of the icons in pixels (180 is the size of iPhones).
     *
     * @var list<int>
     */
    public const ICON_SIZES = [180, 192, 512];

    /**
     * Deliver the manifest.
     */
    public function manifest(Settings $settings, Branding $branding): JsonResponse
    {
        $version = $branding->iconVersion();

        return response()->json([
            'name' => $settings->get('organization.name'),
            'short_name' => $settings->get('organization.short_name'),
            'start_url' => '/dashboard',
            'scope' => '/',
            'display' => 'fullscreen',
            'display_override' => ['fullscreen', 'standalone'],
            'orientation' => 'portrait',
            'background_color' => $branding->iconBackground(),
            'theme_color' => $settings->get('organization.accent_color'),
            'icons' => array_map(fn (int $size) => [
                'src' => route('web-app.icon', ['size' => $size, 'v' => $version], absolute: false),
                'sizes' => "{$size}x{$size}",
                'type' => 'image/png',
                'purpose' => 'any',
            ], self::ICON_SIZES),
        ], headers: ['Content-Type' => 'application/manifest+json']);
    }

    /**
     * Font of the "S" (only this letter, Inter Bold, licence next to it).
     *
     * @var string
     */
    private const FONT = 'fonts/Inter-Bold-S.ttf';

    /**
     * Deliver an icon: the logo for light backgrounds in the middle of a white square, or a white
     * "S" on the accent colour without it. Made once per logo, colour and size.
     */
    public function icon(Settings $settings, Branding $branding, int $size): Response
    {
        abort_unless(in_array($size, self::ICON_SIZES, true), 404);

        $accent = (string) $settings->get('organization.accent_color');
        $key = 'stocks.web-app-icon.'.md5($branding->path('logo_light').$accent.$size);

        $png = Cache::get($key);
        if ($png === null) {
            $png = $this->draw($size, $branding->contents('logo_light'), $accent);
            // without S3 the default icon is shown, but not kept
            if ($branding->path('logo_light') === null || $png !== null) {
                Cache::put($key, $png, now()->addWeek());
            }
        }

        return response($png ?? $this->draw($size, null, $accent), headers: [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }

    /**
     * Draw an icon as png.
     */
    private function draw(int $size, ?string $logo, string $accent): ?string
    {
        $image = imagecreatetruecolor($size, $size);
        $source = $logo !== null ? @imagecreatefromstring($logo) : false;

        if ($source !== false) {
            imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
            // the logo fills 80 %, so round masks of launchers do not cut it
            $scale = $size * 0.8 / max(imagesx($source), imagesy($source));
            $width = (int) round(imagesx($source) * $scale);
            $height = (int) round(imagesy($source) * $scale);
            imagecopyresampled($image, $source, intdiv($size - $width, 2), intdiv($size - $height, 2), 0, 0, $width, $height, imagesx($source), imagesy($source));
        } else {
            [$red, $green, $blue] = sscanf($accent, '#%02x%02x%02x') ?: [34, 148, 140];
            imagefill($image, 0, 0, imagecolorallocate($image, $red, $green, $blue));
            $this->drawLetter($image, $size, imagecolorallocate($image, 255, 255, 255));
        }

        ob_start();
        imagepng($image);

        return ob_get_clean() ?: null;
    }

    /**
     * A white "S" in the middle of the icon, about 60 % of its height.
     */
    private function drawLetter(\GdImage $image, int $size, int $color): void
    {
        $font = resource_path(self::FONT);
        // measured once, then scaled, so the letter is 60 % high whatever the font metrics
        $points = $size * 0.6;
        $box = imagettfbbox($points, 0, $font, 'S');
        $points *= $size * 0.6 / max(1, $box[1] - $box[7]);
        // centre the drawn letter by its bounding box, not by the baseline
        $box = imagettfbbox($points, 0, $font, 'S');
        $width = $box[2] - $box[0];
        $height = $box[1] - $box[7];
        imagettftext($image, $points, 0, (int) round(($size - $width) / 2 - $box[0]), (int) round(($size + $height) / 2 - $box[1]), $color, $font, 'S');
    }
}
