<?php

namespace App\Models;

use App\Support\Inventory\PhotoThumbnail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Photo of an item, stored on the disk "s3" and delivered by App\Http\Controllers\Inventory\ItemPhotoController.
 *
 * @property int $id
 * @property int $item_id
 * @property int|null $condition_change_id photo of a condition change instead of the gallery
 * @property string $path
 * @property string|null $thumb_path small preview for lists, see App\Support\Inventory\PhotoThumbnail
 * @property int $sort
 * @property string|null $source product database the photo came from, null for own photos
 * @property string|null $source_url page of the product at the source, or the address of the image
 * @property string|null $license e.g. "CC BY-SA 3.0"
 * @property string|null $author e.g. "Open Food Facts contributors"
 * @property string|null $source_host host the image was downloaded from
 */
class ItemPhoto extends Model
{
    /**
     * Disk of all photos.
     *
     * @var string
     */
    public const DISK = 's3';

    /**
     * Photos per item in the gallery.
     *
     * @var int
     */
    public const MAX_PER_ITEM = 5;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'condition_change_id',
        'path',
        'thumb_path',
        'sort',
        'source',
        'source_url',
        'license',
        'author',
        'source_host',
    ];

    /**
     * Fields of the origin of a photo from a product database.
     *
     * @var list<string>
     */
    public const SOURCE_FIELDS = ['source', 'source_url', 'license', 'author', 'source_host'];

    /**
     * Store uploaded photos on S3 under "items/<id>/<random name>".
     *
     * All files are stored before any row is created. If S3 can not store one of them (e.g. it is
     * not reachable), the files stored so far are removed again and nothing is saved.
     *
     * @param  list<UploadedFile>  $files
     * @param  ItemConditionChange|null  $conditionChange  photos of a condition change instead of the gallery
     * @param  array<int, array<string, string|null>|null>  $sources  origin per file (same keys as $files), see SOURCE_FIELDS
     * @return list<self>
     *
     * @throws ValidationException
     */
    public static function uploadAll(Item $item, array $files, ?ItemConditionChange $conditionChange = null, array $sources = []): array
    {
        $paths = [];
        $origins = [];
        foreach ($files as $key => $file) {
            $origins[] = array_intersect_key($sources[$key] ?? [], array_flip(self::SOURCE_FIELDS));
            $path = $file->store("items/{$item->id}", self::DISK);
            if ($path === false) {
                if ($paths) {
                    Storage::disk(self::DISK)->delete($paths);
                }

                throw ValidationException::withMessages(['photos' => 'Die Fotos konnten nicht gespeichert werden, der Speicher ist gerade nicht erreichbar. Versuch es später noch einmal.']);
            }
            $paths[] = $path;
        }

        $sort = (int) self::where('item_id', $item->id)->max('sort');

        return array_map(function (string $path, array $origin) use ($item, $conditionChange, &$sort) {
            $photo = new self([...$origin, 'condition_change_id' => $conditionChange?->id, 'path' => $path, 'thumb_path' => PhotoThumbnail::create($path), 'sort' => ++$sort]);
            $photo->item()->associate($item)->save();

            return $photo;
        }, $paths, $origins);
    }

    /**
     * Copy the photo (with its file) to another item, e.g. to a split off part. Returns null if
     * S3 could not copy the file; the caller goes on without this photo.
     */
    public function copyTo(Item $item): ?self
    {
        $path = "items/{$item->id}/".basename($this->path);
        if (! Storage::disk(self::DISK)->copy($this->path, $path)) {
            return null;
        }

        $photo = new self([...$this->only(self::SOURCE_FIELDS), 'path' => $path, 'thumb_path' => PhotoThumbnail::create($path), 'sort' => $this->sort]);
        $photo->item()->associate($item)->save();

        return $photo;
    }

    /**
     * Delete the photo and its files.
     */
    public function deleteWithFile(): void
    {
        $this->delete();
        Storage::disk(self::DISK)->delete($this->files());
    }

    /**
     * Files of the photo on S3: the photo and its preview.
     *
     * @return list<string>
     */
    public function files(): array
    {
        return array_values(array_filter([$this->path, $this->thumb_path]));
    }

    /**
     * Files of several photos, e.g. before their rows are deleted.
     *
     * @param  iterable<self>  $photos
     * @return list<string>
     */
    public static function filesOf(iterable $photos): array
    {
        $files = [];
        foreach ($photos as $photo) {
            $files = [...$files, ...$photo->files()];
        }

        return $files;
    }

    /**
     * Path of the preview. A photo without preview (made before previews existed, or S3 was not
     * reachable) gets it now; until that works, the photo itself is used.
     */
    public function thumbnailPath(): string
    {
        if ($this->thumb_path === null && ($thumb = PhotoThumbnail::create($this->path)) !== null) {
            $this->forceFill(['thumb_path' => $thumb])->saveQuietly();
        }

        return $this->thumb_path ?? $this->path;
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Url of the photo in Stocks (the bucket stays private).
     */
    public function url(): string
    {
        return route('inventory.photos.show', $this, absolute: false);
    }

    /**
     * Credit of a photo from a product database for the small "©" on the photo, null for own photos.
     *
     * @return array{source: string, author: string|null, license: string|null, url: string|null, host: string|null}|null
     */
    public function credit(): ?array
    {
        if ($this->source === null) {
            return null;
        }

        return ['source' => $this->source, 'author' => $this->author, 'license' => $this->license, 'url' => $this->source_url, 'host' => $this->source_host];
    }

    /**
     * Url of the small preview, for lists.
     */
    public function thumbUrl(): string
    {
        return route('inventory.photos.show', [$this, 'thumb' => 1], absolute: false);
    }
}
