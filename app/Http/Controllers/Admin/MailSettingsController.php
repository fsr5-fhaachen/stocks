<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Notifications\InvitationNotification;
use App\Notifications\TestNotification;
use App\Support\MailSettings;
use App\Support\Notifications\MailLayout;
use App\Support\Notifications\WebPush;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Mail server and sender (Verwaltung › Einstellungen › Benachrichtigungen), with connection status,
 * a test mail, the failed jobs of the queue and the state of push. The password is stored encrypted
 * and never sent to the browser.
 */
class MailSettingsController extends Controller
{
    public function __construct(private readonly Settings $settings, private readonly MailSettings $mailSettings) {}

    /**
     * Display the settings with the status of the connection.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Admin/Mail', [
            'mail' => [
                ...collect(['host', 'port', 'username', 'encryption', 'from_name', 'from_address'])->mapWithKeys(fn (string $key) => [$key => $this->settings->get("mail.{$key}")]),
                'has_password' => $this->settings->get('mail.password') !== null,
            ],
            'status' => $this->mailSettings->status(),
            'environment' => ['mailer' => config('mail.default'), 'from' => config('mail.from.address')],
            'testAddress' => $request->user()->email,
            'failedJobs' => DB::table('failed_jobs')->count(),
            'push' => ['enabled' => WebPush::enabled(), 'devices' => PushSubscription::count()],
        ]);
    }

    /**
     * Save the settings. An empty password keeps the stored one; "remove_password" deletes it.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'remove_password' => ['boolean'],
            'encryption' => ['nullable', 'in:ssl,tls'],
            'from_name' => ['nullable', 'string', 'max:100'],
            'from_address' => ['nullable', 'email', 'max:255'],
        ], [], ['host' => 'Server', 'port' => 'Port', 'username' => 'Benutzername', 'password' => 'Passwort', 'from_address' => 'Absenderadresse']);

        $values = collect($validated)->only(['host', 'port', 'username', 'encryption', 'from_name', 'from_address'])->mapWithKeys(fn ($value, string $key) => ["mail.{$key}" => $value])->all();
        if ($request->boolean('remove_password')) {
            $values['mail.password'] = null;
        } elseif (! empty($validated['password'])) {
            $values['mail.password'] = MailSettings::encryptPassword($validated['password']);
        }
        $this->settings->set($values);
        $this->mailSettings->status(fresh: true);

        return back()->with('success', 'Die E-Mail-Einstellungen wurden gespeichert.');
    }

    /**
     * Test the connection again.
     */
    public function check(): RedirectResponse
    {
        $status = $this->mailSettings->status(fresh: true);

        return back()->with($status['ok'] === false ? 'error' : 'success', $status['message']);
    }

    /**
     * Send a test mail.
     */
    public function test(Request $request): RedirectResponse
    {
        $validated = $request->validate(['to' => ['required', 'email']]);
        $this->mailSettings->apply();

        try {
            // in the look of every other mail, so the design can be checked in the mail program
            Notification::route('mail', $validated['to'])->notifyNow(new TestNotification('mail'));
        } catch (\Throwable $exception) {
            return back()->with('error', 'Die Test-Mail ging nicht raus: '.$exception->getMessage());
        }

        if (MailSettings::isSendingBlocked()) {
            return back()->with('success', "Debug-Modus (APP_DEBUG) ist an: Die Test-Mail an {$validated['to']} wurde nur ins Log geschrieben, nicht verschickt.");
        }

        return back()->with('success', "Die Test-Mail ist an {$validated['to']} unterwegs.");
    }

    /**
     * Preview of the mail design in the browser (nothing is sent): the test notification, or with
     * ?mail=invitation the invitation.
     */
    public function preview(Request $request): HttpResponse
    {
        $mail = $request->query('mail') === 'invitation'
            ? (new InvitationNotification('vorschau', $request->user()->name))->toMail($request->user())
            : (new TestNotification('mail'))->toMail($request->user());

        return response(MailLayout::preview($mail));
    }

    /**
     * Put the failed jobs of the queue back into it.
     */
    public function retry(): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => ['all']]);

        return back()->with('success', 'Die fehlgeschlagenen Aufträge sind wieder in der Warteschlange.');
    }
}
