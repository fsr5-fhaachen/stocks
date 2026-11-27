<?php

namespace Modules\Loans\Tests;

use App\Actions\Inventory\StockBook;
use App\Enums\MovementType;
use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Location;
use App\Support\Extensions\ItemExtensions;
use Illuminate\Http\UploadedFile;
use Modules\Loans\Actions\IssueLoan;
use Modules\Loans\Enums\DocumentType;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\LoanItem;
use Modules\Loans\Support\Availability;

/**
 * The issue: scan, tick off, foreign and missing items, freezing the boxes, bulk pieces from their place,
 * locks on issued items, signatures and documents.
 */
class IssueTest extends LoansTestCase
{
    public function test_scanning_ticks_rows_off_and_reports_foreign_items(): void
    {
        $item = $this->item();
        $loan = $this->confirmed([$item, $this->item()]);
        $foreign = $this->item(['name' => 'Fremder Beamer']);
        $admin = $this->admin();

        $this->actingAs($admin)->get("/loans/{$loan->id}/issue")->assertOk()->assertInertia(fn ($page) => $page->component('Loans::Loans/Issue', false)->has('rows', 2));
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/check", ['code' => "http://x/s/{$item->number}"])->assertSessionHasNoErrors();
        $this->assertTrue((bool) $loan->positions()->where('item_id', $item->id)->value('checked_by_scan'));

        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/check", ['code' => $foreign->number])->assertSessionHas('foreign.id', $foreign->id)->assertSessionHas('foreign.free', true);

        // what is not ticked off stays as rest (partial issue, see PartialIssueTest)
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/complete", ['signatures' => ['responsible' => self::SIGNATURE, 'issuer' => self::SIGNATURE]])->assertSessionHasNoErrors();
        $this->assertSame(LoanStatus::Issued, $loan->refresh()->status);
        $this->assertSame(1, $loan->positions()->whereNull('issued_at')->count());
    }

    public function test_a_ticked_box_ticks_its_contents_and_the_last_content_ticks_the_box(): void
    {
        $box = $this->item(['is_container' => true]);
        $first = $this->item(['location_id' => null, 'container_id' => $box->id]);
        $second = $this->item(['location_id' => null, 'container_id' => $box->id]);
        $loan = $this->confirmed([$box]);
        $admin = $this->admin();
        $this->actingAs($admin)->get("/loans/{$loan->id}/issue");

        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/check", ['code' => $box->number]);
        $this->assertSame(3, $loan->loanItems()->whereNotNull('checked_at')->count());

        // taking back a row of the contents keeps the box ticked: that row stays here
        $row = $loan->loanItems()->where('item_id', $first->id)->first();
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/rows/{$row->id}/uncheck");
        $this->assertNotNull($loan->positions()->first()->checked_at);
        $this->assertNull($row->refresh()->checked_at);

        // taking back the box takes back its contents; the last content ticks it again
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/rows/{$loan->positions()->first()->id}/uncheck");
        $this->assertSame(0, $loan->loanItems()->whereNotNull('checked_at')->count());
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/check", ['code' => $first->number]);
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/check", ['code' => $second->number]);
        $this->assertNotNull($loan->positions()->first()->checked_at);
    }

    public function test_a_row_of_the_contents_taken_back_stays_at_the_place_of_the_box(): void
    {
        $box = $this->item(['is_container' => true]);
        $stays = $this->item(['location_id' => null, 'container_id' => $box->id, 'name' => 'Ersatzkabel']);
        $goes = $this->item(['location_id' => null, 'container_id' => $box->id]);
        $loan = $this->confirmed([$box], now()->subHour());
        $admin = $this->admin();
        $this->actingAs($admin)->get("/loans/{$loan->id}/issue");
        $this->post("/loans/{$loan->id}/issue/check", ['code' => $box->number]);
        $row = $loan->loanItems()->where('item_id', $stays->id)->first();
        $this->post("/loans/{$loan->id}/issue/rows/{$row->id}/uncheck");

        $this->post("/loans/{$loan->id}/issue/complete", ['signatures' => ['responsible' => self::SIGNATURE, 'issuer' => self::SIGNATURE]])->assertSessionHasNoErrors();

        $stays->refresh();
        $this->assertNull($stays->container_id);
        $this->assertSame($this->shelf->id, $stays->location_id);
        $this->assertSame($box->id, $goes->refresh()->container_id);
        $this->assertFalse($loan->loanItems()->where('item_id', $stays->id)->exists());
        $names = array_column($loan->documents()->firstWhere('type', 'list')->snapshot['positions'], 'name');
        $this->assertNotContains('Ersatzkabel', $names);
        // it is free for others while the box is out
        $this->assertNull(app(Availability::class)->problemOf($stays));
        $this->assertFalse(app(Availability::class)->check($stays, 1, now(), now()->addDay())['blocked']);
    }

