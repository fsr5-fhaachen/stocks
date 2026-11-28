<?php

namespace Modules\Loans\Tests;

use App\Actions\Inventory\StockBook;
use App\Models\ItemStock;
use App\Models\Location;

/**
 * Bulk positions: the place chosen when adding ("Einpacken" into a loan) and pieces used up or
 * retired before the issue ("Verbraucht", "Ausmustern" in the position list).
 */
class TakeOffTest extends LoansTestCase
{
    public function test_adding_a_bulk_item_keeps_the_place_its_pieces_leave_from(): void
    {
        $tape = $this->item(['kind' => 'bulk', 'quantity' => 10, 'unit' => 'Rollen']);
        $hall = Location::forceCreate(['type' => 'room', 'code' => 'R2', 'full_code' => 'R2']);
        $this->stock()->transfer($this->admin(), $tape, $this->shelf, $hall, 4);
        $loan = $this->draft();

        $this->actingAs($this->admin())->get("/loans/add-item/{$tape->id}?from=".ItemStock::keyOf($hall))
            ->assertInertia(fn ($page) => $page->where('from', ItemStock::keyOf($hall))->has('item.stocks', 2));
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/positions", ['item_id' => $tape->id, 'quantity' => 3, 'from' => ItemStock::keyOf($hall)])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/positions", ['item_id' => $tape->id, 'quantity' => 1, 'from' => 'L999999'])
            ->assertSessionHasErrors('from');

        $position = $loan->positions()->sole();
        $this->assertSame(3, $position->quantity);
        $this->assertSame(ItemStock::keyOf($hall), $position->source_key);
        // only reserved: the pieces stay at their place
        $this->assertSame(4, ItemStock::for($tape, $hall)->quantity);
    }

    public function test_pieces_used_up_before_the_issue_leave_their_place_and_the_position(): void
    {
        $tape = $this->item(['kind' => 'bulk', 'quantity' => 10, 'unit' => 'Rollen']);
        $loan = $this->confirmed([[$tape, 6]]);
        $position = $loan->positions()->sole();

        $this->actingAs($this->admin())->post("/loans/{$loan->id}/positions/{$position->id}/take-off", ['quantity' => 2, 'reason' => 'consumed', 'note' => 'Probelauf'])
            ->assertSessionHasNoErrors();
        $this->assertSame(4, $position->refresh()->quantity);
        $this->assertSame(8, $tape->refresh()->quantity);
        $this->assertSame(8, ItemStock::for($tape, $this->shelf)->quantity);
        $this->assertDatabaseHas('item_movements', ['item_id' => $tape->id, 'type' => 'consume', 'quantity' => 2, 'note' => "Aus {$loan->number}. Probelauf"]);

        // more than booked is refused; all of it removes the position
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/positions/{$position->id}/take-off", ['quantity' => 5, 'reason' => 'defective'])->assertSessionHasErrors('quantity');
        $this->actingAs($this->admin())->post("/loans/{$loan->id}/positions/{$position->id}/take-off", ['quantity' => 4, 'reason' => 'defective'])->assertSessionHasNoErrors();
        $this->assertSame(0, $loan->positions()->count());
        $this->assertSame(4, $tape->refresh()->quantity);
    }

    public function test_issued_pieces_and_missing_rights_are_refused(): void
    {
        $tape = $this->item(['kind' => 'bulk', 'quantity' => 10, 'unit' => 'Rollen']);
        $issued = $this->issued([[$tape, 3]]);
        $row = $issued->positions()->sole();
        $this->actingAs($this->admin())->post("/loans/{$issued->id}/positions/{$row->id}/take-off", ['quantity' => 1, 'reason' => 'consumed'])->assertSessionHasErrors();

        $loan = $this->confirmed([[$tape, 2]], now()->addDays(5), now()->addDays(6));
        $position = $loan->positions()->sole();
        $manager = $this->userWithPermissions('loans.view', 'loans.manage', 'inventory.view', 'inventory.move');
        $this->actingAs($manager)->post("/loans/{$loan->id}/positions/{$position->id}/take-off", ['quantity' => 1, 'reason' => 'defective'])->assertForbidden();
        $this->actingAs($manager)->post("/loans/{$loan->id}/positions/{$position->id}/take-off", ['quantity' => 1, 'reason' => 'consumed'])->assertSessionHasNoErrors();
    }

    private function stock(): StockBook
    {
        return app(StockBook::class);
    }
}
