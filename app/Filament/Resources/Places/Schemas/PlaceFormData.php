<?php

namespace App\Filament\Resources\Places\Schemas;

use App\Models\Attribute;
use App\Models\Place;
use App\Support\OpeningHoursForm;
use Illuminate\Support\Arr;

/**
 * التحويل بين صف المكان وحقول الفورم، في الاتجاهين (صفحتي الإضافة والتعديل بيستخدموه).
 *
 *  - المواعيد: التخزين (`opening_hours`) ↔ حقول الأيام (`OpeningHoursForm`).
 *  - الخصائص: `attribute_place` ↔ 4 CheckboxLists (`attributes_{group}`).
 *  - `location` و`search_text` بيتشالوا من الفورم: الأول عمود POINT (binary) بيبوّظ تحويل Livewire لـ JSON،
 *    والتاني مشتق ومش بيتعدّل من اللوحة.
 */
final class PlaceFormData
{
    /**
     * @param  array<string, mixed>  $attributes  `$record->attributesToArray()`
     * @return array<string, mixed>
     */
    public static function forFill(array $attributes, ?Place $record = null): array
    {
        $data = Arr::except($attributes, ['location', 'search_text']);

        $data['opening_hours'] = OpeningHoursForm::toFormState(self::hours($attributes['opening_hours'] ?? null));
        $data['ramadan_hours'] = OpeningHoursForm::toFormState(self::hours($attributes['ramadan_hours'] ?? null));
        $data['has_ramadan_hours'] = ! empty($attributes['ramadan_hours']);
        $data['search_aliases'] = array_values((array) ($attributes['search_aliases'] ?? []));

        $selected = $record?->exists ? $record->placeAttributes()->pluck('attributes.id')->all() : [];
        $byGroup = $selected === []
            ? []
            : Attribute::query()->whereIn('id', $selected)->get(['id', 'group'])->groupBy('group');

        foreach (Attribute::GROUPS as $group) {
            $data['attributes_'.$group] = isset($byGroup[$group]) ? $byGroup[$group]->pluck('id')->all() : [];
        }

        return $data;
    }

    /**
     * الفورم الجديد: المواعيد فاضية والمدينة لو واحدة بتتحدد (default الحقل).
     *
     * @return array<string, mixed>
     */
    public static function forCreate(): array
    {
        $data = [
            'opening_hours' => OpeningHoursForm::toFormState(null),
            'ramadan_hours' => OpeningHoursForm::toFormState(null),
            'has_ramadan_hours' => false,
            'search_aliases' => [],
        ];

        foreach (Attribute::GROUPS as $group) {
            $data['attributes_'.$group] = [];
        }

        return $data;
    }

    /**
     * «تكرار» (03 القسم 4.2): مسودة جديدة من مكان موجود، من غير الصور والموقع والتليفون. بيتفتح بيها فورم
     * الإضافة (مش بتتحفظ لوحدها: الموقع إلزامي للمسودة). المدينة بتفضل، والمنطقة بتتشال عشان تتقترح من الـ pin
     * الجديد. الاسمين بيتنسخوا زي ما هما (الفرع بنفس الاسم)، وتحذير التكرار في 200م هو اللي بيمسك الغلط.
     *
     * @return array<string, mixed>
     */
    public static function forDuplicate(Place $source): array
    {
        $data = self::forFill($source->attributesToArray(), $source);

        $keep = [
            'name_ar', 'name_en', 'search_aliases', 'description_ar', 'description_en', 'primary_category_id',
            'city_id', 'price_min', 'price_max', 'instagram', 'facebook_url', 'website', 'menu_url',
            'opening_hours', 'ramadan_hours', 'has_ramadan_hours',
        ];
        foreach (Attribute::GROUPS as $group) {
            $keep[] = 'attributes_'.$group;
        }

        return [
            ...Arr::only($data, $keep),
            'categories' => $source->categories()->pluck('categories.id')->all(),
        ];
    }

    /**
     * ناتج `getState()` ← أعمدة المكان. الحقول اللي مش أعمدة (مجموعات الخصائص وزرار رمضان) بتتشال، والمواعيد
     * بترجع لشكل التخزين، ومواعيد رمضان بتتصفّر لو الزرار مقفول.
     *
     * @param  array<string, mixed>  $data  ناتج `$form->getState()`
     * @param  array<string, mixed>  $rawState  `$livewire->data` (فيه الحقول اللي مش بتتحفظ، زي زرار رمضان)
     * @return array<string, mixed>
     */
    public static function forSave(array $data, array $rawState): array
    {
        foreach (Attribute::GROUPS as $group) {
            unset($data['attributes_'.$group]);
        }
        unset($data['has_ramadan_hours'], $data['suggested_area_id'], $data['map_pin']);

        $data['opening_hours'] = OpeningHoursForm::fromFormState(self::hours($data['opening_hours'] ?? null));
        $data['ramadan_hours'] = ! empty($rawState['has_ramadan_hours'])
            ? OpeningHoursForm::fromFormState(self::hours($data['ramadan_hours'] ?? null))
            : null;

        foreach (['price_min', 'price_max'] as $price) {
            $data[$price] = is_numeric($data[$price] ?? null) ? (int) $data[$price] : null;
        }

        if (array_key_exists('search_aliases', $data)) {
            $aliases = array_values(array_unique(array_filter(array_map(
                fn ($alias) => trim((string) $alias),
                (array) $data['search_aliases'],
            ), fn (string $alias) => $alias !== '')));

            $data['search_aliases'] = $aliases === [] ? null : $aliases;
        }

        // النصوص الفاضية بتتخزّن null (الشروط بتفرّق بين مفيش وفاضي)
        foreach (['description_en', 'address_en', 'google_maps_url', 'facebook_url', 'website', 'menu_url', 'internal_notes'] as $field) {
            if (array_key_exists($field, $data) && ! filled($data[$field])) {
                $data[$field] = null;
            }
        }

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function hours(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }
}
