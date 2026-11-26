<?php

namespace App\Support\Inventory;

use App\Models\ItemPhoto;
use App\Support\ImageDecoding;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Small previews of item photos for lists (item list, contents of a box, scan card, pdf). The
 * photos themselves are already scaled down in the browser before the upload (1600 px, see
 * resources/js/composables/useImageCompression.ts), a preview is a jpeg of at most MAX_SIZE px
 * beside the photo on S3: "items/12/abc.jpg" has its preview at "items/12/thumbs/abc.jpg".
 */
class PhotoThumbnail
{
    /**
     * Longest side of a preview in pixels: sharp for the 96 px of the scan card on screens with
     * double density.
     *
     * @var int
     */
    public const MAX_SIZE = 256;

    /**
     * Quality of the jpeg.
     *
     * @var int
     */
    private const QUALITY = 80;

    /**
     * Path of the preview of a photo.
     */
    public static function pathFor(string $photoPath): string
    {
        return trim(dirname($photoPath), '/.').'/thumbs/'.pathinfo($photoPath, PATHINFO_FILENAME).'.jpg';
    }

    /**
     * Make the preview of a photo and store it beside the photo. Returns its path, null when S3
     * is not reachable or the photo can not be read (e.g. webp without webp in GD); the original
     * is shown then.
     */
    public static function create(string $photoPath): ?string
    {
        try {
            $jpeg = self::make(Storage::disk(ItemPhoto::DISK)->get($photoPath) ?? '');
            if ($jpeg === null) {
                return null;
            }

            $path = self::pathFor($photoPath);

            return Storage::disk(ItemPhoto::DISK)->put($path, $jpeg, ['ContentType' => 'image/jpeg']) ? $path : null;
        } catch (\Throwable $exception) {
            Log::warning("Vorschaubild für {$photoPath} ging nicht: {$exception->getMessage()}");

            return null;
        }
    }

    /**
     * Scale an image down to a jpeg of at most MAX_SIZE px, never up. Transparent parts get a
     * white background (jpeg has no transparency). Null if it is not an image GD can read or it is
     * too big to open (App\Support\ImageDecoding).
     */
    public static function make(string $content): ?string
    {
        return ImageDecoding::process($content, fn (\GdImage $source) => self::scale($source));
    }

    /**
     * Scale an open image down to the jpeg of the preview.
     */
    private static function scale(\GdImage $source): ?string
    {
        $scale = min(1, self::MAX_SIZE / max(imagesx($source), imagesy($source)));
        $width = max(1, (int) round(imagesx($source) * $scale));
        $height = max(1, (int) round(imagesy($source) * $scale));

        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        ob_start();
        imagejpeg($image, null, self::QUALITY);

        return ob_get_clean() ?: null;
    }
}
