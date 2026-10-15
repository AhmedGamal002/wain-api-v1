<?php

namespace App\Support\ReviewQueue;

use App\Filament\Pages\ReviewQueue\PendingPlaces;
use App\Filament\Pages\ReviewQueue\PendingReviews;
use App\Filament\Pages\ReviewQueue\ReviewQueuePage;

/**
 * تابز طابور المراجعة (A6، 02 القسم A6): صفحة لكل تاب بتورّث `ReviewQueuePage`، وهنا ترتيبهم. تاب جديد
 * (تقييمات أسبوع 11، وبلاغات واقتراحات وطلبات ملكية أسبوع 13 وبعده) بيتضاف صفحة وسطر هنا، ومفيش حاجة تانية
 * بتتغيّر: شريط التابز والأعداد والـ badge في القايمة الجانبية بيقروا من القايمة دي.
 */
final class ReviewQueues
{
    /**
     * كل التابز بترتيبها (الأول هو اللي بيفتح من القايمة الجانبية).
     *
     * @return list<class-string<ReviewQueuePage>>
     */
    public static function all(): array
    {
        return [
            PendingPlaces::class,
            PendingReviews::class,
        ];
    }

    /**
     * التابز اللي المستخدم الحالي يقدر يشوفها.
     *
     * @return list<class-string<ReviewQueuePage>>
     */
    public static function visible(): array
    {
        return array_values(array_filter(self::all(), fn (string $page) => $page::canAccess()));
    }

    /**
     * أول تاب ظاهر: اللي القايمة الجانبية بتفتحه وبتعرض عليه الإجمالي.
     *
     * @return class-string<ReviewQueuePage>|null
     */
    public static function first(): ?string
    {
        return self::visible()[0] ?? null;
    }

    /**
     * إجمالي اللي مستني قرار في التابز الظاهرة.
     */
    public static function total(): int
    {
        return array_sum(array_map(fn (string $page) => $page::queueCount(), self::visible()));
    }

    /**
     * كام عنصر عدّى `OVERDUE_HOURS` من غير قرار في التابز الظاهرة.
     */
    public static function overdue(): int
    {
        return array_sum(array_map(fn (string $page) => $page::overdueCount(), self::visible()));
    }
}
