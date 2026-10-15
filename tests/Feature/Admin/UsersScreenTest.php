<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\TeamInvitations\Pages\ListTeamInvitations;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Mail\TeamInvitationMail;
use App\Models\SocialAccount;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\TeamInvitations;
use App\Support\UserModeration;
use App\Support\UserModerationException;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * شاشة المستخدمين (A7، 02 القسم A7): مين بيشوفها، والقايمة بالحالات والأدوار، والإيقاف وإلغاؤه بسبب،
 * وتغيير الدور، ودعوة الفريق. القواعد نفسها (`UserModeration`) متجربة هنا كمان من غير الـ UI.
 */
class UsersScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Filament::setCurrentPanel('admin');
    }

    private function asAdmin(): User
    {
        $admin = User::factory()->create(['name' => 'الأدمن'])->assignRole('admin');
        $this->actingAs($admin);

        return $admin;
    }

    private function asModerator(): User
    {
        $moderator = User::factory()->create()->assignRole('moderator');
        $this->actingAs($moderator);

        return $moderator;
    }

    private function list()
    {
        return Livewire::test(ListUsers::class);
    }

    // ───────────────────────── الوصول ─────────────────────────

    public function test_a_regular_user_gets_403_on_the_users_screen(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/users')->assertForbidden();
    }

    public function test_a_moderator_cannot_open_the_users_screen_or_the_invitations(): void
    {
        $this->asModerator();

        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/team-invitations')->assertForbidden();
    }

    public function test_an_admin_opens_the_users_screen_and_the_invitations(): void
    {
        $this->asAdmin();

        $this->get('/admin/users')->assertOk()->assertSee('المستخدمين');
        $this->get('/admin/team-invitations')->assertOk()->assertSee('دعوات الفريق');
    }

    // ───────────────────────── القايمة ─────────────────────────

    public function test_the_list_shows_every_status_and_the_tabs_filter_them(): void
    {
        $this->asAdmin();

        $active = User::factory()->create(['name' => 'نشيط']);
        $suspended = User::factory()->create(['name' => 'موقوف', 'status' => 'suspended', 'suspended_reason' => 'سبام', 'suspended_at' => now()]);
        $deleted = User::factory()->create(['name' => 'محذوف']);
        $deleted->delete();
        $mod = User::factory()->create(['name' => 'مشرف'])->assignRole('moderator');

        $this->list()
            ->assertCanSeeTableRecords([$active, $suspended, $deleted, $mod])
            ->set('activeTab', 'suspended')
            ->assertCanSeeTableRecords([$suspended])
            ->assertCanNotSeeTableRecords([$active, $deleted, $mod])
            ->set('activeTab', 'deleted')
            ->assertCanSeeTableRecords([$deleted])
            ->assertCanNotSeeTableRecords([$active, $suspended])
            ->set('activeTab', 'team')
            ->assertCanSeeTableRecords([$mod])
            ->assertCanNotSeeTableRecords([$active, $suspended, $deleted]);
    }

    public function test_the_status_label_puts_deleted_over_suspended(): void
    {
        $user = User::factory()->create(['status' => 'suspended', 'suspended_at' => now()]);
        $this->assertSame('suspended', UsersTable::statusOf($user));

        $user->delete();
        $this->assertSame('deleted', UsersTable::statusOf($user->refresh()));
        $this->assertSame('active', UsersTable::statusOf(User::factory()->create()));
    }

    public function test_the_registration_method_follows_the_social_accounts(): void
    {
        $email = User::factory()->create();
        $google = User::factory()->create();
        $google->socialAccounts()->create(['provider' => SocialAccount::PROVIDER_GOOGLE, 'provider_user_id' => 'g-1']);
        $apple = User::factory()->create();
        $apple->socialAccounts()->create(['provider' => SocialAccount::PROVIDER_APPLE, 'provider_user_id' => 'a-1']);

        $this->assertSame(['إيميل'], UsersTable::registrationMethods($email->load('socialAccounts')));
        $this->assertSame(['Google'], UsersTable::registrationMethods($google->load('socialAccounts')));
        $this->assertSame(['Apple'], UsersTable::registrationMethods($apple->load('socialAccounts')));

        $this->asAdmin();
        $this->list()
            ->filterTable('registration', 'google')
            ->assertCanSeeTableRecords([$google])
            ->assertCanNotSeeTableRecords([$email, $apple])
            ->filterTable('registration', 'email')
            ->assertCanSeeTableRecords([$email])
            ->assertCanNotSeeTableRecords([$google, $apple]);
    }

    public function test_the_role_filter_and_labels(): void
    {
        $this->asAdmin();

        $plain = User::factory()->create();
        $mod = User::factory()->create()->assignRole('moderator');

        $this->assertSame(['مستخدم'], UsersTable::roleLabels($plain->load('roles')));
        $this->assertSame(['مشرف'], UsersTable::roleLabels($mod->load('roles')));

        $this->list()
            ->filterTable('role', 'moderator')
            ->assertCanSeeTableRecords([$mod])
            ->assertCanNotSeeTableRecords([$plain])
            ->filterTable('role', 'none')
            ->assertCanSeeTableRecords([$plain])
            ->assertCanNotSeeTableRecords([$mod]);
    }

    public function test_the_list_is_searchable_by_name_and_email(): void
    {
        $this->asAdmin();

        $first = User::factory()->create(['name' => 'سلمى', 'email' => 'salma@example.com']);
        $second = User::factory()->create(['name' => 'كريم', 'email' => 'karim@example.com']);

        $this->list()
            ->searchTable('salma@')
            ->assertCanSeeTableRecords([$first])
            ->assertCanNotSeeTableRecords([$second])
            ->searchTable('كريم')
            ->assertCanSeeTableRecords([$second])
            ->assertCanNotSeeTableRecords([$first]);
    }

    // ───────────────────────── الإيقاف ─────────────────────────

    public function test_an_admin_suspends_with_a_reason_and_every_token_is_wiped(): void
    {
        $admin = $this->asAdmin();
        $user = User::factory()->create();
        $user->createToken('phone');
        $user->createToken('tablet');
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->list()
            ->callAction(TestAction::make('suspend')->table($user), ['reason' => 'محتوى مسيء'])
            ->assertHasNoFormErrors()
            ->assertNotified();

        $user->refresh();
        $this->assertSame('suspended', $user->status);
        $this->assertSame('محتوى مسيء', $user->suspended_reason);
        $this->assertNotNull($user->suspended_at);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());

        $log = Activity::query()->where('log_name', 'user')->where('event', 'suspended')->latest('id')->first();
        $this->assertSame($user->id, $log->subject_id);
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertSame('محتوى مسيء', $log->properties['reason']);
    }

    public function test_a_suspension_needs_a_reason(): void
    {
        $this->asAdmin();
        $user = User::factory()->create();

        $this->list()
            ->callAction(TestAction::make('suspend')->table($user), ['reason' => ''])
            ->assertHasFormErrors(['reason' => 'required']);

        $this->assertSame('active', $user->refresh()->status);
    }

    public function test_an_admin_unsuspends_with_a_reason(): void
    {
        $admin = $this->asAdmin();
        $user = User::factory()->create(['status' => 'suspended', 'suspended_reason' => 'سبام', 'suspended_at' => now()]);

        $this->list()
            ->assertActionVisible(TestAction::make('unsuspend')->table($user))
            ->assertActionHidden(TestAction::make('suspend')->table($user))
            ->callAction(TestAction::make('unsuspend')->table($user), ['reason' => 'اتأكدنا إنه بالغلط'])
            ->assertNotified();

        $user->refresh();
        $this->assertSame('active', $user->status);
        $this->assertNull($user->suspended_reason);
        $this->assertNull($user->suspended_at);

        $log = Activity::query()->where('event', 'unsuspended')->latest('id')->first();
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertSame('اتأكدنا إنه بالغلط', $log->properties['reason']);
        $this->assertSame('سبام', $log->properties['suspended_reason']);
    }

    public function test_the_admin_cannot_suspend_or_demote_themselves(): void
    {
        $admin = $this->asAdmin();

        $this->list()
            ->assertActionHidden(TestAction::make('suspend')->table($admin))
            ->assertActionHidden(TestAction::make('changeRole')->table($admin));

        $this->expectException(AuthorizationException::class);
        app(UserModeration::class)->suspend($admin, $admin, 'تجربة');
    }

    public function test_a_deleted_user_has_no_actions(): void
    {
        $this->asAdmin();
        $user = User::factory()->create();
        $user->delete();

        $this->list()
            ->assertActionHidden(TestAction::make('suspend')->table($user))
            ->assertActionHidden(TestAction::make('changeRole')->table($user));

        $this->expectException(UserModerationException::class);
        app(UserModeration::class)->suspend($user->refresh(), User::query()->role('admin')->first(), 'سبب');
    }

    public function test_a_moderator_cannot_run_the_rules_directly(): void
    {
        $moderator = $this->asModerator();
        $user = User::factory()->create();

        $this->expectException(AuthorizationException::class);
        app(UserModeration::class)->suspend($user, $moderator, 'سبب');
    }

    public function test_suspending_twice_or_unsuspending_an_active_user_is_refused(): void
    {
        $admin = $this->asAdmin();
        $moderation = app(UserModeration::class);
        $user = User::factory()->create();

        try {
            $moderation->unsuspend($user, $admin, 'سبب');
            $this->fail('unsuspend of an active user should be refused');
        } catch (UserModerationException $e) {
            $this->assertSame('الحساب ده مش موقوف', $e->getMessage());
        }

        $moderation->suspend($user, $admin, 'سبب');

        $this->expectException(UserModerationException::class);
        $moderation->suspend($user->refresh(), $admin, 'سبب تاني');
    }

    // ───────────────────────── الدور ─────────────────────────

    public function test_an_admin_changes_the_role_and_it_is_logged(): void
    {
        $admin = $this->asAdmin();
        $user = User::factory()->create();

        $this->list()
            ->callAction(TestAction::make('changeRole')->table($user), ['role' => 'moderator'])
            ->assertNotified();

        $this->assertTrue($user->refresh()->hasRole('moderator'));
        $this->assertSame('moderator', UserModeration::teamRole($user));

        $this->list()
            ->callAction(TestAction::make('changeRole')->table($user), ['role' => 'admin'])
            ->assertNotified();

        $user = $user->refresh();
        $this->assertTrue($user->hasRole('admin'));
        $this->assertFalse($user->hasRole('moderator'));

        $this->list()
            ->callAction(TestAction::make('changeRole')->table($user), ['role' => 'none'])
            ->assertNotified();

        $this->assertNull(UserModeration::teamRole($user->refresh()));
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));

        $log = Activity::query()->where('event', 'role_changed')->latest('id')->first();
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertSame('admin', $log->properties['old']);
        $this->assertNull($log->properties['new']);
    }

    public function test_changing_the_team_role_keeps_the_owner_role(): void
    {
        $admin = $this->asAdmin();
        $user = User::factory()->create()->assignRole(['owner', 'moderator']);

        app(UserModeration::class)->changeRole($user, $admin, null);

        $this->assertTrue($user->refresh()->hasRole('owner'));
        $this->assertNull(UserModeration::teamRole($user));
    }

    public function test_the_same_role_or_an_unknown_one_is_refused(): void
    {
        $admin = $this->asAdmin();
        $user = User::factory()->create()->assignRole('moderator');
        $moderation = app(UserModeration::class);

        try {
            $moderation->changeRole($user, $admin, 'moderator');
            $this->fail('same role should be refused');
        } catch (UserModerationException $e) {
            $this->assertSame('ده هو الدور الحالي للمستخدم', $e->getMessage());
        }

        $this->expectException(UserModerationException::class);
        $moderation->changeRole($user, $admin, 'owner');
    }

    // ───────────────────────── دعوة الفريق من اللوحة ─────────────────────────

    public function test_an_admin_invites_a_team_member_from_the_screen(): void
    {
        Mail::fake();
        $admin = $this->asAdmin();

        $this->list()
            ->callAction('invite', ['email' => 'New.Mod@Example.com', 'role' => 'moderator'])
            ->assertHasNoFormErrors()
            ->assertNotified();

        $invitation = TeamInvitation::query()->firstOrFail();
        $this->assertSame('new.mod@example.com', $invitation->email);
        $this->assertSame('moderator', $invitation->role);
        $this->assertSame($admin->id, $invitation->invited_by);
        $this->assertTrue($invitation->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));

        Mail::assertSent(TeamInvitationMail::class, fn ($mail) => $mail->hasTo('new.mod@example.com'));
    }

    public function test_the_invite_action_is_hidden_from_a_moderator(): void
    {
        $this->asModerator();

        // ما بيدخلش الصفحة أصلاً: الرفض 403
        $this->get('/admin/users')->assertForbidden();
        $this->assertFalse(auth()->user()->can('invite', User::class));
    }

    public function test_a_team_member_cannot_be_invited_again_but_a_new_invite_replaces_a_pending_one(): void
    {
        Mail::fake();
        $admin = $this->asAdmin();
        $invitations = app(TeamInvitations::class);

        $first = $invitations->invite('person@example.com', 'moderator', $admin);
        $second = $invitations->invite('person@example.com', 'admin', $admin);

        $this->assertSame(1, TeamInvitation::query()->where('email', 'person@example.com')->count());
        $this->assertFalse(TeamInvitation::query()->whereKey($first->id)->exists());
        $this->assertSame('admin', $second->role);

        User::factory()->create(['email' => 'team@example.com'])->assignRole('moderator');

        $this->expectException(UserModerationException::class);
        $invitations->invite('team@example.com', 'admin', $admin);
    }

    public function test_the_invitations_screen_resends_and_revokes(): void
    {
        Mail::fake();
        $admin = $this->asAdmin();
        $invitation = app(TeamInvitations::class)->invite('person@example.com', 'moderator', $admin);
        $oldHash = $invitation->token_hash;

        Livewire::test(ListTeamInvitations::class)
            ->assertCanSeeTableRecords([$invitation])
            ->callAction(TestAction::make('resend')->table($invitation))
            ->assertNotified();

        $current = TeamInvitation::query()->where('email', 'person@example.com')->firstOrFail();
        $this->assertNotSame($oldHash, $current->token_hash);

        Livewire::test(ListTeamInvitations::class)
            ->callAction(TestAction::make('revoke')->table($current))
            ->assertNotified();

        $this->assertSame(0, TeamInvitation::query()->count());
    }

    public function test_an_unknown_or_missing_role_cannot_be_invited(): void
    {
        $admin = $this->asAdmin();

        $this->expectException(UserModerationException::class);
        app(TeamInvitations::class)->invite('person@example.com', 'owner', $admin);
    }
}
