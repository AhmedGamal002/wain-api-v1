<?php

namespace App\Filament\Resources\Places\Tables;

use App\Models\Area;
use App\Models\Category;
use App\Models\City;
use App\Models\Place;
use App\Models\User;
use App\Support\PlacePublishCheck;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * قايمة الأماكن (03 القسم 4.2): الأعمدة والفلاتر والبحث والصفحات. أزرار الصف والإجراءات الجماعية في أسبوع 6
 * (الإجراءات والـ modals)، وصفحة التعديل في أسبوع 5.
 */
class PlacesTable
{
    /** الأيام اللي بعدها بيانات المكان «ما اتأكدتش» (03 القسم 7.6). */
    private const STALE_DAYS = 90;

    /** الباقة اللي باقي لها أقل من كده بتتلوّن أحمر. */
    private const TIER_WARNING_DAYS = 7;

    private const TIER_LABELS = ['free' => 'مجاني', 'featured' => 'مميز', 'premium' => 'بريميوم'];

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                // عمود «اكتمال البيانات» بيقرا شروط النشر من علاقات محمّلة مسبقاً (من غير N+1). ماتعملش select()
                // بعد withPublishCheckData: بيشيل عمود عدد الخصائص اللي withCount ضافه.
                ->withPublishCheckData()
                ->with(['coverPhoto', 'area:id,name_ar', 'primaryCategory:id,name_ar,color', 'updatedBy:id,name', 'createdBy:id,name'])
                ->addSelect(['views_30d' => DB::table('place_stats_daily')
                    ->selectRaw('COALESCE(SUM(`count`), 0)')
                    ->whereColumn('place_stats_daily.place_id', 'places.id')
                    ->where('place_stats_daily.type', 'view')
                    ->where('place_stats_daily.date', '>=', now()->subDays(30)->toDateString())]))
            ->columns([
                ImageColumn::make('cover')
                    ->label('الصورة')
                    ->state(fn (Place $record) => $record->coverPhoto?->isReady() ? $record->coverPhoto->variantUrl('thumb') : null)
                    ->defaultImageUrl(self::placeholderImage())
                    ->imageSize(48)
                    ->extraImgAttributes(['class' => 'rounded-lg object-cover']),

                // خانة بحث واحدة على الاسمين والتليفون والـ slug (03 القسم 4.2)
                TextColumn::make('name_ar')
                    ->label('الاسم')
                    ->description(fn (Place $record) => $record->name_en)
                    ->weight('bold')
                    ->wrap()
                    ->sortable()
                    ->searchable(query: fn (Builder $query, string $search) => self::search($query, $search)),

                TextColumn::make('area.name_ar')
                    ->label('المنطقة')
                    ->sortable(),

                TextColumn::make('primaryCategory.name_ar')
                    ->label('التصنيف الرئيسي')
                    ->badge()
                    ->color(fn (Place $record) => filled($record->primaryCategory?->color)
                        ? Color::hex($record->primaryCategory->color)
                        : 'gray'),

                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Place::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Place::STATUS_PENDING => 'warning',
                        Place::STATUS_ACTIVE => 'success',
                        Place::STATUS_HIDDEN => Color::Orange,
                        Place::STATUS_REJECTED => 'danger',
                        Place::STATUS_CLOSED => Color::Stone,
                        default => 'gray',
                    })
                    ->icon(fn (string $state) => $state === Place::STATUS_CLOSED ? Heroicon::OutlinedLockClosed : null)
                    ->tooltip(fn (Place $record) => filled($record->status_reason) ? $record->status_reason : null)
                    ->sortable(),

                ViewColumn::make('completeness')
                    ->label('اكتمال البيانات')
                    ->view('filament.tables.columns.place-completeness'),

                TextColumn::make('listing_tier')
                    ->label('الباقة')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::TIER_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'premium' => 'primary',
                        'featured' => 'info',
                        default => 'gray',
                    })
                    ->description(fn (Place $record) => self::tierRemaining($record))
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('places.tier_rank', $direction)),

                TextColumn::make('rating_avg')
                    ->label('التقييم')
                    ->formatStateUsing(fn ($state, Place $record) => $record->rating_count > 0
                        ? number_format($record->rating_avg, 1).' ('.$record->rating_count.')'
                        : '—')
                    ->sortable(),

                TextColumn::make('verified_at')
                    ->label('آخر تأكيد')
                    ->formatStateUsing(fn ($state, Place $record) => $record->verified_at === null
                        ? 'ما اتأكدش'
                        : 'من '.self::daysSince($record->verified_at).' يوم')
                    ->color(fn (Place $record) => self::isStale($record) ? 'danger' : null)
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('آخر تعديل')
                    ->formatStateUsing(fn ($state) => Carbon::parse($state)->locale('ar')->diffForHumans())
                    ->description(fn (Place $record) => $record->updatedBy?->name)
                    ->tooltip(fn (Place $record) => $record->updated_at?->timezone('Africa/Cairo')->format('Y-m-d H:i'))
                    ->sortable(),

                TextColumn::make('phone')
                    ->label('التليفون')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('createdBy.name')
                    ->label('أضافه')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('published_at')
                    ->label('تاريخ النشر')
                    ->date('Y-m-d')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('views_30d')
                    ->label('مشاهدات 30 يوم')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('location')
                    ->label('المدينة والمنطقة')
                    ->schema([
                        Select::make('city_id')
                            ->label('المدينة')
                            ->options(fn () => City::query()->orderBy('sort_order')->pluck('name_ar', 'id')->all())
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('area_id', null)),
                        Select::make('area_id')
                            ->label('المنطقة')
                            ->options(fn (Get $get) => Area::query()
                                ->when(filled($get('city_id')), fn (Builder $q) => $q->where('city_id', $get('city_id')))
                                ->orderBy('sort_order')
                                ->pluck('name_ar', 'id')
                                ->all()),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when(filled($data['city_id'] ?? null), fn (Builder $q) => $q->where('places.city_id', $data['city_id']))
                        ->when(filled($data['area_id'] ?? null), fn (Builder $q) => $q->where('places.area_id', $data['area_id'])))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if (filled($data['city_id'] ?? null)) {
                            $indicators[] = 'المدينة: '.City::query()->whereKey($data['city_id'])->value('name_ar');
                        }
                        if (filled($data['area_id'] ?? null)) {
                            $indicators[] = 'المنطقة: '.Area::query()->whereKey($data['area_id'])->value('name_ar');
                        }

                        return $indicators;
                    }),

                // أي تصنيف للمكان (مش الرئيسي بس)
                SelectFilter::make('categories')
                    ->label('التصنيف')
                    ->multiple()
                    ->options(fn () => Category::query()->orderBy('sort_order')->pluck('name_ar', 'id')->all())
                    ->query(fn (Builder $query, array $data) => filled($data['values'] ?? null)
                        ? $query->whereIn('places.id', DB::table('place_category')
                            ->whereIn('category_id', $data['values'])
                            ->select('place_id'))
                        : $query),

                SelectFilter::make('tier')
                    ->label('الباقة')
                    ->options([
                        'free' => 'مجاني',
                        'featured' => 'مميز',
                        'premium' => 'بريميوم',
                        'expiring' => 'بتنتهي خلال 7 أيام',
                    ])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'free', 'featured', 'premium' => $query->where('places.listing_tier', $data['value']),
                        'expiring' => $query
                            ->where('places.listing_tier', '!=', 'free')
                            ->whereBetween('places.tier_ends_at', [now(), now()->addDays(self::TIER_WARNING_DAYS)]),
                        default => $query,
                    }),

                // الفلتر الوحيد اللي بيعدّي على كل الأماكن بـ PlacePublishCheck (الشرط واحد في كل مكان)
                Filter::make('incomplete')
                    ->label('ناقص بيانات للنشر')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereIn('places.id', PlacePublishCheck::incompleteIds())),

                Filter::make('no_cover')
                    ->label('من غير صورة غلاف')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereNull('places.cover_photo_id')),

                Filter::make('stale')
                    ->label('ما اتأكدش من 90 يوم')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->where(fn (Builder $q) => $q
                        ->whereNull('places.verified_at')
                        ->orWhere('places.verified_at', '<', now()->subDays(self::STALE_DAYS)))),

                TernaryFilter::make('claimed')
                    ->label('له صاحب')
                    ->placeholder('الكل')
                    ->trueLabel('نعم')
                    ->falseLabel('لأ')
                    ->attribute('claimed_by')
                    ->nullable(),

                // الـ Moderator بيفتح افتراضياً على «أماكني»
                SelectFilter::make('created_by')
                    ->label('أضافه')
                    ->options(fn () => User::query()
                        ->role(['admin', 'moderator'])
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->default(fn () => self::isModeratorOnly(auth()->user()) ? auth()->id() : null),

                TrashedFilter::make()
                    ->visible(fn () => auth()->user()?->hasRole('admin') ?? false),
            ])
            ->filtersFormColumns(2)
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->persistSearchInSession()
            ->defaultSort('updated_at', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('مفيش أماكن لسه')
            ->emptyStateDescription('الأماكن اللي هتتضاف هتظهر هنا.')
            ->emptyStateIcon(Heroicon::OutlinedBuildingStorefront);
    }

    /**
     * بحث على الاسمين والـ slug والتليفون. التليفون مخزّن بصيغة دولية (+20…)، فالرقم المحلي (010…) بيتقارن
     * من غير الصفر الأول.
     */
    private static function search(Builder $query, string $search): Builder
    {
        $term = trim($search);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $digits = ltrim((string) preg_replace('/\D+/', '', $term), '0');

        return $query->where(function (Builder $q) use ($like, $digits) {
            $q->where('places.name_ar', 'like', $like)
                ->orWhere('places.name_en', 'like', $like)
                ->orWhere('places.slug', 'like', $like);

            if ($digits !== '') {
                $q->orWhere('places.phone', 'like', '%'.$digits.'%');
            }
        });
    }

    private static function isModeratorOnly(?User $user): bool
    {
        return $user !== null && $user->hasRole('moderator') && ! $user->hasRole('admin');
    }

    private static function daysSince(Carbon $date): int
    {
        return (int) floor($date->diffInDays(now(), true));
    }

    private static function isStale(Place $place): bool
    {
        return $place->verified_at === null || self::daysSince($place->verified_at) > self::STALE_DAYS;
    }

    /**
     * «باقي 12 يوم» للباقة المدفوعة، أحمر لو أقل من 7، و«انتهت» لو فاتت.
     */
    private static function tierRemaining(Place $place): ?Htmlable
    {
        if ($place->listing_tier === 'free' || $place->tier_ends_at === null) {
            return null;
        }

        $days = (int) ceil(now()->floatDiffInDays($place->tier_ends_at, false));
        $text = $days < 0 ? 'انتهت' : "باقي {$days} يوم";

        return new HtmlString($days < self::TIER_WARNING_DAYS
            ? '<span class="text-danger-600 dark:text-danger-400">'.e($text).'</span>'
            : e($text));
    }

    /**
     * أيقونة رمادية للمكان من غير غلاف (data URI عشان ما نعتمدش على ملف).
     */
    private static function placeholderImage(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><rect width="48" height="48" rx="8" fill="#e5e7eb"/>'
            .'<path d="M14 30l7-8 5 6 4-4 4 6H14z" fill="#9ca3af"/><circle cx="31" cy="17" r="3" fill="#9ca3af"/></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
