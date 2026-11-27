<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\StockBook;
use App\Enums\MovementType;
use App\Enums\RetireReason;
use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Location;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Stock of bulk items per place (App\Actions\Inventory\StockBook).
 */
class StockBookTest extends TestCase
{
    private function book(): StockBook
    {
        return app(StockBook::class);
    }

    /**
     * @return array<string, int>
     */
    private function stockOf(Item $item): array
    {
        return $item->stocks()->orderBy('place_key')->pluck('quantity', 'place_key')->map(fn ($quantity) => (int) $quantity)->all();
    }

    public function test_received_pieces_land_in_eingang_and_raise_the_quantity(): void
    {
        $actor = $this->userWithRoles('Admin');
        $batteries = Item::factory()->bulk(0)->create();

        $this->book()->receive($actor, $batteries, 12, note: 'Bestellung');
        $this->book()->receive($actor, $batteries, 3);

        $this->assertSame(['IN' => 15], $this->stockOf($batteries));
        $this->assertSame(15, $batteries->fresh()->quantity);
        $this->assertSame(15, $batteries->quantity);
        $this->assertSame(2, $batteries->movements()->where('type', MovementType::Receive)->count());
        $this->assertSame('Bestellung', $batteries->movements()->reorder('id')->first()->note);
    }

    public function test_pieces_move_between_places_without_new_numbers(): void
    {
        $actor = $this->userWithRoles('Admin');
        $shelf = Location::factory()->create(['code' => 'R1']);
        $box = Item::factory()->container()->create();
        $batteries = Item::factory()->bulk(20)->stockedAt()->create();

        $this->book()->transfer($actor, $batteries, null, $box, 8);
        $this->book()->transfer($actor, $batteries, null, $shelf, 12);
        $this->book()->transfer($actor, $batteries, $box, $shelf, 3);

        // "Eingang" is empty and gone, the quantity did not change
        $this->assertSame(["B{$box->id}" => 5, "L{$shelf->id}" => 15], $this->stockOf($batteries));
        $this->assertSame(20, $batteries->fresh()->quantity);
        $this->assertSame(2, Item::count());
        $this->assertSame(5, $box->stocksInside()->sum('quantity'));
    }

    public function test_a_place_never_goes_below_zero(): void
    {
        $actor = $this->userWithRoles('Admin');
        $box = Item::factory()->container()->create(['number' => 'B-12']);
        $batteries = Item::factory()->bulk(3, 'Stück')->stockedAt($box)->create();

        try {
            $this->book()->transfer($actor, $batteries, $box, null, 4);
            $this->fail('more than there');
        } catch (ValidationException $exception) {
            $this->assertSame('In B-12 liegen nur 3 Stück.', $exception->errors()['quantity'][0]);
        }

        $this->expectException(ValidationException::class);
        $this->book()->transfer($actor, $batteries, null, $box, 1);
    }

    public function test_used_up_pieces_leave_a_place_and_the_stock(): void
    {
        $actor = $this->userWithRoles('Admin');
        $box = Item::factory()->container()->create();
        $batteries = Item::factory()->bulk(10)->stockedAt($box)->create();

        $this->book()->remove($actor, $batteries, $box, 4, RetireReason::Consumed, 'Party');

        $this->assertSame(["B{$box->id}" => 6], $this->stockOf($batteries));
        $this->assertSame(6, $batteries->fresh()->quantity);
        $this->assertDatabaseHas('item_movements', ['item_id' => $batteries->id, 'type' => 'consume', 'quantity' => 4, 'from_container_id' => $box->id, 'note' => 'Party']);

        // pieces of a loan are at no place
        $this->book()->remove($actor, $batteries, false, 1, RetireReason::Defective);
        $this->assertSame(5, $batteries->fresh()->quantity);
        $this->assertSame(["B{$box->id}" => 6], $this->stockOf($batteries));
    }

    public function test_lent_pieces_leave_a_place_and_come_back_to_another(): void
    {
        $actor = $this->userWithRoles('Admin');
        $shelf = Location::factory()->create(['code' => 'R1']);
        $cables = Item::factory()->bulk(10)->stockedAt($shelf)->create();

        $this->book()->takeOut($actor, $cables, $shelf, 7);
        $this->assertSame(["L{$shelf->id}" => 3], $this->stockOf($cables));
        $this->assertSame(10, $cables->fresh()->quantity);

        $this->book()->putBack($actor, $cables, null, 7);
        $this->assertSame(['IN' => 7, "L{$shelf->id}" => 3], $this->stockOf($cables));
        $this->assertSame(['issue', 'return'], $cables->movements()->reorder('id')->get()->map(fn ($movement) => $movement->type->value)->all());
    }

    public function test_counting_corrects_a_place_and_the_quantity(): void
    {
        $actor = $this->userWithRoles('Admin');
        $box = Item::factory()->container()->create();
        $tape = Item::factory()->bulk(10)->stockedAt($box)->create();

        $this->book()->count($actor, $tape, $box, 7);

        $this->assertSame(7, $tape->fresh()->quantity);
        $this->assertDatabaseHas('item_movements', ['item_id' => $tape->id, 'type' => 'count', 'quantity' => 7, 'note' => '-3']);
    }

    public function test_a_place_with_a_target_quantity_stays_at_zero(): void
    {
        $actor = $this->userWithRoles('Admin');
        $box = Item::factory()->container()->create();
        $batteries = Item::factory()->bulk(8)->stockedAt($box)->create();

        $stock = $this->book()->setTarget($batteries, $box, 8);
        $this->assertFalse($stock->isShort());

        $this->book()->remove($actor, $batteries, $box, 8, RetireReason::Consumed);

        $stock = ItemStock::where('item_id', $batteries->id)->sole();
        $this->assertSame(0, $stock->quantity);
        $this->assertTrue($stock->isShort());
        $this->assertSame(['id', 'key', 'quantity', 'target_quantity', 'short', 'place'], array_keys($stock->summary()));
    }

    public function test_only_active_bulk_items_have_a_stock(): void
    {
        $actor = $this->userWithRoles('Admin');

        foreach ([Item::factory()->create(), Item::factory()->bulk()->create(['retired_at' => now()])] as $item) {
            try {
                $this->book()->receive($actor, $item, 1);
                $this->fail('no stock for '.$item->kind->value);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('item', $exception->errors());
            }
        }
        $this->assertSame(0, ItemStock::count());
    }

    public function test_pieces_lie_only_where_items_may_lie(): void
    {
        $actor = $this->userWithRoles('Admin');
        $address = Location::factory()->address()->create(['code' => 'EU']);
        $batteries = Item::factory()->bulk(5)->stockedAt()->create();

        $this->expectException(ValidationException::class);
        $this->book()->transfer($actor, $batteries, null, $address, 1);
    }
}
