<?php

namespace App\Filament\Resources\Places\Actions;

use App\Filament\Resources\Places\PlaceResource;
use App\Models\Place;
use App\Models\User;
use App\Support\PlaceTransitionException;
use App\Support\PlaceTransitions;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * أزرار المكان وmodals-ها (03 القسم 4.2 «أزرار الصف» و4.6 «الـ Modals»). نفس الأزرار في صف القائمة وفي
 * هيدر صفحة التعديل، والقواعد كلها في `PlaceTransitions` (الزرار بيظهر حسب الحالة والدور، والـ Policy بترفض
 * الطلب المباشر).
 *
 * الأزرار الممنوعة على الدور ما بتظهرش أصلاً (`visible`)، ولو اتنادت مباشرة بترفضها `PlaceTransitions`.
 */
final class PlaceActions
{
    private const CAIRO = 'Africa/Cairo';

    /**
     * أزرار الصف في القائمة: «تعديل» ظاهر، والباقي في قايمة.
     *
     * @return array<Action|ActionGroup>
     */
    public static function rowActions(): array
    {
        return [
            Action::make('view')
                ->label('عرض')
                ->icon(Heroicon::OutlinedEye)
                ->color('gray')
                ->url(fn (Place $record) => PlaceResource::getUrl('view', ['record' => $record])),

            Action::make('edit')
                ->label('تعديل')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->url(fn (Place $record) => PlaceResource::getUrl('edit', ['record' => $record]))
                ->visible(fn (Place $record) => ! $record->trashed() && self::can('update', $record)),

            self::restore(),

            ActionGroup::make([
                self::submit(),
                self::publish(),
                self::reject(),
                self::hide(),
                self::show(),
                self::tier(),
                self::verify(),
                self::duplicate(),
                self::viewInApp(),
                self::close(),
                self::reopen(),
                self::delete(),
            ]),
        ];
    }

    /**
     * أزرار الهيدر في صفحة التعديل (نفس الأزرار، من غير «تعديل» و«عرض في التطبيق» اللي بيتعامل معاها الفورم).
     *
     * @return list<Action>
     */
    public static function headerActions(): array
    {
        return [
            self::submit(),
            self::publish(),
            self::reject(),
            self::hide(),
            self::show(),
            self::tier(),
            self::verify(),
            self::duplicate(),
            self::viewInApp(),
            self::close(),
            self::reopen(),
            self::delete(),
        ];
    }

    /**
     * الإجراءات الجماعية (Admin): نشر وإخفاء وأكّد البيانات وحذف. تصدير CSV مع A10 (أسبوع 8).
     *
     * @return list<BulkActionGroup>
     */
    public static function bulkActions(): array
    {
        return [
            BulkActionGroup::make([
                self::bulkPublish(),
                self::bulkHide(),
                self::bulkVerify(),
                self::bulkDelete(),
            ])->visible(fn () => self::user()?->hasRole('admin') ?? false),
        ];
    }

    // ───────────────────────── الإجراءات ─────────────────────────

    public static function submit(): Action
    {
        return Action::make('submit')
            ->label('إرسال للمراجعة')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->visible(fn (Place $record) => ! $record->trashed()
                && $record->status === Place::STATUS_DRAFT
                && self::can('submit', $record))
            ->action(self::run(
                fn (Place $record, array $data, User $user) => app(PlaceTransitions::class)->submit($record, $user),
                'اتبعت للمراجعة',
            ));
    }

    public static function publish(): Action
    {
        return Action::make('publish')
            ->label('نشر')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('نشر المكان')
            ->modalDescription(fn (Place $record) => 'هيظهر «'.$record->name_ar.'» في التطبيق فوراً.')
            ->modalSubmitActionLabel('نشر')
            ->visible(fn (Place $record) => ! $record->trashed()
                && in_array($record->status, [Place::STATUS_DRAFT, Place::STATUS_PENDING], true)
                && self::can('publish', $record))
            ->action(self::run(
                fn (Place $record, array $data, User $user) => app(PlaceTransitions::class)->publish($record, $user),
                'اتنشر',
            ));
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label('رفض')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading('رفض المكان')
            ->modalDescription('هيرجع للي أضافه عشان يعدّله.')
            ->modalSubmitActionLabel('رفض')
            ->schema([
                Select::make('reason')
                    ->label('السبب')
                    ->options(PlaceTransitions::REJECT_REASONS)
                    ->required(),
                Textarea::make('notes')
                    ->label('ملاحظات للي أضافه')
                    ->rows(3)
                    ->maxLength(200)
                    ->required(fn (Get $get) => $get('reason') === 'other'),
            ])
            ->visible(fn (Place $record) => ! $record->trashed()
                && $record->status === Place::STATUS_PENDING
                && self::can('reject', $record))
            ->action(self::run(
                fn (Place $record, array $data, User $user) => app(PlaceTransitions::class)
                    ->reject($record, $user, (string) $data['reason'], $data['notes'] ?? null),
                'اترفض',
            ));
    }

