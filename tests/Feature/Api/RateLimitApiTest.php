<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * الحد العام للطلبات (SEC-01، 06 القسم 1): زائر 60 طلب في الدقيقة ومستخدم مسجّل 120. phpunit.xml بيعلّي الأرقام
 * عشان باقي التستات ما تتأثرش، وهنا بنرجّعها (أرقام صغيرة للمنطق، والأرقام الموثّقة في تست واحد).
 */
class RateLimitApiTest extends TestCase
{
    use RefreshDatabase;

    private const AR = ['Accept-Language' => 'ar'];

    private function limits(int $guest, int $user): void
    {
        config(['wain.rate_limit.guest' => $guest, 'wain.rate_limit.user' => $user]);
    }

    private function hit(array $headers = [], ?string $ip = null): TestResponse
    {
        if ($ip !== null) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        }

        // كل طلب في الإنتاج بيبدأ بـ process جديد؛ هنا الـ guard بيفتكر المستخدم اللي قبله، فبننسّيه
        $this->app['auth']->forgetGuards();

        return $this->getJson('/api/v1/categories', self::AR + $headers);
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('mobile')->plainTextToken];
    }

    private function makeUser(string $email): User
    {
        $user = new User(['name' => 'مستخدم', 'email' => $email, 'password' => 'oldpass123']);
        $user->save();

        return $user;
    }

    public function test_a_guest_is_throttled_after_the_budget_with_the_documented_error(): void
    {
        $this->limits(3, 6);

        for ($i = 0; $i < 3; $i++) {
            $this->hit()->assertOk();
        }

        $blocked = $this->hit()
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_REQUESTS')
            ->assertHeader('X-RateLimit-Limit', 3)
            ->assertHeader('X-RateLimit-Remaining', 0);

        $retryAfter = (int) $blocked->headers->get('Retry-After');
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);
    }

    public function test_the_documented_budgets_are_60_for_a_guest_and_120_for_a_user(): void
    {
        $this->limits(60, 120);

        for ($i = 0; $i < 60; $i++) {
            $this->hit()->assertOk();
        }
        $this->hit()->assertStatus(429);

        $headers = $this->bearer($this->makeUser('user@example.com'));
        $first = $this->hit($headers)->assertOk();

        // المسجّل ليه حدّه هو (120)، مش حد الزائر اللي اتحرق قبل كده من نفس الـ IP
        $this->assertSame('120', $first->headers->get('X-RateLimit-Limit'));
    }

    public function test_a_signed_in_user_has_a_bigger_budget_and_does_not_use_the_guest_one(): void
    {
        $this->limits(2, 4);
        $headers = $this->bearer($this->makeUser('user@example.com'));

        for ($i = 0; $i < 4; $i++) {
            $this->hit($headers)->assertOk();
        }
        $this->hit($headers)->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');

        // الزائر من نفس الـ IP لسه معاه ميزانيته كاملة
        $this->hit()->assertOk();
        $this->hit()->assertOk();
        $this->hit()->assertStatus(429);
    }

    public function test_two_users_do_not_share_a_budget(): void
    {
        $this->limits(2, 2);
        $a = $this->bearer($this->makeUser('a@example.com'));
        $b = $this->bearer($this->makeUser('b@example.com'));

        $this->hit($a)->assertOk();
        $this->hit($a)->assertOk();
        $this->hit($a)->assertStatus(429);

        $this->hit($b)->assertOk();
        $this->hit($b)->assertOk();
        $this->hit($b)->assertStatus(429);
    }

    public function test_the_same_user_keeps_one_budget_across_tokens_and_devices(): void
    {
        $this->limits(2, 2);
        $user = $this->makeUser('user@example.com');

        $this->hit($this->bearer($user) + ['X-Device-Id' => 'phone-1'])->assertOk();
        $this->hit($this->bearer($user) + ['X-Device-Id' => 'phone-2'])->assertOk();
        $this->hit($this->bearer($user) + ['X-Device-Id' => 'phone-3'])->assertStatus(429);
    }

    public function test_guests_behind_the_same_ip_are_separated_by_device_id(): void
    {
        $this->limits(2, 4);

        $this->hit(['X-Device-Id' => 'device-a'])->assertOk();
        $this->hit(['X-Device-Id' => 'device-a'])->assertOk();
        $this->hit(['X-Device-Id' => 'device-a'])->assertStatus(429);

        // جهاز تاني ورا نفس الـ IP (شبكة موبايل) ما يتحجبش
        $this->hit(['X-Device-Id' => 'device-b'])->assertOk();
    }

    public function test_a_guest_without_a_device_id_is_counted_by_ip(): void
    {
        $this->limits(2, 4);

        $this->hit([], '10.0.0.5')->assertOk();
        $this->hit([], '10.0.0.5')->assertOk();
        $this->hit([], '10.0.0.5')->assertStatus(429);

        $this->hit([], '10.0.0.6')->assertOk();
    }

    public function test_an_invalid_token_is_just_a_guest(): void
    {
        $this->limits(2, 4);

        $headers = ['Authorization' => 'Bearer 999|not-a-real-token'];

        $this->hit($headers)->assertOk();
        $this->hit($headers)->assertOk();
        $this->hit($headers)->assertStatus(429);
    }

    public function test_the_budget_comes_back_after_a_minute_and_blocked_requests_do_not_extend_it(): void
    {
        $this->limits(2, 4);

        $this->hit()->assertOk();
        $this->hit()->assertOk();
        for ($i = 0; $i < 5; $i++) {
            $this->hit()->assertStatus(429);
        }

        $this->travel(61)->seconds();

        $this->hit()->assertOk();
    }

    public function test_every_v1_route_is_behind_the_general_limiter(): void
    {
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1')) {
                // الـ middleware بعد فك الـ group (api ← throttle:api)
                $middleware = app('router')->gatherRouteMiddleware($route);

                $this->assertContains(ThrottleRequests::class.':api', $middleware, "{$route->uri()} escapes the general rate limit");
                $checked++;
            }
        }

        $this->assertGreaterThan(5, $checked);
    }

    public function test_the_stricter_limiters_still_apply_on_top_of_the_general_one(): void
    {
        $this->limits(100, 100);
        $this->makeUser('user@example.com');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'user@example.com', 'password' => 'wrong-12345'], self::AR)
                ->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'user@example.com', 'password' => 'wrong-12345'], self::AR)
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    }
}
