<?php

namespace Modules\Loans\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\Contact;
use Modules\Loans\Models\Event;
use Modules\Loans\Models\Loan;
use OwenIt\Auditing\Models\Audit;

/**
 * Delete a contact. Without loans and events "loans.manage" is enough; with them it needs
 * "loans.contacts.delete" and every loan must be finished (closed, with defects or cancelled).
 * The name (with the council) stays at the loans and events as text; e-mail, phone, address and
 * notes are gone, also from the audit. Push of the borrower pages of these loans ends.
 */
class DeleteContact
{
    /**
     * @throws ValidationException
     */
    public function handle(User $actor, Contact $contact): void
    {
        DB::transaction(function () use ($actor, $contact) {
            $loans = $contact->allLoans()->get();
            $events = Event::where('responsible_contact_id', $contact->id)->get();

            if (($loans->isNotEmpty() || $events->isNotEmpty()) && ! $actor->can('loans.contacts.delete')) {
                throw ValidationException::withMessages(['contact' => "{$contact->name} hat Buchungen oder Veranstaltungen. Löschen darf nur, wer Kontakte mit Buchungen löschen darf."]);
            }
            $open = $loans->filter(fn (Loan $loan) => ! in_array($loan->status, LoanStatus::finished(), true));
            if ($open->isNotEmpty()) {
                throw ValidationException::withMessages(['contact' => "{$contact->name} hat noch offene Buchungen: {$open->pluck('number')->join(', ')}. Erst wenn sie abgeschlossen oder storniert sind, lässt sich der Kontakt löschen."]);
            }

            $name = self::keptName($contact);
            foreach ($loans as $loan) {
                $loan->forceFill(array_filter([
                    'borrower_name' => $loan->borrower_contact_id === $contact->id ? $name : null,
                    'responsible_name' => $loan->responsible_contact_id === $contact->id ? $name : null,
                ]))->save();
                // whoever reads the borrower page gets no push any more
                $loan->pushSubscriptions()->delete();
            }
            foreach ($events as $event) {
                $event->forceFill(['responsible_name' => $name])->save();
            }

            $this->forgetInAudit($contact);
            $contact->delete();
        });
    }

    /**
     * Name that stays at the loans: "Name (Fachschaft)", or the name of a private person.
     */
    public static function keptName(Contact $contact): string
    {
        return mb_substr($contact->council ? "{$contact->name} ({$contact->council->name})" : $contact->name, 0, 150);
    }

    /**
     * Remove the personal data from the earlier audit entries of the contact (created, changed).
     */
    private function forgetInAudit(Contact $contact): void
    {
        $personal = array_flip(Contact::PERSONAL);
        Audit::where('auditable_type', $contact->getMorphClass())->where('auditable_id', $contact->id)->get()
            ->each(function (Audit $audit) use ($personal) {
                $audit->forceFill([
                    'old_values' => array_diff_key($audit->old_values ?? [], $personal),
                    'new_values' => array_diff_key($audit->new_values ?? [], $personal),
                ])->save();
            });
    }
}