    public function test_the_issue_freezes_boxes_moves_items_out_and_locks_them(): void
    {
        $box = $this->item(['is_container' => true, 'name' => 'Partybox']);
        $inside = $this->item(['location_id' => null, 'container_id' => $box->id, 'name' => 'Lichterkette']);
        $loan = $this->issued([$box]);

        $this->assertSame(LoanStatus::Issued, $loan->status);
        $this->assertNull($box->refresh()->location_id);
        $this->assertSame($this->shelf->id, $box->home_location_id);
        $this->assertSame($box->id, $inside->refresh()->container_id);
        $this->assertSame(MovementType::Issue, $box->movements()->first()->type);
        $this->assertTrue(LoanItem::where('loan_id', $loan->id)->where('item_id', $inside->id)->where('parent_id', '!=', null)->exists());

        // repacking the box later does not change the signed list
        $later = $this->item(['location_id' => null, 'container_id' => $box->id, 'name' => 'Später dazu']);
        $this->assertSame(['Partybox', 'Lichterkette'], array_column($loan->documents()->first()->snapshot['positions'], 'name'));

        // locked while issued, the item page and the scan card say so
        $this->actingAs($this->admin())->post('/inventory/move', ['item_id' => $box->id, 'target_code' => $this->shelf->full_code])->assertSessionHasErrors('item');
        $this->actingAs($this->admin())->post("/inventory/items/{$inside->id}/retire", ['reason' => 'lost'])->assertSessionHasErrors('item');
        $this->actingAs($this->admin())->get("/inventory/items/{$box->id}")->assertInertia(fn ($page) => $page->where('notices.0.lock', true)->where('notices.0.text', fn ($text) => str_starts_with($text, 'Ausgegeben an Erika Muster bis')));
        $this->actingAs($this->admin())->getJson("/inventory/lookup?code={$inside->number}")->assertJsonPath('notices.0.actions.0.title', 'Rückgabe dieser Buchung');
        $this->actingAs($this->admin())->get('/inventory/items')->assertInertia(fn ($page) => $page->where('items.data', fn ($rows) => collect($rows)->firstWhere('id', $box->id)['badges'][0]['text'] !== null));
        unset($later);
    }

    public function test_the_issue_takes_the_pieces_from_the_chosen_place(): void
    {
        $benches = $this->item(['kind' => 'bulk', 'quantity' => 30, 'unit' => 'Stück']);
        $hall = Location::forceCreate(['type' => 'room', 'code' => 'R2', 'full_code' => 'R2']);
        app(StockBook::class)->transfer($this->admin(), $benches, $this->shelf, $hall, 10);
        $loan = $this->confirmed([[$benches, 8]], now()->subHour());
        $admin = $this->admin();
        $row = $loan->positions()->firstOrFail();

        // a place without enough pieces is refused, the chosen one is kept on the row
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/check", ['row_id' => $row->id, 'quantity' => 8, 'from' => 'X1'])->assertSessionHasErrors();
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/check", ['row_id' => $row->id, 'quantity' => 8, 'from' => ItemStock::keyOf($hall)])->assertSessionHasNoErrors();
        $this->assertSame(ItemStock::keyOf($hall), $row->refresh()->source_key);
        $this->actingAs($admin)->get("/loans/{$loan->id}/issue")->assertInertia(fn ($page) => $page->where('rows.0.source_key', ItemStock::keyOf($hall))->has('rows.0.sources', 2));

        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/complete", ['signatures' => ['responsible' => self::SIGNATURE, 'issuer' => self::SIGNATURE]])->assertSessionHasNoErrors();
        $this->assertSame(2, ItemStock::for($benches, $hall)->quantity);
        $this->assertSame(20, ItemStock::for($benches, $this->shelf)->quantity);
    }

