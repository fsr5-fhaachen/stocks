<?php

namespace App\Support\Products;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * UPCitemdb, free plan (https://www.upcitemdb.com/api): 100 requests a day without account, good
 * for technology and general goods. The licence is for the operations of the instance; there is
 * no credit required. Its images come from shops, Stocks keeps their host with the photo.
 */
class UpcItemDb implements ProductSource
{
    public const URL = 'https://api.upcitemdb.com/prod/trial/lookup';

    /**
     * Cache key that pauses the source until the daily limit is reset.
     */
    private const PAUSED = 'product-lookup:upcitemdb-paused';

    public function __construct(private readonly UserAgent $userAgent) {}

    public function name(): string
    {
        return 'UPCitemdb';
    }

    public function available(): bool
    {
        return ! Cache::has(self::PAUSED);
    }

    public function find(string $gtin): ?array
    {
        try {
            $response = Http::timeout(5)->acceptJson()->withUserAgent((string) $this->userAgent)
                ->get(self::URL, ['upc' => Gtin::upcA($gtin) ?? $gtin]);
        } catch (ConnectionException $exception) {
            throw new SourceUnavailable($exception->getMessage(), previous: $exception);
        }

        $this->rememberLimit($response);
        if ($response->status() === 429) {
            throw new SourceUnavailable('Tageskontingent von UPCitemdb aufgebraucht.');
        }
        if ($response->status() === 404 || $response->json('code') === 'INVALID_UPC') {
            return null;
        }
        if (! $response->successful()) {
            throw new SourceUnavailable("UPCitemdb antwortet mit {$response->status()}.");
        }

        $item = $response->json('items.0');
        if (! is_array($item) || trim((string) ($item['title'] ?? '')) === '') {
            return null;
        }

        return [
            'name' => trim((string) $item['title']),
            'brand' => trim((string) ($item['brand'] ?? '')) ?: null,
            'model' => trim((string) ($item['model'] ?? '')) ?: null,
            'url' => 'https://www.upcitemdb.com/upc/'.(Gtin::upcA($gtin) ?? $gtin),
            'license' => null,
            'images' => collect($item['images'] ?? [])
                ->filter(fn ($url) => is_string($url) && preg_match('#^https?://#i', $url))
                ->unique()->take(10)
                ->map(fn (string $url) => ['url' => $url, 'license' => null, 'author' => null])
                ->values()->all(),
        ];
    }

    /**
     * Pause the source when the daily limit is used up, until the reset the server names.
     */
    private function rememberLimit(Response $response): void
    {
        $remaining = $response->header('X-RateLimit-Remaining');
        if ($response->status() !== 429 && ($remaining === '' || (int) $remaining > 0)) {
            return;
        }

        $reset = (int) $response->header('X-RateLimit-Reset');
        $until = $reset > time() ? Carbon::createFromTimestamp($reset) : now()->addHour();
        Cache::put(self::PAUSED, true, $until);
    }
}
