<?php

namespace App\Support;

/**
 * أرقام التليفون المصرية (03 القسم 4.3 تاب «التواصل»): بتتكتب بأي صيغة وبتتخزّن دولية `+20…`
 * (`places.phone` و`places.whatsapp` VARCHAR(20)، والتطبيق بيعمل منها `tel:` وروابط واتساب).
 *
 * المقبول: موبايل (010 و011 و012 و015 + 8 أرقام)، أرضي بكود المحافظة (مثلاً 02 و03 و013)، وخط ساخن
 * من 5 أرقام (16xxx و19xxx) وده بيتخزّن زي ما هو لإنه مش بيتطلب دولي.
 */
final class PhoneNumber
{
    /** @var array<string, string> */
    private const ARABIC_DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /**
     * الرقم بالصيغة اللي بتتخزّن، أو null لو مش رقم مصري صالح. الفاضي بيرجّع null كمان (الحقل اختياري).
     */
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $digits = strtr($input, self::ARABIC_DIGITS);
        $hasPlus = str_starts_with(trim($digits), '+');
        $digits = preg_replace('/\D+/', '', $digits) ?? '';

        if ($digits === '') {
            return null;
        }

        // خط ساخن: 5 أرقام بتبدأ بـ 1 (16xxx و19xxx)
        if (! $hasPlus && preg_match('/^1\d{4}$/', $digits) === 1) {
            return $digits;
        }

        $national = match (true) {
            str_starts_with($digits, '0020') => substr($digits, 4),
            $hasPlus && str_starts_with($digits, '20') => substr($digits, 2),
            ! $hasPlus && str_starts_with($digits, '20') && strlen($digits) >= 11 => substr($digits, 2),
            str_starts_with($digits, '0') => substr($digits, 1),
            default => $digits,
        };

        // موبايل: 1[0125] + 8 أرقام. أرضي: كود محافظة + باقي الرقم، من 8 لـ 10 أرقام كلها (02 و03 و04x...)،
        // وكود القليوبية 013 بيبدأ بـ 1 لكن مش موبايل (13 + 7 أرقام).
        if (preg_match('/^1[0125]\d{8}$/', $national) === 1
            || preg_match('/^(?:[2-9]\d{7,9}|13\d{7})$/', $national) === 1) {
            return '+20'.$national;
        }

        return null;
    }

    /** الرقم (بعد `normalize`) موبايل مصري؟ الواتساب موبايل بس. */
    public static function isMobile(?string $normalized): bool
    {
        return $normalized !== null && preg_match('/^\+201[0125]\d{8}$/', $normalized) === 1;
    }
}
