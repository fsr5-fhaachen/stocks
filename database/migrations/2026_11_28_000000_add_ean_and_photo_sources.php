<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Barcode of the packaging at the item (EAN, UPC or GTIN, found again on the next scan) and the
 * origin of photos taken from product databases (credit and licence, documentation/INVENTORY.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('ean', 14)->nullable()->after('number')->index();
        });

        Schema::table('item_photos', function (Blueprint $table) {
            // e.g. "Open Food Facts" or "UPCitemdb"; null for own photos
            $table->string('source', 50)->nullable()->after('thumb_path');
            // page of the product at the source, or the address the image came from
            $table->string('source_url', 500)->nullable()->after('source');
            // e.g. "CC BY-SA 3.0"; null if the source names none
            $table->string('license', 50)->nullable()->after('source_url');
            // e.g. "Open Food Facts contributors"
            $table->string('author', 100)->nullable()->after('license');
            // host the image was downloaded from, to find all images of one shop again
            $table->string('source_host', 150)->nullable()->after('author')->index();
        });
    }

    public function down(): void
    {
        Schema::table('item_photos', function (Blueprint $table) {
            $table->dropIndex(['source_host']);
            $table->dropColumn(['source', 'source_url', 'license', 'author', 'source_host']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['ean']);
            $table->dropColumn('ean');
        });
    }
};
