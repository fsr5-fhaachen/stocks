<?php

namespace Modules\Loans\Actions;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Loans\Models\Loan;

/**
 * Archive a finished loan (closed, with defects or cancelled) by hand, or take it back out. An
 * archived loan only leaves the list of loans (filter "Archiv"); its pages, history, borrower page
 * and documents stay as they are. Both steps are in the audit.
 */
class ArchiveLoan
{
    /**
     * @throws ValidationException
     */
    public function archive(User $actor, Loan $loan): void
    {
        if (! $loan->isArchivable()) {
            throw ValidationException::withMessages(['loan' => "{$loan->number} ist nicht abgeschlossen und lässt sich nicht archivieren."]);
        }
        if ($loan->archived_at !== null) {
            return;
        }

        $loan->forceFill(['archived_at' => now(), 'archived_by' => $actor->id])->save();
    }

    /**
     * Take a loan back out of the archive.
     */
    public function restore(Loan $loan): void
    {
        if ($loan->archived_at === null) {
            return;
        }

        $loan->forceFill(['archived_at' => null, 'archived_by' => null])->save();
    }
}
