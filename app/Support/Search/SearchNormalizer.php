<?php

namespace App\Support\Search;

/**
 * تطبيع النص للبحث (02 F4). نفس الدالة بتتطبّق على النص المخزّن في `places.search_text` وعلى كلمة البحث،
 * فالمطابقة بتحصل بين نصّين طبّعهم نفس الكود، ومفيش حاجة تانية في المشروع بتطبّع عربي.
 *
 *  - أ إ آ ٱ = ا، وة = ه، وى = ي (ومعاها ی وک الفارسيين اللي بتطلع من كيبوردات مختلفة)
 *  - من غير تشكيل ولا تطويل
 *  - الأرقام العربية (٠-٩ و۰-۹) بتبقى لاتينية، والحروف اللاتينية small
 *  - أي علامة ترقيم أو رمز بقى مسافة (فمفيش `|` ولا `%` ولا `_` في الناتج، والـ `|` هو فاصل `search_text`)
 *  - المسافات المتكررة مسافة واحدة، والأطراف مقصوصة
 */
final class SearchNormalizer
{
    private const REPLACEMENTS = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ى' => 'ي', 'ی' => 'ي',
        'ة' => 'ه',
        'ک' => 'ك',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /** أقل طول لكلمة بعد «ال» عشان نشيلها (التجمع ← تجمع، لكن الف وام فضلوا). */
    private const MIN_WORD_LENGTH_FOR_ARTICLE = 4;

    public static function normalize(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $text = mb_strtolower($text, 'UTF-8');

        // التطويل (ـ) وكل علامات التشكيل والعلامات المدمجة (Mn)
        $text = preg_replace('/[\x{0640}\p{Mn}]+/u', '', $text) ?? $text;

        $text = strtr($text, self::REPLACEMENTS);

        // أي حاجة مش حرف ولا رقم بقت مسافة
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * كلمات النص بعد التطبيع.
     *
     * @return list<string>
     */
    public static function words(?string $text): array
    {
        $normalized = self::normalize($text);

        return $normalized === '' ? [] : explode(' ', $normalized);
    }

    /**
     * بيشيل «ال» التعريف من أول كلمة بحث (الكافيه ← كافيه). البحث بـ LIKE على جزء من النص، فكلمة من غير
     * «ال» بتطابق «الكافيه» و«كافيه» الاتنين، والعكس لأ. الكلمات القصيرة (الف، المي) بتفضل زي ما هي.
     */
    public static function stripArticle(string $word): string
    {
        if (mb_strlen($word) >= self::MIN_WORD_LENGTH_FOR_ARTICLE && str_starts_with($word, 'ال')) {
            return mb_substr($word, 2);
        }

        return $word;
    }
}
