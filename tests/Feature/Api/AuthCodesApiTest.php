<?php

namespace Tests\Feature\Api;

use App\Mail\AuthCodeMail;
use App\Models\AuthCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * نسيت الباسورد وتأكيد الإيميل (02 F2، 06 القسم 2.1): كود 6 أرقام صالح 15 دقيقة و5 محاولات، «ابعت تاني» بعد
 * 60 ثانية، و`reset_token` لمرة واحدة صالح 10 دقايق، وتغيير الباسورد بيمسح كل الـ tokens.
 */
class AuthCodesApiTest extends TestCase
{
    use RefreshDatabase;

    private const AR = ['Accept-Language' => 'ar'];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    private function makeUser(string $email = 'user@example.com', array $attributes = []): User
    {
        $user = new User(['name' => 'مستخدم', 'email' => $email, 'password' => 'oldpass123']);
        $user->forceFill($attributes)->save();

        return $user;
    }

    private function forgot(string $email = 'user@example.com', array $headers = self::AR): TestResponse
    {
        return $this->postJson('/api/v1/auth/password/forgot', ['email' => $email], $headers);
    }

    private function verifyCode(string $code, string $email = 'user@example.com'): TestResponse
    {
        return $this->postJson('/api/v1/auth/password/verify', ['email' => $email, 'code' => $code], self::AR);
    }

    /**
     * الكود اللي اتبعت في آخر إيميل لـ $email.
     */
    private function lastCode(string $email = 'user@example.com'): string
    {
        $mail = Mail::sent(AuthCodeMail::class, fn (AuthCodeMail $m) => $m->hasTo($email))->last();
        $this->assertNotNull($mail, "no code email was sent to {$email}");

        return $mail->code;
    }

    private function wrongCodeFor(string $code): string
    {
        return $code === '000000' ? '000001' : '000000';
    }

