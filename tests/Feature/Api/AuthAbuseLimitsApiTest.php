<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * سقوف الدخول والتسجيل ونسيت الباسورد فوق الـ 5 في الدقيقة لكل (IP + إيميل) (SEC-01): من غيرها IP واحد يجرّب
 * باسوردات على إيميلات كتير أو يعمل حسابات من غير حد (كل إيميل ميزانية جديدة). وإن الدخول بيعمل نفس الـ hash
 * سواء الإيميل موجود أو لأ (من غير كده زمن الرد بيكشف مين عنده حساب).
 */
class AuthAbuseLimitsApiTest extends TestCase
{
    use RefreshDatabase;

    private const AR = ['Accept-Language' => 'ar'];

    private function login(string $email, ?string $ip = null, string $password = 'wrong-12345'): TestResponse
    {
        if ($ip !== null) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        }

        // كل طلب في الإنتاج process جديد؛ هنا الـ guard بيفتكر المستخدم اللي قبله
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password], self::AR);
    }

    private function register(string $email, ?string $ip = null): TestResponse
    {
        if ($ip !== null) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        }

        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/register', [
            'name' => 'مستخدم',
            'email' => $email,
            'password' => 'secret-pass1',
            'password_confirmation' => 'secret-pass1',
            'terms_accepted' => true,
        ], self::AR);
    }

    private function forgot(string $email, ?string $ip = null): TestResponse
    {
        if ($ip !== null) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        }

        return $this->postJson('/api/v1/auth/password/forgot', ['email' => $email], self::AR);
    }

    private function verify(string $email, ?string $ip = null): TestResponse
    {
        if ($ip !== null) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        }

        return $this->postJson('/api/v1/auth/password/verify', ['email' => $email, 'code' => '123456'], self::AR);
    }

    private function makeUser(string $email = 'user@example.com'): User
    {
        $user = new User(['name' => 'مستخدم', 'email' => $email, 'password' => 'realpass123']);
        $user->save();

        return $user;
    }

    // ---- سقف الـ IP

    public function test_one_ip_cannot_try_passwords_against_unlimited_emails(): void
    {
        config(['wain.rate_limit.auth_ip_minute' => 4]);

        // كل إيميل بميزانية الـ 5 بتاعته، بس الـ IP كله له 4 في الدقيقة
        for ($i = 0; $i < 4; $i++) {
            $this->login("victim{$i}@example.com", '10.0.0.5')->assertUnauthorized();
        }

        $this->login('victim4@example.com', '10.0.0.5')
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_REQUESTS');

        // IP تاني ما اتأثرش، والسقف بيرجع بعد دقيقة
        $this->login('victim5@example.com', '10.0.0.6')->assertUnauthorized();

        $this->travel(61)->seconds();
        $this->login('victim6@example.com', '10.0.0.5')->assertUnauthorized();
    }

    public function test_one_ip_cannot_open_unlimited_accounts(): void
    {
        config(['wain.rate_limit.auth_ip_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->register("spam{$i}@example.com", '10.0.0.5')->assertCreated();
        }

        $this->register('spam3@example.com', '10.0.0.5')->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');

        $this->assertSame(3, User::query()->count());
    }

    public function test_login_and_register_share_the_ip_ceiling(): void
    {
        config(['wain.rate_limit.auth_ip_minute' => 2]);

        $this->login('a@example.com', '10.0.0.5')->assertUnauthorized();
        $this->register('b@example.com', '10.0.0.5')->assertCreated();
        $this->login('c@example.com', '10.0.0.5')->assertStatus(429);
    }

    public function test_the_hourly_ip_ceiling_outlives_the_minute_one(): void
    {
        config(['wain.rate_limit.auth_ip_minute' => 100, 'wain.rate_limit.auth_ip_hour' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->login("victim{$i}@example.com", '10.0.0.5')->assertUnauthorized();
        }

        $this->login('victim3@example.com', '10.0.0.5')->assertStatus(429);

        // دقيقة وعشر دقايق ما بيفتحوش الساعة
        $this->travel(10)->minutes();
        $this->login('victim4@example.com', '10.0.0.5')->assertStatus(429);

        $this->travel(51)->minutes();
        $this->login('victim5@example.com', '10.0.0.5')->assertUnauthorized();
    }

    // ---- الدخول بيعدّ الفاشل بس (واي فاي مشترك)

    public function test_successful_logins_never_use_the_ip_or_email_ceilings(): void
    {
        // سقوف صغيرة جداً: لو الدخول الناجح كان بيتعدّ كان الرابع هيتحجب
        config([
            'wain.rate_limit.auth_ip_minute' => 3,
            'wain.rate_limit.auth_ip_hour' => 3,
            'wain.rate_limit.auth_email' => 2,
        ]);

        // 8 ناس مختلفين على نفس الواي فاي بيدخلوا صح
        for ($i = 0; $i < 8; $i++) {
            $this->makeUser("person{$i}@example.com");
            $this->login("person{$i}@example.com", '10.0.0.5', 'realpass123')->assertOk();
        }

        // ونفس الشخص بيدخل أكتر من مرة (من أجهزة العيلة مثلاً) من غير ما يتحجب بالـ 5 لكل IP + إيميل ولا بسقف الإيميل
        for ($i = 0; $i < 4; $i++) {
            $this->login('person0@example.com', '10.0.0.5', 'realpass123')->assertOk();
        }
    }

    public function test_failed_logins_still_hit_the_ceiling_after_successful_ones(): void
    {
        config(['wain.rate_limit.auth_ip_minute' => 3]);

        $this->makeUser('known@example.com');

        $this->login('known@example.com', '10.0.0.5', 'realpass123')->assertOk();
        $this->login('known@example.com', '10.0.0.5', 'realpass123')->assertOk();

        // الناجحين ما اتحسبوش: التلات غلطات هما اللي بيملوا السقف
        for ($i = 0; $i < 3; $i++) {
            $this->login("guess{$i}@example.com", '10.0.0.5')->assertUnauthorized();
        }

        $this->login('known@example.com', '10.0.0.5', 'realpass123')
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    }

    public function test_a_blocked_login_is_refused_even_with_the_right_password(): void
    {
        config(['wain.rate_limit.auth_email' => 2]);

        $this->makeUser('victim@example.com');

        $this->login('victim@example.com', '10.0.0.1')->assertUnauthorized();
        $this->login('victim@example.com', '10.0.0.2')->assertUnauthorized();

        // الإيميل خد 2 فاشلين من أي مكان: حتى الباسورد الصح بيتحجب لحد ما الدقيقة تعدّي
        $this->login('victim@example.com', '10.0.0.3', 'realpass123')->assertStatus(429);

        $this->travel(61)->seconds();
        $this->login('victim@example.com', '10.0.0.3', 'realpass123')->assertOk();
    }

    public function test_malformed_login_requests_count_as_failures(): void
    {
        config(['wain.rate_limit.auth_ip_minute' => 3]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5']);

        // من غير إيميل: 422 من الـ validation، وبرضه بيتعدّ على الـ IP
        for ($i = 0; $i < 3; $i++) {
            $this->app['auth']->forgetGuards();
            $this->postJson('/api/v1/auth/login', ['password' => 'x'], self::AR)->assertStatus(422);
        }

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['password' => 'x'], self::AR)->assertStatus(429);
    }

    public function test_a_suspended_account_login_counts_as_a_failure(): void
    {
        config(['wain.rate_limit.auth_ip_minute' => 2]);

        $this->makeUser('suspended@example.com')->forceFill(['status' => 'suspended'])->save();

        // 403 ACCOUNT_SUSPENDED (باسورد صح لكن الدخول اترفض): رد فشل، فبيتعدّ
        $this->login('suspended@example.com', '10.0.0.5', 'realpass123')->assertForbidden();
        $this->login('suspended@example.com', '10.0.0.5', 'realpass123')->assertForbidden();
        $this->login('suspended@example.com', '10.0.0.5', 'realpass123')->assertStatus(429);
    }

    public function test_the_documented_default_ceilings(): void
    {
        // phpunit.xml بيعلّي الأرقام، فنقرا الـ defaults من ملف الـ config نفسه: أي تغيير فيهم يبقى مقصود
        $source = (string) file_get_contents(config_path('wain.php'));

        $defaultOf = function (string $env) use ($source): int {
            $this->assertSame(1, preg_match('/env\(\''.$env.'\',\s*(\d+)\)/', $source, $match), "{$env} has no numeric default");

            return (int) $match[1];
        };

        $this->assertSame(1500, $defaultOf('WAIN_RATE_LIMIT_GUEST_IP'));
        $this->assertSame(600, $defaultOf('WAIN_RATE_LIMIT_SUGGEST_IP'));
        $this->assertSame(600, $defaultOf('WAIN_RATE_LIMIT_EVENTS_IP'));
        $this->assertSame(60, $defaultOf('WAIN_RATE_LIMIT_AUTH_IP_MINUTE'));
        $this->assertSame(600, $defaultOf('WAIN_RATE_LIMIT_AUTH_IP_HOUR'));
        $this->assertSame(10, $defaultOf('WAIN_RATE_LIMIT_AUTH_EMAIL'));
    }

    // ---- سقف الإيميل من أي IP

    public function test_a_distributed_attack_on_one_account_is_capped_by_email(): void
    {
        config(['wain.rate_limit.auth_email' => 3]);

        // كل IP لسه جوه ميزانيته (محاولة واحدة)، بس الحساب نفسه له 3 في الدقيقة من أي مكان
        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $ip) {
            $this->login('victim@example.com', $ip)->assertUnauthorized();
        }

        $this->login('victim@example.com', '10.0.0.4')->assertStatus(429);

        // إيميل تاني من نفس الـ IP ما اتأثرش
        $this->login('other@example.com', '10.0.0.4')->assertUnauthorized();
    }

    public function test_requests_without_an_email_do_not_share_one_bucket(): void
    {
        config(['wain.rate_limit.auth_email' => 1]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5']);

        for ($i = 0; $i < 3; $i++) {
            $this->app['auth']->forgetGuards();
            $this->postJson('/api/v1/auth/login', ['password' => 'x'], self::AR)->assertStatus(422);
        }
    }

    public function test_an_email_is_matched_after_trimming_and_lowercasing(): void
    {
        config(['wain.rate_limit.auth_email' => 2]);

        $this->login('Victim@Example.com', '10.0.0.1')->assertUnauthorized();
        $this->login('  victim@example.com ', '10.0.0.2')->assertUnauthorized();
        $this->login('VICTIM@example.com', '10.0.0.3')->assertStatus(429);
    }

    // ---- نسيت الباسورد

    public function test_the_password_flow_has_an_ip_ceiling_per_step(): void
    {
        Mail::fake();
        config(['wain.rate_limit.auth_ip_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->forgot("nobody{$i}@example.com", '10.0.0.5')->assertOk();
        }

        $this->forgot('nobody3@example.com', '10.0.0.5')->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');

        // خطوة الكود ميزانيتها لوحدها، فمستخدم عنده الكود في إيده ما يتحجبش بسبب خطوة «ابعت الكود»
        $this->verify('nobody0@example.com', '10.0.0.5')->assertStatus(422);

        // والدخول limiter تاني خالص
        $this->login('nobody0@example.com', '10.0.0.5')->assertUnauthorized();
    }

    public function test_the_password_flow_has_an_email_ceiling_across_ips(): void
    {
        Mail::fake();
        config(['wain.rate_limit.auth_email' => 2]);

        $this->verify('victim@example.com', '10.0.0.1')->assertStatus(422);
        $this->verify('victim@example.com', '10.0.0.2')->assertStatus(422);
        $this->verify('victim@example.com', '10.0.0.3')->assertStatus(429);

        // «ابعت الكود» لنفس الإيميل ميزانيته لوحدها
        $this->forgot('victim@example.com', '10.0.0.3')->assertOk();
    }

    public function test_a_normal_user_with_a_few_typos_is_never_blocked_by_the_ceilings(): void
    {
        $this->makeUser();

        // بالأرقام الافتراضية (20 للـ IP و10 للإيميل): 3 غلطات وبعدها الدخول الصح
        config(['wain.rate_limit.auth_ip_minute' => 20, 'wain.rate_limit.auth_ip_hour' => 200, 'wain.rate_limit.auth_email' => 10]);

        for ($i = 0; $i < 3; $i++) {
            $this->login('user@example.com', '10.0.0.5')->assertUnauthorized();
        }

        $this->login('user@example.com', '10.0.0.5', 'realpass123')->assertOk();
    }

    // ---- مفيش فرق في تكلفة الدخول بين إيميل موجود وغير موجود

    /**
     * hasher بيعدّ `check` ويسيب الباقي للـ hasher الحقيقي.
     */
    private function countingHasher(): object
    {
        $real = Hash::getFacadeRoot();

        $counting = new class($real) implements Hasher
        {
            public int $checks = 0;

            public int $makes = 0;

            public function __construct(private readonly Hasher $real) {}

            public function info($hashedValue)
            {
                return $this->real->info($hashedValue);
            }

            public function make($value, array $options = [])
            {
                $this->makes++;

                return $this->real->make($value, $options);
            }

            public function check($value, $hashedValue, array $options = [])
            {
                $this->checks++;

                return $this->real->check($value, $hashedValue, $options);
            }

            public function needsRehash($hashedValue, array $options = [])
            {
                return $this->real->needsRehash($hashedValue, $options);
            }
        };

        Hash::swap($counting);

        return $counting;
    }

    public function test_login_checks_one_hash_whether_or_not_the_email_exists(): void
    {
        $this->makeUser('known@example.com');

        $social = new User(['name' => 'Social', 'email' => 'social@example.com']);
        $social->forceFill(['password' => null])->save();

        $hasher = $this->countingHasher();

        $this->login('known@example.com')->assertUnauthorized();
        $this->assertSame(1, $hasher->checks, 'wrong password for a known email');

        $hasher->checks = 0;
        $this->login('nobody@example.com')->assertUnauthorized();
        $this->assertSame(1, $hasher->checks, 'unknown email must cost the same hash check');

        $hasher->checks = 0;
        $this->login('social@example.com')->assertUnauthorized();
        $this->assertSame(1, $hasher->checks, 'an account without a password must cost the same hash check');

        $hasher->checks = 0;
        $this->login('known@example.com', null, 'realpass123')->assertOk();
        $this->assertSame(1, $hasher->checks);
    }

    public function test_the_decoy_hash_is_generated_once_not_on_every_request(): void
    {
        $hasher = $this->countingHasher();

        $this->login('nobody1@example.com')->assertUnauthorized();
        $this->login('nobody2@example.com')->assertUnauthorized();
        $this->login('nobody3@example.com')->assertUnauthorized();

        $this->assertSame(1, $hasher->makes);
        $this->assertSame(3, $hasher->checks);
    }

    public function test_an_unknown_email_and_a_wrong_password_answer_identically(): void
    {
        $this->makeUser('known@example.com');

        $known = $this->login('known@example.com')->assertUnauthorized();
        $unknown = $this->login('nobody@example.com')->assertUnauthorized();

        $this->assertSame($known->json(), $unknown->json());
    }
}
