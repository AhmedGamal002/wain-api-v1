<?php

namespace App\Filament\Resources\Users\Actions;

use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\TeamInvitations;
use App\Support\UserModeration;
use App\Support\UserModerationException;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * أزرار شاشة المستخدمين (A7، 02 «المستخدمين والأدوار»): إيقاف وإلغاء إيقاف بسبب، وتغيير الدور، ودعوة عضو فريق.
 * القواعد كلها في `UserModeration` و`TeamInvitations` (الزرار بيظهر حسب الحالة والصلاحية، والـ Policy بترفض
 * الطلب المباشر)، وكل زرار هنا بيعرض الـ modal وبيعرض اللي رجع من القواعد بس.
 *
 * مفيش تعديل لبيانات المستخدم الشخصية (الاسم والإيميل والصورة): ده قرار من المواصفات مش نقص.
 */
final class UserActions
{
    // ───────────────────────── المستخدم ─────────────────────────

    public static function suspend(): Action
    {
        return Action::make('suspend')
            ->label('إيقاف')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->modalHeading('إيقاف الحساب')
            ->modalDescription('المستخدم هيخرج من كل أجهزته ومش هيقدر يسجّل دخول لحد ما الإيقاف يتشال.')
            ->modalSubmitActionLabel('إيقاف')
            ->schema([
                Textarea::make('reason')
                    ->label('السبب')
                    ->rows(3)
                    ->maxLength(255)
                    ->required(),
            ])
            ->visible(fn (User $record) => ! $record->trashed()
                && ! $record->isSuspended()
                && self::can('suspend', $record))
            ->action(self::run(
                fn (User $record, array $data, User $actor) => app(UserModeration::class)
                    ->suspend($record, $actor, (string) $data['reason']),
                'اتوقف الحساب',
            ));
    }

    public static function unsuspend(): Action
    {
        return Action::make('unsuspend')
            ->label('إلغاء الإيقاف')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->modalHeading('إلغاء إيقاف الحساب')
            ->modalDescription(fn (User $record) => $record->suspended_reason
                ? 'سبب الإيقاف: '.$record->suspended_reason
                : null)
            ->modalSubmitActionLabel('إلغاء الإيقاف')
            ->schema([
                Textarea::make('reason')
                    ->label('السبب')
                    ->rows(3)
                    ->maxLength(255)
                    ->required(),
            ])
            ->visible(fn (User $record) => ! $record->trashed()
                && $record->isSuspended()
                && self::can('unsuspend', $record))
            ->action(self::run(
                fn (User $record, array $data, User $actor) => app(UserModeration::class)
                    ->unsuspend($record, $actor, (string) $data['reason']),
                'اتلغى الإيقاف',
            ));
    }

    public static function changeRole(): Action
    {
        return Action::make('changeRole')
            ->label('تغيير الدور')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('gray')
            ->modalHeading('تغيير دور المستخدم')
            ->modalDescription('الأدمن بيدير المستخدمين والكتالوج والنشر. المشرف بيضيف ويراجع من غير نشر. «مستخدم عادي» بيشيل الدخول للوحة.')
            ->modalSubmitActionLabel('حفظ')
            ->fillForm(fn (User $record) => ['role' => UserModeration::teamRole($record) ?? 'none'])
            ->schema([
                Select::make('role')
                    ->label('الدور')
                    ->options(['none' => 'مستخدم عادي', ...UserModeration::TEAM_ROLES])
                    ->required()
                    ->native(false),
            ])
            ->visible(fn (User $record) => ! $record->trashed() && self::can('changeRole', $record))
            ->action(self::run(
                fn (User $record, array $data, User $actor) => app(UserModeration::class)
                    ->changeRole($record, $actor, $data['role'] === 'none' ? null : (string) $data['role']),
                'اتغيّر الدور',
            ));
    }

    // ───────────────────────── الدعوات ─────────────────────────

    /**
     * دعوة عضو فريق: إيميل ودور، والرابط بيوصل بالإيميل ويفضل صالح 7 أيام.
     */
    public static function invite(): Action
    {
        return Action::make('invite')
            ->label('دعوة عضو فريق')
            ->icon(Heroicon::OutlinedEnvelope)
            ->modalHeading('دعوة عضو فريق')
            ->modalDescription('هيوصله إيميل برابط صالح '.TeamInvitations::VALID_DAYS.' أيام، وبيعمل حسابه بالدور اللي تختاره.')
            ->modalSubmitActionLabel('إرسال الدعوة')
            ->schema([
                TextInput::make('email')
                    ->label('الإيميل')
                    ->email()
                    ->required()
                    ->maxLength(255),
                Select::make('role')
                    ->label('الدور')
                    ->options(UserModeration::TEAM_ROLES)
                    ->default('moderator')
                    ->required()
                    ->native(false),
            ])
            ->visible(fn () => self::can('invite', User::class))
            ->action(function (array $data, Action $action): void {
                try {
                    $invitation = app(TeamInvitations::class)->invite((string) $data['email'], (string) $data['role'], self::user());
                } catch (UserModerationException $e) {
                    self::fail($e);
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title('اتبعتت الدعوة لـ '.$invitation->email)->send();
            });
    }

    public static function resendInvitation(): Action
    {
        return Action::make('resend')
            ->label('ابعت تاني')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('إعادة إرسال الدعوة')
            ->modalDescription('الرابط القديم هيتلغي، وهيتبعت رابط جديد صالح '.TeamInvitations::VALID_DAYS.' أيام.')
            ->visible(fn (TeamInvitation $record) => $record->accepted_at === null && self::can('invite', User::class))
            ->action(function (TeamInvitation $record, Action $action): void {
                try {
                    app(TeamInvitations::class)->resend($record, self::user());
                } catch (UserModerationException $e) {
                    self::fail($e);
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title('اتبعتت الدعوة تاني')->send();
            });
    }

    public static function revokeInvitation(): Action
    {
        return Action::make('revoke')
            ->label('إلغاء الدعوة')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('إلغاء الدعوة')
            ->modalDescription('الرابط هيبطّل يشتغل.')
            ->visible(fn (TeamInvitation $record) => $record->accepted_at === null && self::can('invite', User::class))
            ->action(function (TeamInvitation $record, Action $action): void {
                try {
                    app(TeamInvitations::class)->revoke($record, self::user());
                } catch (UserModerationException $e) {
                    self::fail($e);
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title('اتلغت الدعوة')->send();
            });
    }

    // ───────────────────────── مساعدات ─────────────────────────

    private static function run(Closure $do, string $success): Closure
    {
        return function (User $record, Action $action, array $data = []) use ($do, $success): void {
            try {
                $do($record, $data, self::user());
            } catch (UserModerationException $e) {
                self::fail($e);
                $action->halt();

                return;
            }

            Notification::make()->success()->title($success)->send();
        };
    }

    private static function fail(UserModerationException $e): void
    {
        Notification::make()->danger()->title($e->getMessage())->persistent()->send();
    }

    private static function can(string $ability, User|string $subject): bool
    {
        return self::user()->can($ability, $subject);
    }

    private static function user(): User
    {
        $user = auth()->user();

        assert($user instanceof User);

        return $user;
    }
}
