<?php

namespace Tests\Feature;

use App\Events\PlaceChanged;
use App\Jobs\ImportPlacesFromCsv;
use App\Models\Area;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Place;
use App\Models\PlaceImport;
use App\Models\User;
use App\Support\PlaceCsv;
use App\Support\PlaceCsvException;
use App\Support\PlaceCsvExporter;
use App\Support\PlaceCsvHours;
use App\Support\PlaceCsvImporter;
use App\Support\PlaceCsvRowParser;
use Database\Seeders\AttributeSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CityAreaSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * A10 استيراد وتصدير CSV (02): قراية الملف، والتحقق من السطر بقواعد الفورم، والمعاينة (سليم وغلط ومكرر)،
 * والاستيراد كمسودات، وملف الأخطاء، والتصدير بنفس أعمدة القالب.
 */
class PlaceCsvTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, SettingSeeder::class, CategorySeeder::class, CityAreaSeeder::class, AttributeSeeder::class]);
    }

    /**
     * سطر كامل سليم، والاستثناءات بتتعدّل فوقه.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function row(array $overrides = []): array
    {
        return [
            'name_ar' => 'سيلانترو',
            'name_en' => 'Cilantro',
            'search_aliases' => 'silantro;سيلنترو',
            'description_ar' => 'مكان هادي في التجمع الخامس مناسب للقعدات الطويلة والشغل والمذاكرة، وفيه منيو واسع.',
            'categories' => 'cafes;desserts',
            'primary_category' => 'cafes',
            'attributes' => Attribute::query()->orderBy('id')->limit(3)->pluck('slug')->implode(';'),
            'price_min' => '150',
            'price_max' => '300',
            'area' => 'new-cairo',
            'address_ar' => 'شارع التسعين، جنب كايرو فيستيفال',
            'latitude' => '30.0283',
            'longitude' => '31.4913',
            'phone' => '01001234567',
            'whatsapp' => '01001234567',
            'instagram' => 'https://www.instagram.com/cilantrocafe/',
            'hours_sat' => '09:00-23:30',
            'hours_sun' => '09:00-14:00;18:00-02:00',
            'hours_mon' => 'closed',
            'hours_tue' => '24h',
            ...$overrides,
        ];
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function csv(array $rows, ?array $columns = null, string $delimiter = ','): string
    {
        $columns ??= PlaceCsv::columns();
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $columns, $delimiter, '"', '\\');

        foreach ($rows as $row) {
            fputcsv($out, array_map(fn (string $column) => $row[$column] ?? '', $columns), $delimiter, '"', '\\');
        }

        rewind($out);

        return (string) stream_get_contents($out);
    }

    private function user(string $role = 'admin'): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function importer(): PlaceCsvImporter
    {
        return app(PlaceCsvImporter::class);
    }

    private function body(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    // ───────────────────────── المواعيد ─────────────────────────

    public function test_hours_cells_are_read_in_every_supported_shape(): void
    {
        $open = fn (array ...$slots) => ['value' => ['state' => 'open', 'slots' => $slots], 'error' => null];

        $this->assertSame($open(['09:00', '23:30']), PlaceCsvHours::parseDay('09:00-23:30'));
        $this->assertSame($open(['09:00', '23:30']), PlaceCsvHours::parseDay('9:00 ص - 11:30 م'));
        $this->assertSame($open(['09:00', '23:00']), PlaceCsvHours::parseDay('9am-11pm'));
        $this->assertSame($open(['09:00', '23:30']), PlaceCsvHours::parseDay('٠٩:٠٠-٢٣:٣٠'));
        $this->assertSame($open(['09:00', '14:00'], ['18:00', '02:00']), PlaceCsvHours::parseDay('09:00-14:00;18:00-02:00'));
        $this->assertSame($open(['18:00', '24:00']), PlaceCsvHours::parseDay('18:00-24:00'));
        $this->assertSame($open(['00:00', '06:00']), PlaceCsvHours::parseDay('12am-6am'));

        foreach (['closed', 'مغلق', 'CLOSED'] as $cell) {
            $this->assertSame(['value' => ['state' => 'closed'], 'error' => null], PlaceCsvHours::parseDay($cell));
        }

        foreach (['24h', '24', '24 ساعة', '٢٤'] as $cell) {
            $this->assertSame(['value' => ['state' => '24h'], 'error' => null], PlaceCsvHours::parseDay($cell));
        }

        $this->assertSame(['value' => null, 'error' => null], PlaceCsvHours::parseDay('  '));
    }

    public function test_hours_cells_that_are_not_clear_or_not_valid_are_errors(): void
    {
        foreach (['9-5', 'من 9 لـ 5', '25:00-26:00', '09:00-09:00', '09:00-12:00;11:00-13:00', '09:00-10:00;11:00-12:00;13:00-14:00', '13pm-14pm'] as $cell) {
            $result = PlaceCsvHours::parseDay($cell);

            $this->assertNull($result['value'], $cell);
            $this->assertNotNull($result['error'], $cell);
        }
    }

    public function test_hours_are_written_back_in_the_same_shape(): void
    {
        $this->assertSame('09:00-14:00;18:00-02:00', PlaceCsvHours::format(['state' => 'open', 'slots' => [['9:00', '14:00'], ['18:00', '02:00']]]));
        $this->assertSame('closed', PlaceCsvHours::format(['state' => 'closed']));
        $this->assertSame('24h', PlaceCsvHours::format(['state' => '24h']));
        $this->assertSame('', PlaceCsvHours::format(null));
    }

    // ───────────────────────── قراية الملف ─────────────────────────

    public function test_the_template_is_the_header_only_with_a_bom_for_excel(): void
    {
        $template = PlaceCsv::template();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $template);
        $this->assertSame(PlaceCsv::columns(), str_getcsv(substr(trim($template), 3), ',', '"', '\\'));
    }

    public function test_a_file_with_a_bom_or_a_semicolon_delimiter_is_read(): void
    {
        $plain = $this->csv([$this->row()]);

        foreach ([$plain, "\xEF\xBB\xBF".$plain, $this->csv([$this->row()], delimiter: ';')] as $contents) {
            $read = PlaceCsv::read($contents);

            $this->assertNull($read['error']);
            $this->assertSame('سيلانترو', $read['rows'][2]['name_ar']);
            $this->assertSame('09:00-14:00;18:00-02:00', $read['rows'][2]['hours_sun']);
        }
    }

    public function test_a_windows_1256_file_is_converted(): void
    {
        if (! function_exists('iconv') || @iconv('UTF-8', 'CP1256', 'س') === false) {
            $this->markTestSkipped('iconv CP1256 غير متاح');
        }

        $contents = iconv('UTF-8', 'CP1256//IGNORE', $this->csv([$this->row(['name_ar' => 'قهوة المحطة'])]));

        $read = PlaceCsv::read($contents);

        $this->assertNull($read['error']);
        $this->assertSame('قهوة المحطة', $read['rows'][2]['name_ar']);
    }

    public function test_a_utf16_excel_unicode_text_file_is_read(): void
    {
        $contents = "\xFF\xFE".mb_convert_encoding($this->csv([$this->row()], delimiter: "\t"), 'UTF-16LE', 'UTF-8');

        $read = PlaceCsv::read($contents);

        $this->assertNull($read['error']);
        $this->assertSame('Cilantro', $read['rows'][2]['name_en']);
    }

    public function test_header_names_are_forgiven_and_unknown_columns_reported(): void
    {
        $header = ['Name_AR ', 'name-en', 'categories', 'area', 'address_ar', 'latitude', 'longitude', 'color', 'id', ''];
        $contents = implode(',', $header)."\nسيلانترو,Cilantro,cafes,new-cairo,شارع,30.02,31.49,red,5,\n";

        $read = PlaceCsv::read($contents);

        $this->assertNull($read['error']);
        $this->assertSame(['color'], $read['ignored']);
        $this->assertSame('Cilantro', $read['rows'][2]['name_en']);
    }

    public function test_a_file_missing_required_columns_is_refused_naming_them(): void
    {
        $read = PlaceCsv::read("name_ar,name_en\nسيلانترو,Cilantro\n");

        $this->assertStringContainsString('area', (string) $read['error']);
        $this->assertStringContainsString('latitude', (string) $read['error']);
        $this->assertStringContainsString('categories', (string) $read['error']);
    }

    public function test_empty_trailing_rows_are_skipped_and_line_numbers_match_excel(): void
    {
        $contents = $this->csv([$this->row(), $this->row(['name_ar' => 'التاني', 'name_en' => 'Second'])])
            .str_repeat(",,,,,,\n", 3);

        $read = PlaceCsv::read($contents);

        $this->assertSame([2, 3], array_keys($read['rows']));
    }

    public function test_more_than_500_rows_or_no_rows_or_nothing_is_refused(): void
    {
        $many = array_fill(0, PlaceCsv::MAX_ROWS + 1, $this->row());

        $this->assertStringContainsString('500', (string) PlaceCsv::read($this->csv($many))['error']);
        $this->assertNull(PlaceCsv::read($this->csv(array_fill(0, PlaceCsv::MAX_ROWS, $this->row())))['error']);
        $this->assertNotNull(PlaceCsv::read($this->csv([]))['error']);
        $this->assertNotNull(PlaceCsv::read('')['error']);
    }

    public function test_a_cell_the_export_protected_from_excel_formulas_is_read_back(): void
    {
        $this->assertSame("'=SUM(A1)", PlaceCsv::safeCell('=SUM(A1)'));
        $this->assertSame('مكان', PlaceCsv::safeCell('مكان'));
        $this->assertSame('=SUM(A1)', PlaceCsv::readCell("'=SUM(A1)"));
        $this->assertSame("'عادي", PlaceCsv::readCell("'عادي"));
    }

    // ───────────────────────── التحقق من السطر ─────────────────────────

    /**
     * @param  array<string, string>  $cells
     * @return array{data: array<string, mixed>, errors: list<string>}
     */
    private function parse(array $cells): array
    {
        return (new PlaceCsvRowParser)->parse($cells);
    }

    public function test_a_full_row_becomes_place_columns(): void
    {
        $result = $this->parse($this->row());
        $data = $result['data'];

        $this->assertSame([], $result['errors']);
        $this->assertSame('سيلانترو', $data['name_ar']);
        $this->assertSame(['silantro', 'سيلنترو'], $data['search_aliases']);
        $this->assertSame(Category::query()->where('slug', 'cafes')->value('id'), $data['primary_category_id']);
        $this->assertCount(2, $data['category_ids']);
        $this->assertCount(3, $data['attribute_ids']);
        $this->assertSame(150, $data['price_min']);
        $this->assertSame(300, $data['price_max']);
        $this->assertSame(Area::query()->where('slug', 'new-cairo')->value('id'), $data['area_id']);
        $this->assertNotNull($data['city_id']);
        $this->assertSame(30.0283, $data['latitude']);
        $this->assertSame('+201001234567', $data['phone']);
        $this->assertSame('+201001234567', $data['whatsapp']);
        $this->assertSame('cilantrocafe', $data['instagram']);
        $this->assertSame(['state' => 'closed'], $data['opening_hours']['mon']);
        $this->assertSame(['state' => '24h'], $data['opening_hours']['tue']);
        $this->assertSame([['09:00', '14:00'], ['18:00', '02:00']], $data['opening_hours']['sun']['slots']);
        $this->assertArrayNotHasKey('wed', $data['opening_hours']);
        $this->assertNull($data['ramadan_hours']);
    }

    public function test_the_catalog_is_matched_by_slug_or_by_name(): void
    {
        $result = $this->parse($this->row([
            'categories' => 'كافيهات;Desserts',
            'primary_category' => '',
            'area' => 'التجمع الخامس',
            'city' => 'القاهرة',
        ]));

        $this->assertSame([], $result['errors']);
        $this->assertSame(Category::query()->where('slug', 'cafes')->value('id'), $result['data']['primary_category_id']);
        $this->assertSame(Area::query()->where('slug', 'new-cairo')->value('id'), $result['data']['area_id']);
    }

    public function test_a_single_category_in_primary_only_is_enough(): void
    {
        $result = $this->parse($this->row(['categories' => '', 'primary_category' => 'rooftops']));

        $this->assertSame([], $result['errors']);
        $this->assertSame([$result['data']['primary_category_id']], $result['data']['category_ids']);
    }

    public function test_every_bad_value_is_reported_on_its_column(): void
    {
        $errors = $this->parse($this->row([
            'name_ar' => '',
            'name_en' => 'A',
            'categories' => 'cafes;nope',
            'primary_category' => 'rooftops',
            'attributes' => 'no-such-attribute',
            'price_min' => '500',
            'price_max' => '100',
            'area' => 'atlantis',
            'latitude' => '31.4913',
            'longitude' => '30.0283',
            'phone' => '12345678901234',
            'whatsapp' => '0222345678',
            'facebook_url' => 'http://facebook.com/x',
            'instagram' => 'bad handle!',
            'hours_wed' => '9-5',
        ]))['errors'];

        $columns = array_map(fn (string $error) => explode(':', $error)[0], $errors);

        foreach (['name_ar', 'name_en', 'categories', 'primary_category', 'attributes', 'price_max', 'area', 'phone', 'whatsapp', 'facebook_url', 'instagram', 'hours_wed'] as $column) {
            $this->assertContains($column, $columns, "{$column} ما اتبلّغش");
        }
    }

    public function test_coordinates_outside_egypt_or_swapped_are_refused(): void
    {
        $swapped = $this->parse($this->row(['latitude' => '31.4913', 'longitude' => '30.0283']));
        $this->assertNotEmpty($swapped['errors']);
        $this->assertStringContainsString('latitude', $swapped['errors'][0]);

        $this->assertNotEmpty($this->parse($this->row(['latitude' => 'abc']))['errors']);
        $this->assertNotEmpty($this->parse($this->row(['longitude' => '']))['errors']);

        // الفاصلة العشرية من Excel بالعربي
        $this->assertSame([], $this->parse($this->row(['latitude' => '30,0283', 'longitude' => '31,4913']))['errors']);
    }

    public function test_a_hidden_category_or_attribute_is_not_found(): void
    {
        Category::query()->where('slug', 'parks')->update(['is_active' => false]);
        Attribute::query()->orderBy('id')->first()->update(['is_active' => false]);
        $slug = Attribute::query()->orderBy('id')->first()->slug;

        $errors = $this->parse($this->row(['categories' => 'parks', 'primary_category' => '', 'attributes' => $slug]))['errors'];

        $this->assertCount(2, $errors);
    }

    public function test_prices_may_be_left_empty_for_a_draft(): void
    {
        $result = $this->parse($this->row(['price_min' => '', 'price_max' => '', 'description_ar' => '', 'attributes' => '', 'hours_sat' => '', 'hours_sun' => '', 'hours_mon' => '', 'hours_tue' => '']));

        $this->assertSame([], $result['errors']);
        $this->assertNull($result['data']['price_min']);
        $this->assertNull($result['data']['opening_hours']);
    }

    // ───────────────────────── المعاينة ─────────────────────────

    public function test_the_preview_marks_each_row_valid_error_or_duplicate_and_adds_nothing(): void
    {
        Place::factory()->create(['name_ar' => 'قهوة الميدان', 'name_en' => 'Midan Coffee', 'latitude' => 30.0500, 'longitude' => 31.4500]);

        $contents = $this->csv([
            $this->row(),
            $this->row(['name_ar' => '', 'name_en' => 'No Arabic Name']),
            $this->row(['name_ar' => 'قهوة الميدان', 'name_en' => 'Midan Coffee', 'latitude' => '30.0501', 'longitude' => '31.4501']),
            $this->row(['name_ar' => 'سيلانترو', 'name_en' => 'Cilantro', 'latitude' => '30.0284', 'longitude' => '31.4914']),
        ]);

        $import = $this->importer()->preview($contents, 'places.csv', $this->user());
        $rows = collect($import->rows)->keyBy('line');

        $this->assertSame(PlaceImport::STATUS_PREVIEWED, $import->status);
        $this->assertSame([4, 1, 2, 1], [$import->total_rows, $import->valid_rows, $import->duplicate_rows, $import->error_rows]);

        $this->assertSame(PlaceImport::ROW_VALID, $rows[2]['status']);
        $this->assertSame(PlaceImport::ROW_ERROR, $rows[3]['status']);
        $this->assertStringContainsString('name_ar', $rows[3]['errors'][0]);
        $this->assertSame(PlaceImport::ROW_DUPLICATE, $rows[4]['status']);
        $this->assertStringContainsString('قهوة الميدان', $rows[4]['notes'][0]);
        // نفس الاسم والموقع جوه الملف
        $this->assertSame(PlaceImport::ROW_DUPLICATE, $rows[5]['status']);
        $this->assertStringContainsString('السطر 2', $rows[5]['notes'][0]);

        // المعاينة مش بتضيف: المكان الموجود بس
        $this->assertSame(1, Place::query()->count());
    }

    public function test_the_same_name_far_apart_is_not_a_duplicate(): void
    {
        $contents = $this->csv([
            $this->row(),
            $this->row(['latitude' => '30.0900', 'longitude' => '31.5400']),
        ]);

        $import = $this->importer()->preview($contents, 'places.csv', $this->user());

        $this->assertSame(2, $import->valid_rows);
    }

    public function test_a_bad_file_throws_with_a_message_for_the_user(): void
    {
        $this->expectException(PlaceCsvException::class);

        $this->importer()->preview("name_ar\nx\n", 'bad.csv', null);
    }

    // ───────────────────────── الاستيراد ─────────────────────────

    public function test_confirming_adds_the_valid_rows_as_drafts_with_their_relations(): void
    {
        Event::fake([PlaceChanged::class]);
        $admin = $this->user();

        $import = $this->importer()->preview($this->csv([
            $this->row(),
            $this->row(['name_ar' => '', 'name_en' => 'Broken']),
        ]), 'places.csv', $admin);

        $import->forceFill(['status' => PlaceImport::STATUS_IMPORTING])->save();
        $this->importer()->run($import->refresh());

        $import->refresh();
        $place = Place::query()->sole();

        $this->assertSame(PlaceImport::STATUS_DONE, $import->status);
        $this->assertSame(1, $import->imported_rows);
        $this->assertNotNull($import->finished_at);

        $this->assertSame(Place::STATUS_DRAFT, $place->status);
        $this->assertSame('سيلانترو', $place->name_ar);
        $this->assertSame('cilantro', $place->slug);
        $this->assertSame($admin->id, $place->created_by);
        $this->assertSame('mid', $place->price_range);
        $this->assertSame(['silantro', 'سيلنترو'], $place->search_aliases);
        $this->assertSame('+201001234567', $place->phone);
        $this->assertSame(['cafes', 'desserts'], $place->categories()->orderBy('categories.id')->pluck('slug')->all());
        $this->assertCount(3, $place->placeAttributes);
        $this->assertSame([['09:00', '14:00'], ['18:00', '02:00']], $place->opening_hours['sun']['slots']);

        // الـ listeners (البحث والمواعيد والـ cache) بتشوف المكان بعلاقاته: PlaceChanged بعد حفظ التصنيفات
        Event::assertDispatched(PlaceChanged::class, fn (PlaceChanged $event) => $event->place->is($place));

        $created = Activity::query()->where('subject_id', $place->id)->where('event', 'created')->first();
        $this->assertSame($admin->id, $created?->causer_id);
    }

    public function test_the_search_text_of_an_imported_place_has_its_categories(): void
    {
        $import = $this->importer()->preview($this->csv([$this->row()]), 'places.csv', $this->user());
        $import->forceFill(['status' => PlaceImport::STATUS_IMPORTING])->save();
        $this->importer()->run($import->refresh());

        $text = (string) Place::query()->sole()->search_text;

        $this->assertStringContainsString('كافيهات', $text);
        $this->assertStringContainsString('silantro', $text);
    }

    public function test_duplicates_are_skipped_unless_the_user_chose_to_include_them(): void
    {
        Place::factory()->create(['name_ar' => 'سيلانترو', 'name_en' => 'Cilantro', 'latitude' => 30.0283, 'longitude' => 31.4913]);

        $import = $this->importer()->preview($this->csv([$this->row()]), 'places.csv', $this->user());
        $this->assertSame(1, $import->duplicate_rows);

        $import->forceFill(['status' => PlaceImport::STATUS_IMPORTING, 'include_duplicates' => false])->save();
        $this->importer()->run($import->refresh());

        $this->assertSame(1, Place::query()->count());
        $this->assertSame(PlaceImport::RESULT_SKIPPED, $import->refresh()->rows[0]['result']);

        $second = $this->importer()->preview($this->csv([$this->row()]), 'places.csv', $this->user());
        $second->forceFill(['status' => PlaceImport::STATUS_IMPORTING, 'include_duplicates' => true])->save();
        $this->importer()->run($second->refresh());

        $this->assertSame(2, Place::query()->count());
    }

    public function test_running_again_does_not_add_the_same_places_twice(): void
    {
        $import = $this->importer()->preview($this->csv([$this->row(), $this->row(['name_ar' => 'التاني', 'name_en' => 'Second', 'latitude' => '30.1', 'longitude' => '31.6'])]), 'places.csv', $this->user());
        $import->forceFill(['status' => PlaceImport::STATUS_IMPORTING])->save();

        $this->importer()->run($import->refresh());
        $this->importer()->run($import->refresh());

        $this->assertSame(2, Place::query()->count());
        $this->assertSame(2, $import->refresh()->imported_rows);
    }

    public function test_the_job_only_runs_an_import_that_was_confirmed(): void
    {
        $import = $this->importer()->preview($this->csv([$this->row()]), 'places.csv', $this->user());

        // لسه معاينة: الـ job ما بيضيفش
        (new ImportPlacesFromCsv($import->id))->handle($this->importer());
        $this->assertSame(0, Place::query()->count());

        $import->forceFill(['status' => PlaceImport::STATUS_IMPORTING])->save();
        (new ImportPlacesFromCsv($import->id))->handle($this->importer());

        $this->assertSame(1, Place::query()->count());
        $this->assertSame(PlaceImport::STATUS_DONE, $import->refresh()->status);
    }

    public function test_a_job_that_died_marks_the_import_failed(): void
    {
        $import = $this->importer()->preview($this->csv([$this->row()]), 'places.csv', $this->user());
        $import->forceFill(['status' => PlaceImport::STATUS_IMPORTING])->save();

        (new ImportPlacesFromCsv($import->id))->failed(new \RuntimeException('boom'));

        $this->assertSame(PlaceImport::STATUS_FAILED, $import->refresh()->status);
        $this->assertNotEmpty($import->error_message);
    }

    // ───────────────────────── ملف الأخطاء ─────────────────────────

    public function test_the_errors_file_has_the_failed_and_skipped_rows_with_their_reason_and_can_be_uploaded_again(): void
    {
        Place::factory()->create(['name_ar' => 'قهوة الميدان', 'name_en' => 'Midan Coffee', 'latitude' => 30.0500, 'longitude' => 31.4500]);

        $import = $this->importer()->preview($this->csv([
            $this->row(),
            $this->row(['name_ar' => 'غلط', 'name_en' => 'Wrong', 'latitude' => '99']),
            $this->row(['name_ar' => 'قهوة الميدان', 'name_en' => 'Midan Coffee', 'latitude' => '30.0500', 'longitude' => '31.4500']),
        ]), 'places.csv', $this->user());
        $import->forceFill(['status' => PlaceImport::STATUS_IMPORTING])->save();
        $this->importer()->run($import->refresh());

        $errors = PlaceCsvImporter::errorsCsv($import->refresh());
        $read = PlaceCsv::read($errors);

        // السليم اتضاف فمش في الملف، والغلط والمكرر فيه
        $this->assertNull($read['error']);
        $this->assertSame([3, 4], array_column(array_map(fn ($row) => ['row' => (int) $row['row']], $read['rows']), 'row'));
        $this->assertSame('غلط', $read['rows'][2]['name_ar']);
        $this->assertStringContainsString('latitude', $read['rows'][2]['reason']);
        $this->assertStringContainsString('مكرر', $read['rows'][3]['reason']);

        // الأعمدة row وreason مش بتتحسب «أعمدة مش معروفة»
        $this->assertSame([], $read['ignored']);
    }

    // ───────────────────────── التصدير ─────────────────────────

    public function test_the_export_has_the_template_columns_then_the_reference_ones(): void
    {
        $place = Place::factory()->complete()->create([
            'name_ar' => 'سيلانترو', 'name_en' => 'Cilantro', 'phone' => '+201001234567', 'instagram' => 'cilantrocafe',
            'search_aliases' => ['silantro'],
        ]);

        $csv = $this->body(PlaceCsvExporter::download(Place::query()->whereKey($place->id), 'x.csv'));
        $read = PlaceCsv::read($csv);

        $this->assertNull($read['error']);
        $row = $read['rows'][2];
        $this->assertSame('سيلانترو', $row['name_ar']);
        $this->assertSame('silantro', $row['search_aliases']);
        $this->assertSame('cafes', $row['primary_category']);
        $this->assertSame('new-cairo', $row['area']);
        $this->assertSame('cairo', $row['city']);
        $this->assertSame('09:00-23:30', $row['hours_sat']);
        $this->assertSame((string) $place->id, $row['id']);
        $this->assertSame(Place::STATUS_DRAFT, $row['status']);
        $this->assertSame([], $read['ignored']);
    }

    public function test_an_exported_file_imports_back_as_the_same_place(): void
    {
        $place = Place::factory()->complete()->create([
            'name_ar' => 'قهوة المحطة', 'name_en' => 'El Mahatta', 'phone' => '+201001234567', 'whatsapp' => '+201001234567',
            'instagram' => 'mahatta', 'facebook_url' => 'https://facebook.com/mahatta', 'search_aliases' => ['mahta', 'محطة'],
            'ramadan_hours' => ['sat' => ['state' => 'open', 'slots' => [['20:00', '03:00']]]],
        ]);
        $place->categories()->syncWithoutDetaching(Category::query()->where('slug', 'desserts')->pluck('id'));

        $csv = $this->body(PlaceCsvExporter::download(Place::query()->whereKey($place->id), 'x.csv'));
        $expected = $place->fresh()->only(['name_ar', 'name_en', 'search_aliases', 'description_ar', 'price_min', 'price_max', 'latitude', 'longitude', 'phone', 'whatsapp', 'instagram', 'facebook_url', 'opening_hours', 'ramadan_hours']);
        $attributes = $place->placeAttributes()->pluck('slug')->sort()->values()->all();
        $categories = $place->categories()->pluck('slug')->sort()->values()->all();

        $place->forceDelete();

        $import = $this->importer()->preview($csv, 'export.csv', $this->user());
        $this->assertSame([1, 0, 0], [$import->valid_rows, $import->duplicate_rows, $import->error_rows]);

        $import->forceFill(['status' => PlaceImport::STATUS_IMPORTING])->save();
        $this->importer()->run($import->refresh());

        $copy = Place::query()->sole();

        $this->assertSame($expected, $copy->only(array_keys($expected)));
        $this->assertSame($attributes, $copy->placeAttributes()->pluck('slug')->sort()->values()->all());
        $this->assertSame($categories, $copy->categories()->pluck('slug')->sort()->values()->all());
        $this->assertSame('cafes', $copy->primaryCategory->slug);
    }

    public function test_free_text_that_looks_like_a_formula_is_protected_in_the_export(): void
    {
        $place = Place::factory()->create(['name_ar' => '=HYPERLINK("http://x")', 'internal_notes' => '@cmd']);

        $csv = $this->body(PlaceCsvExporter::download(Place::query()->whereKey($place->id), 'x.csv'));

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'@cmd", $csv);
    }

    public function test_the_export_keeps_the_order_of_the_query(): void
    {
        $a = Place::factory()->create(['name_ar' => 'ألف']);
        $b = Place::factory()->create(['name_ar' => 'باء']);
        $c = Place::factory()->create(['name_ar' => 'جيم']);

        $csv = $this->body(PlaceCsvExporter::download(Place::query()->orderByDesc('places.id'), 'x.csv'));
        $rows = PlaceCsv::read($csv)['rows'];

        $this->assertSame(['جيم', 'باء', 'ألف'], array_values(array_column($rows, 'name_ar')));
        $this->assertSame([$c->id, $b->id, $a->id], array_map(fn ($row) => (int) $row['id'], array_values($rows)));
    }

    // ───────────────────────── التنضيف ─────────────────────────

    public function test_old_previews_and_results_are_pruned_but_a_running_import_is_kept(): void
    {
        $make = fn (string $status, int $daysAgo) => PlaceImport::query()->create([
            'file_name' => 'a.csv', 'status' => $status, 'total_rows' => 0, 'rows' => [],
            'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo),
        ]);

        $make(PlaceImport::STATUS_PREVIEWED, 31);
        $make(PlaceImport::STATUS_DONE, 45);
        $running = $make(PlaceImport::STATUS_IMPORTING, 40);
        $fresh = $make(PlaceImport::STATUS_PREVIEWED, 2);

        $this->artisan('places:prune-imports')->expectsOutputToContain('Deleted 2')->assertSuccessful();

        $this->assertEqualsCanonicalizing([$running->id, $fresh->id], PlaceImport::query()->pluck('id')->all());
    }
}
