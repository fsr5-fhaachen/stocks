<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk rows remember the place their pieces leave from at the issue ("Quelle", a place key of
 * item_stocks: "L<id>", "B<id>" or "IN" for "Eingang"). Rows of box contents get their box.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_items', function (Blueprint $table) {
            $table->string('source_key', 20)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('loan_items', function (Blueprint $table) {
            $table->dropColumn('source_key');
        });
    }
};
