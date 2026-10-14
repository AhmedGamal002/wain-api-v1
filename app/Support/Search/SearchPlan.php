<?php

namespace App\Support\Search;

/**
 * خطة بحث لكلمة المستخدم بعد التطبيع وتوسيع المرادفات.
 *
 * كل «وحدة» هي كلمة (أو عبارة من القاموس) وبدائلها: المكان لازم يطابق أي بديل منها (OR)، ولازم يطابق كل
 * الوحدات (AND). `sources` هي الكلمة اللي كتبها المستخدم لكل وحدة (بعد التطبيع وشيل «ال»)، وبيتعمل منها
 * التصحيح ونص «نتايج لـ …».
 */
final class SearchPlan
{
    /**
     * @param  string  $query  كلمة البحث بعد التطبيع كاملة
     * @param  list<list<string>>  $units
     * @param  list<string>  $sources
     * @param  bool  $corrected  الخطة دي نتيجة تصحيح إملائي، مش اللي المستخدم كتبه
     */
    public function __construct(
        public readonly string $query,
        public readonly array $units,
        public readonly array $sources,
        public readonly bool $corrected = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->units === [];
    }

    /**
     * الكلمة اللي بيدوّر بيها فعلاً (بتتعرض للمستخدم لما الخطة اتصحّحت).
     */
    public function text(): string
    {
        return implode(' ', $this->sources);
    }
}
