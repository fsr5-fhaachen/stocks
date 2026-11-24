<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archive of loans, and contacts that can be deleted although they have loans: the name stays at
 * the loans and events as text, the reference becomes null. Existing data is not changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->index()->after('cancelled_reason');
            $table->foreignId('archived_by')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();
            // filled when the contact is deleted (Modules\Loans\Actions\DeleteContact)
            $table->string('borrower_name', 150)->nullable()->after('borrower_contact_id');
            $table->string('responsible_name', 150)->nullable()->after('responsible_contact_id');

            $table->dropForeign(['borrower_contact_id']);
            $table->dropForeign(['responsible_contact_id']);
            $table->foreign('borrower_contact_id')->references('id')->on('contacts')->nullOnDelete();
            $table->foreign('responsible_contact_id')->references('id')->on('contacts')->nullOnDelete();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->string('responsible_name', 150)->nullable()->after('responsible_contact_id');

            $table->dropForeign(['responsible_contact_id']);
            $table->foreign('responsible_contact_id')->references('id')->on('contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['responsible_contact_id']);
            $table->foreign('responsible_contact_id')->references('id')->on('contacts')->restrictOnDelete();
        });
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('responsible_name');
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->dropForeign(['borrower_contact_id']);
            $table->dropForeign(['responsible_contact_id']);
            $table->dropForeign(['archived_by']);
            $table->foreign('borrower_contact_id')->references('id')->on('contacts')->restrictOnDelete();
            $table->foreign('responsible_contact_id')->references('id')->on('contacts')->restrictOnDelete();
        });
        Schema::table('loans', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
            $table->dropColumn(['archived_at', 'archived_by', 'borrower_name', 'responsible_name']);
        });
    }
};
