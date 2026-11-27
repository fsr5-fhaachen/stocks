<?php

namespace App\Support\Products;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Open Food Facts and Open Products Facts (https://openfoodfacts.github.io/openfoodfacts-server/api/):
 * free, without key, at most 100 product requests a minute, a custom user agent is required. Data
 * under ODbL, images under CC BY-SA: photos taken from here keep source, licence and author.
 */
class OpenFacts implements ProductSource
{
    /**
     * @param  string  $host  e.g. "world.openfoodfacts.org"
     * @param  string  $label  e.g. "Open Food Facts"
     */
    public function __construct(private readonly UserAgent $userAgent, private readonly string $host, private readonly string $label) {}

    public function name(): string
    {
        return $this->label;
    }

    public function available(): bool
    {
        return true;
    }

    public function find(string $gtin): ?array
    {
        try {
            $response = Http::timeout(5)->acceptJson()->withUserAgent((string) $this->userAgent)
                ->get("https://{$this->host}/api/v2/product/{$gtin}.json", [
                    'fields' => 'product_name_de,product_name,generic_name_de,brands,quantity,image_front_url,image_url',
                ]);
        } catch (ConnectionException $exception) {
            throw new SourceUnavailable($exception->getMessage(), previous: $exception);
        }

        if ($response->status() === 404 || ($response->successful() && (int) $response->json('status') === 0)) {
            return null;
        }
        if (! $response->successful()) {
            throw new SourceUnavailable("{$this->label} antwortet mit {$response->status()}.");
        }

        $product = (array) $response->json('product', []);
        $name = trim((string) ($product['product_name_de'] ?? '')) ?: trim((string) ($product['product_name'] ?? '')) ?: trim((string) ($product['generic_name_de'] ?? ''));
        $images = collect([$product['image_front_url'] ?? null, $product['image_url'] ?? null])
            ->filter(fn ($url) => is_string($url) && str_starts_with($url, 'https://'))
            // the display size (400 px) has a full size beside it
            ->map(fn (string $url) => preg_replace('/\.\d+\.jpg$/', '.full.jpg', $url))
            ->unique()->values();
        if ($name === '' && $images->isEmpty()) {
            return null;
        }

        return [
            'name' => $name ?: null,
            'brand' => trim(explode(',', (string) ($product['brands'] ?? ''))[0]) ?: null,
            'model' => trim((string) ($product['quantity'] ?? '')) ?: null,
            'url' => "https://{$this->host}/product/{$gtin}",
            'license' => 'ODbL',
            'images' => $images->map(fn (string $url) => ['url' => $url, 'license' => 'CC BY-SA 3.0', 'author' => "{$this->label} contributors"])->all(),
        ];
    }
}
