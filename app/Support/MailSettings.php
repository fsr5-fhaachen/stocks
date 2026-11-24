<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * Mail server from the settings (Verwaltung › Einstellungen › E-Mail) instead of the environment.
 * While no host is set, the MAIL_* variables apply (e.g. "log" in development).
 *
 * apply() runs before every request and every queued job (middleware ApplyMailSettings, queue
 * listener in AppServiceProvider), so a changed setting counts at once, also with Octane.
 *
 * In debug mode (APP_DEBUG=true) no mail leaves the server: every mail goes to the log, whatever
 * the settings or MAIL_MAILER say (MAIL_SEND_IN_DEBUG=true allows sending again).
 */
class MailSettings
{
    /**
     * Seconds a connection test is kept, so the settings page does not open a connection on every visit.
     */
    private const STATUS_SECONDS = 900;

    public function __construct(private readonly Settings $settings) {}

    /**
     * Check if the mail server is set in the settings.
     */
    public function isConfigured(): bool
    {
        return (string) $this->settings->get('mail.host') !== '';
    }

    /**
     * Check if sending is blocked: in debug mode mails only go to the log.
     */
    public static function isSendingBlocked(): bool
    {
        return (bool) config('app.debug') && ! config('stocks.mail_send_in_debug');
    }

    /**
     * Send every mail to the log while sending is blocked. Runs on boot (console, scheduler) and in
     * apply() (requests, queued jobs).
     */
    public static function blockSendingInDebug(): void
    {
        if (self::isSendingBlocked()) {
            config(['mail.default' => 'log']);
        }
    }

    /**
     * Use the mail server of the settings for the next mails. In debug mode the server is still
     * prepared (the connection test uses it), but mails go to the log.
     */
    public function apply(): void
    {
        if (! $this->isConfigured()) {
            self::blockSendingInDebug();

            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.scheme' => $this->settings->get('mail.encryption') === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.host' => $this->settings->get('mail.host'),
            'mail.mailers.smtp.port' => (int) ($this->settings->get('mail.port') ?: 587),
            'mail.mailers.smtp.username' => $this->settings->get('mail.username'),
            'mail.mailers.smtp.password' => $this->password(),
            'mail.mailers.smtp.timeout' => 10,
        ]);
        // the mailer is built again with the new values
        Mail::purge('smtp');
        self::blockSendingInDebug();
    }

    /**
     * Store the password encrypted. Null keeps the stored one, an empty string removes it.
     */
    public static function encryptPassword(?string $password): ?string
    {
        return $password === null || $password === '' ? null : Crypt::encryptString($password);
    }

    /**
     * Connection status: whether the server answers and accepts the login. Kept for 15 minutes, the
     * scheduler tests again every 5 minutes (stocks:mail-status), so the menu badge stays filled
     * even when a scheduler run is missed; $fresh tests again.
     *
     * @return array{ok: bool|null, message: string, checked_at: string}
     */
    public function status(bool $fresh = false): array
    {
        $key = $this->statusKey();
        if ($fresh) {
            cache()->forget($key);
        }

        return cache()->remember($key, self::STATUS_SECONDS, fn () => [...$this->test(), 'checked_at' => now()->toIso8601String()]);
    }

    /**
     * The last connection test, without testing again (null if there is none). For the menu,
     * which must not wait for the network.
     *
     * @return array{ok: bool|null, message: string, checked_at: string}|null
     */
    public function lastStatus(): ?array
    {
        return cache()->get($this->statusKey());
    }

    /**
     * Cache key of the status; a changed setting starts a new test.
     */
    private function statusKey(): string
    {
        $values = array_map(fn (string $key) => $this->settings->get("mail.{$key}"), ['host', 'port', 'username', 'encryption', 'password']);

        return 'stocks.mail.status.'.md5(json_encode($values));
    }

    /**
     * Open a connection to the server and log in.
     *
     * @return array{ok: bool|null, message: string}
     */
    private function test(): array
    {
        $status = $this->testConnection();
        if (self::isSendingBlocked()) {
            $status['message'] .= ' Debug-Modus (APP_DEBUG) ist an: E-Mails werden nicht verschickt, nur ins Log geschrieben.';
        }

        return $status;
    }

    /**
     * Open a connection to the server and log in, without the note on the debug mode.
     *
     * @return array{ok: bool|null, message: string}
     */
    private function testConnection(): array
    {
        if (! $this->isConfigured()) {
            $mailer = config('mail.default');

            return ['ok' => null, 'message' => $mailer === 'smtp'
                ? 'Kein Mailserver eingestellt, es gelten die Umgebungsvariablen ('.config('mail.mailers.smtp.host').').'
                : "Kein Mailserver eingestellt. E-Mails gehen an „{$mailer}“ und werden nicht verschickt."];
        }

        $this->apply();
        try {
            $transport = Mail::mailer('smtp')->getSymfonyTransport();
            if ($transport instanceof EsmtpTransport) {
                $transport->start();
                $transport->stop();
            }

            return ['ok' => true, 'message' => 'Verbunden mit '.$this->settings->get('mail.host').'.'];
        } catch (\Throwable $exception) {
            return ['ok' => false, 'message' => 'Keine Verbindung: '.$exception->getMessage()];
        }
    }

    /**
     * The stored password, decrypted.
     */
    private function password(): ?string
    {
        $stored = $this->settings->get('mail.password');

        try {
            return $stored ? Crypt::decryptString($stored) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
