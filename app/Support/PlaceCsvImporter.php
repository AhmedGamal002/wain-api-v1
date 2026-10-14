<?php

namespace App\Support;

use App\Events\PlaceChanged;
use App\Filament\Resources\Places\PlaceResource;
use App\Models\Place;
use App\Models\PlaceImport;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\CauserResolver;
use Throwable;

/**
 * استيراد أماكن من CSV (A10، 02): الخطوتين اللي في المواصفات.
 *
 *  1. `preview()`: بيقرا الملف ويتحقق من كل سطر (`PlaceCsvRowParser`) وبيعلّم كل سطر سليم أو غلط أو مكرر
 *     محتمل (200 متر وشبه الاسم، قصاد الداتابيز وقصاد سطور الملف نفسه)، من غير ما يكتب أي مكان.
 *  2. `run()` (من الـ job): السليم بس بيتضاف **مسودات** (والمكرر لو اللي أكّد اختار)، والغلط بيفضل في ملف
 *     الأخطاء (`errorsCsv()`). الصور مش بتتستورد.
 *
 * `run()` بيكمّل من حيث وقف: السطر اللي اتسجّل له `result` بيتعدّى، فإعادة تشغيل الـ job ما بتكرّرش أماكن.
 */
final class PlaceCsvImporter
{
    /** بيحفظ التقدّم كل كام سطر، عشان صفحة الاستيراد تعرض عدّاد والـ job المقطوع يكمّل. */
    private const SAVE_EVERY = 25;

    /** أعمدة المكان اللي بتتكتب مباشرة من السطر المتحقق منه. */
    private const COLUMNS = [
        'name_ar', 'name_en', 'search_aliases', 'description_ar', 'description_en', 'primary_category_id', 'city_id',
        'area_id', 'address_ar', 'address_en', 'latitude', 'longitude', 'google_maps_url', 'phone', 'whatsapp',
        'instagram', 'facebook_url', 'website', 'menu_url', 'price_min', 'price_max', 'opening_hours',
        'ramadan_hours', 'internal_notes',
    ];

    /**
     * يقرا ويتحقق ويحفظ المعاينة (من غير ما يضيف مكان).
     *
     * @throws PlaceCsvException لو الملف نفسه مش صالح
     */
    public function preview(string $contents, string $fileName, ?User $user): PlaceImport
    {
        $read = PlaceCsv::read($contents);

        if ($read['error'] !== null) {
            throw new PlaceCsvException($read['error']);
        }

        $parser = new PlaceCsvRowParser;
        $duplicates = app(PlaceDuplicates::class);

        $rows = [];
        // السطور السليمة والمكررة قبل السطر الحالي، لمقارنة التكرار جوه الملف
        $seen = [];

        foreach ($read['rows'] as $line => $cells) {
            $parsed = $parser->parse($cells);
            $data = $parsed['data'];
            $label = $cells['name_ar'] !== '' ? $cells['name_ar'] : ($cells['name_en'] !== '' ? $cells['name_en'] : "سطر {$line}");

            if ($parsed['errors'] !== []) {
                $rows[] = [
                    'line' => $line, 'status' => PlaceImport::ROW_ERROR, 'label' => $label,
                    'errors' => $parsed['errors'], 'notes' => [], 'raw' => $cells, 'data' => null,
                    'result' => null, 'place_id' => null, 'failure' => null,
                ];

                continue;
            }

            $notes = [];

            foreach ($duplicates->near($data['latitude'], $data['longitude'], $data['name_ar'], $data['name_en']) as $match) {
                $notes[] = "شبه «{$match['name_ar']}» الموجود (#{$match['id']}) على بعد {$match['distance_m']} متر";
            }

            foreach ($seen as $earlier) {
                if (PlaceLocation::haversineMeters($data['latitude'], $data['longitude'], $earlier['latitude'], $earlier['longitude']) <= PlaceDuplicates::RADIUS_METERS
                    && PlaceDuplicates::sameName($data['name_ar'], $data['name_en'], $earlier['name_ar'], $earlier['name_en'])) {
                    $notes[] = "شبه السطر {$earlier['line']} في نفس الملف";
                }
            }

            $seen[] = ['line' => $line, ...array_intersect_key($data, array_flip(['name_ar', 'name_en', 'latitude', 'longitude']))];

            $rows[] = [
                'line' => $line,
                'status' => $notes === [] ? PlaceImport::ROW_VALID : PlaceImport::ROW_DUPLICATE,
                'label' => $label, 'errors' => [], 'notes' => $notes,
                // الأصل مطلوب بس لملف الأخطاء (الغلط والمكرر)
                'raw' => $notes === [] ? null : $cells,
                'data' => $data, 'result' => null, 'place_id' => null, 'failure' => null,
            ];
        }

        $count = fn (string $status) => count(array_filter($rows, fn (array $row) => $row['status'] === $status));

        return PlaceImport::query()->create([
            'user_id' => $user?->id,
            'file_name' => mb_substr($fileName, 0, 255),
            'status' => PlaceImport::STATUS_PREVIEWED,
            'total_rows' => count($rows),
            'valid_rows' => $count(PlaceImport::ROW_VALID),
            'duplicate_rows' => $count(PlaceImport::ROW_DUPLICATE),
            'error_rows' => $count(PlaceImport::ROW_ERROR),
            'rows' => $rows,
            'ignored_columns' => $read['ignored'] === [] ? null : $read['ignored'],
        ]);
    }

