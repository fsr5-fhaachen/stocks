<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buildings (new level between address and room) can be left out of the codes of their children.
 * Existing locations are not changed: there are no buildings yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            // only used by buildings: their code is part of the codes below them
            $table->boolean('show_in_code')->default(false)->after('full_code');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('show_in_code');
        });
    }
};
