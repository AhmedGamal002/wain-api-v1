<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * اللغة من هيدر Accept-Language: ar أو en، والافتراضي ar (06 القسم 1).
 *
 * بيتسجّل global (مش في group الـ api) عشان يشتغل كمان على الـ 404 لمسار مش موجود، فرسالة
 * الخطأ تيجي بلغة الطلب. بيتجاهل أي مسار غير /api/* (لوحة Filament بلغتها).
 */
class SetApiLocale
{
    public const SUPPORTED = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/*')) {
            App::setLocale($request->getPreferredLanguage(self::SUPPORTED) ?? 'ar');
        }

        return $next($request);
    }
}