    private function resetToken(string $email = 'user@example.com'): string
    {
        $this->forgot($email)->assertOk();

        return $this->verifyCode($this->lastCode($email), $email)->assertOk()->json('data.reset_token');
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('mobile')->plainTextToken, 'Accept-Language' => 'ar'];
    }

    // ---- نسيت الباسورد: الإرسال

    public function test_forgot_emails_a_six_digit_code_valid_for_15_minutes(): void
    {
        $this->makeUser();

        $this->forgot()->assertOk()->assertExactJson(['data' => ['resend_in' => 60]]);

        Mail::assertSent(AuthCodeMail::class, 1);
        $code = $this->lastCode();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $row = AuthCode::query()->firstOrFail();
        $this->assertSame('password_reset', $row->type);
        $this->assertSame('user@example.com', $row->email);
        $this->assertNotSame($code, $row->code_hash, 'the code must be stored hashed');
        $this->assertTrue(Hash::check($code, $row->code_hash));
        $this->assertEqualsWithDelta(now()->addMinutes(15)->timestamp, $row->expires_at->timestamp, 5);
        $this->assertSame(0, $row->attempts);
    }

    public function test_forgot_answers_the_same_for_an_unknown_email_and_sends_nothing(): void
    {
        $known = $this->makeUser();

        $unknownResponse = $this->forgot('nobody@example.com')->assertOk();
        $this->assertSame(['data' => ['resend_in' => 60]], $unknownResponse->json());

        Mail::assertNothingSent();
        $this->assertDatabaseCount('auth_codes', 0);
        $this->assertNotNull($known->id);
    }

    public function test_forgot_does_not_send_to_a_suspended_account_but_answers_the_same(): void
    {
        $this->makeUser('user@example.com', ['status' => 'suspended', 'suspended_at' => now()]);

        $this->forgot()->assertOk()->assertExactJson(['data' => ['resend_in' => 60]]);

        Mail::assertNothingSent();
    }

    public function test_forgot_is_case_and_space_insensitive_on_the_email(): void
    {
        $this->makeUser('user@example.com');

        $this->forgot('  USER@Example.com ')->assertOk();

        Mail::assertSent(AuthCodeMail::class, fn (AuthCodeMail $m) => $m->hasTo('user@example.com'));
    }

    public function test_resend_waits_60_seconds_and_a_new_code_cancels_the_old_one(): void
    {
        $this->makeUser();

        $this->forgot()->assertOk();
        $first = $this->lastCode();

        // قبل 60 ثانية: نفس الرد، ومفيش إيميل تاني
        $this->travel(30)->seconds();
        $early = $this->forgot()->assertOk();
        // last_sent_at is stored to the second, so the remaining time can be off by one
        $this->assertEqualsWithDelta(30, $early->json('data.resend_in'), 1);
        Mail::assertSent(AuthCodeMail::class, 1);

        // بعد 60 ثانية: كود جديد
        $this->travel(31)->seconds();
        $this->forgot()->assertOk();
        Mail::assertSent(AuthCodeMail::class, 2);
        $second = $this->lastCode();

        $this->assertSame(1, AuthCode::query()->whereNull('consumed_at')->count(), 'only one live code per email');
        // القديم اتلغى (لو صدفة نفس الرقم نتخطّى)
        if ($first !== $second) {
            $this->verifyCode($first)->assertStatus(422)->assertJsonPath('code', 'INVALID_CODE');
        }
        $this->verifyCode($second)->assertOk();
    }

    // ---- نسيت الباسورد: التحقق من الكود

    public function test_the_right_code_returns_a_one_time_reset_token(): void
    {
        $this->makeUser();
        $this->forgot()->assertOk();

        $response = $this->verifyCode($this->lastCode())
            ->assertOk()
            ->assertJsonStructure(['data' => ['reset_token', 'expires_in']])
            ->assertJsonPath('data.expires_in', 600);

        $token = $response->json('data.reset_token');
        $this->assertGreaterThanOrEqual(60, strlen($token));

        $row = AuthCode::query()->firstOrFail();
        $this->assertNotNull($row->consumed_at, 'the code is single use');
        $this->assertSame(hash('sha256', $token), $row->reset_token_hash, 'only a hash of the token is stored');
        $this->assertNotSame($token, $row->reset_token_hash);

        // الكود المستهلك ما ينفعش تاني
        $this->verifyCode($this->lastCode())->assertStatus(422)->assertJsonPath('code', 'INVALID_CODE');
    }

    public function test_wrong_codes_are_counted_and_five_wrong_attempts_lock_the_code(): void
    {
        $this->makeUser();
        $this->forgot()->assertOk();
        $good = $this->lastCode();
        $bad = $this->wrongCodeFor($good);

        for ($i = 1; $i <= 4; $i++) {
            $this->verifyCode($bad)
                ->assertStatus(422)
                ->assertJsonPath('code', 'INVALID_CODE')
                ->assertJsonStructure(['errors' => ['code']]);
            $this->assertSame($i, AuthCode::query()->firstOrFail()->attempts);
        }

        // الخامسة الغلط بتقفل الكود
        $this->verifyCode($bad)->assertStatus(422)->assertJsonPath('code', 'CODE_ATTEMPTS_EXCEEDED');

        // وميزانية الخطوة (5 في الدقيقة) خلصت كمان، فالسادسة بتتحجب قبل ما توصل للكود
        $this->verifyCode($good)->assertStatus(429);

        // بعد دقيقة: حتى الكود الصح بعد القفل ما بيفتحش
        $this->travel(61)->seconds();
        $this->verifyCode($good)->assertStatus(422)->assertJsonPath('code', 'CODE_ATTEMPTS_EXCEEDED');
    }

    public function test_an_expired_code_is_reported_as_expired(): void
    {
        $this->makeUser();
        $this->forgot()->assertOk();
        $code = $this->lastCode();

        $this->travel(16)->minutes();

        $this->verifyCode($code)->assertStatus(422)->assertJsonPath('code', 'CODE_EXPIRED');
    }

    public function test_a_code_that_was_never_requested_or_an_unknown_email_is_just_invalid(): void
    {
        $this->makeUser();

        $this->verifyCode('123456')->assertStatus(422)->assertJsonPath('code', 'INVALID_CODE');
        $this->verifyCode('123456', 'nobody@example.com')->assertStatus(422)->assertJsonPath('code', 'INVALID_CODE');
    }

    public function test_the_code_must_be_six_digits_and_spaces_from_pasting_are_ignored(): void
    {
        $this->makeUser();
        $this->forgot()->assertOk();
        $code = $this->lastCode();

        $this->verifyCode('12345')->assertStatus(422)->assertJsonValidationErrors('code');
        $this->verifyCode('abcdef')->assertStatus(422)->assertJsonValidationErrors('code');

        $spaced = substr($code, 0, 3).' '.substr($code, 3);
        $this->verifyCode($spaced)->assertOk();
    }

    // ---- نسيت الباسورد: الباسورد الجديد

    public function test_reset_changes_the_password_signs_everyone_out_and_confirms_the_email(): void
    {
        $user = $this->makeUser();
        $oldToken = $user->createToken('phone')->plainTextToken;
        $user->createToken('tablet');
        $this->assertNull($user->fresh()->email_verified_at);

        $token = $this->resetToken();

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'user@example.com',
            'reset_token' => $token,
            'password' => 'newpass456',
            'password_confirmation' => 'newpass456',
        ], self::AR)->assertNoContent();

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('newpass456', $fresh->password));
        $this->assertNotNull($fresh->email_verified_at, 'the code proved the mailbox is theirs');
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$oldToken}"])->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', ['email' => 'user@example.com', 'password' => 'oldpass123'], self::AR)
            ->assertUnauthorized()->assertJsonPath('code', 'INVALID_CREDENTIALS');
        $this->postJson('/api/v1/auth/login', ['email' => 'user@example.com', 'password' => 'newpass456'], self::AR)
            ->assertOk()->assertJsonPath('data.user.email_verified', true);
    }

    public function test_the_reset_token_works_once(): void
    {
        $this->makeUser();
        $token = $this->resetToken();
        $payload = [
            'email' => 'user@example.com',
            'reset_token' => $token,
            'password' => 'newpass456',
            'password_confirmation' => 'newpass456',
        ];

        $this->postJson('/api/v1/auth/password/reset', $payload, self::AR)->assertNoContent();

        $this->postJson('/api/v1/auth/password/reset', [...$payload, 'password' => 'another789', 'password_confirmation' => 'another789'], self::AR)
            ->assertStatus(422)->assertJsonPath('code', 'RESET_TOKEN_INVALID');
    }

    public function test_a_weak_password_is_rejected_without_burning_the_token(): void
    {
        $this->makeUser();
        $token = $this->resetToken();

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'user@example.com',
            'reset_token' => $token,
            'password' => 'onlyletters',
            'password_confirmation' => 'onlyletters',
        ], self::AR)->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'user@example.com',
            'reset_token' => $token,
            'password' => 'newpass456',
            'password_confirmation' => 'mismatch456',
        ], self::AR)->assertStatus(422)->assertJsonValidationErrors('password');

        // نفس الـ token لسه شغّال
        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'user@example.com',
            'reset_token' => $token,
            'password' => 'newpass456',
            'password_confirmation' => 'newpass456',
        ], self::AR)->assertNoContent();
    }

    public function test_the_reset_token_expires_after_10_minutes_and_is_bound_to_the_email(): void
    {
        $this->makeUser();
        $this->makeUser('other@example.com');
        $token = $this->resetToken();

        // token حد تاني
        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'other@example.com',
            'reset_token' => $token,
            'password' => 'newpass456',
            'password_confirmation' => 'newpass456',
        ], self::AR)->assertStatus(422)->assertJsonPath('code', 'RESET_TOKEN_INVALID');

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'user@example.com',
            'reset_token' => 'definitely-not-the-token',
            'password' => 'newpass456',
            'password_confirmation' => 'newpass456',
        ], self::AR)->assertStatus(422)->assertJsonPath('code', 'RESET_TOKEN_INVALID');

        $this->travel(11)->minutes();

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'user@example.com',
            'reset_token' => $token,
            'password' => 'newpass456',
            'password_confirmation' => 'newpass456',
        ], self::AR)->assertStatus(422)->assertJsonPath('code', 'RESET_TOKEN_INVALID');
    }

    // ---- تأكيد الإيميل

    public function test_email_codes_need_a_signed_in_user(): void
    {
        $this->postJson('/api/v1/auth/email/resend', [], self::AR)->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->postJson('/api/v1/auth/email/verify', ['code' => '123456'], self::AR)->assertUnauthorized();
    }

    public function test_the_email_code_confirms_the_account_email(): void
    {
        $user = $this->makeUser();
        $headers = $this->bearer($user);

        $this->postJson('/api/v1/auth/email/resend', [], $headers)->assertOk()->assertExactJson(['data' => ['resend_in' => 60]]);

        Mail::assertSent(AuthCodeMail::class, fn (AuthCodeMail $m) => $m->hasTo('user@example.com') && $m->type === 'email_verify');

        $this->postJson('/api/v1/auth/email/verify', ['code' => $this->lastCode()], $headers)
            ->assertOk()
            ->assertJsonPath('data.email', 'user@example.com')
            ->assertJsonPath('data.email_verified', true);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_email_resend_waits_60_seconds_with_retry_after(): void
    {
        $headers = $this->bearer($this->makeUser());

        $this->postJson('/api/v1/auth/email/resend', [], $headers)->assertOk();

        $this->travel(20)->seconds();
        $blocked = $this->postJson('/api/v1/auth/email/resend', [], $headers)
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_REQUESTS');
        $this->assertEqualsWithDelta(40, (int) $blocked->headers->get('Retry-After'), 1);
        Mail::assertSent(AuthCodeMail::class, 1);

        $this->travel(41)->seconds();
        $this->postJson('/api/v1/auth/email/resend', [], $headers)->assertOk();
        Mail::assertSent(AuthCodeMail::class, 2);
    }

    public function test_resend_for_a_verified_account_is_refused(): void
    {
        $user = $this->makeUser('user@example.com', ['email_verified_at' => now()]);

        $this->postJson('/api/v1/auth/email/resend', [], $this->bearer($user))
            ->assertStatus(422)
            ->assertJsonPath('code', 'EMAIL_ALREADY_VERIFIED');

        Mail::assertNothingSent();
    }

    public function test_verifying_an_already_verified_account_is_a_harmless_no_op(): void
    {
        $user = $this->makeUser('user@example.com', ['email_verified_at' => now()]);

        $this->postJson('/api/v1/auth/email/verify', ['code' => '123456'], $this->bearer($user))
            ->assertOk()
            ->assertJsonPath('data.email_verified', true);
    }

    public function test_wrong_email_codes_are_limited_to_five_attempts(): void
    {
        $user = $this->makeUser();
        $headers = $this->bearer($user);

        $this->postJson('/api/v1/auth/email/resend', [], $headers)->assertOk();
        $good = $this->lastCode();
        $bad = $this->wrongCodeFor($good);

        for ($i = 1; $i <= 4; $i++) {
            $this->postJson('/api/v1/auth/email/verify', ['code' => $bad], $headers)
                ->assertStatus(422)->assertJsonPath('code', 'INVALID_CODE');
        }
        $this->postJson('/api/v1/auth/email/verify', ['code' => $bad], $headers)
            ->assertStatus(422)->assertJsonPath('code', 'CODE_ATTEMPTS_EXCEEDED');

        // ميزانية الخطوة (5 في الدقيقة) خلصت كمان، فبعد دقيقة نتأكد إن الكود الصح مقفول
        $this->postJson('/api/v1/auth/email/verify', ['code' => $good], $headers)->assertStatus(429);
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/email/verify', ['code' => $good], $headers)
            ->assertStatus(422)->assertJsonPath('code', 'CODE_ATTEMPTS_EXCEEDED');

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_a_password_reset_code_cannot_confirm_the_email(): void
    {
        $user = $this->makeUser();
        $this->forgot()->assertOk();
        $resetCode = $this->lastCode();

        $this->postJson('/api/v1/auth/email/verify', ['code' => $resetCode], $this->bearer($user))
            ->assertStatus(422)->assertJsonPath('code', 'INVALID_CODE');
    }

    // ---- اللغة والـ rate limit والتنضيف

    public function test_messages_follow_the_request_language(): void
    {
        $this->makeUser();

        $this->postJson('/api/v1/auth/password/verify', ['email' => 'user@example.com', 'code' => '123456'], ['Accept-Language' => 'ar'])
            ->assertJsonPath('message', 'الكود غلط، راجع الإيميل وحاول تاني.');
        $this->postJson('/api/v1/auth/password/verify', ['email' => 'user@example.com', 'code' => '123456'], ['Accept-Language' => 'en'])
            ->assertJsonPath('message', 'That code is not correct. Check your email and try again.');
    }

    public function test_the_code_email_uses_the_account_language(): void
    {
        $this->makeUser('user@example.com', ['locale' => 'en']);

        $this->forgot('user@example.com', ['Accept-Language' => 'ar'])->assertOk();

        $mail = Mail::sent(AuthCodeMail::class)->first();
        $this->assertSame('en', $mail->locale);
    }

    public function test_the_password_flow_has_its_own_limiter_and_never_blocks_login(): void
    {
        $this->makeUser();

        // 5 في الدقيقة لكل IP + إيميل (SEC-01)
        for ($i = 0; $i < 5; $i++) {
            $this->verifyCode('123456')->assertStatus(422);
        }
        $this->verifyCode('123456')->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');

        // الدخول لسه شغّال (limiter تاني)
        $this->postJson('/api/v1/auth/login', ['email' => 'user@example.com', 'password' => 'oldpass123'], self::AR)->assertOk();
    }

    public function test_each_password_step_has_its_own_budget_and_the_email_is_matched_after_trimming(): void
    {
        $this->makeUser();

        // خطوة الكود اتسدّت
        for ($i = 0; $i < 5; $i++) {
            $this->verifyCode('123456')->assertStatus(422);
        }
        $this->verifyCode('123456')->assertStatus(429);

        // مسافات وحروف كبيرة في الإيميل ما بتفتحش ميزانية جديدة لنفس الإيميل
        $this->verifyCode('123456', '  USER@example.com ')->assertStatus(429);

        // لكن «ابعت الكود» وتغيير الباسورد ليهم ميزانيتهم، فالمستخدم السريع ما يتحجبش
        $this->forgot()->assertOk();
        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'user@example.com',
            'reset_token' => 'not-a-real-token',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ], self::AR)->assertStatus(422)->assertJsonPath('code', 'RESET_TOKEN_INVALID');
    }

    public function test_a_realistic_fast_flow_with_a_couple_of_typos_is_never_throttled(): void
    {
        $this->makeUser();

        $this->forgot()->assertOk();
        $code = $this->lastCode();

        // غلطتين في الكود، وبعدها الصح
        $this->verifyCode($this->wrongCodeFor($code))->assertStatus(422);
        $this->verifyCode($this->wrongCodeFor($code))->assertStatus(422);
        $token = $this->verifyCode($code)->assertOk()->json('data.reset_token');

        // باسورد ضعيف مرتين وبعدها صح
        $payload = ['email' => 'user@example.com', 'reset_token' => $token, 'password_confirmation' => 'abcdefgh'];
        $this->postJson('/api/v1/auth/password/reset', $payload + ['password' => 'abcdefgh'], self::AR)->assertStatus(422);
        $this->postJson('/api/v1/auth/password/reset', $payload + ['password' => 'abcdefgh'], self::AR)->assertStatus(422);
        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'user@example.com', 'reset_token' => $token, 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ], self::AR)->assertNoContent();
    }

    public function test_login_failures_do_not_block_the_password_flow(): void
    {
        $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'user@example.com', 'password' => 'wrong-12345'], self::AR)->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'user@example.com', 'password' => 'wrong-12345'], self::AR)->assertStatus(429);

        $this->forgot()->assertOk();
        Mail::assertSent(AuthCodeMail::class, 1);
    }

    public function test_prune_command_removes_old_codes_and_keeps_recent_ones(): void
    {
        $this->makeUser();

        AuthCode::query()->create([
            'type' => 'password_reset', 'email' => 'old@example.com', 'code_hash' => 'x',
            'expires_at' => now()->subDays(3), 'last_sent_at' => now()->subDays(3),
        ]);
        AuthCode::query()->create([
            'type' => 'email_verify', 'email' => 'used@example.com', 'code_hash' => 'x',
            'expires_at' => now()->addMinutes(5), 'last_sent_at' => now()->subDays(2), 'consumed_at' => now()->subDays(2),
        ]);
        AuthCode::query()->create([
            'type' => 'password_reset', 'email' => 'fresh@example.com', 'code_hash' => 'x',
            'expires_at' => now()->addMinutes(10), 'last_sent_at' => now(),
        ]);
        AuthCode::query()->create([
            'type' => 'password_reset', 'email' => 'justexpired@example.com', 'code_hash' => 'x',
            'expires_at' => now()->subHour(), 'last_sent_at' => now()->subHours(2),
        ]);

        $this->artisan('auth:prune-codes')->assertSuccessful();

        $left = AuthCode::query()->pluck('email')->sort()->values()->all();
        // بنسيب يوم بعد الانتهاء عشان قاعدة «ابعت تاني» تفضل شغالة وقت الحدود
        $this->assertSame(['fresh@example.com', 'justexpired@example.com'], $left);
    }
}