    public function test_the_issue_takes_bulk_pieces_from_their_place(): void
    {
        $benches = $this->item(['kind' => 'bulk', 'quantity' => 40, 'unit' => 'Stück', 'name' => 'Bierbank']);
        $loan = $this->issued([[$benches, 20]]);

        // the item keeps its whole stock, the shelf gives the 20 that left
        $this->assertSame(40, $benches->refresh()->quantity);
        $this->assertSame(20, ItemStock::for($benches, $this->shelf)->quantity);
        $this->assertSame(20, $loan->positions()->first()->quantity);
        $this->assertSame(20, $benches->movements()->where('type', MovementType::Issue)->sole()->quantity);
        $this->assertSame(20, app(ItemExtensions::class)->awayQuantity($benches));

        // not locked: the 20 here can move or be used up, not more
        $this->actingAs($this->admin())->get("/inventory/items/{$benches->id}")->assertInertia(fn ($page) => $page->where('item.quantity_here', 20)->where('notices.0.lock', false)->where('notices.0.text', fn ($text) => str_starts_with($text, '20 Stück ausgegeben an')));
        // "vorhanden" on the scan card and in the list
        $this->actingAs($this->admin())->getJson("/inventory/lookup?code={$benches->number}")->assertJsonPath('quantity_here', 20)->assertJsonPath('quantity', 40);
        $this->actingAs($this->admin())->get('/inventory/items')->assertInertia(fn ($page) => $page->where('items.data', fn ($rows) => collect($rows)->firstWhere('id', $benches->id)['quantity_here'] === 20));
        Location::forceCreate(['type' => 'room', 'code' => 'R2', 'full_code' => 'R2']);
        $this->actingAs($this->admin())->post('/inventory/move', ['item_id' => $benches->id, 'target_code' => 'R2', 'quantity' => 21])->assertSessionHasErrors('quantity');
        // retired as a whole only once nothing is away
        $this->actingAs($this->admin())->post("/inventory/items/{$benches->id}/retire", ['reason' => 'lost'])->assertSessionHasErrors('item');
        $this->actingAs($this->admin())->post('/inventory/move', ['item_id' => $benches->id, 'target_code' => 'R2', 'quantity' => 5])->assertSessionHasNoErrors();
        $this->assertSame(40, $benches->refresh()->quantity);
        // without quantity all pieces of the chosen place move
        $this->actingAs($this->admin())->post('/inventory/move', ['item_id' => $benches->id, 'target_code' => 'R2', 'from' => ItemStock::keyOf($this->shelf)])->assertSessionHasNoErrors();
        $this->assertSame(0, ItemStock::for($benches, $this->shelf)->quantity);
        $this->assertSame(20, ItemStock::for($benches, Location::firstWhere('code', 'R2'))->quantity);

        // the tab "Verleih" shows where the pieces are
        $this->actingAs($this->admin())->getJson("/loans/items/{$benches->id}")->assertJsonPath('pieces.0.quantity', 20)->assertJsonPath('pieces.0.loan.number', $loan->number);
    }

    public function test_signatures_are_required_unless_signed_on_paper(): void
    {
        $item = $this->item();
        $loan = $this->confirmed([$item], now()->subHour());
        $admin = $this->admin();
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/check", ['code' => $item->number]);

        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/complete", ['signatures' => ['responsible' => 'data:image/png;base64,bm90IGEgcG5n']])->assertSessionHasErrors();
        $this->actingAs($admin)->post("/loans/{$loan->id}/issue/complete", ['on_paper' => true, 'scan' => UploadedFile::fake()->image('blatt.jpg')])->assertSessionHasNoErrors();

        $loan->refresh();
        $this->assertSame(LoanStatus::Issued, $loan->status);
        $this->assertSame(4, $loan->signatures()->where('on_paper', true)->count());
        $this->assertTrue($loan->documents()->where('type', DocumentType::PaperScan)->exists());
        $this->assertSame($admin->id, $loan->issuer_id);
    }

    public function test_an_early_pickup_starts_the_time_now(): void
    {
        $loan = $this->issued([$this->item()], now()->addDays(2), now()->addDays(3));

        $this->assertTrue($loan->pickup_at->lte(now()));
    }

    public function test_someone_else_picking_up_becomes_the_responsible_person(): void
    {
        $loan = $this->confirmed([$this->item()]);
        $other = $this->contact(['name' => 'Jana Neu', 'phone' => null]);

        $this->actingAs($this->admin())->post("/loans/{$loan->id}/issue/responsible", ['responsible_contact_id' => $other->id])->assertSessionHasErrors('responsible_contact_id');
        $other->update(['phone' => '1']);
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/issue/responsible", ['responsible_contact_id' => $other->id])->assertSessionHasNoErrors();
        $this->assertSame($other->id, $loan->refresh()->responsible_contact_id);
    }

    public function test_an_early_pickup_checks_only_what_leaves_and_names_the_rest_at_its_row(): void
    {
        $free = $this->item();
        $busy = $this->item();
        // the busy item is out with another loan until this evening
        $this->issued([$busy], now()->subDay(), now()->addHours(6));
        $loan = $this->confirmed([$free, $busy], now()->addDay(), now()->addDays(2));
        $admin = $this->admin();

        $page = $this->actingAs($admin)->get("/loans/{$loan->id}/issue")->assertInertia(fn ($page) => $page->where('loan.early', true));
        $rows = collect($page->viewData('page')['props']['rows'])->keyBy('number');
        $this->assertNull($rows[$free->number]['early']);
        $this->assertStringContainsString('Jetzt noch nicht frei', $rows[$busy->number]['early']);

        // only the free one leaves; the busy one stays as rest and does not stop the early pickup
        $this->post("/loans/{$loan->id}/issue/check", ['code' => $free->number])->assertSessionHasNoErrors();
        $this->post("/loans/{$loan->id}/issue/complete", ['signatures' => ['responsible' => self::SIGNATURE, 'issuer' => self::SIGNATURE]])->assertSessionHasNoErrors();
        $this->assertSame(LoanStatus::Issued, $loan->refresh()->status);
        $this->assertTrue($loan->pickup_at->isPast());
        $this->assertTrue(IssueLoan::hasRest($loan));
    }
}