    public static function hide(): Action
    {
        return Action::make('hide')
            ->label('إخفاء')
            ->icon(Heroicon::OutlinedEyeSlash)
            ->color('warning')
            ->modalHeading('إخفاء المكان')
            ->modalDescription('المكان هيختفي من التطبيق فوراً.')
            ->modalSubmitActionLabel('إخفاء')
            ->schema(self::hideFields())
            ->visible(fn (Place $record) => ! $record->trashed()
                && $record->status === Place::STATUS_ACTIVE
                && self::can('hide', $record))
            ->action(self::run(
                fn (Place $record, array $data, User $user) => app(PlaceTransitions::class)
                    ->hide($record, $user, (string) $data['reason'], $data['details'] ?? null),
                'اتخفى',
            ));
    }

    public static function show(): Action
    {
        return Action::make('show')
            ->label('إظهار')
            ->icon(Heroicon::OutlinedEye)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('إظهار المكان')
            ->modalDescription('هيظهر في التطبيق تاني.')
            ->visible(fn (Place $record) => ! $record->trashed()
                && $record->status === Place::STATUS_HIDDEN
                && self::can('show', $record))
            ->action(self::run(
                fn (Place $record, array $data, User $user) => app(PlaceTransitions::class)->show($record, $user),
                'اتظهر',
            ));
    }

    public static function tier(): Action
    {
        return Action::make('tier')
            ->label('الباقة')
            ->icon(Heroicon::OutlinedSparkles)
            ->modalHeading('تغيير الباقة')
            ->modalSubmitActionLabel('حفظ الباقة')
            ->fillForm(fn (Place $record) => [
                'tier' => $record->listing_tier,
                'from' => $record->tier_starts_at?->timezone(self::CAIRO)->toDateString(),
                'to' => $record->tier_ends_at?->timezone(self::CAIRO)->toDateString(),
            ])
            ->schema([
                Select::make('tier')
                    ->label('الباقة')
                    ->options(['free' => 'مجاني', 'featured' => 'مميز', 'premium' => 'بريميوم'])
                    ->live()
                    ->required(),
                DatePicker::make('from')
                    ->label('من')
                    ->native(false)
                    ->required(fn (Get $get) => $get('tier') !== 'free')
                    ->visible(fn (Get $get) => $get('tier') !== 'free'),
                DatePicker::make('to')
                    ->label('إلى')
                    ->native(false)
                    ->after('from')
                    ->required(fn (Get $get) => $get('tier') !== 'free')
                    ->visible(fn (Get $get) => $get('tier') !== 'free'),
                TextInput::make('note')
                    ->label('ملاحظة (رقم الفاتورة)')
                    ->maxLength(120),
            ])
            // `?Place`: الزرار كمان في تاب «النشر والإدارة» بتاع الفورم، وفي صفحة الإضافة مفيش record
            ->visible(fn (?Place $record) => $record?->exists === true
                && ! $record->trashed()
                && $record->status === Place::STATUS_ACTIVE
                && self::can('setTier', $record))
            ->action(self::run(
                fn (Place $record, array $data, User $user) => app(PlaceTransitions::class)->setTier(
                    $record,
                    $user,
                    (string) $data['tier'],
                    self::date($data['from'] ?? null),
                    self::date($data['to'] ?? null),
                    $data['note'] ?? null,
                ),
                'اتغيّرت الباقة',
            ));
    }

    public static function verify(): Action
    {
        return Action::make('verify')
            ->label('أكّد البيانات')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('gray')
            ->visible(fn (Place $record) => ! $record->trashed()
                && $record->status === Place::STATUS_ACTIVE
                && self::can('update', $record))
            ->action(self::run(
                fn (Place $record, array $data, User $user) => app(PlaceTransitions::class)->verify($record, $user),
                'اتسجّل تأكيد البيانات',
            ));
    }

