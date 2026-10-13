<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\VerifyResetCodeRequest;
use App\Models\AuthCode;
use App\Models\User;
use App\Services\AuthCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * نسيت الباسورد (02 F2): الإيميل ← كود 6 أرقام ← `reset_token` ← باسورد جديد.
 *
 * @group Auth
 */
class PasswordResetController extends Controller
{
    public function __construct(private readonly AuthCodeService $codes) {}

    /**
     * Forgot password: send a code
     *
     * بيبعت كود 6 أرقام للإيميل (صالح 15 دقيقة، و5 محاولات). **الرد واحد دايماً** سواء الإيميل مسجّل أو لأ،
     * عشان ما نكشفش مين عنده حساب. وهو نفسه «ابعت تاني»: لو لسه ما عدّتش 60 ثانية من آخر إرسال بيرجّع نفس
     * الرد من غير ما يبعت كود جديد، و`resend_in` هو الباقي بالثواني. الكود الجديد بيلغي القديم.
     *
     * 5 محاولات في الدقيقة لكل IP + إيميل، ولكل خطوة من الـ 3 (forgot وverify وreset) ميزانيتها لوحدها
     * (`429 TOO_MANY_REQUESTS` ومعاه هيدر `Retry-After`).
     *
     * @response 200 {"data": {"resend_in": 60}}
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $type = AuthCode::TYPE_PASSWORD_RESET;

        $wait = $this->codes->resendIn($type, $email);

        if ($wait === 0) {
            $user = User::query()->where('email', $email)->first();

            // الحسابات الموقوفة ما بتاخدش كود، والمحذوفة مش موجودة أصلاً. الرد نفسه في كل الحالات.
            if ($user !== null && $user->status === 'active') {
                $this->codes->issueAndSend($type, $email, $user, $user->locale ?? app()->getLocale());
            }

            $wait = AuthCodeService::RESEND_AFTER_SECONDS;
        }

        return response()->json(['data' => ['resend_in' => $wait]]);
    }

    /**
     * Check the code
     *
     * بيتحقق من الكود. لو صح بيرجّع `reset_token` (لمرة واحدة، صالح 10 دقايق) يتبعت مع الباسورد الجديد،
     * عشان شاشة الكود تتحقق قبل ما تفتح شاشة الباسورد.
     *
     * أخطاء (422، والرسالة تحت حقل `code`):
     * - `INVALID_CODE`: الكود غلط (أو مفيش كود مطلوب).
     * - `CODE_EXPIRED`: الكود انتهت صلاحيته، اطلب كود جديد.
     * - `CODE_ATTEMPTS_EXCEEDED`: خلّص الـ 5 محاولات، اطلب كود جديد.
     *
     * @response 200 {"data": {"reset_token": "Zq1...", "expires_in": 600}}
     */
    public function verify(VerifyResetCodeRequest $request): JsonResponse
    {
        $record = $this->codes->verify(
            AuthCode::TYPE_PASSWORD_RESET,
            $request->validated('email'),
            $request->validated('code'),
        );

        return response()->json(['data' => [
            'reset_token' => $this->codes->exchangeForResetToken($record),
            'expires_in' => AuthCodeService::RESET_TOKEN_TTL_MINUTES * 60,
        ]]);
    }

    /**
     * Set the new password
     *
     * بيغيّر الباسورد بالـ `reset_token` (مرة واحدة). **كل الـ tokens القديمة بتتمسح**، فالمستخدم يدخل
     * من جديد بالباسورد الجديد (`204`). الكود اللي وصل للإيميل بيثبّت إن الإيميل بتاعه، فالحساب بيتأكّد.
     *
     * - `RESET_TOKEN_INVALID` (422): الـ token غلط أو منتهي أو اتستخدم، ابدأ من أول.
     * - أخطاء الباسورد (422 `VALIDATION_ERROR`) تحت حقل `password`.
     *
     * @response 204
     */
    public function reset(ResetPasswordRequest $request): Response
    {
        $email = $request->validated('email');

        $this->codes->consumeResetToken($email, $request->validated('reset_token'));

        $user = User::query()->where('email', $email)->first();

        // الحساب اتحذف بين الخطوتين: الـ token ما بقاش ليه صاحب
        if ($user !== null) {
            $user->forceFill([
                'password' => $request->validated('password'),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            $user->tokens()->delete();
        }

        return response()->noContent();
    }
}
