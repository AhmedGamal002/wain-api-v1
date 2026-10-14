<?php

namespace App\Support;

/**
 * اسم حساب إنستجرام (03 القسم 4.3 تاب «التواصل»): بيتكتب `name` أو `@name` أو رابط `instagram.com/name/…`
 * وبيتخزّن الاسم بس. الفورم والـ CSV (A10) بيستخدموا نفس الدالة.
 */
final class InstagramHandle
{
    /** حروف وأرقام ونقطة و_ لحد 30 حرف (قاعدة إنستجرام نفسها). */
    public const PATTERN = '/^[A-Za-z0-9._]{1,30}$/';

    /**
     * الاسم من أي صيغة، أو null لو فاضي. مش بيتحقق من الشكل: `isValid()` لده.
     */
    public static function from(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('~instagram\.com/([^/?#\s]+)~i', $value, $match) === 1) {
            $value = $match[1];
        }

        $value = ltrim($value, '@');

        return $value === '' ? null : $value;
    }

    public static function isValid(string $handle): bool
    {
        return preg_match(self::PATTERN, $handle) === 1;
    }
}
