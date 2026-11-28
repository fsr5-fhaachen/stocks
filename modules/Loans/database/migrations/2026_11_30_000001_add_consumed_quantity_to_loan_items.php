<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consumables at the return: how many were used up, counted on their own beside those that came
 * back ("zurück" and "verbraucht"); what is left of the issued pieces is missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_items', function (Blueprint $table) {
            $table->unsignedInteger('consumed_quantity')->nullable()->after('returned_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('loan_items', function (Blueprint $table) {
            $table->dropColumn('consumed_quantity');
        });
    }
};
