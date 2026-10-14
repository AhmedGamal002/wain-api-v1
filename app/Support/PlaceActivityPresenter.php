<?php

namespace App\Support;

use App\Models\Place;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

/**
 * بيحوّل سطر سجل التغييرات (spatie activity_log) لنوع تغيير بالعربي وسطور «الحقل: القديم ← الجديد» (03 القسم
 * 4.7). القيم الخام بتتحوّل لأسماء مفهومة (الحالة بالعربي، والتاريخ بتوقيت القاهرة، والـ id بيفضل رقم لأن
 * الاسم اللي اتغيّر ممكن يتغيّر بعدها).
 */
final class PlaceActivityPresenter
{
    /** اسم الحقل في السجل ← اسمه في اللوحة. */
    public const FIELD_LABELS = [
        'slug' => 'الرابط المختصر',
        'name_ar' => 'الاسم بالعربي',
        'name_en' => 'الاسم بالإنجليزي',
        'search_aliases' => 'كلمات البحث',
        'description_ar' => 'الوصف بالعربي',
        'description_en' => 'الوصف بالإنجليزي',
        'primary_category_id' => 'التصنيف الرئيسي',
        'city_id' => 'المدينة',
        'area_id' => 'المنطقة',
        'address_ar' => 'العنوان بالعربي',
        'address_en' => 'العنوان بالإنجليزي',
        'latitude' => 'خط العرض',
        'longitude' => 'خط الطول',
        'google_maps_url' => 'رابط Google Maps',
        'phone' => 'التليفون',
        'whatsapp' => 'الواتساب',
        'instagram' => 'إنستجرام',
        'facebook_url' => 'فيسبوك',
        'website' => 'الموقع',
        'menu_url' => 'رابط المنيو',
        'price_min' => 'السعر من',
        'price_max' => 'السعر إلى',
        'opening_hours' => 'المواعيد',
        'ramadan_hours' => 'مواعيد رمضان',
        'cover_photo_id' => 'صورة الغلاف',
        'status' => 'الحالة',
        'status_reason' => 'السبب',
        'published_at' => 'تاريخ النشر',
        'closed_at' => 'تاريخ القفل',
        'listing_tier' => 'الباقة',
        'tier_starts_at' => 'بداية الباقة',
        'tier_ends_at' => 'نهاية الباقة',
        'verified_at' => 'آخر تأكيد للبيانات',
        'claimed_by' => 'صاحب المكان',
        'internal_notes' => 'ملاحظات داخلية',
    ];

    private const TIERS = ['free' => 'مجاني', 'featured' => 'مميز', 'premium' => 'بريميوم'];

    private const TIER_FIELDS = ['listing_tier', 'tier_starts_at', 'tier_ends_at'];

    /** حقول طويلة: بيتكتب إنها اتغيّرت من غير القيمتين. */
    private const LONG_FIELDS = ['description_ar', 'description_en', 'opening_hours', 'ramadan_hours', 'search_aliases', 'internal_notes'];

    /** إنشاء، تعديل، تغيير حالة، تغيير باقة، صور، حذف، استرجاع. */
    public static function type(Activity $activity): string
    {
        $changed = array_keys((array) ($activity->properties['attributes'] ?? []));

        return match ($activity->event) {
            'created' => 'إنشاء',
            'photos' => 'صور',
            'deleted' => 'حذف',
            'restored' => 'استرجاع',
            default => match (true) {
                in_array('status', $changed, true) => 'تغيير حالة',
                array_intersect(self::TIER_FIELDS, $changed) !== [] => 'تغيير باقة',
                default => 'تعديل',
            },
        };
    }

    /**
     * سطور التغيير: «الحالة: مسودة ← منشور». للإنشاء بتتعرض القيم الجديدة بس. السجل اللي مالوش حقول
     * (صور، حذف) بيرجّع وصفه.
     *
     * @return list<string>
     */
    public static function lines(Activity $activity): array
    {
        $new = (array) ($activity->properties['attributes'] ?? []);
        $old = (array) ($activity->properties['old'] ?? []);
        $lines = [];

        foreach ($new as $field => $value) {
            $label = self::FIELD_LABELS[$field] ?? $field;

            if (in_array($field, self::LONG_FIELDS, true)) {
                $lines[] = $label.': اتغيّر';

                continue;
            }

            $after = self::format($field, $value);

            $lines[] = array_key_exists($field, $old)
                ? $label.': '.self::format($field, $old[$field]).' ← '.$after
                : $label.': '.$after;
        }

        if (filled($activity->properties['note'] ?? null)) {
            $lines[] = 'ملاحظة: '.$activity->properties['note'];
        }

        if ($lines === [] && filled($activity->description)) {
            $lines[] = (string) $activity->description;
        }

        return $lines;
    }

    private static function format(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'فاضي';
        }

        return match (true) {
            $field === 'status' => Place::STATUS_LABELS[$value] ?? (string) $value,
            $field === 'listing_tier' => self::TIERS[$value] ?? (string) $value,
            in_array($field, ['published_at', 'verified_at', 'tier_starts_at', 'tier_ends_at'], true) => self::date($value, true),
            $field === 'closed_at' => self::date($value, false),
            is_array($value) => implode('، ', array_map('strval', $value)),
            default => (string) $value,
        };
    }

    private static function date(mixed $value, bool $withTime): string
    {
        try {
            $date = Carbon::parse((string) $value)->timezone('Africa/Cairo');

            return $date->format($withTime ? 'Y-m-d H:i' : 'Y-m-d');
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}
