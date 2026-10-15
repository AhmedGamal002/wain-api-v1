<?php

namespace App\Filament\Pages\ReviewQueue;

use App\Filament\Resources\Places\PlaceResource;
use App\Models\Review;
use App\Models\ReviewPhoto;
use App\Models\User;
use App\Support\ReviewModeration;
use App\Support\ReviewModerationException;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * تاب «تقييمات» (A6، 02 القسم A6): التقييمات الجديدة والتعديلات على المنشور، الأقدم أول. المشرف بيشوف النجوم
 * والنص والصور والمستخدم **وعدد تقييماته المرفوضة**، وبيقرر: موافقة، موافقة مع حذف صورة، أو رفض بسبب.
 *
 * القواعد في `ReviewModeration` (Admin وModerator): الزرار بيعرض النتيجة بس، والحدث (`ReviewPublished` و
 * `ReviewRejected`) وإعادة حساب المتوسط بيطلعوا من هناك. التعديل على منشور بيتعرض بقيمه الجديدة وعليه علامة
 * «تعديل»، والقديم لسه ظاهر للناس لحد القرار.
 */
class PendingReviews extends ReviewQueuePage
{
    protected static ?string $slug = 'review-queue/reviews';

    protected static ?int $navigationSort = 2;

    public static function queueLabel(): string
    {
        return 'تقييمات';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->can('viewAny', Review::class) ?? false;
    }

    public static function queueCount(): int
    {
        return Review::query()->awaitingDecision()->count();
    }

