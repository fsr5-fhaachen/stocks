<?php

use App\Support\Inventory\PhotoThumbnail;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Small previews of photos for lists (App\Support\Inventory\PhotoThumbnail). The previews of the
 * existing photos are made right here. This step never fails: the container runs the migrations
 * at its start and would not start otherwise. A preview that can not be made now (S3 not
 * reachable, broken image) is made the first time the photo is shown in a list.
 */
return new class extends Migration
{
    /**
     * Failures in a row after which the rest is left for later: S3 is probably not reachable,
     * waiting for every photo would only delay the start.
     *
     * @var int
     */
    private const MAX_FAILURES_IN_A_ROW = 5;

    public function up(): void
    {
        Schema::table('item_photos', function (Blueprint $table) {
            $table->string('thumb_path')->nullable()->after('path');
        });

        $failures = 0;
        DB::table('item_photos')->whereNull('thumb_path')->orderBy('id')->chunkById(100, function ($photos) use (&$failures) {
            foreach ($photos as $photo) {
                $thumb = PhotoThumbnail::create($photo->path);
                if ($thumb === null) {
                    if (++$failures >= self::MAX_FAILURES_IN_A_ROW) {
                        Log::warning('Vorschaubilder: abgebrochen, sie entstehen beim ersten Anzeigen.');

                        return false;
                    }

                    continue;
                }

                $failures = 0;
                DB::table('item_photos')->where('id', $photo->id)->update(['thumb_path' => $thumb]);
            }

            return true;
        });
    }

    public function down(): void
    {
        Schema::table('item_photos', function (Blueprint $table) {
            $table->dropColumn('thumb_path');
        });
    }
};
