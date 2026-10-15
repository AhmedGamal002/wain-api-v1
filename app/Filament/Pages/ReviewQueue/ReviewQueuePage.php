<?php

namespace App\Filament\Pages\ReviewQueue;

use App\Support\ReviewQueue\ReviewQueues;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * أساس تابز طابور المراجعة (A6، 02 القسم A6): شاشة واحدة فيها كل حاجة مستنية قرار من الفريق، متقسمة تابز
 * بالأعداد، والهدف إن مفيش حاجة تفضل أكتر من 48 ساعة.
 *
 * كل تاب صفحة لوحدها (رابطها `/admin/review-queue/{slug}`، فالإشعار يقدر يفتح التاب بتاعه مباشرة)، بجدولها
 * وأزرارها، وشريط التابز اللي فوق (`getSubNavigation`) بيتبني من `ReviewQueues` بأعداد كل تاب. اللي بيتغيّر من تاب
 * لتاب: `queueLabel` و`queueCount` و`overdueCount` والجدول. اللي مشترك: الشريط والعمر (`ageColor`) واختصارات
 * الكيبورد (`resources/views/filament/pages/review-queue.blade.php`).
 */
abstract class ReviewQueuePage extends Page implements HasTable
{
    use InteractsWithTable;

    /** بعد كام ساعة من غير قرار العنصر بيتلوّن أحمر (02 القسم A6 «معيار القبول»). */
    public const OVERDUE_HOURS = 48;

    /** وبعد كام ساعة بيتلوّن برتقالي (يوم). */
    public const WARNING_HOURS = 24;

    protected static string|UnitEnum|null $navigationGroup = 'المراجعة';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected string $view = 'filament.pages.review-queue';

    /** اسم التاب في الشريط. */
    abstract public static function queueLabel(): string;

    /** عدد العناصر المستنية قرار في التاب. */
    abstract public static function queueCount(): int;

    /** منهم كام عدّى `OVERDUE_HOURS`. */
    abstract public static function overdueCount(): int;

    // ───────────────────────── القايمة الجانبية ─────────────────────────

    /**
     * العنصر الوحيد في القايمة الجانبية هو أول تاب («طابور المراجعة» وعليه إجمالي المستني)؛ باقي التابز من الشريط.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return static::class === ReviewQueues::first();
    }

    public static function getNavigationLabel(): string
    {
        return 'طابور المراجعة';
    }

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return Heroicon::OutlinedInboxArrowDown;
    }

    public static function getNavigationBadge(): ?string
    {
        $total = ReviewQueues::total();

        return $total > 0 ? (string) $total : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return ReviewQueues::overdue() > 0 ? 'danger' : 'warning';
    }

    public function getTitle(): string
    {
        return 'طابور المراجعة — '.static::queueLabel();
    }

    // ───────────────────────── شريط التابز ─────────────────────────

    /**
     * @return array<NavigationItem>
     */
    public function getSubNavigation(): array
    {
        $current = static::class;

        return array_map(function (string $page) use ($current): NavigationItem {
            $count = $page::queueCount();

            return NavigationItem::make($page::queueLabel())
                ->url($page::getUrl())
                ->badge($count > 0 ? (string) $count : null, $page::overdueCount() > 0 ? 'danger' : 'warning')
                ->isActiveWhen(fn (): bool => $page === $current);
        }, ReviewQueues::visible());
    }

    // ───────────────────────── العمر ─────────────────────────

    /**
     * لون عمر العنصر: أحمر بعد 48 ساعة، برتقالي بعد 24، وغير كده رمادي.
     */
    public static function ageColor(?Carbon $since): string
    {
        if ($since === null) {
            return 'gray';
        }

        $hours = $since->diffInHours(now(), absolute: true);

        return match (true) {
            $hours >= self::OVERDUE_HOURS => 'danger',
            $hours >= self::WARNING_HOURS => 'warning',
            default => 'gray',
        };
    }

    /**
     * «من 3 ساعات» بالعربي.
     */
    public static function ageLabel(?Carbon $since): string
    {
        if ($since === null) {
            return '—';
        }

        return $since->copy()->locale('ar')->diffForHumans(['parts' => 1, 'short' => false]);
    }

    /**
     * أقدم تاريخ يتحسب عليه العنصر «متأخر».
     */
    public static function overdueBefore(): Carbon
    {
        return now()->subHours(self::OVERDUE_HOURS);
    }
}
