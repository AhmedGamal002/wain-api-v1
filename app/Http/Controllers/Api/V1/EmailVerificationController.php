<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyEmailRequest;
use App\Http\Resources\UserResource;
use App\Models\AuthCode;
use App\Services\AuthCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * تأكيد الإيميل (02 F2): مش مطلوب للتصفح، مطلوب قبل أول تقييم أو اقتراح. بيتطلب من التطبيق لما
 * المستخدم غير المؤكَّد يحاول يقيّم أو يقترح، وفي بانر «أكّد إيميلك» في «حسابي».
 *
 * @group Auth
 */
class EmailVerificationController extends Controller
{
    public function __construct(private readonly AuthCodeService $codes) {}

    /**
     * Send the verification code
     *
     * بيبعت كود 6 أرقام لإيميل الحساب (صالح 15 دقيقة، و5 محاولات). هو نفسه «ابعت تاني»: بعد 60 ثانية من
     * آخر إرسال بس، قبلها `429 TOO_MANY_REQUESTS` ومعاه هيدر `Retry-After` بالثواني. الكود الجديد بيلغي القديم.
     * لو الإيميل متأكّد خلاص: `422 EMAIL_ALREADY_VERIFIED`. وفوق ده 5 طلبات في الدقيقة لكل مستخدم
     * (`429 TOO_MANY_REQUESTS`).
     *
     * @authenticated
     *
     * @response 200 {"data": {"resend_in": 60}}
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->email_verified_at !== null) {
            throw new ApiException(422, 'EMAIL_ALREADY_VERIFIED');
        }

        $wait = $this->codes->resendIn(AuthCode::TYPE_EMAIL_VERIFY, $user->email);
        if ($wait > 0) {
            throw new ApiException(429, 'TOO_MANY_REQUESTS', '', null, ['Retry-After' => (string) $wait]);
        }

        $this->codes->issueAndSend(AuthCode::TYPE_EMAIL_VERIFY, $user->email, $user, $user->locale ?? app()->getLocale());

        return response()->json(['data' => ['resend_in' => AuthCodeService::RESEND_AFTER_SECONDS]]);
    }

    /**
     * Verify the email
     *
     * بيتحقق من الكود وبيأكّد الإيميل. بيرجّع المستخدم (`email_verified: true`). لو الإيميل متأكّد قبل كده
     * بيرجّع المستخدم من غير ما يعمل حاجة.
     *
     * أخطاء (422، تحت حقل `code`): `INVALID_CODE` و`CODE_EXPIRED` و`CODE_ATTEMPTS_EXCEEDED`.
     * 5 طلبات في الدقيقة لكل مستخدم (`429 TOO_MANY_REQUESTS`).
     *
     * @authenticated
     */
    public function verify(VerifyEmailRequest $request): UserResource
    {
        $user = $request->user();

        if ($user->email_verified_at === null) {
            $record = $this->codes->verify(AuthCode::TYPE_EMAIL_VERIFY, $user->email, $request->validated('code'));

            $record->forceFill(['consumed_at' => now()])->save();
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return new UserResource($user);
    }
}
