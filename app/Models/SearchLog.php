<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * عملية بحث اتسجّلت (`log=1`، 06 القسم 5). للتقارير بس: الـ API ما بيرجّعهاش لحد.
 */
class SearchLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'results_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
