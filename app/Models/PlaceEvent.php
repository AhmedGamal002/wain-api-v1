<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * حدث خام من الموبايل (03 القسم 2، 5.5). بيتكتب من `RecordPlaceEvent` على الـ queue، وبيتجمّع كل ليلة في
 * `place_stats_daily` (`PlaceStatsAggregator`) وبيتمسح بعد 90 يوم. للتقارير بس: الـ API ما بيرجّعهوش.
 */
class PlaceEvent extends Model
{
    public const UPDATED_AT = null;

    /** الأنواع اللي الموبايل بيبعتها على `POST /places/{slug}/events` (06 القسم 2.8). */
    public const CLIENT_TYPES = ['view', 'directions', 'call', 'whatsapp', 'instagram', 'menu', 'share'];

    /** كل الأنواع في الجدول: `save` بيتسجل من السيرفر مع المفضلة (F9، أسبوع 11). */
    public const TYPES = [...self::CLIENT_TYPES, 'save'];

    /** حدث `view` بيتحسب مرة واحدة لكل جهاز لكل مكان في الساعة (03 القسم 5.5). */
    public const VIEW_DEDUPE_MINUTES = 60;

    /** الأحداث الخام بتتمسح بعد المدة دي، والمتجمّع بيفضل (06 القسم 4). */
    public const RETENTION_DAYS = 90;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
