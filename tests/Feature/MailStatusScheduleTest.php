<?php

namespace Tests\Feature;

use App\Support\MailSettings;
use App\Support\Settings;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * The scheduler tests the mail server, so the menu badge is filled without opening the page.
 */
class MailStatusScheduleTest extends TestCase
{
    private function badge(): ?string
    {
        $navigation = $this->actingAs($this->admin())->get('/dashboard')->viewData('page')['props']['navigation'];
        $entries = collect($navigation)->flatMap(fn ($section) => $section['entries'])->flatMap(fn ($entry) => $entry['children'] ?? [$entry]);

        return $entries->firstWhere('title', 'Benachrichtigungen')['badge']['text'] ?? null;
    }

    public function test_the_command_fills_the_status_for_the_menu(): void
    {
        app(Settings::class)->set(['mail.host' => '127.0.0.1', 'mail.port' => 1]);
        $this->assertNull(app(MailSettings::class)->lastStatus());
        $this->assertNull($this->badge());

        $this->artisan('stocks:mail-status')->assertSuccessful();

        $this->assertFalse(app(MailSettings::class)->lastStatus()['ok']);
        $this->assertSame('getrennt', $this->badge());
    }

    public function test_it_runs_every_five_minutes_only_with_a_mail_server(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'stocks:mail-status'));
        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertFalse($event->filtersPass($this->app));

        app(Settings::class)->set(['mail.host' => 'smtp.example.org']);
        $this->assertTrue($event->filtersPass($this->app));
    }

    public function test_without_a_mail_server_nothing_is_tested(): void
    {
        $this->artisan('stocks:mail-status')->expectsOutput('Kein Mailserver eingestellt.')->assertSuccessful();

        $this->assertNull(app(MailSettings::class)->lastStatus());
    }
}