    /**
     * بيضيف السطور المتأكّدة كمسودات. بيتنادى من الـ job بعد ما الحالة بقت `importing`.
     */
    public function run(PlaceImport $import): void
    {
        $user = $import->user;
        $rows = $import->rows;
        $imported = 0;
        $resolver = app(CauserResolver::class);

        // السجل (activity_log) لازم يقول مين استورد، ومفيش مستخدم داخل في الـ queue
        $resolver->setCauser($user);

        try {
            foreach ($rows as $index => $row) {
                if (($row['result'] ?? null) !== null) {
                    $imported += $row['result'] === PlaceImport::RESULT_IMPORTED ? 1 : 0;

                    continue;
                }

                if (! $this->shouldImport($row, $import->include_duplicates)) {
                    $rows[$index]['result'] = PlaceImport::RESULT_SKIPPED;

                    continue;
                }

                try {
                    $place = $this->create($row['data'], $user);

                    $rows[$index]['result'] = PlaceImport::RESULT_IMPORTED;
                    $rows[$index]['place_id'] = $place->id;
                    $imported++;
                } catch (Throwable $e) {
                    report($e);

                    $rows[$index]['result'] = PlaceImport::RESULT_FAILED;
                    $rows[$index]['failure'] = 'حصل خطأ وقت الحفظ، حاول تستورد السطر ده لوحده';
                }

                if ($imported % self::SAVE_EVERY === 0) {
                    $import->forceFill(['rows' => $rows, 'imported_rows' => $imported])->save();
                }
            }
        } finally {
            $resolver->setCauser(null);
        }

        $import->forceFill([
            'rows' => $rows,
            'imported_rows' => $imported,
            'status' => PlaceImport::STATUS_DONE,
            'finished_at' => now(),
        ])->save();

        self::notify($import);
    }

    /**
     * ملف الأخطاء: السطور اللي ما اتضافتش (غلط، أو مكرر اتخطّى، أو فشل وقت الحفظ) بنفس أعمدة القالب ومعاها
     * رقم السطر والسبب، فبعد التصليح الملف نفسه بيترفع تاني.
     */
    public static function errorsCsv(PlaceImport $import): string
    {
        $columns = PlaceCsv::columns();
        $rows = [];

        foreach ($import->rows as $row) {
            $notImported = ($row['status'] ?? null) === PlaceImport::ROW_ERROR
                || in_array($row['result'] ?? null, [PlaceImport::RESULT_SKIPPED, PlaceImport::RESULT_FAILED], true);

            if (! $notImported) {
                continue;
            }

            $reason = match ($row['status']) {
                PlaceImport::ROW_ERROR => implode(' | ', $row['errors']),
                default => ($row['result'] ?? null) === PlaceImport::RESULT_FAILED
                    ? (string) ($row['failure'] ?? 'فشل الحفظ')
                    : 'مكرر محتمل: '.implode(' | ', $row['notes']),
            };

            $raw = (array) ($row['raw'] ?? []);

            $rows[] = [
                (string) $row['line'],
                PlaceCsv::safeCell($reason),
                ...array_map(fn (string $column) => PlaceCsv::safeCell((string) ($raw[$column] ?? '')), $columns),
            ];
        }

        return PlaceCsv::write(['row', 'reason', ...$columns], $rows);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function shouldImport(array $row, bool $includeDuplicates): bool
    {
        return match ($row['status'] ?? null) {
            PlaceImport::ROW_VALID => true,
            PlaceImport::ROW_DUPLICATE => $includeDuplicates,
            default => false,
        };
    }

    /**
     * مسودة جديدة بالتصنيفات والخصائص، وبعدها `PlaceChanged` تاني عشان الـ listeners (البحث والمواعيد والـ
     * cache) تشوف العلاقات (الـ Observer اتنادى قبل ما تتحفظ). نفس مسار فورم الإضافة.
     *
     * @param  array<string, mixed>  $data
     */
    private function create(array $data, ?User $user): Place
    {
        $place = DB::transaction(function () use ($data, $user): Place {
            $place = new Place(array_intersect_key($data, array_flip(self::COLUMNS)));
            $place->status = Place::STATUS_DRAFT;
            $place->created_by = $user?->id;
            $place->updated_by = $user?->id;
            $place->save();

            $place->categories()->sync($data['category_ids']);
            $place->placeAttributes()->sync($data['attribute_ids']);

            return $place;
        });

        PlaceChanged::dispatch($place->refresh());

        return $place;
    }

    /**
     * إشعار الجرس لمن رفع الملف، بنتيجة الاستيراد ورابط الصفحة. فشل الإشعار ما بيبوّظش الاستيراد.
     */
    public static function notify(PlaceImport $import): void
    {
        if ($import->user === null) {
            return;
        }

        try {
            $failed = $import->status === PlaceImport::STATUS_FAILED;
            $skipped = $import->total_rows - $import->imported_rows;

            $notification = Notification::make()
                ->title($failed
                    ? 'الاستيراد فشل'
                    : "اتستورد {$import->imported_rows} مكان كمسودات"
                        .($skipped > 0 ? " واتعدّى {$skipped}" : ''))
                ->body($failed ? $import->error_message : $import->file_name)
                ->actions([
                    Action::make('open')
                        ->label('عرض النتيجة')
                        // panel صريح: الـ queue مفيهوش panel حالي
                        ->url(PlaceResource::getUrl('import', ['import' => $import->id], panel: 'admin'))
                        ->markAsRead(),
                ]);

            ($failed || $skipped > 0 ? $notification->warning() : $notification->success())->sendToDatabase($import->user);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
