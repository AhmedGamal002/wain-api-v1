<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رفعة ملف CSV أماكن (A10): المعاينة بعد التحقق، ثم الاستيراد على الـ queue، ثم النتيجة. التفاصيل في
 * `PlaceCsvImporter`، والـ job في `ImportPlacesFromCsv`.
 *
 * @property list<array<string, mixed>> $rows
 */
class PlaceImport extends Model
{
    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_IMPORTING = 'importing';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    /** حالات السطر بعد التحقق. */
    public const ROW_VALID = 'valid';

    public const ROW_DUPLICATE = 'duplicate';

    public const ROW_ERROR = 'error';

    /** نتيجة السطر بعد الاستيراد. */
    public const RESULT_IMPORTED = 'imported';

    public const RESULT_SKIPPED = 'skipped';

    public const RESULT_FAILED = 'failed';

    /** المعاينة اللي ما اتأكدتش والاستيرادات الخلصانة بتتمسح بعد المدة دي (`places:prune-imports`). */
    public const RETENTION_DAYS = 30;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rows' => 'array',
            'ignored_columns' => 'array',
            'include_duplicates' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPreview(): bool
    {
        return $this->status === self::STATUS_PREVIEWED;
    }

    public function isImporting(): bool
    {
        return $this->status === self::STATUS_IMPORTING;
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_DONE, self::STATUS_FAILED], true);
    }

    /**
     * عدد السطور اللي هتتضاف لو اتأكّد دلوقتي (السليم، والمكرر لو اتختار).
     */
    public function importableCount(bool $includeDuplicates): int
    {
        return $this->valid_rows + ($includeDuplicates ? $this->duplicate_rows : 0);
    }
}
