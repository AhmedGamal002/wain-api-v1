<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * تقييم أو اقتراح أو رفع صورة لازم يكون الإيميل متأكَّد (02 F2، 06: `403 EMAIL_NOT_VERIFIED`). التصفح والحفظ
 * مش محتاجينه. اسم الـ alias: `verified-email`، وبيتحط بعد `auth:sanctum`.
 */
class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');

        if ($user === null || $user->email_verified_at === null) {
            throw new ApiException(403, 'EMAIL_NOT_VERIFIED');
        }

        return $next($request);
    }
}