    /**
     * تكرار: بيفتح صفحة الإضافة بحقول المكان متعبية (من غير الصور والموقع والتليفون). المسودة ما بتتحفظش
     * قبل ما الفريق يحط الـ pin، لإن الموقع إلزامي للمسودة.
     */
    public static function duplicate(): Action
    {
        return Action::make('duplicate')
            ->label('تكرار')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->url(fn (Place $record) => PlaceResource::getUrl('create', ['duplicate' => $record->getKey()]))
            ->visible(fn (Place $record) => ! $record->trashed() && self::can('create', Place::class));
    }

    public static function viewInApp(): Action
    {
        return Action::make('viewInApp')
            ->label('عرض في التطبيق')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->url(fn (Place $record) => rtrim((string) config('wain.share_base_url'), '/').'/'.$record->slug)
            ->openUrlInNewTab()
            ->visible(fn (Place $record) => ! $record->trashed()
                && in_array($record->status, [Place::STATUS_ACTIVE, Place::STATUS_CLOSED], true)
                && filled(config('wain.share_base_url')));
    }

    public static function close(): Action
    {
        return Action::make('close')
            ->label('قفل نهائي')
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('danger')
            ->modalHeading('قفل المكان نهائياً')
            ->modalDescription('هيظهر للمستخدمين كمغلق نهائياً ويختفي من البحث.')
            ->modalSubmitActionLabel('قفل نهائي')
            ->schema([
                DatePicker::make('closed_at')
                    ->label('تاريخ القفل')
                    ->native(false)
                    ->default(fn () => now(self::CAIRO)->toDateString())
                    ->maxDate(fn () => now(self::CAIRO)->toDateString())
                    ->required(),
                Textarea::make('note')
                    ->label('ملاحظة')
                    ->rows(2)
                    ->maxLength(200),
            ])
            ->visible(fn (Place $record) => ! $record->trashed()
                && in_array($record->status, [Place::STATUS_ACTIVE, Place::STATUS_HIDDEN], true)
                && self::can('close', $record))
            ->action(self::run(
                fn (Place $record, array $data, User $user) => app(PlaceTransitions::class)
                    ->close($record, $user, self::date($data['closed_at']) ?? now(self::CAIRO), $data['note'] ?? null),
                'اتقفل نهائياً',
            ));
    }

    public static function reopen(): Action
    {
        return Action::make('reopen')
            ->label('إعادة فتح')
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('إعادة فتح المكان')
            ->modalDescription('هيرجع منشور وظاهر في التطبيق.')
            ->visible(fn (Place $record) => ! $record->trashed()
                && $record->status === Place::STATUS_CLOSED
                && self::can('publish', $record))
            ->action(self::run(
                fn (Place $record, array $data, User $user) => app(PlaceTransitions::class)->reopen($record, $user),
                'اتفتح تاني',
            ));
    }

    public static function delete(): Action
    {
        return Action::make('delete')
            ->label('حذف')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->modalHeading('حذف المكان')
            ->modalDescription('الحذف للمكرر والغلط بس. لو المكان قفل استخدم قفل نهائي. بيتسترجع خلال '.Place::PURGE_AFTER_DAYS.' يوم.')
            ->modalSubmitActionLabel('حذف')
            ->schema(fn (Place $record) => $record->status === Place::STATUS_ACTIVE
                ? [
                    TextInput::make('confirm_name')
                        ->label('اكتب اسم المكان للتأكيد')
                        ->helperText($record->name_ar)
                        ->required()
                        ->in(array_values(array_filter([$record->name_ar, $record->name_en])))
                        ->validationMessages(['in' => 'الاسم مش مطابق']),
                ]
                : [])
            ->visible(fn (Place $record) => ! $record->trashed() && self::can('delete', $record))
            ->action(function (Place $record, Action $action) {
                $user = self::user();

                try {
                    app(PlaceTransitions::class)->delete($record, $user);
                } catch (PlaceTransitionException $e) {
                    self::fail($e);
                    $action->halt();
                }

                Notification::make()->success()->title('اتحذف')->send();
            })
            ->successRedirectUrl(fn () => PlaceResource::getUrl('index'));
    }

    public static function restore(): Action
    {
        return Action::make('restore')
            ->label('استرجاع')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('استرجاع المكان')
            ->modalDescription('هيرجع «مخفي» عشان حد يراجعه قبل ما يظهر.')
            ->visible(fn (Place $record) => $record->trashed() && self::can('restore', $record))
            ->action(self::run(
                fn (Place $record, array $data, User $user) => app(PlaceTransitions::class)->restore($record, $user),
                'اتسترجع كمخفي',
            ));
    }

    // ───────────────────────── جماعي ─────────────────────────

