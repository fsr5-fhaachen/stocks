<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk items in loans without split off parts: the row of a loan holds how many left and how many
 * are missing, the item keeps its whole stock and its place (see documentation/LOANS.md).
 *
 * Parts that were split off at an issue or for missing pieces go back into their origin: the
 * quantity is added to the origin, the part is retired as merged and keeps its number, so no old
 * label points to nothing. Missing pieces become loan_items.missing_quantity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_items', function (Blueprint $table) {
            // missing pieces of a bulk row after the return ("vermisst")
            $table->unsignedInteger('missing_quantity')->nullable()->after('missing_item_id');
        });

        DB::transaction(function () {
            // parts that left with a loan
            foreach (DB::table('loan_items')->whereNotNull('issued_item_id')->get() as $row) {
                $this->mergeBack((int) $row->issued_item_id, (int) $row->item_id);
            }

            // missing pieces of a bulk item: a part of its own (or the whole item without place)
            $rows = DB::table('loan_items')
                ->join('items', 'items.id', '=', 'loan_items.item_id')
                ->where('items.kind', 'bulk')
                ->whereNotNull('loan_items.missing_item_id')
                ->select('loan_items.*')
                ->get();
            foreach ($rows as $row) {
                $missing = DB::table('items')->find($row->missing_item_id);
                $open = $row->missing_resolution === null && $missing !== null && $missing->retired_at === null;
                $quantity = $missing === null ? 0 : (int) $missing->quantity;
                if ((int) $row->missing_item_id !== (int) $row->item_id) {
                    $this->mergeBack((int) $row->missing_item_id, (int) $row->item_id);
                }
                DB::table('loan_items')->where('id', $row->id)->update([
                    'missing_item_id' => null,
                    'missing_quantity' => $open || $row->missing_resolution !== null ? $quantity : null,
                ]);
            }
        });

        Schema::table('loan_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('issued_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('loan_items', function (Blueprint $table) {
            $table->foreignId('issued_item_id')->nullable()->after('quantity')->constrained('items')->nullOnDelete();
            $table->dropColumn('missing_quantity');
        });
    }

    /**
     * Add an active part to its origin and retire it as merged. A part that is retired already
     * (merged at the stowing, used up, lost) stays as it is.
     */
    private function mergeBack(int $partId, int $originId): void
    {
        $part = DB::table('items')->find($partId);
        if ($part === null || $part->retired_at !== null || $partId === $originId) {
            return;
        }
        $origin = DB::table('items')->find($originId);

        DB::table('items')->where('id', $originId)->increment('quantity', (int) $part->quantity);
        DB::table('items')->where('id', $partId)->update([
            'retired_at' => now(),
            'retired_reason' => 'merged',
            'retired_note' => "Zu {$origin->number} zurückgebucht (Umstellung: Mengenartikel im Verleih ohne Abteilen).",
            'location_id' => null,
            'container_id' => null,
        ]);
    }
};
