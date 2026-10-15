<?php

namespace Tests\Feature;

use App\Mail\TeamInvitationMail;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\TeamInvitations;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * قبول دعوة الفريق من الرابط (A7): الحساب بيتعمل بالدور المحدد، والرابط لمرة واحدة وصالح 7 أيام.
 */
class TeamInvitationAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        RateLimiter::clear('team-invitation|127.0.0.1');
        $this->admin = User::factory()->create()->assignRole('admin');
    }

    /**
     * يعمل دعوة ويرجّع الرابط اللي اتبعت في الإيميل.
     */
    private function invite(string $email = 'new@example.com', string $role = 'moderator'): string
    {
        Mail::fake();

        app(TeamInvitations::class)->invite($email, $role, $this->admin);

        $url = null;
        Mail::assertSent(TeamInvitationMail::class, function (TeamInvitationMail $mail) use (&$url) {
            $url = $mail->url;

            return true;
        });

        return (string) $url;
    }

    private function accept(string $url, array $data = []): TestResponse
    {
        return $this->post($url, array_merge([
            'name' => 'عضو جديد',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ], $data));
    }

    public function test_the_link_opens_the_form_for_a_new_account(): void
    {
        $url = $this->invite();

        $this->get($url)
            ->assertOk()
            ->assertSee('new@example.com')
            ->assertSee('مشرف')
            ->assertSee('name="password"', false);
    }

    public function test_accepting_creates_the_account_with_the_role_and_burns_the_link(): void
    {
        $url = $this->invite('new@example.com', 'moderator');

        $this->accept($url)->assertOk()->assertSee('أهلاً بيك في الفريق');

        $user = User::query()->where('email', 'new@example.com')->firstOrFail();
        $this->assertSame('عضو جديد', $user->name);
        $this->assertTrue(Hash::check('secret123', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('active', $user->status);
        $this->assertTrue($user->hasRole('moderator'));
        $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')));
        $this->assertNotNull(TeamInvitation::query()->first()->accepted_at);

        // الرابط اتحرق
        $this->get($url)->assertStatus(410);
        $this->accept($url, ['name' => 'تاني'])->assertStatus(410);
        $this->assertSame(1, User::query()->where('email', 'new@example.com')->count());
    }

    public function test_an_admin_invitation_gives_the_admin_role(): void
    {
        $url = $this->invite('boss@example.com', 'admin');

        $this->accept($url)->assertOk();

        $this->assertTrue(User::query()->where('email', 'boss@example.com')->firstOrFail()->hasRole('admin'));
    }

    public function test_the_link_expires_after_seven_days(): void
    {
        $url = $this->invite();

        $this->travelTo(now()->addDays(7)->addMinute());

        $this->get($url)->assertStatus(410)->assertSee('انتهت');
        $this->accept($url)->assertStatus(410);
        $this->assertNull(User::query()->where('email', 'new@example.com')->first());
    }

    public function test_the_link_is_still_good_on_the_sixth_day(): void
    {
        $url = $this->invite();

        $this->travelTo(now()->addDays(6));

        $this->get($url)->assertOk();
    }

    public function test_a_wrong_token_is_the_same_page_as_an_expired_one(): void
    {
        $this->get('/team/invitations/'.str_repeat('a', 64))->assertStatus(410)->assertSee('انتهت');
    }

    public function test_the_token_is_stored_hashed(): void
    {
        $url = $this->invite();
        $token = basename(parse_url($url, PHP_URL_PATH));

        $invitation = TeamInvitation::query()->firstOrFail();

        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
    }

    public function test_a_new_account_needs_a_valid_name_and_password(): void
    {
        $url = $this->invite();

        $this->accept($url, ['name' => 'ع'])->assertSessionHasErrors('name');
        $this->accept($url, ['password' => 'short'])->assertSessionHasErrors('password');
        $this->accept($url, ['password_confirmation' => 'different123'])->assertSessionHasErrors('password');

        $this->assertNull(User::query()->where('email', 'new@example.com')->first());
        $this->assertNull(TeamInvitation::query()->first()->accepted_at);
    }

    public function test_a_regular_account_gets_the_role_without_a_new_password(): void
    {
        $existing = User::factory()->create(['email' => 'user@example.com', 'name' => 'مستخدم']);
        $oldPassword = $existing->password;
        $url = $this->invite('user@example.com', 'moderator');

        $this->get($url)->assertOk()->assertDontSee('name="password"', false);

        $this->post($url)->assertOk();

        $existing->refresh();
        $this->assertTrue($existing->hasRole('moderator'));
        $this->assertSame($oldPassword, $existing->password);
        $this->assertSame('مستخدم', $existing->name);
        $this->assertSame(1, User::query()->where('email', 'user@example.com')->count());
    }

    public function test_an_account_suspended_after_the_invitation_cannot_accept(): void
    {
        $existing = User::factory()->create(['email' => 'user@example.com']);
        $url = $this->invite('user@example.com', 'moderator');

        $existing->forceFill(['status' => 'suspended', 'suspended_at' => now()])->save();

        $this->post($url)->assertStatus(410);
        $this->assertFalse($existing->refresh()->hasRole('moderator'));
    }

    public function test_the_mail_carries_the_role_the_inviter_and_the_days(): void
    {
        Mail::fake();
        app(TeamInvitations::class)->invite('new@example.com', 'admin', $this->admin);

        Mail::assertSent(TeamInvitationMail::class, function (TeamInvitationMail $mail) {
            return $mail->role === 'أدمن'
                && $mail->days === 7
                && $mail->hasTo('new@example.com')
                && str_contains($mail->url, '/team/invitations/')
                && str_contains($mail->render(), 'قبول الدعوة');
        });
    }
}
