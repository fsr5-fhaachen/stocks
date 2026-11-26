<?php

namespace App\Support;

/**
 * Decode images with GD without running out of memory. GD needs about 4 bytes per pixel while an
 * image is open: a png of 4000 × 4000 pixels takes 64 MB, although the file has only a few hundred
 * KB. Running out of memory is a fatal error that no try/catch stops, so the size is read from the
 * header first. Images up to MAX_PIXELS get a higher memory limit just while they are open; the
 * old limit is set again afterwards (an Octane worker would keep it otherwise). Bigger images and
 * files GD can not read give null, the caller shows its fallback.
 */
class ImageDecoding
{
    /**
     * Most pixels of an image that is opened at all (about 200 MB while it is open).
     *
     * @var int
     */
    public const MAX_PIXELS = 40_000_000;

    /**
     * Memory for the rest of the request beside the image.
     *
     * @var int
     */
    private const SPARE_BYTES = 64 * 1024 * 1024;

    /**
     * Open an image and pass it to $work, which returns the result (e.g. encoded bytes). The image
     * is closed before the memory limit is set back. Null for a file that is not an image GD can
     * read or that is bigger than $maxPixels.
     *
     * @template T
     *
     * @param  callable(\GdImage): T  $work
     * @return T|null
     */
    public static function process(string $content, callable $work, int $maxPixels = self::MAX_PIXELS): mixed
    {
        $size = $content !== '' ? @getimagesizefromstring($content) : false;
        if ($size === false || $size[0] < 1 || $size[1] < 1 || $maxPixels < $size[0] * $size[1]) {
            return null;
        }

        // the image lives only inside this function, so it is freed when the function returns
        $run = function () use ($content, $work): mixed {
            $image = @imagecreatefromstring($content);

            return $image === false ? null : $work($image);
        };

        $old = (string) ini_get('memory_limit');
        $needed = memory_get_usage() + $size[0] * $size[1] * 5 + self::SPARE_BYTES;
        $limit = self::bytes($old);
        if ($limit < 0 || $limit >= $needed) {
            return $run();
        }

        ini_set('memory_limit', (string) $needed);
        try {
            return $run();
        } finally {
            ini_set('memory_limit', $old);
        }
    }

    /**
     * A memory limit like "128M" in bytes, -1 for no limit.
     */
    private static function bytes(string $limit): int
    {
        $value = (int) $limit;

        return match (strtoupper(substr(trim($limit), -1))) {
            'G' => $value * 1024 ** 3,
            'M' => $value * 1024 ** 2,
            'K' => $value * 1024,
            default => $value,
        };
    }
}
