<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Logos and favicon of the instance: the logo for dark backgrounds ("logo", dark mode), the logo for
 * light backgrounds ("logo_light", light mode and the icon of the web app), the print logo and the
 * favicon. The files lie on S3 (like the photos of items); the settings
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
     * Version of the icons of the web app, part of their url: a new light logo or another accent
     * colour gives new urls, otherwise devices would keep the old icon for a week.
     */
    public function iconVersion(): string
    {
        return substr(md5($this->path('logo_light').'|'.$this->settings->get('organization.accent_color')), 0, 12);
    }

    /**
     * Background of the web app while it starts (manifest): white behind the light logo, else the
     * accent colour like the icon.
     */
    public function iconBackground(): string
    {
        return $this->path('logo_light') !== null ? '#ffffff' : (string) $this->settings->get('organization.accent_color');
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
     * Scale an image down to a png of at most $maxSize px, keeping transparency (also of palette
     * pngs, which imagescale() would give a black background). Null if it is not an image.
     */
    public static function scalePng(string $content, int $maxSize): ?string
    {
        $image = @imagecreatefromstring($content);
        if ($image === false) {
            return null;
        }

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
        $path = $file->store('branding', self::DISK);
        if ($path === false) {
            throw ValidationException::withMessages([$kind => 'Das Bild konnte nicht gespeichert werden, der Speicher ist gerade nicht erreichbar.']);
        }

        $this->remove($kind);
        $this->settings->set([self::KINDS[$kind] => $path]);
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
