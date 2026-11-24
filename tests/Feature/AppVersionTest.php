<?php

namespace Tests\Feature;

use App\Support\AppVersion;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Version below the menu and the button to a newer release on GitHub.
 */
class AppVersionTest extends TestCase
{
    /**
     * Let GitHub answer with the given newest release.
     */
    private function fakeLatestRelease(string $tag): void
    {
        Http::fake(['api.github.com/*' => Http::response([
            'tag_name' => $tag,
            'html_url' => "https://github.com/fsr5-fhaachen/stocks/releases/tag/{$tag}",
        ])]);
    }

    public function test_the_command_keeps_the_newest_release(): void
    {
        $this->fakeLatestRelease('v0.4.0');

        $this->artisan('stocks:update-check')->assertSuccessful();

        $this->assertSame('v0.4.0', app(AppVersion::class)->latestRelease()['tag']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.github.com/repos/fsr5-fhaachen/stocks/releases/latest');
    }

    public function test_a_failed_check_keeps_the_last_known_release(): void
    {
        Http::fake(['api.github.com/*' => Http::sequence()
            ->push(['tag_name' => 'v0.4.0', 'html_url' => 'https://github.com/fsr5-fhaachen/stocks/releases/tag/v0.4.0'])
            ->push('rate limit', 403)]);
        $version = app(AppVersion::class);

        $this->assertTrue($version->check());
        $this->assertFalse($version->check());

        $this->assertSame('v0.4.0', $version->latestRelease()['tag']);
    }

    public function test_without_any_release_nothing_is_kept(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        $this->assertTrue(app(AppVersion::class)->check());

        $this->assertNull(app(AppVersion::class)->latestRelease());
    }

    public function test_only_a_newer_release_is_an_update(): void
    {
        $version = app(AppVersion::class);
        $cases = [
            // installed, newest, update?
            ['v0.3.0', 'v0.4.0', true],
            ['v0.3.0', 'v0.3.0', false],
            ['v0.3.0', 'v0.2.9', false],
            ['v0.9.0', 'v0.10.0', true],
            ['0.3.0', 'v0.3.1', true],
            ['dev', 'v0.4.0', false],
            ['dev-abc1234', 'v0.4.0', false],
            ['v0.3.0', 'v0.4.0-rc.1', false],
        ];

        // one fake for all cases, it answers with the newest release of the current case
        $newest = null;
        Http::fake(function () use (&$newest) {
            return Http::response(['tag_name' => $newest, 'html_url' => "https://github.com/fsr5-fhaachen/stocks/releases/tag/{$newest}"]);
        });

        foreach ($cases as [$installed, $newest, $isUpdate]) {
            config(['stocks.version' => $installed]);
            $version->check();

            $this->assertSame($isUpdate, $version->update() !== null, "{$installed} → {$newest}");
        }
    }

    public function test_everyone_sees_the_version_but_only_admins_the_update(): void
    {
        config(['stocks.version' => 'v0.3.0']);
        $this->fakeLatestRelease('v0.4.0');
        app(AppVersion::class)->check();

        $this->actingAs($this->admin())->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('app.version', 'v0.3.0')
            ->where('app.commit', null)
            ->where('app.update.tag', 'v0.4.0')
            ->where('app.update.url', 'https://github.com/fsr5-fhaachen/stocks/releases/tag/v0.4.0'));

        $this->actingAs($this->userWithPermissions('inventory.view'))->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('app.version', 'v0.3.0')
            ->where('app.update', null));
    }

    public function test_the_dev_instance_shows_its_commit_and_no_update(): void
    {
        config(['stocks.version' => 'dev-0123456789abcdef0123456789abcdef01234567']);
        $this->fakeLatestRelease('v9.0.0');
        app(AppVersion::class)->check();

        $this->actingAs($this->admin())->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('app.commit', '0123456789abcdef0123456789abcdef01234567')
            ->where('app.commit_url', 'https://github.com/fsr5-fhaachen/stocks/commit/0123456789abcdef0123456789abcdef01234567')
            ->where('app.update', null));
    }

    public function test_it_runs_every_30_minutes_unless_switched_off(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'stocks:update-check'));
        $this->assertNotNull($event);
        $this->assertContains($event->expression, ['*/30 * * * *', '0,30 * * * *']);

        config(['stocks.update_check' => true]);
        $this->assertTrue($event->filtersPass($this->app));
        config(['stocks.update_check' => false]);
        $this->assertFalse($event->filtersPass($this->app));
    }
}
