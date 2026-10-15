<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\Actions\UserActions;
use App\Models\SocialAccount;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * جدول «مستخدمو التطبيق» (A7): الناس اللي سجّلوا من التطبيق، من غير فريق اللوحة (دول في «الفريق»،
 * `TeamTable`). الاسم والإيميل وطريقة التسجيل والحالة (نشط · موقوف · محذوف) وعدد تقييماته (F8) وتاريخ التسجيل.
 * المحذوف = soft delete بعد طلب حذف الحساب، فالـ resource بيشيل الـ scope بتاعه عشان يظهر.
 *
 * الإجراءات: إيقاف وإلغاء إيقاف بسبب، و«إضافة للفريق» (بيدّيه دور أدمن أو مشرف، فيطلع من هنا ويظهر في «الفريق»).
 * الدوال العامة تحت (`statusOf` و`registrationMethods` و`roleLabels`) مشتركة مع `TeamTable`.
 */
class UsersTable
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_DELETED = 'deleted';

    public const ROLE_LABELS = [
        'admin' => 'أدمن',
        'moderator' => 'مشرف',
        'owner' => 'صاحب مكان',
    ];

    private const REGISTRATION_LABELS = [
        'email' => 'إيميل',
        SocialAccount::PROVIDER_GOOGLE => 'Google',
        SocialAccount::PROVIDER_APPLE => 'Apple',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('الاسم')
                    ->placeholder('—')
                    ->weight('bold')
                    ->searchable(),

                TextColumn::make('email')
                    ->label('الإيميل')
                    ->copyable()
                    ->searchable(),

                TextColumn::make('registration')
                    ->label('التسجيل')
                    ->state(fn (User $record) => self::registrationMethods($record))
                    ->badge()
                    ->color('gray'),

                // صاحب مكان بس بيبان هنا (دور فريق مش ممكن يكون في الجدول ده)
                TextColumn::make('owner')
                    ->label('النوع')
                    ->state(fn (User $record) => $record->roles->contains('name', 'owner') ? [self::ROLE_LABELS['owner']] : [])
                    ->badge()
                    ->color('info')
                    ->placeholder('—'),

                self::statusColumn(),

                TextColumn::make('reviews_count')
                    ->label('التقييمات')
                    ->counts('reviews')
                    ->numeric()
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('created_at')
                    ->label('تاريخ التسجيل')
                    ->dateTime('Y-m-d', 'Africa/Cairo')
                    ->sortable(),

                TextColumn::make('last_active_at')
                    ->label('آخر نشاط')
                    ->dateTime('Y-m-d', 'Africa/Cairo')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('owners')
                    ->label('أصحاب الأماكن بس')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'owner'))),

                SelectFilter::make('registration')
                    ->label('طريقة التسجيل')
                    ->options(self::REGISTRATION_LABELS)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'email' => $query->whereDoesntHave('socialAccounts'),
                        default => $query->whereHas('socialAccounts', fn (Builder $accounts) => $accounts->where('provider', $data['value'])),
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    UserActions::suspend(),
                    UserActions::unsuspend(),
                    UserActions::promote(),
                ]),
            ]);
    }

    /**
     * عمود الحالة (نشط · موقوف · محذوف) بلونه وسبب الإيقاف في الـ tooltip. مشترك مع جدول الفريق.
     */
    public static function statusColumn(): TextColumn
    {
        return TextColumn::make('status')
            ->label('الحالة')
            ->state(fn (User $record) => self::statusOf($record))
            ->formatStateUsing(fn (string $state) => match ($state) {
                self::STATUS_SUSPENDED => 'موقوف',
                self::STATUS_DELETED => 'محذوف',
                default => 'نشط',
            })
            ->badge()
            ->color(fn (string $state) => match ($state) {
                self::STATUS_SUSPENDED => 'danger',
                self::STATUS_DELETED => Color::Gray,
                default => 'success',
            })
            ->tooltip(fn (User $record) => $record->isSuspended() && ! $record->trashed()
                ? 'السبب: '.($record->suspended_reason ?? '—')
                : null);
    }

    /**
     * الحالة المعروضة: المحذوف بيغلب الإيقاف.
     */
    public static function statusOf(User $user): string
    {
        return match (true) {
            $user->trashed() => self::STATUS_DELETED,
            $user->isSuspended() => self::STATUS_SUSPENDED,
            default => self::STATUS_ACTIVE,
        };
    }

    /**
     * @return list<string>
     */
    public static function registrationMethods(User $user): array
    {
        $providers = $user->socialAccounts->pluck('provider')->unique()->values();

        if ($providers->isEmpty()) {
            return [self::REGISTRATION_LABELS['email']];
        }

        return $providers->map(fn (string $provider) => self::REGISTRATION_LABELS[$provider] ?? $provider)->all();
    }

    /**
     * @return list<string>
     */
    public static function roleLabels(User $user): array
    {
        $labels = $user->roles->pluck('name')
            ->map(fn (string $name) => self::ROLE_LABELS[$name] ?? $name)
            ->values()
            ->all();

        return $labels === [] ? ['مستخدم'] : $labels;
    }
}
