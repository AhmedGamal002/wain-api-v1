<?php

namespace App\Http\Requests;

use Closure;

/**
 * بارامترات GET /places/map (03 القسم 5.1، 02 F6): حدود الخريطة `bbox` + نفس فلاتر `GET /places`.
 *
 * الترتيب والموقع والـ pagination مش بتتقبل هنا: الخريطة بترجّع لحد `LIMIT` pin من المستطيل بس، مرتبين
 * بالترتيب المقترح (الباقة أولاً)، والموبايل بيبعت `bbox` بتاع الجزء الظاهر من الخريطة.
 */
class MapPlacesRequest extends PlaceFilterRequest
{
    /** أقصى عدد pins في الرد (02 F6: «الحركة سلسة في وجود 200 pin»). */
    public const LIMIT = 200;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->filterRules(),
            'bbox' => ['bail', 'required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (self::parseBbox((string) $value) === null) {
                    $fail('حدود الخريطة لازم تبقى `minLng,minLat,maxLng,maxLat` بأرقام صحيحة: الطول بين -180 و180، والعرض بين -90 و90، والأصغر قبل الأكبر.');
                }
            }],
        ];
    }

    public function queryParameters(): array
    {
        return [
            'bbox' => [
                'description' => 'حدود الجزء الظاهر من الخريطة: `minLng,minLat,maxLng,maxLat` (الطول ثم العرض، الجنوب الغربي ثم الشمال الشرقي). '
                    .'الطول والعرض بنقطة عشرية، والأصغر قبل الأكبر. البحث عبر خط التاريخ (180) مش مدعوم.',
                'example' => '31.40,29.95,31.55,30.10',
            ],
            ...parent::queryParameters(),
        ];
    }

    /**
     * حدود الخريطة كأرقام: `[minLng, minLat, maxLng, maxLat]`.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function bbox(): array
    {
        return self::parseBbox((string) $this->validated('bbox')) ?? [0.0, 0.0, 0.0, 0.0];
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}|null null لو الشكل أو المدى أو الترتيب غلط
     */
    private static function parseBbox(string $value): ?array
    {
        $parts = explode(',', $value);

        if (count($parts) !== 4) {
            return null;
        }

        $numbers = [];
        foreach ($parts as $part) {
            $part = trim($part);

            if (! preg_match('/^-?\d+(\.\d+)?$/', $part)) {
                return null;
            }

            $numbers[] = (float) $part;
        }

        [$minLng, $minLat, $maxLng, $maxLat] = $numbers;

        $valid = $minLng >= -180 && $maxLng <= 180 && $minLat >= -90 && $maxLat <= 90
            && $minLng < $maxLng && $minLat < $maxLat;

        return $valid ? [$minLng, $minLat, $maxLng, $maxLat] : null;
    }
}
