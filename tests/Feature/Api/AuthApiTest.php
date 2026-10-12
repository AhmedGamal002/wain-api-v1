<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * F2 الأساسي: register وlogin وlogout وme (02 F2، 06 القسم 1).
 */
class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    private const AR = ['Accept-Language' => 'ar'];

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('auth');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'معلوم',
            'email' => 'Maaloum@Example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'terms_accepted' => true,
        ], $overrides);
    }

    private function makeUser(string $email = 'user@example.com', string $password = 'secret123', array $attributes = []): User
    {
        $user = new User(['name' => 'مستخدم', 'email' => $email, 'password' => $password]);
        $user->forceFill($attributes)->save();

        return $user;
    }

    public function test_register_creates_the_account_and_returns_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload(), self::AR);

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['token', 'token_type', 'expires_at', 'user' => [
                'id', 'name', 'email', 'email_verified', 'avatar_url', 'locale', 'default_area_id',
            ]]])
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.name', 'معلوم')
            ->assertJsonPath('data.user.email', 'maaloum@example.com')
            ->assertJsonPath('data.user.email_verified', false)
            ->assertJsonPath('data.user.locale', 'ar');

        $user = User::query()->where('email', 'maaloum@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('secret123', $user->password));
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertNotNull($user->terms_version);
    }

    public function test_the_register_token_works_and_is_valid_for_30_days(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload());
        $token = $response->json('data.token');

        $this->assertEqualsWithDelta(
            now()->addDays(30)->timestamp,
            Carbon::parse($response->json('data.expires_at'))->timestamp,
            60,
        );

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.email', 'maaloum@example.com');
    }

    public function test_register_stores_english_locale_from_accept_language(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(), ['Accept-Language' => 'en'])
            ->assertCreated()
            ->assertJsonPath('data.user.locale', 'en');
    }

    public function test_register_validation_errors_are_per_field_in_the_request_language(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'م',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ], self::AR);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['name', 'email', 'password', 'terms_accepted']]);

        $this->assertStringContainsString('الاسم', $response->json('errors.name.0'));
        $this->assertSame('اكتب إيميل صحيح.', $response->json('errors.email.0'));
    }

    public function test_password_needs_a_letter_and_a_number(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['password' => 'onlyletters', 'password_confirmation' => 'onlyletters']), self::AR)
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/auth/register', $this->payload(['password' => '12345678', 'password_confirmation' => '12345678']), self::AR)
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_register_rejects_a_duplicate_email_case_insensitively(): void
    {
        $this->makeUser('maaloum@example.com');

        $this->postJson('/api/v1/auth/register', $this->payload(['email' => 'MAALOUM@example.com']), self::AR)
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'الإيميل مستخدم قبل كده.');
    }

    public function test_register_requires_terms_acceptance(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['terms_accepted' => false]), self::AR)
            ->assertStatus(422)
            ->assertJsonValidationErrors('terms_accepted');
    }

    public function test_login_returns_a_token_for_valid_credentials(): void
    {
        $this->makeUser('user@example.com', 'secret123');

        $this->postJson('/api/v1/auth/login', ['email' => ' USER@example.com ', 'password' => 'secret123'], self::AR)
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'token_type', 'expires_at', 'user' => ['id', 'email']]])
            ->assertJsonPath('data.user.email', 'user@example.com');
    }

    public function test_wrong_password_and_unknown_email_get_the_same_error(): void
    {
        $this->makeUser('user@example.com', 'secret123');

        $wrongPassword = $this->postJson('/api/v1/auth/login', ['email' => 'user@example.com', 'password' => 'nope-12345'], self::AR);
        $unknownEmail = $this->postJson('/api/v1/auth/login', ['email' => 'ghost@example.com', 'password' => 'nope-12345'], self::AR);

        $wrongPassword->assertUnauthorized()
            ->assertExactJson(['message' => 'الإيميل أو الباسورد غلط.', 'code' => 'INVALID_CREDENTIALS']);
        $this->assertSame($wrongPassword->json(), $unknownEmail->json());
        $unknownEmail->assertUnauthorized();
    }

    public function test_social_account_without_a_password_cannot_log_in_with_a_password(): void
    {
        $user = new User(['name' => 'Google User', 'email' => 'social@example.com']);
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->postJson('/api/v1/auth/login', ['email' => 'social@example.com', 'password' => 'anything123'], self::AR)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_suspended_account_gets_account_suspended(): void
    {
        $this->makeUser('user@example.com', 'secret123', ['status' => 'suspended', 'suspended_at' => now()]);

        $this->postJson('/api/v1/auth/login', ['email' => 'user@example.com', 'password' => 'secret123'], self::AR)
            ->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
    }

    public function test_deleted_account_cannot_log_in(): void
    {
        $user = $this->makeUser('user@example.com', 'secret123');
        $user->delete();

        $this->postJson('/api/v1/auth/login', ['email' => 'user@example.com', 'password' => 'secret123'], self::AR)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_sixth_login_attempt_in_a_minute_is_throttled_with_retry_after(): void
    {
        $this->makeUser('user@example.com', 'secret123');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'user@example.com', 'password' => 'wrong-12345'], self::AR)
                ->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'user@example.com', 'password' => 'wrong-12345'], self::AR)
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_REQUESTS')
            ->assertHeader('Retry-After');
    }

    public function test_throttle_is_per_email_not_global(): void
    {
        $this->makeUser('first@example.com', 'secret123');
        $this->makeUser('second@example.com', 'secret123');

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'first@example.com', 'password' => 'wrong-12345']);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'second@example.com', 'password' => 'secret123'])
            ->assertOk();
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me', self::AR)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_me_returns_the_current_user_without_internal_fields(): void
    {
        $user = $this->makeUser('user@example.com');
        $token = $user->createToken('mobile')->plainTextToken;

        $response = $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"]);

        $response->assertOk()
            ->assertExactJson(['data' => [
                'id' => $user->id,
                'name' => 'مستخدم',
                'email' => 'user@example.com',
                'email_verified' => false,
                'avatar_url' => null,
                'locale' => 'ar',
                'default_area_id' => null,
            ]]);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = $this->makeUser('user@example.com');
        $current = $user->createToken('mobile')->plainTextToken;
        $other = $user->createToken('mobile')->plainTextToken;

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$current}"])->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 1);

        // الـ guard بيحتفظ بالمستخدم بين الـ requests في نفس التست، فبنصفّيه عشان الـ token الملغي يتفحص تاني
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$current}"])->assertUnauthorized();
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$other}"])->assertOk();
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/logout', [], self::AR)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }
}
