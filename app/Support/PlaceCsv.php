<?php

namespace App\Support;

use League\Csv\Reader;
use Throwable;

/**
 * قالب CSV الأماكن (A10، 02 «استيراد وتصدير CSV»): الأعمدة، وقراية الملف (الترميز والفاصل والعناوين)، وكتابته
 * للقالب والتصدير. التحقق من كل سطر في `PlaceCsvRowParser`، والإنشاء في `PlaceCsvImporter`.
 *
 * الأعمدة بنفس أسماء حقول A1. التصنيفات والخصائص بالـ slug (أو الاسم) مفصولة بـ `;`، وكلمات البحث كمان بـ `;`.
 * المواعيد عمود لكل يوم (`hours_sat` … `hours_fri`، ومثلها `ramadan_hours_*`): `09:00-23:30` أو فترتين
 * `09:00-14:00;18:00-02:00` أو `closed` أو `24h`. الصور مش بتتستورد (بتتضاف يدوياً بعد كده).
 */
final class PlaceCsv
{
    /** أقصى عدد سطور في الملف (02 A10). */
    public const MAX_ROWS = 500;

    /** أقصى حجم للملف بالبايت: 500 سطر بوصف عربي وإنجليزي بتوصل لحوالي 1.5MB. */
    public const MAX_BYTES = 3 * 1024 * 1024;

    public const LIST_SEPARATOR = ';';

    /** @var list<string> الأعمدة قبل المواعيد */
    private const LEADING = [
        'name_ar', 'name_en', 'search_aliases', 'description_ar', 'description_en',
        'categories', 'primary_category', 'attributes', 'price_min', 'price_max',
        'city', 'area', 'address_ar', 'address_en', 'latitude', 'longitude', 'google_maps_url',
        'phone', 'whatsapp', 'instagram', 'facebook_url', 'website', 'menu_url',
    ];

    /** أعمدة مرجعية بتتكتب في التصدير بس وبيتجاهلها الاستيراد من غير تحذير. */
    public const REFERENCE_COLUMNS = ['id', 'slug', 'status'];

    /** أعمدة ملف الأخطاء قبل أعمدة القالب (رقم السطر والسبب): بيتجاهلها الاستيراد، فالملف بعد التصليح بيترفع تاني. */
    public const ERROR_FILE_COLUMNS = ['row', 'reason'];

    /** الأعمدة اللي لازم تكون موجودة في الملف (الباقي اختياري، والتصنيف: واحد من `categories` و`primary_category`). */
    public const REQUIRED_COLUMNS = ['name_ar', 'name_en', 'area', 'address_ar', 'latitude', 'longitude'];

    /**
     * أعمدة القالب بالترتيب.
     *
     * @return list<string>
     */
    public static function columns(): array
    {
        return [
            ...self::LEADING,
            ...self::hoursColumns('hours_'),
            ...self::hoursColumns('ramadan_hours_'),
            'internal_notes',
        ];
    }

    /**
     * @return list<string>
     */
    public static function hoursColumns(string $prefix): array
    {
        return array_map(fn (string $day) => $prefix.$day, OpeningHours::DAYS);
    }

