<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Logos and favicon of the instance: the logo for dark backgrounds ("logo", dark mode), the logo for
 * light backgrounds ("logo_light", light mode and the icon of the web app while there is no own
 * icon), the print logo, the favicon and the own icon of the web app ("app_icon", fills the icon). The files lie on S3 (like the photos of items); the settings
 * keep their paths. App\Http\Controllers\BrandingController delivers them, also to guests (login
 * page, browser tab).
 */
class Branding
{
    /**
     * Disk of the files.
     *
     * @var string
     */
    public const DISK = 's3';

    /**
     * Images that can be changed, with their setting.
     *
     * @var array<string, string>
     */
    public const KINDS = [
        // logo for dark backgrounds (dark mode), e.g. a white one
        'logo' => 'organization.logo_path',
        // logo for light backgrounds: light mode and the icon on the home screen
        'logo_light' => 'organization.logo_light_path',
        'favicon' => 'organization.favicon_path',
        // logo for paper and labels, e.g. a dark one when the screen logo is white
        'print_logo' => 'organization.print_logo_path',
        // own icon of the web app on the home screen, fills it without margin
        'app_icon' => 'organization.app_icon_path',
    ];

    public function __construct(private readonly Settings $settings) {}

    /**
     * Path of an image on S3, null while the default is used.
     */
    public function path(string $kind): ?string
    {
        return $this->settings->get(self::KINDS[$kind]);
    }

    /**
     * Url of an image, null for a logo that was never uploaded. The file name is part of the url,
     * so browsers load a new image right away although they keep images for a week.
     */
    public function url(string $kind): ?string
    {
        $path = $this->path($kind);
        if ($path === null) {
            return $kind === 'favicon' ? '/favicon.ico' : null;
        }

        return route('branding.show', ['kind' => $kind, 'v' => pathinfo($path, PATHINFO_FILENAME)], absolute: false);
    }

    /**
     * Kind of the logo for print (labels, pdf): the print logo, or the logo while none was uploaded.
     */
    public function printKind(): string
    {
        return $this->path('print_logo') !== null ? 'print_logo' : 'logo';
    }

