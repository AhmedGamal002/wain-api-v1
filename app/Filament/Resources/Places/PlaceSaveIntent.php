<?php

namespace App\Filament\Resources\Places;

use App\Models\Place;

/**
 * الزرار اللي ضغط عليه الفريق في صفحة الإضافة أو التعديل (03 القسم 4.3 «أزرار الحفظ»). هو اللي بيحدد
 * التحقق (حقول المسودة بس، ولا شروط النشر كلها، ولا «مايكسرش شرط») والحالة الجديدة للمكان.
 */
enum PlaceSaveIntent: string
{
    /** حفظ كمسودة: الحقول الإلزامية بس، والمكان بيفضل مسودة (والمرفوض بيرجع مسودة). */
    case Draft = 'draft';

    /** حفظ وإرسال للمراجعة: شروط النشر كلها، والحالة «قيد المراجعة». */
    case Submit = 'submit';

    /** حفظ ونشر (Admin): شروط النشر كلها، والحالة «منشور». */
    case Publish = 'publish';

    /** حفظ على مكان في أي حالة تانية: ممنوع يكسر شرط نشر، والحالة بتفضل زي ما هي. */
    case Keep = 'keep';

    /** الحالة اللي المكان بيروحلها بعد الحفظ، أو null لو بتفضل زي ما هي. */
    public function targetStatus(?string $current): ?string
    {
        return match ($this) {
            self::Draft => in_array($current, [null, Place::STATUS_DRAFT, Place::STATUS_REJECTED], true) ? Place::STATUS_DRAFT : null,
            self::Submit => Place::STATUS_PENDING,
            self::Publish => Place::STATUS_ACTIVE,
            self::Keep => null,
        };
    }

    /** بيحتاج الشروط التسعة كلها قبل الحفظ؟ */
    public function requiresCompletePlace(): bool
    {
        return $this === self::Submit || $this === self::Publish;
    }

    public function savedTitle(): string
    {
        return match ($this) {
            self::Draft => 'اتحفظ كمسودة',
            self::Submit => 'اتحفظ واتبعت للمراجعة',
            self::Publish => 'اتحفظ واتنشر',
            self::Keep => 'اتحفظ',
        };
    }
}
