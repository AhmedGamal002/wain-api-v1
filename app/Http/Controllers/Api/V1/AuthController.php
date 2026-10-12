<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * F2 الأساسي (06 القسم 3): register وlogin وlogout وme. نسيت الباسورد وتأكيد الإيميل وGoogle/Apple
 * والحذف بتتضاف في أسابيعها (4 و14 و20).
 */
class AuthController extends Controller
{
    /** صلاحية الـ token بالأيام (SEC-02) */
    private const TOKEN_DAYS = 30;

    /** نسخة الشروط لو الـ settings فاضية (القيمة الفعلية بتيجي من جدول settings، المفتاح terms_version) */
    private const DEFAULT_TERMS_VERSION = '2026-10';

    /**
     * POST /auth/register — بيعمل الحساب ويرجّع token، فالمستخدم يدخل على طول.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = new User($request->safe()->only(['name', 'email', 'password']));
        $user->forceFill([
            'locale' => app()->getLocale() === 'en' ? 'en' : 'ar',
            'terms_version' => DB::table('settings')->where('key', 'terms_version')->value('value') ?? self::DEFAULT_TERMS_VERSION,
            'terms_accepted_at' => now(),
            'last_active_at' => now(),
        ])->save();

        return $this->tokenResponse($request, $user, 201);
    }

    /**
     * POST /auth/login — رسالة واحدة للإيميل والباسورد الغلط (ما نقولش إن الإيميل مش موجود).
     * 5 محاولات في الدقيقة لكل IP + إيميل (limiter `auth`).
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->first();

        // حسابات Google/Apple من غير باسورد (password = null) ما بتدخلش بالباسورد
        if ($user === null || $user->password === null || ! Hash::check($request->validated('password'), $user->password)) {
            throw new ApiException(401, 'INVALID_CREDENTIALS');
        }

        if ($user->status !== 'active') {
            throw new ApiException(403, 'ACCOUNT_SUSPENDED');
        }

        $user->forceFill(['last_active_at' => now()])->save();

        return $this->tokenResponse($request, $user);
    }

    /**
     * POST /auth/logout — بيمسح الـ token الحالي بس (باقي أجهزة المستخدم تفضل شغالة).
     */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /**
     * GET /auth/me
     */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    private function tokenResponse(Request $request, User $user, int $status = 200): JsonResponse
    {
        $token = $user->createToken('mobile', ['*'], now()->addDays(self::TOKEN_DAYS));

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $token->accessToken->expires_at?->utc()->toIso8601ZuluString(),
                'user' => (new UserResource($user))->resolve($request),
            ],
        ], $status);
    }
}