    /**
     * Kind of the image the icon of the web app is made of: the own icon (fills it), else the logo
     * for light backgrounds (on white), null for the "S" on the accent colour.
     */
    public function iconKind(): ?string
    {
        foreach (['app_icon', 'logo_light'] as $kind) {
            if ($this->path($kind) !== null) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Version of the icons of the web app, part of their url: a new icon or light logo or another
     * accent colour gives new urls, otherwise devices would keep the old icon for a week.
     */
    public function iconVersion(): string
    {
        return substr(md5($this->path('app_icon').'|'.$this->path('logo_light').'|'.$this->settings->get('organization.accent_color')), 0, 12);
    }

    /**
     * Background of the web app while it starts (manifest): white behind an own icon or the light
     * logo, else the accent colour like the icon.
     */
    public function iconBackground(): string
    {
        return $this->iconKind() !== null ? '#ffffff' : (string) $this->settings->get('organization.accent_color');
    }

    /**
     * Kind of the logo for mails, which are always light: the print logo, else the logo for light
     * backgrounds, null for none (the logo for dark backgrounds would be white on white).
     */
    public function mailKind(): ?string
    {
        foreach (['print_logo', 'logo_light'] as $kind) {
            if ($this->path($kind) !== null) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * An uploaded image as png, at most $maxSize px wide and high (pdf, mails), null without image,
     * while S3 is not reachable or when it can not be read. Cached by path, a new upload has a new
     * path; S3 and the decoding are passed only once.
     */
    public function pngFor(string $kind, int $maxSize = 400): ?string
    {
        $path = $this->path($kind);
        if ($path === null) {
            return null;
        }

        $key = 'branding.png.'.md5($path).'.'.$maxSize;
        if (($png = Cache::get($key)) !== null) {
            return $png;
        }

        $content = $this->contents($kind);
        $png = $content !== null ? self::scalePng($content, $maxSize) : null;
        if ($png !== null) {
            Cache::forever($key, $png);
        }

        return $png;
    }

    /**
     * Longest side of uploaded logos and of the own icon, they are scaled down when stored: enough
     * for screen, labels, pdf and the icon (512 px), and small enough to open quickly.
     *
     * @var int
     */
    public const MAX_STORED_SIZE = 1024;

    /**
     * Longest side of an uploaded png favicon: Firefox shows no favicon at all when it is very
     * big, browsers need at most 256 px.
     *
     * @var int
     */
    public const MAX_FAVICON_SIZE = 256;

    /**
     * Scale an image down to a png of at most $maxSize px, keeping transparency (also of palette
     * pngs, which imagescale() would give a black background). Null if it is not an image or too
     * big to open (App\Support\ImageDecoding).
     */
    public static function scalePng(string $content, int $maxSize): ?string
    {
        return ImageDecoding::process($content, fn (\GdImage $image) => self::scaleImage($image, $maxSize));
    }

    /**
     * Scale an open image down to a png of at most $maxSize px, see scalePng().
     */
    private static function scaleImage(\GdImage $image, int $maxSize): string
    {
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $scale = min(1, $maxSize / max(imagesx($image), imagesy($image)));
        if ($scale < 1) {
            $scaled = imagecreatetruecolor(max(1, (int) round(imagesx($image) * $scale)), max(1, (int) round(imagesy($image) * $scale)));
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            imagefill($scaled, 0, 0, imagecolorallocatealpha($scaled, 0, 0, 0, 127));
            imagecopyresampled($scaled, $image, 0, 0, 0, 0, imagesx($scaled), imagesy($scaled), imagesx($image), imagesy($image));
            $image = $scaled;
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * Type of an image from its bytes, e.g. "image/png" or "image/vnd.microsoft.icon".
     */
    public static function mimeType(string $content): string
    {
        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);

        return is_string($type) && str_starts_with($type, 'image/') ? $type : 'application/octet-stream';
    }

    /**
     * Content of an uploaded image, null without image or while S3 is not reachable.
     */
    public function contents(string $kind): ?string
    {
        $path = $this->path($kind);
        if ($path === null) {
            return null;
        }

        try {
            return Storage::disk(self::DISK)->get($path);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Replace an image with an uploaded file.
     *
     * @throws ValidationException
     */
    public function replace(string $kind, UploadedFile $file): void
    {
        $path = $this->storeScaled($kind, $file);
        if ($path === false) {
            throw ValidationException::withMessages([$kind => 'Das Bild konnte nicht gespeichert werden, der Speicher ist gerade nicht erreichbar.']);
        }

        $this->remove($kind);
        $this->settings->set([self::KINDS[$kind] => $path]);
    }

    /**
     * Store an image as png of at most MAX_STORED_SIZE px (favicon MAX_FAVICON_SIZE), so it is
     * never opened at full size later (a big image used up the memory when the icon was made).
     * An ico favicon is kept as it is, GD can not read it. False when S3 can not store it.
     *
     * @throws ValidationException
     */
    private function storeScaled(string $kind, UploadedFile $file): string|false
    {
        $content = (string) $file->get();
        if ($kind === 'favicon' && self::mimeType($content) !== 'image/png') {
            return $file->store('branding', self::DISK);
        }

        $png = self::scalePng($content, $kind === 'favicon' ? self::MAX_FAVICON_SIZE : self::MAX_STORED_SIZE);
        if ($png === null) {
            // GD may lack a format (webp in the container): a small image is kept as it is, it can
            // be shown in the browser but is left out where GD is needed (icon, pdf, mails)
            $size = @getimagesizefromstring($content);
            if ($size !== false && max($size[0], $size[1]) <= self::MAX_STORED_SIZE) {
                return $file->store('branding', self::DISK);
            }

            throw ValidationException::withMessages([$kind => 'Das Bild konnte nicht gelesen werden oder ist zu groß. Bitte als PNG oder JPG mit höchstens '.self::MAX_STORED_SIZE.' Pixeln Kantenlänge hochladen.']);
        }

        $path = 'branding/'.Str::random(40).'.png';

        return Storage::disk(self::DISK)->put($path, $png, ['ContentType' => 'image/png']) ? $path : false;
    }

    /**
     * Go back to the default image. A file S3 can not delete is left behind.
     */
    public function remove(string $kind): void
    {
        if ($old = $this->path($kind)) {
            Storage::disk(self::DISK)->delete($old);
            $this->settings->set([self::KINDS[$kind] => null]);
        }
    }
}
