<?php

use App\Support\Inventory\StockMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Bulk items keep their stock per place instead of split off parts, see StockMigration. Only one
 * way: down() keeps the merged data (restore a backup to go back).
 */
return new class extends Migration
{
    public function up(): void
    {
        StockMigration::run();
    }

    public function down(): void
    {
        // the parts are not split again
    }
};
