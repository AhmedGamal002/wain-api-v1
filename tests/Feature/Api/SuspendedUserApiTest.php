<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\UserModeration;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * معيار قبول A7: «المستخدم الموقوف بيخرج من التطبيق في أول request». الإيقاف بيمسح الـ tokens فالرد 401
 * (التطبيق بيعامله كـ logout)، و`ACCOUNT_SUSPENDED` شبكة أمان لحالة اتغيّرت من غير مسح.
 */
class SuspendedUserApiTest extends TestCase
{
    use RefreshDatabase;

    private const AR = ['Accept-Language' => 'ar'];

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('auth');
        $this->seed(RoleSeeder::class);
    }

    private function loggedIn(): array
    {
        $user = new User(['name' => 'مستخدم', 'email' => 'user@example.com', 'password' => 'secret123']);
        $user->save();

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'user@example.com', 'password' => 'secret123'], self::AR)
            ->assertOk()
            ->json('data.token');

        return [$user, $token];
    }

    /**
     * request بالـ token. الـ guard بيحتفظ باليوزر المتحمّل جوه نفس الـ process، فبنفرّغه بين الـ requests عشان كل
     * واحد يتعامل كأنه process لوحده (زي الإنتاج).
     */
    private function me(string $token): TestResponse
    {
        app('auth')->forgetGuards();

        return $this->withToken($token)->getJson('/api/v1/auth/me', self::AR);
    }

    public function test_the_first_request_after_a_suspension_is_a_401(): void
    {
        [$user, $token] = $this->loggedIn();
        $admin = User::factory()->create()->assignRole('admin');

        $this->me($token)->assertOk();

        app(UserModeration::class)->suspend($user, $admin, 'سبام');

        $this->me($token)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_a_status_changed_without_wiping_tokens_gets_account_suspended_and_the_tokens_go(): void
    {
        [$user, $token] = $this->loggedIn();

        $user->forceFill(['status' => 'suspended', 'suspended_at' => now()])->save();
        $this->assertSame(1, $user->tokens()->count());

        $this->me($token)
            ->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_SUSPENDED');

        $this->assertSame(0, $user->tokens()->count());

        $this->me($token)->assertUnauthorized();
    }

    public function test_an_unsuspended_user_logs_in_again(): void
    {
        [$user] = $this->loggedIn();
        $admin = User::factory()->create()->assignRole('admin');
        $moderation = app(UserModeration::class);

        $moderation->suspend($user, $admin, 'سبام');

        $this->postJson('/api/v1/auth/login', ['email' => 'user@example.com', 'password' => 'secret123'], self::AR)
            ->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_SUSPENDED');

        $moderation->unsuspend($user->refresh(), $admin, 'بالغلط');

        $this->postJson('/api/v1/auth/login', ['email' => 'user@example.com', 'password' => 'secret123'], self::AR)->assertOk();
    }
}
