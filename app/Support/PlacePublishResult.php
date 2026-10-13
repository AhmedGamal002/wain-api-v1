<?php

namespace App\Support;

/**
 * نتيجة `PlacePublishCheck`: كل شرط من التسعة ✔ أو ✖. بتتقرا من الـ checklist الجانبية وعمود «اكتمال البيانات»
 * والأزرار والإجراءات الجماعية، فكل المخرجات (الناقص والعدد والرسالة) من هنا.
 */
final class PlacePublishResult
{
    /**
     * @param  array<string, bool>  $passed  الشرط => اتحقق؟ (بترتيب الشروط)
     */
    public function __construct(private readonly array $passed) {}

    /**
     * الشروط بترتيبها، كل واحد بمفتاحه واسمه والتاب اللي يفتحه وهل اتحقق.
     *
     * @return list<array{key: string, label: string, tab: string, ok: bool}>
     */
    public function items(): array
    {
        $items = [];
        foreach ($this->passed as $key => $ok) {
            $items[] = [
                'key' => $key,
                'label' => PlacePublishCheck::LABELS[$key],
                'tab' => PlacePublishCheck::CONDITIONS[$key],
                'ok' => $ok,
            ];
        }

        return $items;
    }

    /**
     * مفاتيح الشروط الناقصة.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        return array_keys(array_filter($this->passed, fn (bool $ok) => ! $ok));
    }

    /**
     * أسماء الشروط الناقصة بالعربي.
     *
     * @return list<string>
     */
    public function missingLabels(): array
    {
        return array_map(fn (string $key) => PlacePublishCheck::LABELS[$key], $this->missing());
    }

    public function passedCount(): int
    {
        return count($this->passed) - count($this->missing());
    }

    public function total(): int
    {
        return count($this->passed);
    }

    public function isComplete(): bool
    {
        return $this->missing() === [];
    }

    /**
     * الحقول الإلزامية للمسودة (الاسمين والتصنيف والمنطقة والموقع والعنوان): المسودة نفسها ما بتتحفظش من غيرهم.
     */
    public function canSaveAsDraft(): bool
    {
        foreach (PlacePublishCheck::DRAFT_REQUIRED as $key) {
            if (! ($this->passed[$key] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * الناقص من الحقول الإلزامية للمسودة بس.
     *
     * @return list<string>
     */
    public function missingForDraft(): array
    {
        return array_values(array_intersect($this->missing(), PlacePublishCheck::DRAFT_REQUIRED));
    }

    /**
     * التابز (مفاتيح `PlacePublishCheck::CONDITIONS`) اللي فيها شرط ناقص، من غير تكرار. التاب اللي فيه خطأ
     * بيظهر عليه نقطة حمرا.
     *
     * @return list<string>
     */
    public function missingTabs(): array
    {
        return array_values(array_unique(array_map(
            fn (string $key) => PlacePublishCheck::CONDITIONS[$key],
            $this->missing(),
        )));
    }

    /**
     * رسالة محاولة نشر ناقص (03 القسم 4.9): «ما ينفعش يتنشر. ناقص: صورة الغلاف، المواعيد».
     */
    public function message(): string
    {
        return 'ما ينفعش يتنشر. ناقص: '.implode('، ', $this->missingLabels());
    }
}
