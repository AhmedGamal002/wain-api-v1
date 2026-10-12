<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * المنطقة (A4): التجمع الخامس، الزمالك... بتتعرض في كارت المكان.
 */
class Area extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * الاسم بلغة الطلب، ولو الإنجليزي فاضي بيرجع العربي (03 القسم 5.1).
     */
    public function localizedName(): string
    {
        if (app()->getLocale() === 'en' && filled($this->name_en)) {
            return $this->name_en;
        }

        return $this->name_ar;
    }
}
