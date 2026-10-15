<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * شبكة أمان للمستخدم الموقوف على الـ API (A7، 06 القسم 1: `403 ACCOUNT_SUSPENDED`، التطبيق يعمل logout).
 *
 * الإيقاف نفسه بيمسح كل الـ tokens (`UserModeration::suspend`)، فالمستخدم بياخد 401 في أول request. ده بيغطي
 * token اتعمل في نفس لحظة الإيقاف أو حالة اتغيّرت من برّه: بيرجّع 403 وبيمسح الـ tokens عشان اللي بعده 401.
 *
 * اسم الـ alias: `active`. بيتحط بعد `auth:sanctum` على كل route مسجّل.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');

        if ($user instanceof User && $user->isSuspended()) {
            $user->tokens()->delete();

            throw new ApiException(403, 'ACCOUNT_SUSPENDED');
        }

        return $next($request);
    }
}
