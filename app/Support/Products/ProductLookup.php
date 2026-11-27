<?php

namespace App\Support\Products;

use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Product data by barcode for the guided capture: asks the sources one after the other and
 * merges what they know into suggestions (name, description, images). Every answer is kept per
 * code, so scanning the same packaging again costs no request: a hit for HIT_DAYS, "nothing
 * found" for one day (the databases may know the product tomorrow). Answers with an unreachable
 * source are not kept.
 */
class ProductLookup
{
    public const HIT_DAYS = 30;

    /**
     * Most images offered per product.
     */
    public const MAX_IMAGES = 12;

    /**
     * @param  list<ProductSource>  $sources  in the order they are asked
     */
    public function __construct(private readonly Settings $settings, private readonly array $sources) {}

    /**
     * Whether the instance asks product databases at all (setting "Produktdaten per Barcode").
     */
    public function enabled(): bool
    {
        return (bool) $this->settings->get('inventory.product_lookup');
    }

    /**
     * Suggestions for a barcode in its stored form (Gtin::normalize()).
     *
     * @return array{code: string, found: bool, name: string|null, description: string|null, images: list<array{index: int, source: string, url: string, license: string|null, author: string|null, page: string|null, host: string}>, sources: list<array{name: string, url: string|null, license: string|null}>, unavailable: list<string>}
     */
    public function lookup(string $gtin): array
    {
        $key = self::cacheKey($gtin);
        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $hits = [];
        $unavailable = [];
        foreach ($this->sources as $source) {
            if (! $source->available()) {
                $unavailable[] = $source->name();

                continue;
            }
            try {
                if (($hit = $source->find($gtin)) !== null) {
                    $hits[$source->name()] = $hit;
                }
            } catch (SourceUnavailable $exception) {
                Log::info("Produktdaten: {$source->name()} nicht erreichbar ({$exception->getMessage()}).");
                $unavailable[] = $source->name();
            }
        }

        $result = $this->merge($gtin, $hits, $unavailable);
        if ($result['found']) {
            Cache::put($key, $result, now()->addDays(self::HIT_DAYS));
        } elseif ($unavailable === []) {
            Cache::put($key, $result, now()->addDay());
        }

        return $result;
    }

    /**
     * Origin of an image of a cached answer, for storing with the photo; null if unknown.
     *
     * @return array{source: string, source_url: string|null, license: string|null, author: string|null, source_host: string}|null
     */
    public function origin(string $gtin, int $index): ?array
    {
        $image = $this->cachedImage($gtin, $index);

        return $image ? [
            'source' => $image['source'],
            'source_url' => $image['page'] ?? $image['url'],
            'license' => $image['license'],
            'author' => $image['author'],
            'source_host' => $image['host'],
        ] : null;
    }

    /**
     * An image of a cached answer; only these are downloaded (no free addresses).
     *
     * @return array{index: int, source: string, url: string, license: string|null, author: string|null, page: string|null, host: string}|null
     */
    public function cachedImage(string $gtin, int $index): ?array
    {
        $cached = Cache::get(self::cacheKey($gtin));

        return is_array($cached) ? ($cached['images'][$index] ?? null) : null;
    }

    private static function cacheKey(string $gtin): string
    {
        return "product-lookup:{$gtin}";
    }

    /**
     * One suggestion from the answers of the sources: name of the first source that has one,
     * description from brand and model, images of all sources without doubles.
     *
     * @param  array<string, array{name: string|null, brand: string|null, model: string|null, url: string|null, license: string|null, images: list<array{url: string, license: string|null, author: string|null}>}>  $hits
     * @param  list<string>  $unavailable
     * @return array<string, mixed>
     */
    private function merge(string $gtin, array $hits, array $unavailable): array
    {
        $first = fn (string $field) => collect($hits)->pluck($field)->first(fn ($value) => $value !== null && $value !== '');
        $brand = $first('brand');
        $name = $first('name');
        $model = $first('model');
        // the brand is often part of the name already
        $description = collect([$brand && ! str_contains(mb_strtolower((string) $name), mb_strtolower($brand)) ? $brand : null, $model])
            ->filter()->implode(' · ');

        $images = [];
        foreach ($hits as $source => $hit) {
            foreach ($hit['images'] as $image) {
                if (count($images) >= self::MAX_IMAGES || in_array($image['url'], array_column($images, 'url'), true)) {
                    continue;
                }
                $images[] = [
                    'index' => count($images),
                    'source' => $source,
                    'url' => $image['url'],
                    'license' => $image['license'],
                    'author' => $image['author'],
                    'page' => $hit['url'],
                    'host' => (string) parse_url($image['url'], PHP_URL_HOST),
                ];
            }
        }

        return [
            'code' => $gtin,
            'found' => $hits !== [],
            'name' => $name,
            'description' => $description ?: null,
            'images' => $images,
            'sources' => collect($hits)->map(fn (array $hit, string $source) => ['name' => $source, 'url' => $hit['url'], 'license' => $hit['license']])->values()->all(),
            'unavailable' => $unavailable,
        ];
    }
}