    /**
     * دليل الأعمدة لصفحة الاستيراد: العمود، إلزامي؟، الصيغة، مثال.
     *
     * @return list<array{column: string, required: bool, format: string, example: string}>
     */
    public static function guide(): array
    {
        $rows = [
            ['name_ar', true, 'من 2 لـ 120 حرف', 'سيلانترو'],
            ['name_en', true, 'من 2 لـ 120 حرف. بيتعمل منه الرابط', 'Cilantro'],
            ['search_aliases', false, 'لحد 10 كلمات مفصولة بـ ;، كل واحدة لحد 40 حرف', 'silantro;سيلنترو'],
            ['description_ar', false, 'لحد 1000 حرف (50 على الأقل للنشر)', ''],
            ['description_en', false, 'لحد 1000 حرف', ''],
            ['categories', true, 'من 1 لـ 3 تصنيفات بالـ slug أو الاسم مفصولة بـ ;', 'cafes;desserts'],
            ['primary_category', false, 'واحد من اللي فوق. لو فاضي بيبقى الأول', 'cafes'],
            ['attributes', false, 'خصائص بالـ slug أو الاسم مفصولة بـ ;', 'wifi;outdoor-seating'],
            ['price_min', false, 'جنيه للفرد، من 0 لـ 10000', '150'],
            ['price_max', false, 'جنيه للفرد، مش أقل من price_min', '300'],
            ['city', false, 'slug المدينة أو اسمها. لو فاضي بتتحدد من المنطقة', 'cairo'],
            ['area', true, 'slug المنطقة أو اسمها', 'new-cairo'],
            ['address_ar', true, 'لحد 255 حرف', 'شارع التسعين، جنب كايرو فيستيفال'],
            ['address_en', false, 'لحد 255 حرف', ''],
            ['latitude', true, 'خط العرض بنقطة عشرية، جوه مصر', '30.0283'],
            ['longitude', true, 'خط الطول بنقطة عشرية، جوه مصر', '31.4913'],
            ['google_maps_url', false, 'رابط https لحد 500 حرف', ''],
            ['phone', false, 'موبايل 01… أو أرضي بكود المحافظة أو خط ساخن 5 أرقام', '01001234567'],
            ['whatsapp', false, 'موبايل مصري', '01001234567'],
            ['instagram', false, 'اسم الحساب أو @الاسم أو الرابط', 'cilantrocafe'],
            ['facebook_url', false, 'رابط https', ''],
            ['website', false, 'رابط https', ''],
            ['menu_url', false, 'رابط https لحد 500 حرف', ''],
            ['hours_sat … hours_fri', false, 'عمود لكل يوم: 09:00-23:30، أو فترتين 09:00-14:00;18:00-02:00، أو closed، أو 24h. «إلى» الأصغر من «من» معناها بعد نص الليل', '09:00-23:30'],
            ['ramadan_hours_sat … ramadan_hours_fri', false, 'نفس صيغة المواعيد، لو للمكان مواعيد خاصة في رمضان', ''],
            ['internal_notes', false, 'ملاحظات داخلية لحد 2000 حرف (مش بتظهر في التطبيق)', ''],
        ];

        return array_map(fn (array $row) => [
            'column' => $row[0], 'required' => $row[1], 'format' => $row[2], 'example' => $row[3],
        ], $rows);
    }

    /**
     * ملف CSV (UTF-8 بـ BOM عشان Excel يفتح العربي صح) من صفوف جاهزة.
     *
     * @param  list<string>  $header
     * @param  iterable<list<string>>  $rows
     */
    public static function write(array $header, iterable $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header, ',', '"', '\\');

        foreach ($rows as $row) {
            fputcsv($out, $row, ',', '"', '\\');
        }

        rewind($out);
        $contents = (string) stream_get_contents($out);
        fclose($out);

