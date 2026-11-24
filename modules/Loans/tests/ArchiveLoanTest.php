<?php

namespace Modules\Loans\Tests;

use Modules\Loans\Actions\CancelLoan;
use Modules\Loans\Models\Loan;
use Modules\Loans\Support\LoanPage;

/**
 * Archive of finished loans: by hand, with its own permission, only out of the list.
 */
class ArchiveLoanTest extends LoansTestCase
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

    /**
     * Ids in the list of loans.
     *
     * @return list<int>
     */
    private function listed(string $query = ''): array
    {
        return collect($this->actingAs($this->admin())->get("/loans{$query}")->viewData('page')['props']['loans']['data'])->pluck('id')->all();
    }

    public function test_a_closed_loan_is_archived_and_restored(): void
    {
        $loan = $this->closed();
        $archivist = $this->userWithPermissions('loans.view', 'loans.archive');

        $this->actingAs($archivist)->post("/loans/{$loan->id}/archive")->assertSessionHasNoErrors();
        $loan->refresh();
        $this->assertNotNull($loan->archived_at);
        $this->assertSame($archivist->id, $loan->archived_by);
        $this->assertNotContains($loan->id, $this->listed());
        $this->assertSame([$loan->id], $this->listed('?archive=1'));
        $this->actingAs($this->admin())->get("/loans/{$loan->id}")->assertInertia(fn ($page) => $page->where('loan.archived', true)->where('loan.archiver', $archivist->name));

        $this->actingAs($archivist)->delete("/loans/{$loan->id}/archive")->assertSessionHasNoErrors();
        $this->assertNull($loan->refresh()->archived_at);
        $this->assertContains($loan->id, $this->listed());
    }

    public function test_only_finished_loans_can_be_archived(): void
    {
        $issued = $this->issued([$this->item()]);
        $draft = $this->draft([$this->item()]);
        $cancelled = $this->draft([$this->item()]);
        app(CancelLoan::class)->handle($cancelled, 'abgesagt');

        $this->actingAs($this->admin())->post("/loans/{$issued->id}/archive")->assertSessionHasErrors('loan');
        $this->assertNull($issued->refresh()->archived_at);

        $this->actingAs($this->admin())->post('/loans/archive', ['ids' => [$issued->id, $draft->id, $cancelled->id]])->assertSessionHasNoErrors();
        $this->assertNull($draft->refresh()->archived_at);
        $this->assertNotNull($cancelled->refresh()->archived_at);
    }

    public function test_archiving_needs_the_permission(): void
    {
        $loan = $this->closed();

        $this->actingAs($this->userWithPermissions('loans.view', 'loans.manage', 'loans.cancel'))->post("/loans/{$loan->id}/archive")->assertForbidden();
        $this->assertNull($loan->refresh()->archived_at);
    }

    public function test_history_and_borrower_page_stay(): void
    {
        $loan = $this->closed();
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/archive");
        $item = $loan->positions()->first()->item;

        $this->actingAs($this->admin())->get("/loans/contacts/{$loan->responsible_contact_id}")
            ->assertInertia(fn ($page) => $page->where('loans.0.id', $loan->id)->where('loans.0.archived', true));
        $this->actingAs($this->admin())->getJson("/loans/items/{$item->id}")->assertOk()->assertJsonFragment(['number' => $loan->number]);
        auth()->logout();
        $this->get(LoanPage::url($loan))->assertOk();
    }
}
