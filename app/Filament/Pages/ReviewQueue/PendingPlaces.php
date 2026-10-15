<?php

namespace App\Filament\Pages\ReviewQueue;

use App\Filament\Resources\Places\Actions\PlaceActions;
use App\Filament\Resources\Places\PlaceResource;
use App\Filament\Resources\Places\Tables\PlacesTable;
use App\Models\Place;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * تاب «أماكن قيد المراجعة» (A6، 02 القسم A6): الأماكن اللي الفريق بعتها للمراجعة (`status = pending`)،
 * الأقدم أول، وعليها checklist النشر وعمرها (أحمر بعد 48 ساعة). القرار بنفس زراري `نشر` و`رفض` بتوع قايمة الأماكن
 * (`PlaceActions`)، فالقواعد والرسائل والإشعار للي أضاف المكان واحدة: الزرار بيمر على `PlaceTransitions`.
 */
class PendingPlaces extends ReviewQueuePage
{
    protected static ?string $slug = 'review-queue/places';

    protected static ?int $navigationSort = 1;

    public static function queueLabel(): string
    {
        return 'أماكن قيد المراجعة';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->can('viewAny', Place::class) ?? false;
    }

    public static function queueCount(): int
    {
        return self::pending()->count();
    }

    public static function overdueCount(): int
    {
        return self::pending()
            ->whereRaw('COALESCE(places.submitted_at, places.updated_at) <= ?', [self::overdueBefore()])
            ->count();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return new HtmlString('الأقدم أول. اللي عدّى '.self::OVERDUE_HOURS.' ساعة من غير قرار بيتلوّن أحمر.');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                self::pending()
                    // شروط النشر بتتقرا من علاقات محمّلة مسبقاً (من غير N+1). ماتعملش select() بعد withPublishCheckData
                    ->withPublishCheckData()
                    ->with(['coverPhoto', 'area:id,name_ar', 'primaryCategory:id,name_ar,color', 'createdBy:id,name']),
            )
            ->defaultSort('submitted_at', 'asc')
            ->columns([
                ImageColumn::make('cover')
                    ->label('الصورة')
                    ->state(fn (Place $record) => $record->coverPhoto?->isReady() ? $record->coverPhoto->variantUrl('thumb') : null)
                    ->defaultImageUrl(PlacesTable::placeholderImage())
                    ->imageSize(48)
                    ->extraImgAttributes(['class' => 'rounded-lg object-cover']),

                TextColumn::make('name_ar')
                    ->label('المكان')
                    ->description(fn (Place $record) => collect([$record->primaryCategory?->name_ar, $record->area?->name_ar])->filter()->implode(' · '))
                    ->weight('bold')
                    ->wrap()
                    ->searchable(['name_ar', 'name_en']),

                TextColumn::make('createdBy.name')
                    ->label('أضافه')
                    ->placeholder('—'),

                TextColumn::make('age')
                    ->label('مستني من')
                    ->state(fn (Place $record) => $record->submitted_at ?? $record->updated_at)
                    ->formatStateUsing(fn (?Carbon $state) => self::ageLabel($state))
                    ->badge()
                    ->icon(Heroicon::OutlinedClock)
                    ->color(fn (?Carbon $state) => self::ageColor($state) === 'gray' ? Color::Gray : self::ageColor($state))
                    ->tooltip(fn (?Carbon $state) => $state?->copy()->timezone('Africa/Cairo')->format('Y-m-d H:i'))
                    ->sortable(query: fn (Builder $query, string $direction) => $query
                        ->orderByRaw('COALESCE(places.submitted_at, places.updated_at) '.($direction === 'desc' ? 'desc' : 'asc'))),

                ViewColumn::make('completeness')
                    ->label('شروط النشر')
                    ->view('filament.tables.columns.place-completeness'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('فتح')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('gray')
                    ->url(fn (Place $record) => PlaceResource::getUrl('edit', ['record' => $record])),
                PlaceActions::publish(),
                PlaceActions::reject(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([PlaceActions::bulkPublish()])
                    ->visible(fn () => Auth::user()?->hasRole('admin') ?? false),
            ])
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->emptyStateHeading('مفيش أماكن مستنية مراجعة')
            ->emptyStateDescription('كل اللي اتبعت اتراجع.')
            ->poll('60s');
    }

    /**
     * اللي الاختصارات بتشتغل عليه في الصفحة الحالية (الـ id والاسم ورابط الفتح وإيه المسموح)، بترتيب الجدول.
     *
     * @return list<array{id: int, name: string, url: string, publish: bool, reject: bool}>
     */
    public function keyboardItems(): array
    {
        $user = Auth::user();
        $records = $this->getTableRecords();
        $records = method_exists($records, 'items') ? $records->items() : $records;

        return collect($records)
            ->map(fn (Place $place) => [
                'id' => $place->id,
                'name' => $place->name_ar,
                'url' => PlaceResource::getUrl('edit', ['record' => $place]),
                'publish' => $user instanceof User && $user->can('publish', $place),
                'reject' => $user instanceof User && $user->can('reject', $place),
            ])
            ->values()
            ->all();
    }

    /**
     * @return Builder<Place>
     */
    private static function pending(): Builder
    {
        return Place::query()->where('places.status', Place::STATUS_PENDING);
    }
}