        return $contents;
    }

    /**
     * قالب فاضي: العناوين بس.
     */
    public static function template(): string
    {
        return self::write(self::columns(), []);
    }

    /**
     * خلية نص حر في الملف الناتج: أي خلية بتبدأ بـ `= + - @` أو tab أو CR بيتحط قبلها `'` عشان Excel ما ينفّذهاش كمعادلة.
     * الاستيراد بيشيل الـ `'` ده (`readCell`).
     */
    public static function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /**
     * عكس `safeCell`.
     */
    public static function readCell(string $value): string
    {
        $value = trim($value);

        return preg_match("/^'[=+\\-@]/", $value) === 1 ? substr($value, 1) : $value;
    }

    /**
     * يقرا الملف: بيتحقق من الترميز والعناوين وعدد السطور، وبيرجّع السطور كـ `[line => [column => cell]]`
     * (رقم السطر زي ما بيظهر في Excel: العناوين سطر 1).
     *
     * @return array{rows: array<int, array<string, string>>, ignored: list<string>, error: ?string}
     */
    public static function read(string $contents): array
    {
        $fail = fn (string $message) => ['rows' => [], 'ignored' => [], 'error' => $message];

        if ($contents === '') {
            return $fail('الملف فاضي.');
        }

        if (strlen($contents) > self::MAX_BYTES) {
            return $fail('الملف أكبر من '.(self::MAX_BYTES / 1024 / 1024).' ميجا.');
        }

        $text = self::toUtf8($contents);

        if ($text === null) {
            return $fail('ما قدرناش نقرا ترميز الملف. احفظه من Excel بصيغة «CSV UTF-8».');
        }

        try {
            $reader = Reader::createFromString($text);
            $reader->setDelimiter(self::delimiter($text));
            $reader->setHeaderOffset(0);

            // عمود من غير اسم (فاصلة زيادة في آخر العناوين) بيتسمّى لوحده عشان ما يتحسبش مكرر
            $header = array_map(
                fn ($name, $index) => self::headerName((string) $name) ?: '__empty_'.$index,
                $reader->getHeader(),
                array_keys($reader->getHeader()),
            );
        } catch (Throwable) {
            return $fail('الملف مش CSV صالح.');
        }

        if (count(array_unique($header)) !== count($header)) {
            return $fail('فيه عمود مكرر في عناوين الملف.');
        }

        $known = [...self::columns(), ...self::REFERENCE_COLUMNS, ...self::ERROR_FILE_COLUMNS];
        $ignored = array_values(array_filter($header, fn (string $name) => ! str_starts_with($name, '__empty_') && ! in_array($name, $known, true)));

        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, $header));
        if (! in_array('categories', $header, true) && ! in_array('primary_category', $header, true)) {
            $missing[] = 'categories';
        }

        if ($missing !== []) {
            return $fail('الملف ناقصه أعمدة إلزامية: '.implode('، ', $missing).'. حمّل القالب واكتب فيه.');
        }

        $rows = [];

        try {
            // المفتاح = ترتيب السطر في الملف (العناوين 0)، فرقم السطر زي ما بيظهر في Excel (العناوين 1)
            foreach ($reader->getRecords($header) as $offset => $record) {
                $line = $offset + 1;
                $cells = [];

                foreach ($header as $name) {
                    if (in_array($name, $known, true)) {
                        $cells[$name] = self::readCell((string) ($record[$name] ?? ''));
                    }
                }

                // سطر فاضي (Excel بيسيب سطور فاضية في الآخر) بيتعدّى من غير ما يتعدّ
                if (implode('', $cells) === '') {
                    continue;
                }

                $rows[$line] = $cells;

                if (count($rows) > self::MAX_ROWS) {
                    return $fail('الملف فيه أكتر من '.self::MAX_ROWS.' سطر. قسّمه لملفين.');
                }
            }
        } catch (Throwable) {
            return $fail('الملف مش CSV صالح.');
        }

        if ($rows === []) {
            return $fail('الملف مفيهوش أي سطر بيانات.');
        }

        return ['rows' => $rows, 'ignored' => $ignored, 'error' => null];
    }

    /**
     * النص بـ UTF-8 من أي ترميز بيطلع من Excel: UTF-8 (بـ BOM أو من غيره) أو UTF-16 أو Windows-1256 (CSV العادي
     * في ويندوز العربي). null لو ما اتعرفش.
     */
    private static function toUtf8(string $contents): ?string
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        if (str_starts_with($contents, "\xFF\xFE") || str_starts_with($contents, "\xFE\xFF")) {
            $converted = @mb_convert_encoding($contents, 'UTF-8', 'UTF-16');

            return is_string($converted) ? $converted : null;
        }

        if (mb_check_encoding($contents, 'UTF-8')) {
            return $contents;
        }

        if (function_exists('iconv')) {
            $converted = @iconv('CP1256', 'UTF-8', $contents);

            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        return null;
    }

    /**
     * الفاصل من أول سطر: Excel بالعربي بيحفظ `;` أحياناً و`,` أحياناً (والـ tab لو «Unicode Text»).
     */
    private static function delimiter(string $text): string
    {
        $first = strtok($text, "\r\n") ?: '';
        $best = ',';
        $bestCount = -1;

        foreach ([',', ';', "\t"] as $candidate) {
            $count = substr_count($first, $candidate);

            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    private static function headerName(string $name): string
    {
        $name = trim(str_replace("\xEF\xBB\xBF", '', $name));

        return preg_replace('/[\s\-]+/u', '_', mb_strtolower($name)) ?? $name;
    }
}