    public static function bulkPublish(): BulkAction
    {
        return BulkAction::make('bulkPublish')
            ->label('نشر المحدد')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('الناقص بيتعدّى ومش بيمنع الباقي.')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) {
                $result = app(PlaceTransitions::class)->publishMany($records->load('categories:categories.id', 'coverPhoto:id,status')->loadCount('placeAttributes'), self::user());

                $notification = Notification::make()
                    ->title('اتنشر '.$result['published'].($result['skipped'] !== [] ? '، واتعدّى '.count($result['skipped']).' ناقصين' : ''));

                if ($result['skipped'] === []) {
                    $notification->success();
                } else {
                    $notification->warning()->body(collect($result['skipped'])
                        ->take(5)
                        ->map(fn (array $row) => $row['place']->name_ar.': '.implode('، ', $row['missing']))
                        ->implode("\n"));
                }

                $notification->send();
            });
    }

    public static function bulkHide(): BulkAction
    {
        return BulkAction::make('bulkHide')
            ->label('إخفاء المحدد')
            ->icon(Heroicon::OutlinedEyeSlash)
            ->color('warning')
            ->modalHeading('إخفاء الأماكن المحددة')
            ->modalDescription('الأماكن المنشورة بس هتتخفى، بسبب واحد للكل.')
            ->schema(self::hideFields())
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data) {
                $hidden = 0;
                $skipped = 0;

                foreach ($records as $place) {
                    try {
                        app(PlaceTransitions::class)->hide($place, self::user(), (string) $data['reason'], $data['details'] ?? null);
                        $hidden++;
                    } catch (PlaceTransitionException) {
                        $skipped++;
                    }
                }

                Notification::make()
                    ->success()
                    ->title('اتخفى '.$hidden.($skipped > 0 ? '، واتعدّى '.$skipped.' (مش منشورين)' : ''))
                    ->send();
            });
    }

    public static function bulkVerify(): BulkAction
    {
        return BulkAction::make('bulkVerify')
            ->label('أكّد البيانات')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->requiresConfirmation()
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) {
                foreach ($records as $place) {
                    app(PlaceTransitions::class)->verify($place, self::user());
                }

                Notification::make()->success()->title('اتأكدت بيانات '.$records->count().' مكان')->send();
            });
    }

    public static function bulkDelete(): BulkAction
    {
        return BulkAction::make('bulkDelete')
            ->label('حذف المحدد')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('حذف الأماكن المحددة')
            ->modalDescription('الحذف للمكرر والغلط بس. بيتسترجع خلال '.Place::PURGE_AFTER_DAYS.' يوم.')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) {
                $deleted = 0;

                foreach ($records as $place) {
                    try {
                        app(PlaceTransitions::class)->delete($place, self::user());
                        $deleted++;
                    } catch (PlaceTransitionException) {
                        // محذوف أصلاً
                    }
                }

                Notification::make()->success()->title('اتحذف '.$deleted.' مكان')->send();
            });
    }

    // ───────────────────────── داخلي ─────────────────────────

    /**
     * حقول modal الإخفاء (الفردي والجماعي).
     *
     * @return list<Select|Textarea>
     */
    private static function hideFields(): array
    {
        return [
            Select::make('reason')
                ->label('السبب')
                ->options(PlaceTransitions::HIDE_REASONS)
                ->required(),
            Textarea::make('details')
                ->label('تفاصيل')
                ->rows(3)
                ->maxLength(200)
                ->required(fn (Get $get) => $get('reason') === 'other'),
        ];
    }

    /**
     * يلف الإجراء: رسالة نجاح، ولو `PlaceTransitionException` رسالة خطأ بتسمّي السبب والـ modal بيفضل مفتوح.
     *
     * @param  Closure(Place, array<string, mixed>, User): mixed  $do
     */
    private static function run(Closure $do, string $success): Closure
    {
        return function (Place $record, Action $action, array $data = []) use ($do, $success) {
            try {
                $do($record, $data, self::user());
            } catch (PlaceTransitionException $e) {
                self::fail($e);
                $action->halt();
            }

            Notification::make()->success()->title($success)->send();
        };
    }

    private static function fail(PlaceTransitionException $e): void
    {
        Notification::make()->danger()->title($e->getMessage())->persistent()->send();
    }

    private static function can(string $ability, Place|string $record): bool
    {
        return self::user()?->can($ability, $record) ?? false;
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /** تاريخ من حقل DatePicker (نص `Y-m-d`) بتوقيت القاهرة، أو null. */
    private static function date(mixed $value): ?Carbon
    {
        return filled($value) ? Carbon::parse((string) $value, self::CAIRO) : null;
    }
}
