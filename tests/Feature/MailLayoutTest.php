<?php

namespace Tests\Feature;

use App\Notifications\InvitationNotification;
use App\Notifications\TestNotification;
use App\Support\Branding;
use App\Support\Notifications\MailLayout;
use App\Support\Settings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Every mail in the look of Stocks: logo or name, accent colour, foot with the settings.
 */
class MailLayoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Branding::DISK);
        app(Settings::class)->set(['organization.name' => 'FSR 5', 'organization.accent_color' => '#ff0000', 'legal.contact_email' => 'fsr@example.org']);
    }

    public function test_a_notification_has_the_name_the_accent_colour_and_no_laravel(): void
    {
        $user = $this->admin();
        $html = (string) (new TestNotification('mail'))->toMail($user)->render();

        $this->assertStringContainsString('FSR 5', $html);
        $this->assertStringContainsString('background-color: #ff0000', $html);
        $this->assertStringContainsString('fsr@example.org', $html);
        $this->assertStringContainsString('Benachrichtigungen im Profil ändern', $html);
        $this->assertStringNotContainsString('Laravel', $html);
        $this->assertStringNotContainsString('cid:', $html);
    }

    public function test_the_print_logo_is_embedded(): void
    {
        $this->actingAs($this->admin())->post('/admin/settings/branding', ['print_logo' => UploadedFile::fake()->image('druck.png', 800, 200)]);
        $mail = (new TestNotification('mail'))->toMail($this->admin());

        $this->assertStringContainsString('src="cid:'.MailLayout::LOGO_CID.'"', (string) $mail->render());
        $email = new Email;
        foreach ($mail->callbacks as $callback) {
            $callback($email);
        }
        $this->assertSame(MailLayout::LOGO_CID, $email->getAttachments()[0]->getContentId());
        // the preview shows it without the mail
        $this->assertStringContainsString('data:image/png;base64,', MailLayout::preview($mail));
    }

    public function test_the_dark_logo_is_never_used(): void
    {
        $this->actingAs($this->admin())->post('/admin/settings/branding', ['logo' => UploadedFile::fake()->image('weiss.png')]);

        $this->assertStringNotContainsString('cid:', (string) (new TestNotification('mail'))->toMail($this->admin())->render());
    }

    public function test_invitation_and_password_reset_use_the_same_frame(): void
    {
        $user = $this->admin();
        $invitation = (string) (new InvitationNotification('abc', 'Erika'))->toMail($user)->render();
        $reset = (new ResetPassword('token'))->toMail($user);

        $this->assertStringContainsString('background-color: #ff0000', $invitation);
        $this->assertInstanceOf(MailMessage::class, $reset);
        $this->assertSame('Passwort zurücksetzen', $reset->subject);
        $this->assertStringContainsString('FSR 5', (string) $reset->render());
    }

    public function test_admins_see_a_preview(): void
    {
        $this->actingAs($this->admin())->get('/admin/mail/preview')->assertOk()->assertSee('Wenn du das liest');
        $this->actingAs($this->admin())->get('/admin/mail/preview?mail=invitation')->assertOk()->assertSee('Konto anlegen');
        $this->actingAs($this->userWithPermissions())->get('/admin/mail/preview')->assertForbidden();
    }
}
