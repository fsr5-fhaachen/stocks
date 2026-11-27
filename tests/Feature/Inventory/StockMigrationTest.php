<?php

namespace Tests\Feature\Inventory;

use App\Enums\RetireReason;
use App\Models\Item;
use App\Models\Location;
use App\Support\Inventory\StockMigration;
use Tests\TestCase;

/**
 * One time move from split off parts to stock per place (App\Support\Inventory\StockMigration).
 * The old state is built with the factory: bulk items with a place and split_from_id.
 */
class StockMigrationTest extends TestCase
{
    /**
     * @return array<string, int>
     */
    private function stockOf(Item $item): array
    {
        return $item->stocks()->orderBy('place_key')->pluck('quantity', 'place_key')->map(fn ($quantity) => (int) $quantity)->all();
    }

    public function test_parts_go_back_into_their_origin_as_stock_at_their_places(): void
    {
        $shelf = Location::factory()->create(['code' => 'R1']);
        $room = Location::factory()->create(['code' => '014']);
        $box = Item::factory()->container()->at($shelf)->create();
        $tape = Item::factory()->bulk(10)->at($shelf)->create(['home_location_id' => $shelf->id]);
        $part = Item::factory()->bulk(4)->at($box)->create(['split_from_id' => $tape->id, 'number' => 'TEST-P1']);
        // a part of a part, and one more at the same shelf
        $partOfPart = Item::factory()->bulk(2)->at($room)->create(['split_from_id' => $part->id, 'number' => 'TEST-P2']);
        $sameShelf = Item::factory()->bulk(1)->at($shelf)->create(['split_from_id' => $tape->id, 'number' => 'TEST-P3']);

        StockMigration::run();

        $tape->refresh();
        $this->assertSame(17, $tape->quantity);
        $this->assertNull($tape->location_id);
        $this->assertTrue($tape->homeLocation->is($shelf));
        $this->assertEquals(["B{$box->id}" => 4, "L{$room->id}" => 2, "L{$shelf->id}" => 11], $this->stockOf($tape));

        foreach ([$part, $partOfPart, $sameShelf] as $merged) {
            $merged->refresh();
            $this->assertSame(RetireReason::Merged, $merged->retired_reason);
            $this->assertSame($tape->id, $merged->merged_into_id);
            $this->assertNull($merged->location_id);
            $this->assertNull($merged->container_id);
        }
        $this->assertDatabaseHas('item_movements', ['item_id' => $tape->id, 'type' => 'merge', 'quantity' => 7, 'note' => 'Aus TEST-P1, TEST-P2 und TEST-P3 zusammengeführt (Bestand je Platz).']);
    }

    public function test_the_oldest_active_part_takes_over_from_a_retired_origin(): void
    {
        $shelf = Location::factory()->create(['code' => 'R1']);
        $origin = Item::factory()->bulk(0)->create(['retired_at' => now(), 'retired_reason' => RetireReason::Consumed]);
        $older = Item::factory()->bulk(3)->at($shelf)->create(['split_from_id' => $origin->id]);
        $younger = Item::factory()->bulk(5)->create(['split_from_id' => $origin->id]);

        StockMigration::run();

        $older->refresh();
        $this->assertNull($older->retired_at);
        $this->assertNull($older->split_from_id);
        $this->assertSame(8, $older->quantity);
        // without place the pieces go into "Eingang"
        $this->assertEquals(['IN' => 5, "L{$shelf->id}" => 3], $this->stockOf($older));
        $this->assertSame($older->id, $younger->refresh()->merged_into_id);
        $this->assertNotNull($origin->refresh()->retired_at);
        $this->assertNull($origin->merged_into_id);
    }

    public function test_single_items_and_retired_bulk_items_stay_as_they_are(): void
    {
        $shelf = Location::factory()->create(['code' => 'R1']);
        $beamer = Item::factory()->at($shelf)->create();
        $gone = Item::factory()->bulk(0)->create(['retired_at' => now(), 'retired_reason' => RetireReason::Consumed]);
        $empty = Item::factory()->bulk(0)->at($shelf)->create();

        StockMigration::run();

        $this->assertTrue($beamer->refresh()->location->is($shelf));
        $this->assertSame([], $this->stockOf($gone));
        // a bulk item with nothing left keeps no row, but stays active
        $this->assertSame([], $this->stockOf($empty));
        $this->assertNull($empty->refresh()->retired_at);
        $this->assertNull($empty->location_id);
    }
}
