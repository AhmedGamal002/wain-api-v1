<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\User;
use App\Support\PlaceTransitions;
use Database\Seeders\AttributeSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CityAreaSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * إشعارات الجرس في اللوحة (03 القسم 4.9): اتبعت للمراجعة ← الأدمنز، اتنشر أو اترفض ← اللي أضافه.
 */
class PlaceNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private PlaceTransitions $transitions;

    private User $admin;

    private User $otherAdmin;

    private User $moderator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, SettingSeeder::class, CategorySeeder::class, CityAreaSeeder::class, AttributeSeeder::class]);

        $this->transitions = new PlaceTransitions;
        $this->admin = User::factory()->create()->assignRole('admin');
        $this->otherAdmin = User::factory()->create()->assignRole('admin');
        $this->moderator = User::factory()->create()->assignRole('moderator');
    }

    /** @return list<string> عناوين إشعارات المستخدم */
    private function titles(User $user): array
    {
        return $user->notifications()->get()->map(fn ($n) => $n->data['title'] ?? '')->all();
    }

    public function test_submitting_a_place_notifies_the_other_admins_but_not_the_sender(): void
    {
        $this->actingAs($this->moderator);
        $place = Place::factory()->complete()->create(['name_ar' => 'كافيه الأمل']);

        $this->transitions->submit($place, $this->moderator);

        $this->assertSame(['مكان جديد مستني مراجعة: كافيه الأمل'], $this->titles($this->admin));
        $this->assertSame(['مكان جديد مستني مراجعة: كافيه الأمل'], $this->titles($this->otherAdmin));
        $this->assertSame([], $this->titles($this->moderator));
    }

    public function test_a_suspended_admin_is_not_notified(): void
    {
        $this->otherAdmin->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($this->moderator);
        $place = Place::factory()->complete()->create();

        $this->transitions->submit($place, $this->moderator);

        $this->assertSame([], $this->titles($this->otherAdmin));
        $this->assertCount(1, $this->titles($this->admin));
    }

    public function test_publishing_a_pending_place_notifies_who_added_it(): void
    {
        $this->actingAs($this->moderator);
        $place = Place::factory()->complete()->create(['name_ar' => 'مطعم النيل']);
        $this->transitions->submit($place, $this->moderator);

        $this->actingAs($this->admin);
        $this->transitions->publish($place->refresh(), $this->admin);

        $this->assertSame(['مكانك «مطعم النيل» اتنشر'], $this->titles($this->moderator));
    }

    public function test_rejecting_notifies_who_added_it_with_the_reason(): void
    {
        $this->actingAs($this->moderator);
        $place = Place::factory()->complete()->create(['name_ar' => 'مطعم النيل']);
        $this->transitions->submit($place, $this->moderator);

        $this->actingAs($this->admin);
        $this->transitions->reject($place->refresh(), $this->admin, 'weak_photos', 'الغلاف مش واضح');

        $notification = $this->moderator->notifications()->latest()->first();
        $this->assertSame('مكانك «مطعم النيل» اترفض', $notification->data['title']);
        $this->assertSame('صور ضعيفة: الغلاف مش واضح', $notification->data['body']);
    }

    public function test_an_admin_publishing_their_own_draft_is_not_notified_about_it(): void
    {
        $this->actingAs($this->admin);
        $place = Place::factory()->complete()->create(['created_by' => $this->admin->id]);

        $this->transitions->publish($place, $this->admin);

        $this->assertSame([], $this->titles($this->admin));
    }

    public function test_hiding_and_other_changes_do_not_notify(): void
    {
        $this->actingAs($this->admin);
        $place = Place::factory()->published()->create(['created_by' => $this->moderator->id]);

        $this->transitions->hide($place, $this->admin, 'complaint');
        $place->update(['name_ar' => 'اسم جديد']);

        $this->assertSame([], $this->titles($this->moderator));
    }
}