    public static function overdueCount(): int
    {
        return Review::query()
            ->awaitingDecision()
            ->whereRaw('COALESCE(reviews.submitted_at, reviews.updated_at) <= ?', [self::overdueBefore()])
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
                Review::query()
                    ->awaitingDecision()
                    ->select('reviews.*')
                    // عدد تقييمات صاحبه المرفوضة (02 A6): استعلام فرعي واحد بدل N+1
                    ->selectSub(
                        Review::query()
                            ->from('reviews as past')
                            ->selectRaw('COUNT(*)')
                            ->whereColumn('past.user_id', 'reviews.user_id')
                            ->where('past.status', Review::STATUS_REJECTED),
                        'user_rejected_count',
                    )
                    ->with(['place:id,slug,name_ar', 'user:id,name,deleted_at', 'photos']),
            )
            ->defaultSort('submitted_at', 'asc')
            ->columns([
                TextColumn::make('place.name_ar')
                    ->label('المكان')
                    ->weight('bold')
                    ->description(fn (Review $record) => $record->hasPendingEdit() ? 'تعديل على تقييم منشور' : 'تقييم جديد')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('rating')
                    ->label('النجوم')
                    ->state(fn (Review $record) => self::stars((int) ($record->hasPendingEdit() ? $record->pending_payload['rating'] ?? $record->rating : $record->rating)))
                    ->description(fn (Review $record) => $record->hasPendingEdit() && (int) ($record->pending_payload['rating'] ?? $record->rating) !== $record->rating
                        ? 'كان '.self::stars($record->rating)
                        : null),

                TextColumn::make('body')
                    ->label('الرأي')
                    ->state(fn (Review $record) => $record->hasPendingEdit() ? ($record->pending_payload['body'] ?? null) : $record->body)
                    ->limit(160)
                    ->wrap()
                    ->placeholder('من غير نص'),

                ImageColumn::make('photos')
                    ->label('الصور')
                    ->state(fn (Review $record) => self::photoUrls($record))
                    ->stacked()
                    ->limit(3)
                    ->limitedRemainingText()
                    ->imageSize(44),

                TextColumn::make('user.name')
                    ->label('المستخدم')
                    ->placeholder('مستخدم محذوف')
                    ->description(fn (Review $record) => ($record->user_rejected_count ?? 0) > 0
                        ? 'اترفض له '.$record->user_rejected_count.' تقييم'
                        : null),

                TextColumn::make('age')
                    ->label('مستني من')
                    ->state(fn (Review $record) => $record->submitted_at ?? $record->updated_at)
                    ->formatStateUsing(fn (?Carbon $state) => self::ageLabel($state))
                    ->badge()
                    ->icon(Heroicon::OutlinedClock)
                    ->color(fn (?Carbon $state) => self::ageColor($state) === 'gray' ? Color::Gray : self::ageColor($state))
                    ->tooltip(fn (?Carbon $state) => $state?->copy()->timezone('Africa/Cairo')->format('Y-m-d H:i'))
                    ->sortable(query: fn (Builder $query, string $direction) => $query
                        ->orderByRaw('COALESCE(reviews.submitted_at, reviews.updated_at) '.($direction === 'desc' ? 'desc' : 'asc'))),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('فتح المكان')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->url(fn (Review $record) => PlaceResource::getUrl('edit', ['record' => $record->place_id])),
                // اسم الزرار `publish` و`reject`: اختصارات الكيبورد (J/K/A/R) بتفتحهم بالاسم
                $this->approveAction(),
                $this->approveWithoutPhotosAction(),
                $this->rejectAction(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->emptyStateHeading('مفيش تقييمات مستنية مراجعة')
            ->emptyStateDescription('كل اللي اتبعت اتراجع.')
            ->poll('60s');
    }

    /**
     * اللي الاختصارات بتشتغل عليه في الصفحة الحالية (نفس شكل `PendingPlaces::keyboardItems`).
     *
     * @return list<array{id: int, name: string, url: string, publish: bool, reject: bool}>
     */
    public function keyboardItems(): array
    {
        $user = Auth::user();
        $records = $this->getTableRecords();
        $records = method_exists($records, 'items') ? $records->items() : $records;

        return collect($records)
            ->map(fn (Review $review) => [
                'id' => $review->id,
                'name' => ($review->place?->name_ar ?? '').' — '.self::stars($review->rating),
                'url' => PlaceResource::getUrl('edit', ['record' => $review->place_id]),
                'publish' => $user instanceof User && $user->can('moderate', $review),
                'reject' => $user instanceof User && $user->can('moderate', $review),
            ])
            ->values()
            ->all();
    }

    // ───────────────────────── الإجراءات ─────────────────────────

    private function approveAction(): Action
    {
        return Action::make('publish')
            ->label('موافقة')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('الموافقة على التقييم')
            ->modalDescription(fn (Review $record) => $record->hasPendingEdit()
                ? 'القيم الجديدة هتنزل مكان القديمة، والمتوسط هيتحسب تاني.'
                : 'هيظهر في صفحة المكان ويدخل في المتوسط.')
            ->visible(fn (Review $record) => $this->can($record))
            ->action(fn (Review $record, Action $action) => $this->decide(
                $action,
                fn (User $user) => app(ReviewModeration::class)->approve($record, $user),
                'اتنشر',
            ));
    }

    private function approveWithoutPhotosAction(): Action
    {
        return Action::make('approveWithoutPhotos')
            ->label('موافقة مع حذف صورة')
            ->icon(Heroicon::OutlinedPhoto)
            ->color('warning')
            ->modalHeading('موافقة مع حذف صور')
            ->modalDescription('الصور اللي تختارها بتتمسح نهائياً، والتقييم نفسه بيتنشر.')
            ->modalSubmitActionLabel('موافقة وحذف')
            ->schema([
                CheckboxList::make('photos')
                    ->label('الصور اللي هتتمسح')
                    ->options(fn (Review $record) => collect(ReviewModeration::photosUnderReview($record))
                        ->mapWithKeys(fn (ReviewPhoto $photo) => [
                            $photo->id => '<img src="'.e($photo->variantUrl('thumb') ?? '').'" alt="" style="height:72px;border-radius:8px;object-fit:cover">',
                        ])
                        ->all())
                    ->allowHtml()
                    ->columns(3)
                    ->required(),
            ])
            ->visible(fn (Review $record) => $this->can($record) && count(ReviewModeration::photosUnderReview($record)) > 0)
            ->action(fn (Review $record, array $data, Action $action) => $this->decide(
                $action,
                fn (User $user) => app(ReviewModeration::class)->approve($record, $user, array_map('intval', $data['photos'] ?? [])),
                'اتنشر من غير الصور اللي اخترتها',
            ));
    }

    private function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('رفض')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading('رفض التقييم')
            ->modalDescription(fn (Review $record) => $record->hasPendingEdit()
                ? 'التعديل بس هو اللي بيترفض، والتقييم المنشور القديم بيفضل زي ما هو.'
                : 'صاحبه هيشوف السبب ويقدر يعدّل ويبعت تاني.')
            ->modalSubmitActionLabel('رفض')
            ->schema([
                Select::make('reason')
                    ->label('السبب')
                    ->options(ReviewModeration::REJECT_REASONS)
                    ->required(),
                Textarea::make('notes')
                    ->label('ملاحظات لصاحب التقييم')
                    ->rows(3)
                    ->maxLength(200)
                    ->required(fn (Get $get) => $get('reason') === 'other'),
            ])
            ->visible(fn (Review $record) => $this->can($record))
            ->action(fn (Review $record, array $data, Action $action) => $this->decide(
                $action,
                fn (User $user) => app(ReviewModeration::class)->reject($record, $user, (string) $data['reason'], $data['notes'] ?? null),
                'اترفض',
            ));
    }

    /**
     * ينفّذ القرار ويعرض النتيجة، أو رسالة الخطأ لو القاعدة رفضته.
     *
     * @param  callable(User): mixed  $do
     */
    private function decide(Action $action, callable $do, string $success): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            $action->halt();

            return;
        }

        try {
            $do($user);
        } catch (ReviewModerationException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
            $action->halt();

            return;
        }

        Notification::make()->success()->title($success)->send();
    }

    private function can(Review $review): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('moderate', $review);
    }

    // ───────────────────────── مساعدات العرض ─────────────────────────

    private static function stars(int $rating): string
    {
        $rating = max(0, min(5, $rating));

        return str_repeat('★', $rating).str_repeat('☆', 5 - $rating);
    }

    /**
     * صور التقييم الحالية، ولو في تعديل منتظر بتاعته (اللي هتنزل بعد الموافقة).
     *
     * @return list<string>
     */
    private static function photoUrls(Review $review): array
    {
        $photos = $review->photos->all();

        if ($review->hasPendingEdit()) {
            $ids = array_map('intval', (array) ($review->pending_payload['photo_ids'] ?? []));
            $photos = $ids === []
                ? []
                : ReviewPhoto::query()->whereIn('id', $ids)->orderBy('sort_order')->orderBy('id')->get()->all();
        }

        return array_values(array_filter(array_map(
            fn (ReviewPhoto $photo) => $photo->variantUrl('thumb'),
            $photos,
        )));
    }
}
