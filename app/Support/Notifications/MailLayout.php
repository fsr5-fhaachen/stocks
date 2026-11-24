<?php

namespace App\Support\Notifications;

use App\Support\Branding;
use App\Support\Settings;
use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Frame of every mail in the look of Stocks (documentation/NOTIFICATIONS.md): theme "stocks"
 * (resources/views/mail/stocks.blade.php, accent colour of the settings), head with the print
 * logo (else the logo for light backgrounds, else the name), foot with organization, contact and
 * where the settings are. The logo travels inside the mail (cid), so mail programs show it without
 * loading images; without S3 the name stands there and the mail still goes out.
 */
class MailLayout
{
    /**
     * Content id of the embedded logo.
     *
     * @var string
     */
    public const LOGO_CID = 'logo@stocks';

    /**
     * Give a mail the frame.
     *
     * @param  array{text: string, url: string}|null  $settings  where the receiver changes what it gets
     */
    public static function apply(MailMessage $mail, ?array $settings = null): MailMessage
    {
        $branding = app(Branding::class);
        $kind = $branding->mailKind();
        $png = $kind !== null ? $branding->pngFor($kind) : null;

        if ($png !== null) {
            $mail->withSymfonyMessage(fn (Email $email) => $email->addPart(
                (new DataPart($png, 'logo.png', 'image/png'))->asInline()->setContentId(self::LOGO_CID),
            ));
        }

        $mail->viewData['layout'] = [...self::frame($png !== null), 'settings' => $settings];

        return $mail;
    }

    /**
     * Data of head and foot.
     *
     * @return array{name: string, logo: string|null, url: string, contact: string|null, phone: string|null, settings: array{text: string, url: string}|null}
     */
    public static function frame(bool $withLogo = false): array
    {
        $settings = app(Settings::class);

        return [
            'name' => (string) $settings->get('organization.name'),
            'logo' => $withLogo ? 'cid:'.self::LOGO_CID : null,
            'url' => (string) config('app.url'),
            'contact' => $settings->get('legal.contact_email'),
            'phone' => $settings->get('organization.phone'),
            'settings' => null,
        ];
    }

    /**
     * Html of a mail for the browser: the embedded logo becomes a data uri (preview for admins).
     */
    public static function preview(MailMessage $mail): string
    {
        $html = (string) $mail->render();
        $branding = app(Branding::class);
        $kind = $branding->mailKind();
        $png = $kind !== null ? $branding->pngFor($kind) : null;

        return $png !== null ? str_replace('cid:'.self::LOGO_CID, 'data:image/png;base64,'.base64_encode($png), $html) : $html;
    }
}
