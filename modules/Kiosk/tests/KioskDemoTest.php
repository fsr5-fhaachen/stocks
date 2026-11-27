<?php

namespace Modules\Kiosk\Tests;

use App\Models\User;
use App\Support\Demo\DemoGenerator;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Kiosk\Enums\KioskPlatform;
use Modules\Kiosk\Models\KioskDevice;
use Tests\TestCase;

/**
 * Demo data of the kiosk module (documentation/DEMO.md).
 */
class KioskDemoTest extends TestCase
{
    /**
     * One test with two generator runs (several seconds each): the first in a transaction for the
     * pairing urls, the second stays for the unlock.
     */
    public function test_the_generator_pairs_two_tablets_with_the_same_urls_for_the_same_seed(): void
    {
        Storage::fake('s3');
        $tokens = fn (): array => KioskDevice::orderBy('id')->get()->map(fn (KioskDevice $device) => $device->token)->all();

        DB::beginTransaction();
        app(DemoGenerator::class)->run();
        $first = $tokens();
        DB::rollBack();
        Cache::flush();

        app(DemoGenerator::class)->run();
        $this->assertSame($first, $tokens(), 'the same seed gives the same pairing urls');

        $this->assertSame(['Lager-Tablet' => KioskPlatform::Android, 'iPad Ausgabe' => KioskPlatform::Ipados], KioskDevice::orderBy('id')->get()->mapWithKeys(fn (KioskDevice $device) => [$device->name => $device->platform])->all());

        $device = KioskDevice::first();
        $member = User::where('email', 'demo2@example.com')->firstOrFail();
        $this->withCookie(KioskDevice::COOKIE_NAME, $device->cookieValue())
            ->post('/kiosk/unlock', ['user_id' => $member->id, 'pin' => UserFactory::PIN])
            ->assertRedirect('/kiosk/home');
    }
}
