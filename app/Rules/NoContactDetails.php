<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * نص التقييم ممنوع فيه أرقام تليفونات وروابط (02 F8). بتتشاف حتى لو الأرقام عربية (٠١٢٣) أو فيها مسافات
 * وشرط، والرابط حتى من غير http (`example.com`، `wa.me/...`) أو إنستجرام (`@handle`).
 *
 * 9 أرقام ورا بعض (مع فواصل) هو حد التليفون: سعر أو تاريخ أو «حوالي 120 جنيه» تعدّي، ورقم موبايل (11) وأرضي
 * بكود المحافظة (9–10) مش بيعدّوا.
 */
class NoContactDetails implements ValidationRule
{
    private const MIN_PHONE_DIGITS = 9;

    /** أرقام عربية-هندية وفارسية ← لاتينية. */
    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (self::containsPhone($value) || self::containsLink($value)) {
            $fail('reviews.no_contact_details')->translate();
        }
    }

    public static function containsPhone(string $text): bool
    {
        $text = strtr($text, self::DIGITS);

        return (bool) preg_match('/\+?\d(?:[\s().-]?\d){'.(self::MIN_PHONE_DIGITS - 1).',}/', $text);
    }

    public static function containsLink(string $text): bool
    {
        $text = mb_strtolower($text);

        return (bool) preg_match('~(?:https?://|www\.|wa\.me|t\.me|@[a-z0-9_.]{3,}|\b[a-z0-9][a-z0-9-]*\.(?:com|net|org|io|co|app|me|eg|info|link|shop|tv|ly|gl)\b)~u', $text);
    }
}
