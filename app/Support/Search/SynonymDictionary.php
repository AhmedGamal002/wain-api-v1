<?php

namespace App\Support\Search;

/**
 * قاموس المرادفات (02 F4): `resources/search/synonyms.php`. الكلمة أو العبارة بتتوسّع لكل مجموعتها وقت
 * الاستعلام، فمفيش حاجة بتتخزّن مع الأماكن ولا بتحتاج إعادة بناء `search_text` لما القاموس يتحدّث.
 *
 * الملف صغير (عشرات المجموعات) فبيتحمّل مرة واحدة في الـ process من غير cache تاني.
 */
final class SynonymDictionary
{
    /** أطول عبارة مسموحة في القاموس بالكلمات (فاست فود = 2). */
    public const MAX_PHRASE_WORDS = 3;

    private static ?self $shared = null;

    /** @var array<string, int> الكلمة أو العبارة المطبَّعة ← رقم مجموعتها */
    private array $index = [];

    /** @var list<list<string>> */
    private array $groups = [];

    /**
     * @param  list<list<string>>  $groups
     */
    public function __construct(array $groups)
    {
        foreach ($groups as $group) {
            $members = [];

            foreach ($group as $term) {
                $normalized = self::normalizeTerm((string) $term);

                if ($normalized === '' || count(explode(' ', $normalized)) > self::MAX_PHRASE_WORDS) {
                    continue;
                }

                $members[$normalized] = $normalized;
            }

            if (count($members) < 2) {
                continue;
            }

            $this->groups[] = array_values($members);
            $id = array_key_last($this->groups);

            // الكلمة اللي في أكتر من مجموعة بتروح لآخر مجموعة (قاعدة الملف)
            foreach ($members as $member) {
                $this->index[$member] = $id;
            }
        }
    }

    /**
     * القاموس اللي في `resources/search/synonyms.php`.
     */
    public static function shared(): self
    {
        if (self::$shared === null) {
            $path = resource_path('search/synonyms.php');
            $groups = is_file($path) ? require $path : [];

            self::$shared = new self(is_array($groups) ? $groups : []);
        }

        return self::$shared;
    }

    /**
     * ينسى النسخة المحمّلة (للتستات لما بتبدّل الملف).
     */
    public static function flush(): void
    {
        self::$shared = null;
    }

    /**
     * المصطلح نفسه ومرادفاته (مطبَّعة)، أو `[]` لو مش في القاموس. الإدخال لازم يكون مطبَّع ومن غير «ال».
     *
     * @return list<string>
     */
    public function alternatives(string $term): array
    {
        $id = $this->index[$term] ?? null;

        return $id === null ? [] : $this->groups[$id];
    }

    public function knows(string $term): bool
    {
        return isset($this->index[$term]);
    }

    /**
     * التطبيع بتاع القاموس: نفس تطبيع البحث، و«ال» بتتشال من كل كلمة (التجمع الخامس ← تجمع خامس) عشان
     * تطابق كلمة المستخدم بعد ما بتتشال منها هي كمان.
     */
    private static function normalizeTerm(string $term): string
    {
        $words = array_map(SearchNormalizer::stripArticle(...), SearchNormalizer::words($term));

        return implode(' ', $words);
    }
}
