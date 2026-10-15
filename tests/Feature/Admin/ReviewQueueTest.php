<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\ReviewQueue\PendingPlaces;
use App\Filament\Pages\ReviewQueue\PendingReviews;
use App\Filament\Pages\ReviewQueue\ReviewQueuePage;
use App\Models\Place;
use App\Models\User;
use App\Support\PlaceTransitions;
use App\Support\ReviewQueue\ReviewQueues;
use Database\Seeders\AttributeSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CityAreaSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * طابور المراجعة (A6، 02 القسم A6): الهيكل (التابز والأعداد والقايمة الجانبية والعمر) وتاب «أماكن قيد المراجعة».
 * القواعد نفسها (نشر ورفض) في `PlaceTransitionsTest`، هنا اللي بيتأكد منه الطابور: مين بيشوفه، وإيه اللي فيه
 * وبأنهي ترتيب، والقرار من جواه.
 */
class ReviewQueueTest extends TestCase
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
        $admin = User::factory()->create()->assignRole('admin');
        $this->actingAs($admin);

        return $admin;
    }

    private function asModerator(): User
    {
        $moderator = User::factory()->create()->assignRole('moderator');
        $this->actingAs($moderator);

        return $moderator;
    }

    /**
     * مكان قيد المراجعة بعمر معيّن (`submitted_at` بيتملى من الـ Observer بـ now()، فبنرجّعه للماضي بعد الإنشاء).
     */
    private function pending(int $hoursAgo = 1, array $attributes = []): Place
    {
        $place = Place::factory()->complete()->status(Place::STATUS_PENDING)->create($attributes);

        DB::table('places')->where('id', $place->id)->update(['submitted_at' => now()->subHours($hoursAgo)]);

        return $place->refresh();
    }

    private function queue()
    {
        return Livewire::test(PendingPlaces::class);
    }

    // ───────────────────────── الوصول ─────────────────────────

    public function test_a_regular_user_cannot_open_the_queue(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(PendingPlaces::getUrl())->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $this->get(PendingPlaces::getUrl())->assertRedirect();
    }

    public function test_the_team_opens_the_queue_at_its_own_url(): void
    {
        $this->asAdmin();
        $this->get('/admin/review-queue/places')->assertOk()->assertSee('أماكن قيد المراجعة');

        $this->asModerator();
        $this->get('/admin/review-queue/places')->assertOk();
    }

    // ───────────────────────── المحتوى والترتيب ─────────────────────────

    public function test_only_pending_places_show_and_the_oldest_comes_first(): void
    {
        $this->asAdmin();

        $newer = $this->pending(2);
        $oldest = $this->pending(60);
        $middle = $this->pending(30);
        $draft = Place::factory()->complete()->create();
        $active = Place::factory()->published()->create();
        $rejected = Place::factory()->complete()->status(Place::STATUS_REJECTED)->create();

        $this->queue()
            ->assertCanSeeTableRecords([$oldest, $middle, $newer], inOrder: true)
            ->assertCanNotSeeTableRecords([$draft, $active, $rejected]);
    }

    public function test_a_deleted_place_is_not_in_the_queue(): void
    {
        $this->asAdmin();

        $gone = $this->pending();
        $gone->delete();

        $this->queue()->assertCanNotSeeTableRecords([$gone]);
        $this->assertSame(0, PendingPlaces::queueCount());
    }

    public function test_the_queue_can_be_searched_by_name(): void
    {
        $this->asAdmin();

        $first = $this->pending(1, ['name_ar' => 'مطعم الفيروز', 'name_en' => 'Fayrouz Restaurant']);
        $second = $this->pending(2, ['name_ar' => 'كافيه النخيل', 'name_en' => 'Nakheel Cafe']);

        $this->queue()
            ->searchTable('الفيروز')
            ->assertCanSeeTableRecords([$first])
            ->assertCanNotSeeTableRecords([$second]);
    }

    public function test_an_empty_queue_says_so(): void
    {
        $this->asAdmin();

        $this->queue()->assertSee('مفيش أماكن مستنية مراجعة');
    }

    // ───────────────────────── الأعداد والعمر ─────────────────────────

    public function test_counts_and_overdue_follow_the_forty_eight_hours(): void
    {
        $this->pending(1);
        $this->pending(30);
        $this->pending(47);
        $this->pending(49);
        $this->pending(100);
        Place::factory()->published()->create();

        $this->assertSame(5, PendingPlaces::queueCount());
        $this->assertSame(2, PendingPlaces::overdueCount());
    }

    public function test_the_age_colour_is_red_after_forty_eight_hours_and_orange_after_a_day(): void
    {
        $this->assertSame('gray', ReviewQueuePage::ageColor(now()->subHours(3)));
        $this->assertSame('warning', ReviewQueuePage::ageColor(now()->subHours(25)));
        $this->assertSame('warning', ReviewQueuePage::ageColor(now()->subHours(47)));
        $this->assertSame('danger', ReviewQueuePage::ageColor(now()->subHours(49)));
        $this->assertSame('gray', ReviewQueuePage::ageColor(null));
    }

    public function test_the_age_is_written_in_arabic(): void
    {
        $this->assertSame('منذ 3 ساعات', ReviewQueuePage::ageLabel(Carbon::now()->subHours(3)));
        $this->assertSame('منذ 3 أيام', ReviewQueuePage::ageLabel(Carbon::now()->subDays(3)));
        $this->assertSame('منذ 20 دقيقة', ReviewQueuePage::ageLabel(Carbon::now()->subMinutes(20)));
        $this->assertSame('—', ReviewQueuePage::ageLabel(null));
    }

    // ───────────────────────── القايمة الجانبية والتابز ─────────────────────────

    public function test_the_sidebar_has_one_item_with_the_total_and_goes_red_when_something_is_overdue(): void
    {
        $this->asAdmin();

        $this->assertTrue(PendingPlaces::shouldRegisterNavigation());
        $this->assertNull(PendingPlaces::getNavigationBadge());
        $this->assertSame('warning', PendingPlaces::getNavigationBadgeColor());

        $this->pending(1);
        $this->pending(2);

        $this->assertSame('2', PendingPlaces::getNavigationBadge());
        $this->assertSame('warning', PendingPlaces::getNavigationBadgeColor());

        $this->pending(72);

        $this->assertSame('3', PendingPlaces::getNavigationBadge());
        $this->assertSame('danger', PendingPlaces::getNavigationBadgeColor());
    }

    public function test_the_registry_lists_the_tab_for_the_team_with_its_count(): void
    {
        $this->asAdmin();
        $this->pending();

        $this->assertSame([PendingPlaces::class, PendingReviews::class], ReviewQueues::visible());
        $this->assertSame(PendingPlaces::class, ReviewQueues::first());
        $this->assertSame(1, ReviewQueues::total());

        $this->queue()->assertSee('أماكن قيد المراجعة');
    }

    // ───────────────────────── القرار ─────────────────────────

    public function test_an_admin_publishes_from_the_queue(): void
    {
        $this->asAdmin();
        $place = $this->pending();

        $this->queue()
            ->assertActionVisible(TestAction::make('publish')->table($place))
            ->callAction(TestAction::make('publish')->table($place))
            ->assertNotified();

        $this->assertSame(Place::STATUS_ACTIVE, $place->refresh()->status);
        $this->queue()->assertCanNotSeeTableRecords([$place]);
    }

    public function test_an_admin_rejects_with_a_reason_and_it_leaves_the_queue(): void
    {
        $this->asAdmin();
        $place = $this->pending();

        $this->queue()
            ->callAction(TestAction::make('reject')->table($place), ['reason' => 'weak_photos', 'notes' => null])
            ->assertHasNoFormErrors();

        $place->refresh();
        $this->assertSame(Place::STATUS_REJECTED, $place->status);
        $this->assertSame(PlaceTransitions::REJECT_REASONS['weak_photos'], $place->status_reason);
    }

    public function test_a_rejection_needs_a_reason(): void
    {
        $this->asAdmin();
        $place = $this->pending();

        $this->queue()
            ->callAction(TestAction::make('reject')->table($place), ['reason' => null])
            ->assertHasFormErrors(['reason' => 'required']);

        $this->assertSame(Place::STATUS_PENDING, $place->refresh()->status);
    }

    public function test_the_other_reason_needs_a_note(): void
    {
        $this->asAdmin();
        $place = $this->pending();

        $this->queue()
            ->callAction(TestAction::make('reject')->table($place), ['reason' => 'other', 'notes' => ''])
            ->assertHasFormErrors(['notes' => 'required']);

        $this->assertSame(Place::STATUS_PENDING, $place->refresh()->status);
    }

    public function test_a_moderator_sees_the_queue_but_not_the_decision_buttons(): void
    {
        $this->asModerator();
        $place = $this->pending();

        $this->queue()
            ->assertCanSeeTableRecords([$place])
            ->assertActionHidden(TestAction::make('publish')->table($place))
            ->assertActionHidden(TestAction::make('reject')->table($place));
    }

    public function test_the_keyboard_helper_only_offers_what_the_user_may_do(): void
    {
        $this->asAdmin();
        $place = $this->pending();

        $items = $this->queue()->instance()->keyboardItems();

        $this->assertCount(1, $items);
        $this->assertSame($place->id, $items[0]['id']);
        $this->assertTrue($items[0]['publish']);
        $this->assertTrue($items[0]['reject']);
        $this->assertStringContainsString('/admin/places/'.$place->id.'/edit', $items[0]['url']);

        $this->asModerator();

        $moderator = $this->queue()->instance()->keyboardItems();
        $this->assertFalse($moderator[0]['publish']);
        $this->assertFalse($moderator[0]['reject']);
    }

    // ───────────────────────── submitted_at ─────────────────────────

    public function test_submitted_at_is_set_when_a_place_enters_review(): void
    {
        $admin = $this->asAdmin();
        $place = Place::factory()->complete()->create();

        $this->assertNull($place->submitted_at);

        app(PlaceTransitions::class)->submit($place, $admin);

        $this->assertNotNull($place->refresh()->submitted_at);
        $this->assertTrue($place->submitted_at->diffInSeconds(now(), absolute: true) < 5);
    }

    public function test_a_place_created_as_pending_has_it_and_publishing_keeps_it(): void
    {
        $admin = $this->asAdmin();
        $place = Place::factory()->complete()->status(Place::STATUS_PENDING)->create();

        $submittedAt = $place->submitted_at;
        $this->assertNotNull($submittedAt);

        $this->travel(3)->hours();
        app(PlaceTransitions::class)->publish($place, $admin);

        $this->assertEquals($submittedAt->timestamp, $place->refresh()->submitted_at->timestamp);
    }

    public function test_a_second_submission_after_a_rejection_restarts_the_age(): void
    {
        $admin = $this->asAdmin();
        $place = $this->pending(60);
        $first = $place->submitted_at;

        app(PlaceTransitions::class)->reject($place, $admin, 'weak_photos');

        // اللي أضافه بيعدّل ويبعت تاني من الفورم (نية «إرسال» بتحفظ الحالة pending مباشرة)
        $this->travel(1)->hours();
        $place->refresh()->update(['status' => Place::STATUS_PENDING]);

        $this->assertTrue($place->refresh()->submitted_at->greaterThan($first));
        $this->assertLessThan(5, $place->submitted_at->diffInSeconds(now(), absolute: true));
    }
}
