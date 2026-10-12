<?php

namespace App\Http\Resources;

use App\Models\Place;
use App\Support\OpeningHours;
use App\Support\OpeningStatus;
use App\Support\OpenNow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * بلوك `hours` في تفاصيل المكان (03 القسم 5.3). السيرفر هو اللي بيحسب `is_open_now` و`status_text`.
 *
 * ```json
 * { "is_open_now": true, "state": "open", "status_text": "مفتوح · بيقفل 11:30 م", "is_ramadan": false,
 *   "week": [ { "day": "sat", "state": "open", "slots": [["09:00","23:30"]], "is_today": true } ] }
 * ```
 *
 * `state` (open_24h | open | closing_soon | closed) هو اللي الموبايل بيحدد منه لون السطر. لو المواعيد
 * فاضية بيرجع `is_open_now`, `state`, `status_text` بـ null و`week` فاضية.
 *
 * @mixin Place
 */
class PlaceHoursResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $now = $request->attributes->get(PlaceCardResource::OPEN_NOW_ATTRIBUTE) ?? OpenNow::at();
        $hours = $now->hoursFor($this->resource);
        $status = OpeningStatus::describe($hours, $now->weekMinute);

        return [
            'is_open_now' => $status['is_open_now'] ?? null,
            'state' => $status['state'] ?? null,
            'status_text' => $status['text'] ?? null,
            'is_ramadan' => $now->usesRamadanHours($this->resource),
            'week' => $this->week($hours, intdiv($now->weekMinute, OpeningHours::MINUTES_PER_DAY)),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $hours
     * @return list<array<string, mixed>>
     */
    private function week(?array $hours, int $todayIndex): array
    {
        if ($hours === null || $hours === []) {
            return [];
        }

        return array_map(function (string $day, int $index) use ($hours, $todayIndex) {
            $entry = is_array($hours[$day] ?? null) ? $hours[$day] : ['state' => 'closed'];
            $state = in_array($entry['state'] ?? null, ['open', '24h'], true) ? $entry['state'] : 'closed';

            $row = ['day' => $day, 'state' => $state];
            if ($state === 'open') {
                $row['slots'] = array_values($entry['slots'] ?? []);
            }
            $row['is_today'] = $index === $todayIndex;

            return $row;
        }, OpeningHours::DAYS, array_keys(OpeningHours::DAYS));
    }
}
