<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk items with their stock spread over places (documentation/INVENTORY.md, "Bestand"): one row
 * per place, the virtual compartment "Eingang" has no place. Components ("Bestandteile") of sets,
 * single items and boxes, minimum quantity of bulk items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            // both empty: the virtual compartment "Eingang"
            $table->foreignId('location_id')->nullable()->constrained('locations');
            $table->foreignId('container_id')->nullable()->constrained('items');
            // "L<id>", "B<id>" or "IN", one row per item and place (App\Models\ItemStock::keyOf)
            $table->string('place_key', 20);
            $table->unsignedInteger('quantity')->default(0);
            // "Sollmenge" of this place; a row with it stays at 0
            $table->unsignedInteger('target_quantity')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'place_key']);
        });

        Schema::create('item_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('name', 100);
            $table->unsignedInteger('quantity')->default(1);
            // missing at the item now; of a set: over all sets of the bulk item
            $table->unsignedInteger('missing')->default(0);
            $table->timestamps();
        });

        Schema::table('items', function (Blueprint $table) {
            $table->unsignedInteger('min_quantity')->nullable()->after('quantity');
            // item or box with components: last checked as complete
            $table->timestamp('components_checked_at')->nullable()->after('min_quantity');
            // a split off part that went into another item when the stock moved to places;
            // scanning its number leads there (App\Support\Inventory\CodeLookup)
            $table->foreignId('merged_into_id')->nullable()->after('split_from_id')->constrained('items')->nullOnDelete();
        });

        Schema::table('item_units', function (Blueprint $table) {
            $table->boolean('has_components')->default(false)->after('is_consumable');
        });
        DB::table('item_units')->where('name', 'like', 'Set%')->update(['has_components' => true]);
    }

    public function down(): void
    {
        Schema::table('item_units', function (Blueprint $table) {
            $table->dropColumn('has_components');
        });
        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_id');
            $table->dropColumn(['min_quantity', 'components_checked_at']);
        });
        Schema::dropIfExists('item_components');
        Schema::dropIfExists('item_stocks');
    }
};
