<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Version of this instance and the newest release on GitHub, for the version in the menu.
 *
 * The version comes from the image (STOCKS_VERSION, set by the workflows on build): the tag of a
 * release ("v0.3.0") or "dev-<commit>" on the dev instance; "dev" without docker. The scheduler
 * asks GitHub every 30 minutes (stocks:update-check) and keeps the answer, so the menu never
 * waits for the network. Drafts and pre-releases never count.
 */
class AppVersion
{
    /**
     * Cache key of the newest release.
     */
    private const CACHE_KEY = 'stocks.latest_release';

    /**
     * The installed version, e.g. "v0.3.0", "dev-<commit>" or "dev".
     */
    public function current(): string
    {
        return (string) (config('stocks.version') ?: 'dev');
    }

    /**
     * Check if the installed version is a release (and not a dev build).
     */
    public function isRelease(): bool
    {
        return self::normalize($this->current()) !== null;
    }

    /**
     * Commit of a dev build ("dev-<commit>"), null for releases and builds without commit.
     */
    public function commit(): ?string
    {
        return preg_match('/^dev-([0-9a-f]{7,40})$/i', $this->current(), $matches) ? $matches[1] : null;
    }

    /**
     * Ask GitHub for the newest release and keep it. False if GitHub could not be asked: the last
     * known release stays then.
     */
    public function check(): bool
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders(['User-Agent' => 'Stocks'])
                ->timeout(5)
                ->get('https://api.github.com/repos/'.config('stocks.repository').'/releases/latest');
        } catch (\Throwable) {
            return false;
        }

        // no release yet
        if ($response->status() === 404) {
            cache()->forget(self::CACHE_KEY);

            return true;
        }
        if (! $response->successful() || ! is_string($response->json('tag_name'))) {
            return false;
        }

        cache()->forever(self::CACHE_KEY, [
            'tag' => $response->json('tag_name'),
            'url' => $response->json('html_url'),
        ]);

        return true;
    }

    /**
     * The newest release from the last check, null if there is none (yet).
     *
     * @return array{tag: string, url: string}|null
     */
    public function latestRelease(): ?array
    {
        return cache()->get(self::CACHE_KEY);
    }

    /**
     * The newest release if it is newer than the installed one; never for dev builds.
     *
     * @return array{tag: string, url: string}|null
     */
    public function update(): ?array
    {
        $latest = $this->latestRelease();
        $installed = self::normalize($this->current());
        $newest = $latest ? self::normalize($latest['tag']) : null;

        return $installed !== null && $newest !== null && version_compare($newest, $installed, '>') ? $latest : null;
    }

    /**
     * Data for the menu: the version for everyone, the update only for those who manage the instance.
     *
     * @return array{version: string, commit: string|null, commit_url: string|null, update: array{tag: string, url: string}|null}
     */
    public function shared(User $user): array
    {
        $commit = $this->commit();

        return [
            'version' => $this->current(),
            'commit' => $commit,
            'commit_url' => $commit ? 'https://github.com/'.config('stocks.repository').'/commit/'.$commit : null,
            'update' => $user->can('settings.manage') ? $this->update() : null,
        ];
    }

    /**
     * "v1.2.3" or "1.2.3" as "1.2.3"; null for anything else (dev builds, pre-releases like "1.2.3-rc.1").
     */
    private static function normalize(string $version): ?string
    {
        return preg_match('/^v?(\d+\.\d+\.\d+)$/i', trim($version), $matches) ? $matches[1] : null;
    }
}
