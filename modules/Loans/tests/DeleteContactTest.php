<?php

namespace Modules\Loans\Tests;

use Modules\Loans\Actions\CancelLoan;
use Modules\Loans\Models\Contact;
use Modules\Loans\Models\Council;
use Modules\Loans\Models\Event;
use Modules\Loans\Models\Loan;
use Modules\Loans\Support\LoanPage;
use OwenIt\Auditing\Models\Audit;

/**
 * Deleting contacts with loans: the name stays at the loans, the personal data is gone.
 */
class DeleteContactTest extends LoansTestCase
{
    private function closed(): Loan
    {
        $loan = $this->issued([$this->item()]);
        $admin = $this->admin();
        $row = $loan->positions()->firstOrFail();
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/check", ['row_id' => $row->id]);
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/complete", ['returner' => 'Erika', 'signatures' => ['returner' => self::SIGNATURE, 'receiver' => self::SIGNATURE]])->assertSessionHasNoErrors();

        return $loan->refresh();
    }

    private function deleter()
    {
        return $this->userWithPermissions('loans.view', 'loans.manage', 'loans.contacts.delete');
    }

    public function test_a_contact_with_finished_loans_is_deleted_and_its_name_stays(): void
    {
        $this->enableModelAuditing();
        $loan = $this->closed();
        $contact = $loan->responsible;
        $contact->update(['phone' => '0241 999']);
        $event = Event::create(['name' => 'Sommerfest', 'starts_at' => now(), 'ends_at' => now()->addDay(), 'responsible_contact_id' => $contact->id]);
        $loan->pushSubscriptions()->create(['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc']);

        $this->actingAs($this->deleter())->delete("/loans/contacts/{$contact->id}")->assertRedirect('/loans/contacts');

        $this->assertModelMissing($contact);
        $loan->refresh();
        $this->assertNull($loan->responsible_contact_id);
        $this->assertSame('Erika Muster', $loan->responsible_name);
        $this->assertSame('Erika Muster', $loan->borrower_name);
        $this->assertSame('Erika Muster', $loan->responsibleName());
        $this->assertSame('Erika Muster', $event->refresh()->responsible_name);
        $this->assertSame(0, $loan->pushSubscriptions()->count());

        $admin = $this->admin();
        $this->actingAs($admin)->get("/loans/{$loan->id}")->assertInertia(fn ($page) => $page
            ->where('loan.responsible', ['id' => null, 'name' => 'Erika Muster'])->where('loan.borrower', ['id' => null, 'name' => 'Erika Muster']));
        $this->actingAs($admin)->get('/loans')->assertInertia(fn ($page) => $page->where('loans.data.0.responsible', 'Erika Muster'));
        $this->actingAs($admin)->get('/loans?search=Erika')->assertInertia(fn ($page) => $page->has('loans.data', 1));
        $this->actingAs($admin)->get("/loans/events/{$event->id}")->assertInertia(fn ($page) => $page->where('event.responsible.name', 'Erika Muster')->where('event.responsible_name', 'Erika Muster'));
        auth()->logout();
        $this->get(LoanPage::url($loan))->assertOk()->assertInertia(fn ($page) => $page->where('contacts.responsible.name', 'Erika Muster')->where('contacts.responsible.phone', null));

        // nothing personal is left in the audit, the name is
        $audits = Audit::where('auditable_type', $contact->getMorphClass())->where('auditable_id', $contact->id)->get();
        $this->assertNotEmpty($audits);
        foreach ($audits as $audit) {
            $values = json_encode([$audit->old_values, $audit->new_values]);
            foreach (['erika@example.com', '0241', 'Eupener', '52066', 'Aachen'] as $personal) {
                $this->assertStringNotContainsString($personal, $values);
            }
        }
        $this->assertSame('Erika Muster', $audits->firstWhere('event', 'deleted')->old_values['name']);
    }

    public function test_an_open_loan_stops_the_deletion(): void
    {
        $loan = $this->issued([$this->item()]);

        $this->actingAs($this->deleter())->delete("/loans/contacts/{$loan->responsible_contact_id}")
            ->assertSessionHasErrors(['contact' => "Erika Muster hat noch offene Buchungen: {$loan->number}. Erst wenn sie abgeschlossen oder storniert sind, lässt sich der Kontakt löschen."]);
        $this->assertNotNull($loan->refresh()->responsible);
    }

    public function test_the_council_stays_with_the_name_of_a_cancelled_loan(): void
    {
        $council = Council::create(['number' => 95, 'name' => 'FSR 95']);
        $loan = $this->draft([$this->item()]);
        $loan->responsible->update(['council_id' => $council->id]);
        app(CancelLoan::class)->handle($loan, 'abgesagt');

        $this->actingAs($this->deleter())->delete("/loans/contacts/{$loan->responsible_contact_id}")->assertSessionHasNoErrors();

        $this->assertSame('Erika Muster (FSR 95)', $loan->refresh()->responsibleName());
    }

    public function test_with_loans_it_needs_the_permission_without_not(): void
    {
        $loan = $this->closed();
        $manager = $this->userWithPermissions('loans.view', 'loans.manage');

        $this->actingAs($manager)->delete("/loans/contacts/{$loan->responsible_contact_id}")->assertSessionHasErrors('contact');
        $this->assertNotNull(Contact::find($loan->responsible_contact_id));

        $lonely = $this->contact(['name' => 'Ohne Buchung']);
        $this->actingAs($manager)->delete("/loans/contacts/{$lonely->id}")->assertRedirect('/loans/contacts');
        $this->assertModelMissing($lonely);
    }

    public function test_the_page_tells_what_deleting_means(): void
    {
        $loan = $this->issued([$this->item()]);

        $this->actingAs($this->deleter())->get("/loans/contacts/{$loan->responsible_contact_id}")
            ->assertInertia(fn ($page) => $page->where('deletion.loans', 1)->where('deletion.events', 0)->where('deletion.open', [$loan->number]));
    }
}
