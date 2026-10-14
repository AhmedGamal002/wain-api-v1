<?php

namespace Tests\Feature\Admin;

use App\Events\PlaceChanged;
use App\Filament\Resources\Places\Pages\CreatePlace;
use App\Filament\Resources\Places\Pages\EditPlace;
use App\Models\Area;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Place;
use App\Models\User;
use App\Support\OpeningHours;
use App\Support\OpeningHoursForm;
use Database\Factories\PlaceFactory;
use Database\Seeders\AreaSeeder;
use Database\Seeders\AttributeSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CityAreaSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A1 صفحتي الإضافة والتعديل (03 القسم 4.3): الحفظ كمسودة بالإلزامي بس، والإرسال والنشر بالشروط التسعة،
 * و«حفظ» على المنشور ممنوع يكسر شرط، والمواعيد والخصائص والتليفون وإنستجرام والخريطة.
 */
class PlaceFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, SettingSeeder::class, CategorySeeder::class, CityAreaSeeder::class, AttributeSeeder::class]);
        Filament::setCurrentPanel('admin');
    }

    private function asAdmin(): User
    {
        $admin = User::factory()->create(['name' => 'جمال'])->assignRole('admin');
        $this->actingAs($admin);

        return $admin;
    }

    private function asModerator(): User
    {
        $moderator = User::factory()->create(['name' => 'معلوم'])->assignRole('moderator');
        $this->actingAs($moderator);

        return $moderator;
    }

    /**
     * أقل بيانات للمسودة (الاسمين والتصنيف والمنطقة والعنوان والموقع).
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function draftData(array $override = []): array
    {
        $category = Category::query()->orderBy('id')->firstOrFail();
        $area = Area::query()->orderBy('id')->firstOrFail();

        return array_merge([
            'name_ar' => 'سيلانترو',
            'name_en' => 'Cilantro',
            'categories' => [$category->id],
            'primary_category_id' => $category->id,
            'city_id' => $area->city_id,
            'area_id' => $area->id,
            'address_ar' => 'شارع التسعين، التجمع الخامس',
            'latitude' => 30.03,
            'longitude' => 31.47,
        ], $override);
    }

    private function create(array $override = [])
    {
        return Livewire::test(CreatePlace::class)->fillForm($this->draftData($override));
    }

    /** زرار من أزرار الحفظ تحت الفورم. */
    private function formAction(string $name): TestAction
    {
        return TestAction::make($name)->schemaComponent('form-actions', schema: 'content');
    }

    /** زرار جوه حقل أو مكوّن في الفورم (المفتاح بيشمل التاب). */
    private function fieldAction(string $name, string $component): TestAction
    {
        return TestAction::make($name)->schemaComponent($component, schema: 'form');
    }

    private function edit(Place $place)
    {
        return Livewire::test(EditPlace::class, ['record' => $place->getKey()]);
    }

    // ───────────────────────── الصفحات والصلاحيات ─────────────────────────

    public function test_create_and_edit_pages_open_for_the_team_only(): void
    {
        $place = Place::factory()->create();

        $this->get('/admin/places/create')->assertRedirect('/admin/login');

        $this->actingAs(User::factory()->create());
        $this->get('/admin/places/create')->assertForbidden();
        $this->get("/admin/places/{$place->id}/edit")->assertForbidden();

        $this->asModerator();
        $this->get('/admin/places/create')->assertOk();
        $this->get("/admin/places/{$place->id}/edit")->assertOk();

        $this->asAdmin();
        $this->get('/admin/places/create')->assertOk();
        $this->get("/admin/places/{$place->id}/edit")->assertOk();
    }

    public function test_the_form_has_seven_tabs_and_the_checklist(): void
    {
        $this->asAdmin();

        Livewire::test(CreatePlace::class)
            ->assertSee('الأساسية')
            ->assertSee('التصنيف والسعر')
            ->assertSee('الموقع')
            ->assertSee('التواصل')
            ->assertSee('المواعيد')
            ->assertSee('الصور')
            ->assertSee('النشر والإدارة')
            ->assertSee('شروط النشر')
            ->assertSee('0 من 9')
            ->assertSee('صورة الغلاف')
            ->assertSee('مفضّل');
    }

    public function test_edit_form_does_not_expose_the_spatial_column(): void
    {
        $this->asAdmin();
        $place = Place::factory()->complete()->create();

        $component = $this->edit($place)->assertOk();

        $data = $component->get('data');
        $this->assertArrayNotHasKey('location', $data);
        $this->assertArrayNotHasKey('search_text', $data);
        $this->assertSame('open', $data['opening_hours']['sat']['state']);
        $this->assertSame('09:00', $data['opening_hours']['sat']['slot1_from']);
    }

    // ───────────────────────── مسودة جديدة ─────────────────────────

    public function test_a_draft_saves_with_the_required_fields_only(): void
    {
        $admin = $this->asAdmin();
        Event::fake([PlaceChanged::class]);

        $this->create()->call('create')->assertHasNoFormErrors();

        $place = Place::query()->where('name_en', 'Cilantro')->firstOrFail();

        $this->assertSame(Place::STATUS_DRAFT, $place->status);
        $this->assertSame('cilantro', $place->slug);
        $this->assertSame($admin->id, $place->created_by);
        $this->assertSame($admin->id, $place->updated_by);
        $this->assertEqualsWithDelta(30.03, $place->latitude, 0.0001);
        $this->assertNull($place->opening_hours);
        $this->assertNull($place->ramadan_hours);
        $this->assertSame([$place->primary_category_id], $place->categories->modelKeys());
        $this->assertSame(0, $place->placeAttributes()->count());
        $this->assertSame(1, DB::table('places')->whereRaw('ST_X(location) = 31.47')->where('id', $place->id)->count());

        Event::assertDispatched(PlaceChanged::class);
    }

    public function test_the_draft_is_not_saved_without_the_required_fields(): void
    {
        $this->asAdmin();

        $this->create([
            'name_ar' => '', 'name_en' => '', 'categories' => [], 'primary_category_id' => null,
            'city_id' => null, 'area_id' => null, 'address_ar' => '', 'latitude' => null, 'longitude' => null,
        ])
            ->call('create')
            ->assertHasFormErrors([
                'name_ar' => 'required', 'name_en' => 'required', 'categories' => 'required',
                'primary_category_id' => 'required', 'city_id' => 'required', 'area_id' => 'required',
                'address_ar' => 'required', 'latitude' => 'required', 'longitude' => 'required',
            ]);

        $this->assertSame(0, Place::query()->count());
    }

    public function test_a_pin_outside_egypt_is_rejected(): void
    {
        $this->asAdmin();

        $this->create(['latitude' => 48.85, 'longitude' => 2.35])
            ->call('create')
            ->assertHasFormErrors(['latitude', 'longitude']);

        $this->assertSame(0, Place::query()->count());
    }

    public function test_primary_category_must_be_one_of_the_selected_categories(): void
    {
        $this->asAdmin();
        [$first, $second] = Category::query()->orderBy('id')->limit(2)->get();

        $this->create(['categories' => [$first->id], 'primary_category_id' => $second->id])
            ->call('create')
            ->assertHasFormErrors(['primary_category_id']);
    }

    public function test_at_most_three_categories(): void
    {
        $this->asAdmin();
        $ids = Category::query()->orderBy('id')->limit(4)->pluck('id')->all();

        $this->create(['categories' => $ids, 'primary_category_id' => $ids[0]])
            ->call('create')
            ->assertHasFormErrors(['categories']);
    }

    public function test_create_another_keeps_city_and_area(): void
    {
        $this->asAdmin();
        $data = $this->draftData();

        $component = $this->create()->call('createAnother')->assertHasNoFormErrors();

        $this->assertSame(1, Place::query()->count());
        $this->assertEquals($data['city_id'], $component->get('data.city_id'));
        $this->assertEquals($data['area_id'], $component->get('data.area_id'));
        $this->assertNull($component->get('data.name_ar'));
    }

    public function test_the_create_page_has_no_publish_buttons(): void
    {
        $this->asAdmin();

        Livewire::test(CreatePlace::class)
            ->assertActionExists($this->formAction('create'))
            ->assertActionExists($this->formAction('createAnother'))
            ->assertActionDoesNotExist($this->formAction('saveAndPublish'))
            ->assertActionDoesNotExist($this->formAction('saveAndSubmit'));
    }

    // ───────────────────────── الحالات والأزرار ─────────────────────────

    public function test_save_buttons_follow_the_status_and_the_role(): void
    {
        $draft = Place::factory()->create();
        $pending = Place::factory()->complete()->status(Place::STATUS_PENDING)->create();
        $active = Place::factory()->published()->create();

        $this->asModerator();
        $this->edit($draft)->assertActionVisible($this->formAction('saveAndSubmit'))->assertActionDoesNotExist($this->formAction('saveAndPublish'));
        $this->edit($pending)->assertActionDoesNotExist($this->formAction('saveAndSubmit'))->assertActionDoesNotExist($this->formAction('saveAndPublish'));
        $this->edit($active)->assertActionDoesNotExist($this->formAction('saveAndSubmit'))->assertActionDoesNotExist($this->formAction('saveAndPublish'));

        $this->asAdmin();
        $this->edit($draft)->assertActionVisible($this->formAction('saveAndSubmit'))->assertActionVisible($this->formAction('saveAndPublish'));
        $this->edit($pending)->assertActionDoesNotExist($this->formAction('saveAndSubmit'))->assertActionVisible($this->formAction('saveAndPublish'));
        $this->edit($active)->assertActionDoesNotExist($this->formAction('saveAndSubmit'))->assertActionDoesNotExist($this->formAction('saveAndPublish'));
    }

    public function test_submit_for_review_requires_all_nine_conditions_and_names_the_missing_ones(): void
    {
        $this->asModerator();
        $place = Place::factory()->create();

        $this->edit($place)
            ->callAction($this->formAction('saveAndSubmit'))
            ->assertNotified('ما ينفعش يتبعت للمراجعة. ناقص: الوصف بالعربي، 3 خصائص على الأقل، سعر الفرد، المواعيد، صورة الغلاف');

        $this->assertSame(Place::STATUS_DRAFT, $place->refresh()->status);
    }

    public function test_a_blocked_publish_rolls_back_everything_and_marks_the_tabs(): void
    {
        $this->asAdmin();
        $place = Place::factory()->create(['name_ar' => 'الاسم القديم']);
        $original = $place->categories->modelKeys();
        $other = Category::query()->where('id', '!=', $place->primary_category_id)->orderBy('id')->firstOrFail();

        $component = $this->edit($place)
            ->fillForm(['name_ar' => 'اسم جديد', 'categories' => [$place->primary_category_id, $other->id]])
            ->callAction($this->formAction('saveAndPublish'));

        $component->assertNotified();

        $place->refresh();
        $this->assertSame('الاسم القديم', $place->name_ar);
        $this->assertSame(Place::STATUS_DRAFT, $place->status);
        $this->assertEqualsCanonicalizing($original, $place->categories()->pluck('categories.id')->all());

        $missing = $component->get('missingConditions');
        $this->assertContains('cover', $missing);
        $this->assertContains('hours', $missing);
        $this->assertNotContains('names', $missing);
        // التاب اللي ناقصه شرط بيتعلّم (نقطة حمرا)
        $component->assertSee('fi-badge');
    }

    public function test_save_and_submit_moves_a_complete_place_to_pending_and_clears_the_reason(): void
    {
        $this->asModerator();
        $place = Place::factory()->complete()->status(Place::STATUS_REJECTED)->create(['status_reason' => 'صور ضعيفة']);

        $this->edit($place)->callAction($this->formAction('saveAndSubmit'))->assertHasNoFormErrors();

        $place->refresh();
        $this->assertSame(Place::STATUS_PENDING, $place->status);
        $this->assertNull($place->status_reason);
    }

    public function test_save_and_publish_by_an_admin_publishes_a_complete_place(): void
    {
        $this->asAdmin();
        $place = Place::factory()->complete()->status(Place::STATUS_PENDING)->create();

        $this->edit($place)->callAction($this->formAction('saveAndPublish'))->assertHasNoFormErrors();

        $place->refresh();
        $this->assertSame(Place::STATUS_ACTIVE, $place->status);
        $this->assertNotNull($place->published_at);
    }

    public function test_a_moderator_cannot_publish_even_by_calling_the_action_directly(): void
    {
        $this->asModerator();
        $place = Place::factory()->complete()->create();

        try {
            $this->edit($place)->call('mountAction', 'saveAndPublish');
        } catch (\Throwable) {
            // الزرار مش ظاهر أصلاً
        }

        $this->assertSame(Place::STATUS_DRAFT, $place->refresh()->status);
    }

    public function test_saving_a_rejected_place_returns_it_to_draft(): void
    {
        $this->asModerator();
        $place = Place::factory()->status(Place::STATUS_REJECTED)->create(['status_reason' => 'مكرر']);

        $this->edit($place)->fillForm(['name_ar' => 'اسم بعد التعديل'])->call('save')->assertHasNoFormErrors();

        $place->refresh();
        $this->assertSame(Place::STATUS_DRAFT, $place->status);
        $this->assertSame('اسم بعد التعديل', $place->name_ar);
        $this->assertSame('مكرر', $place->status_reason, 'السبب بيفضل ظاهر لحد الإرسال تاني');
    }

    public function test_editing_a_published_place_cannot_break_a_publish_condition(): void
    {
        $this->asModerator();
        $place = Place::factory()->published()->create();

        $this->edit($place)
            ->fillForm(['price_min' => null, 'price_max' => null, 'address_ar' => 'عنوان جديد'])
            ->call('save')
            ->assertNotified('الحفظ ده هيكسر شرط نشر: سعر الفرد');

        $place->refresh();
        $this->assertSame(100, $place->price_min);
        $this->assertSame(200, $place->price_max);
        $this->assertNotSame('عنوان جديد', $place->address_ar);
        $this->assertSame(Place::STATUS_ACTIVE, $place->status);
    }

    public function test_a_published_place_can_be_edited_when_no_condition_breaks(): void
    {
        $this->asModerator();
        $place = Place::factory()->published()->create();

        $this->edit($place)->fillForm(['address_ar' => 'عنوان جديد', 'price_max' => 300])->call('save')->assertHasNoFormErrors();

        $place->refresh();
        $this->assertSame('عنوان جديد', $place->address_ar);
        $this->assertSame(300, $place->price_max);
        $this->assertSame('mid', $place->price_range);
        $this->assertSame(Place::STATUS_ACTIVE, $place->status);
    }

    public function test_an_already_missing_condition_does_not_block_an_unrelated_edit(): void
    {
        $this->asAdmin();
        // منشور من seeder قديم من غير مواعيد: التعديل في حاجة تانية مايتمنعش
        $place = Place::factory()->published()->create(['opening_hours' => null]);

        $this->edit($place)->fillForm(['address_en' => 'New address'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('New address', $place->refresh()->address_en);
    }

    // ───────────────────────── المواعيد ─────────────────────────

    public function test_opening_hours_are_saved_in_the_storage_shape(): void
    {
        $this->asAdmin();
        $place = Place::factory()->create();

        $hours = OpeningHoursForm::toFormState(null);
        $hours['sat'] = ['state' => 'open', 'slot1_from' => '09:00', 'slot1_to' => '14:00', 'has_second' => true, 'slot2_from' => '18:00', 'slot2_to' => '02:00'];
        $hours['sun'] = ['state' => 'closed', 'slot1_from' => null, 'slot1_to' => null, 'has_second' => false, 'slot2_from' => null, 'slot2_to' => null];
        $hours['mon'] = ['state' => '24h', 'slot1_from' => null, 'slot1_to' => null, 'has_second' => false, 'slot2_from' => null, 'slot2_to' => null];

        $this->edit($place)->fillForm(['opening_hours' => $hours])->call('save')->assertHasNoFormErrors();

        $stored = $place->refresh()->opening_hours;
        $this->assertSame(['state' => 'open', 'slots' => [['09:00', '14:00'], ['18:00', '02:00']]], $stored['sat']);
        $this->assertSame(['state' => 'closed'], $stored['sun']);
        $this->assertSame(['state' => '24h'], $stored['mon']);
        $this->assertArrayNotHasKey('tue', $stored);
    }

    public function test_hours_save_rebuilds_place_hours(): void
    {
        $this->asAdmin();
        $place = Place::factory()->create();

        $hours = OpeningHoursForm::toFormState(PlaceFactory::weekHours());
        $this->edit($place)->fillForm(['opening_hours' => $hours])->call('save')->assertHasNoFormErrors();

        $this->assertGreaterThan(0, DB::table('place_hours')->where('place_id', $place->id)->count());
    }

    public function test_overlapping_slots_are_rejected_with_the_day_name(): void
    {
        $this->asAdmin();
        $place = Place::factory()->create();

        $hours = OpeningHoursForm::toFormState(null);
        $hours['fri'] = ['state' => 'open', 'slot1_from' => '09:00', 'slot1_to' => '14:00', 'has_second' => true, 'slot2_from' => '13:00', 'slot2_to' => '20:00'];

        $component = $this->edit($place)->fillForm(['opening_hours' => $hours])->call('save');

        $component->assertHasFormErrors(['opening_hours.fri.state']);
        $this->assertNull($place->refresh()->opening_hours);
    }

    public function test_ramadan_hours_follow_the_toggle(): void
    {
        $this->asAdmin();
        $place = Place::factory()->create();
        $ramadan = OpeningHoursForm::toFormState(PlaceFactory::weekHours());

        $this->edit($place)
            ->fillForm(['has_ramadan_hours' => true, 'ramadan_hours' => $ramadan])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('open', $place->refresh()->ramadan_hours['sat']['state']);

        // لما الزرار يتقفل مواعيد رمضان بتتصفّر حتى لو الجدول فيه بيانات
        $this->edit($place)
            ->fillForm(['has_ramadan_hours' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($place->refresh()->ramadan_hours);
    }

    public function test_copy_saturday_to_every_day(): void
    {
        $this->asAdmin();
        $place = Place::factory()->create();

        $hours = OpeningHoursForm::toFormState(null);
        $hours['sat'] = ['state' => 'open', 'slot1_from' => '10:00', 'slot1_to' => '22:00', 'has_second' => false, 'slot2_from' => null, 'slot2_to' => null];

        $component = $this->edit($place)->fillForm(['opening_hours' => $hours]);

        // أزرار النسخ جوه السكشن: بنختبر الدالة نفسها اللي بيستخدمها الزرار
        $copied = OpeningHoursForm::copyDay($component->get('data.opening_hours'), 'sat', OpeningHours::DAYS);

        foreach (OpeningHours::DAYS as $day) {
            $this->assertSame('10:00', $copied[$day]['slot1_from']);
        }
    }

    // ───────────────────────── الخصائص والتصنيفات ─────────────────────────

    public function test_attributes_from_the_four_groups_are_synced(): void
    {
        $this->asAdmin();
        $place = Place::factory()->create();
        $vibe = Attribute::query()->where('group', 'vibe')->orderBy('id')->firstOrFail();
        $services = Attribute::query()->where('group', 'services')->orderBy('id')->firstOrFail();

        $this->edit($place)
            ->fillForm(['attributes_vibe' => [$vibe->id], 'attributes_services' => [$services->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(
            [$vibe->id, $services->id],
            $place->placeAttributes()->pluck('attributes.id')->all(),
        );

        // إزالة واحدة
        $this->edit($place)->fillForm(['attributes_services' => []])->call('save')->assertHasNoFormErrors();

        $this->assertSame([$vibe->id], $place->placeAttributes()->pluck('attributes.id')->all());
    }

    public function test_a_hidden_attribute_is_kept_when_the_form_saves(): void
    {
        $this->asAdmin();
        $place = Place::factory()->create();
        $visible = Attribute::query()->where('is_active', true)->orderBy('id')->firstOrFail();
        $hidden = Attribute::query()->where('id', '!=', $visible->id)->orderBy('id')->firstOrFail();
        $hidden->update(['is_active' => false]);
        $place->placeAttributes()->sync([$hidden->id]);

        $this->edit($place)
            ->fillForm(['attributes_'.$visible->group => [$visible->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(
            [$visible->id, $hidden->id],
            $place->placeAttributes()->pluck('attributes.id')->all(),
        );
    }

    public function test_single_category_becomes_primary_and_removed_one_clears_it(): void
    {
        $this->asAdmin();
        [$first, $second] = Category::query()->orderBy('id')->limit(2)->get();

        $component = Livewire::test(CreatePlace::class)
            ->fillForm(['categories' => [$second->id]])
            ->assertFormSet(['primary_category_id' => $second->id]);

        $component->fillForm(['categories' => [$first->id], 'primary_category_id' => $second->id]);
        $this->assertContains((int) $component->get('data.primary_category_id'), [$second->id]);
    }

    // ───────────────────────── التواصل ─────────────────────────

    public function test_phone_numbers_are_stored_in_international_form(): void
    {
        $this->asAdmin();

        $this->create(['phone' => '010 0123 4567', 'whatsapp' => '٠١١١٢٣٤٥٦٧٨'])
            ->call('create')
            ->assertHasNoFormErrors();

        $place = Place::query()->firstOrFail();
        $this->assertSame('+201001234567', $place->phone);
        $this->assertSame('+201112345678', $place->whatsapp);
    }

    public function test_invalid_phone_and_landline_whatsapp_are_rejected(): void
    {
        $this->asAdmin();

        $this->create(['phone' => '0100', 'whatsapp' => '0225123456'])
            ->call('create')
            ->assertHasFormErrors(['phone', 'whatsapp']);

        $this->assertSame(0, Place::query()->count());
    }

    public function test_landline_and_hotline_are_valid_phones(): void
    {
        $this->asAdmin();

        $this->create(['phone' => '0225123456'])->call('create')->assertHasNoFormErrors();
        $this->assertSame('+20225123456', Place::query()->firstOrFail()->phone);

        // اسم تاني بالاتنين: نفس الاسم على نفس الـ pin بيطلّع تحذير التكرار (PlaceDuplicateWarningTest)
        $this->create(['name_ar' => 'مكان الخط الساخن', 'name_en' => 'Hotline Place', 'phone' => '16000'])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame('16000', Place::query()->where('name_en', 'Hotline Place')->firstOrFail()->phone);
    }

    public function test_instagram_takes_the_handle_from_a_link(): void
    {
        $this->asAdmin();

        $this->create(['instagram' => 'https://www.instagram.com/cilantro.eg/?hl=en'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('cilantro.eg', Place::query()->firstOrFail()->instagram);
    }

    public function test_links_must_be_https(): void
    {
        $this->asAdmin();

        $this->create(['website' => 'http://cilantro.example', 'menu_url' => 'https://cilantro.example/menu'])
            ->call('create')
            ->assertHasFormErrors(['website']);
    }

    // ───────────────────────── الـ slug والأسماء البديلة ─────────────────────────

    public function test_slug_is_editable_until_the_first_publish_then_locked(): void
    {
        $this->asAdmin();
        $place = Place::factory()->create(['name_en' => 'Slug Test']);

        $this->edit($place)->fillForm(['slug' => 'my-custom-slug'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('my-custom-slug', $place->refresh()->slug);

        $published = Place::factory()->published()->create(['name_en' => 'Locked Place']);
        $original = $published->slug;
        $this->edit($published)->fillForm(['slug' => 'hacked-slug'])->call('save');

        $this->assertSame($original, $published->refresh()->slug);
    }

    public function test_slug_rejects_reserved_taken_and_malformed_values(): void
    {
        $this->asAdmin();
        $taken = Place::factory()->create(['name_en' => 'Taken Place']);
        $place = Place::factory()->create();

        foreach (['nearby', $taken->slug, 'Bad Slug!'] as $slug) {
            $this->edit($place)->fillForm(['slug' => $slug])->call('save')->assertHasFormErrors(['slug']);
        }
    }

    public function test_search_aliases_are_limited_and_trimmed(): void
    {
        $this->asAdmin();

        $this->create(['search_aliases' => ['silantro', ' cilantro ', 'silantro']])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['silantro', 'cilantro'], Place::query()->firstOrFail()->search_aliases);

        $this->create(['name_en' => 'Too Many', 'search_aliases' => array_map(fn ($i) => "alias{$i}", range(1, 11))])
            ->call('create')
            ->assertHasFormErrors(['search_aliases']);
    }

    // ───────────────────────── الموقع ─────────────────────────

    public function test_extract_location_fills_the_pin_from_a_google_maps_link(): void
    {
        $this->asAdmin();

        $component = Livewire::test(CreatePlace::class)
            ->fillForm(['google_maps_url' => 'https://www.google.com/maps/place/Cilantro/@30.0300,31.4700,17z/data=!3d30.0311234!4d31.4722345'])
            ->callAction($this->fieldAction('extractLocation', 'place-tabs.location.location-actions'));

        $component->assertFormSet(['latitude' => 30.0311234, 'longitude' => 31.4722345]);
    }

    public function test_extract_location_follows_a_short_link(): void
    {
        $this->asAdmin();
        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, ['Location' => 'https://www.google.com/maps/@30.1,31.2,17z']),
        ]);

        Livewire::test(CreatePlace::class)
            ->fillForm(['google_maps_url' => 'https://maps.app.goo.gl/AbCdEf123'])
            ->callAction($this->fieldAction('extractLocation', 'place-tabs.location.location-actions'))
            ->assertFormSet(['latitude' => 30.1, 'longitude' => 31.2]);
    }

    public function test_extract_location_refuses_foreign_links_and_pins_outside_egypt(): void
    {
        $this->asAdmin();
        Http::fake();

        $component = Livewire::test(CreatePlace::class)
            ->fillForm(['google_maps_url' => 'https://evil.example/@30.1,31.2,17z'])
            ->callAction($this->fieldAction('extractLocation', 'place-tabs.location.location-actions'));

        $component->assertNotified('الرابط ده مش رابط Google Maps');
        $this->assertNull($component->get('data.latitude'));
        Http::assertNothingSent();

        $component
            ->fillForm(['google_maps_url' => 'https://www.google.com/maps/@48.85,2.35,12z'])
            ->callAction($this->fieldAction('extractLocation', 'place-tabs.location.location-actions'))
            ->assertNotified('الموقع اللي في الرابط برّه مصر');
    }

    public function test_moving_the_pin_suggests_the_nearest_area(): void
    {
        $this->asAdmin();
        $area = Area::query()->where('is_active', true)->firstOrFail();

        $component = Livewire::test(CreatePlace::class)
            ->set('data.latitude', (float) $area->center_lat)
            ->set('data.longitude', (float) $area->center_lng);

        $component->assertFormSet(['area_id' => $area->id, 'city_id' => $area->city_id]);
    }

    public function test_a_manual_area_choice_is_not_overwritten_by_a_pin_move(): void
    {
        $this->asAdmin();
        $this->seed(AreaSeeder::class);
        [$suggested, $manual] = Area::query()->orderBy('id')->limit(2)->get();

        $component = Livewire::test(CreatePlace::class)
            ->set('data.latitude', (float) $suggested->center_lat)
            ->set('data.longitude', (float) $suggested->center_lng)
            ->assertFormSet(['area_id' => $suggested->id])
            ->set('data.area_id', $manual->id)
            ->set('data.latitude', (float) $suggested->center_lat + 0.001)
            ->set('data.longitude', (float) $suggested->center_lng + 0.001);

        $component->assertFormSet(['area_id' => $manual->id]);
    }

    // ───────────────────────── الإدارة ─────────────────────────

    public function test_confirm_data_stamps_verified_at(): void
    {
        $this->asModerator();
        $place = Place::factory()->create();

        $this->edit($place)->callAction($this->fieldAction('confirmData', 'place-tabs.admin.admin-actions'));

        $this->assertNotNull($place->refresh()->verified_at);
    }

    public function test_only_an_admin_sees_release_claim(): void
    {
        $owner = User::factory()->create()->assignRole('owner');
        $place = Place::factory()->create(['claimed_by' => $owner->id]);

        $this->asModerator();
        $this->assertFalse(auth()->user()->can('releaseClaim', $place));

        $this->asAdmin();
        $this->assertTrue(auth()->user()->can('releaseClaim', $place));
    }

    public function test_internal_notes_are_limited(): void
    {
        $this->asAdmin();

        $this->create(['internal_notes' => str_repeat('ا', 2001)])
            ->call('create')
            ->assertHasFormErrors(['internal_notes']);
    }

    public function test_the_list_links_rows_to_the_edit_page_and_offers_new_place(): void
    {
        $this->asAdmin();
        $place = Place::factory()->create();

        $this->get('/admin/places')->assertOk()->assertSee('/admin/places/'.$place->id.'/edit');
        $this->get('/admin/places')->assertSee('مكان جديد');
    }
}
