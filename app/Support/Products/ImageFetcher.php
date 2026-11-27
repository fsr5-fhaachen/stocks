<?php

namespace App\Support\Products;

use App\Support\ImageDecoding;
use Closure;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Http;

/**
 * Download an image of a product database (the images of UPCitemdb lie on any shop) and hand it
 * on as jpeg. Only public addresses are fetched: the host is resolved first, addresses in the
 * internal network are refused and the connection goes to the checked address (no second lookup),
 * redirects are followed by hand and checked the same way.
 */
class ImageFetcher
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /**
     * Longest side of the jpeg; the browser scales it down like an own photo afterwards.
     */
    private const MAX_SIZE = 2000;

    private const MAX_REDIRECTS = 3;

    /**
     * @param  (Closure(string): list<string>)|null  $resolver  addresses of a host (tests replace it)
     */
    public function __construct(private readonly UserAgent $userAgent, private readonly ?Closure $resolver = null) {}

    /**
     * The image as jpeg, null if it can not be fetched or is no image.
     */
    public function fetch(string $url): ?string
    {
        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
            $target = $this->checkedTarget($url);
            if ($target === null) {
                return null;
            }

            try {
                $response = Http::timeout(10)->withUserAgent((string) $this->userAgent)
                    ->withOptions([
                        'allow_redirects' => false,
                        // connect to the checked address, not to a second answer of the DNS
                        'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:".(str_contains($target['ip'], ':') ? "[{$target['ip']}]" : $target['ip'])]],
                        'on_headers' => function ($response): void {
                            if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                                throw new \RuntimeException('Bild zu groß.');
                            }
                        },
                    ])->get($url);
            } catch (\Throwable) {
                // not reachable, too big, refused: the image is left out
                return null;
            }

            if ($response->redirect() && ($location = $response->header('Location')) !== '') {
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

                continue;
            }
            if (! $response->successful() || strlen($response->body()) > self::MAX_BYTES) {
                return null;
            }

            return ImageDecoding::process($response->body(), fn (\GdImage $image) => $this->jpeg($image));
        }

        return null;
    }

    /**
     * Host, port and a public address of the url, null for anything else.
     *
     * @return array{host: string, port: int, ip: string}|null
     */
    private function checkedTarget(string $url): ?array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $addresses = $this->resolver ? ($this->resolver)($host) : $this->resolve($host);
        foreach ($addresses as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return null;
            }
        }

        return $addresses ? ['host' => $host, 'port' => $parts['port'] ?? ($scheme === 'https' ? 443 : 80), 'ip' => $addresses[0]] : null;
    }

    /**
     * IPv4 and IPv6 addresses of a host.
     *
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null, $records)));
    }

    /**
     * Scale down to MAX_SIZE and encode as jpeg on white (no transparency in jpeg).
     */
    private function jpeg(\GdImage $source): ?string
    {
        $scale = min(1, self::MAX_SIZE / max(imagesx($source), imagesy($source)));
        $width = max(1, (int) round(imagesx($source) * $scale));
        $height = max(1, (int) round(imagesy($source) * $scale));
        $target = imagecreatetruecolor($width, $height);
        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        ob_start();
        imagejpeg($target, null, 85);

        return ob_get_clean() ?: null;
    }
}
