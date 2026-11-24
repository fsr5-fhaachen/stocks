<?php

namespace Modules\Loans\Tests;

use App\Models\Item;
use App\Support\Extensions\ItemExtensions;
use Modules\Loans\Models\Loan;

/**
 * Lent items are not "Ohne Platz"; back but not put away or missing they are again.
 */
class LoanAwayItemsTest extends LoansTestCase
{
    /**
     * Ids in "Ohne Platz".
     *
     * @return list<int>
     */
    private function misplaced(): array
    {
        return app(ItemExtensions::class)->misplaced()->orderBy('id')->pluck('id')->all();
    }

    private function returnRow(Loan $loan, ?string $state = null): void
    {
        $admin = $this->admin();
        $row = $loan->positions()->firstOrFail();
        $state === null
            ? $this->actingAs($admin)->post("/loans/{$loan->id}/return/check", ['row_id' => $row->id])
            : $this->actingAs($admin)->post("/loans/{$loan->id}/return/rows/{$row->id}/defect", ['state' => $state, 'note' => 'nicht auffindbar']);
        $this->actingAs($admin)->post("/loans/{$loan->id}/return/complete", ['returner' => 'Erika', 'signatures' => ['returner' => self::SIGNATURE, 'receiver' => self::SIGNATURE]])->assertSessionHasNoErrors();
    }

    public function test_an_issued_item_is_not_misplaced_until_it_is_back(): void
    {
        $item = $this->item();
        $loan = $this->issued([$item]);
        $this->assertNull($item->refresh()->location_id);

        $this->assertSame([], $this->misplaced());
        $this->actingAs($this->admin())->getJson('/inventory/stats')->assertJsonPath('misplaced', 0);
        // the list of all items shows it with its badge
        $this->actingAs($this->admin())->get('/inventory/items')
            ->assertInertia(fn ($page) => $page->where('items.data.0.badges.0.text', 'ausgegeben bis '.$loan->return_at->format('d.m.')));

        // back, not put away yet: misplaced again
        $this->returnRow($loan);
        $this->assertSame([$item->id], $this->misplaced());
    }

    public function test_a_missing_item_stays_misplaced(): void
    {
        $item = $this->item();
        $this->returnRow($this->issued([$item]), 'missing');

        $this->assertSame([$item->id], $this->misplaced());
    }

    public function test_of_a_bulk_item_only_the_lent_part_is_left_out(): void
    {
        $cables = $this->item(['kind' => 'bulk', 'quantity' => 10, 'unit' => 'Stück', 'location_id' => null]);
        $loan = $this->issued([[$cables, 4]]);
        $part = $loan->positions()->first()->issued_item_id;
        $this->assertNotNull($part);

        // the stock lies nowhere and stays misplaced, the lent part is away
        $this->assertSame([$cables->id], $this->misplaced());
        $this->assertNotContains($part, $this->misplaced());
    }

    public function test_with_the_module_off_lent_items_count_again(): void
    {
        $item = $this->item();
        $this->issued([$item]);

        $this->deactivateModule('loans');

        $this->assertSame([$item->id], $this->misplaced());
    }

    public function test_the_contents_of_a_lent_box_were_never_misplaced(): void
    {
        $box = $this->item(['is_container' => true]);
        $content = Item::factory()->create(['container_id' => $box->id, 'home_container_id' => $box->id]);
        $this->issued([$box]);

        $this->assertSame($box->id, $content->refresh()->container_id);
        $this->assertSame([], $this->misplaced());
    }
}
