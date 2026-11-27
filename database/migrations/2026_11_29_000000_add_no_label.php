<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Items and locations can be marked as "Kein Etikett nötig": they are left out of "Etikett fehlt"
 * and of printing all missing labels. Existing rows keep needing a label.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('no_label')->default(false)->after('label_printed_at');
        });
        Schema::table('locations', function (Blueprint $table) {
            $table->boolean('no_label')->default(false)->after('label_printed_at');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('no_label');
        });
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('no_label');
        });
    }
};
