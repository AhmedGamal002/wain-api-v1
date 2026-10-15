<?php

namespace App\Filament\Resources\TeamInvitations;

use App\Filament\Resources\TeamInvitations\Pages\ListTeamInvitations;
use App\Filament\Resources\Users\Actions\UserActions;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\UserModeration;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * دعوات الفريق (A7): اللي اتبعتت وحالتها (مستنية · انتهت · اتقبلت)، مع «ابعت تاني» و«إلغاء». الدعوة نفسها بتتبعت من
 * زرار «دعوة عضو فريق» في شاشة المستخدمين. Admin بس (نفس صلاحية شاشة المستخدمين).
 */
class TeamInvitationResource extends Resource
{
    protected static ?string $model = TeamInvitation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $modelLabel = 'دعوة';

    protected static ?string $pluralModelLabel = 'دعوات الفريق';

    protected static ?string $navigationLabel = 'دعوات الفريق';

    protected static string|\UnitEnum|null $navigationGroup = 'المستخدمين';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->label('الإيميل')
                    ->weight('bold')
                    ->searchable(),

                TextColumn::make('role')
                    ->label('الدور')
                    ->formatStateUsing(fn (string $state) => UserModeration::TEAM_ROLES[$state] ?? $state)
                    ->badge()
                    ->color('gray'),

                TextColumn::make('status')
                    ->label('الحالة')
                    ->state(fn (TeamInvitation $record) => match (true) {
                        $record->accepted_at !== null => 'accepted',
                        $record->isExpired() => 'expired',
                        default => 'pending',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'accepted' => 'اتقبلت',
                        'expired' => 'انتهت',
                        default => 'مستنية',
                    })
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'accepted' => 'success',
                        'expired' => Color::Gray,
                        default => 'warning',
                    }),

                TextColumn::make('inviter.name')
                    ->label('دعاه')
                    ->placeholder('—'),

                TextColumn::make('expires_at')
                    ->label('تنتهي')
                    ->dateTime('Y-m-d H:i', 'Africa/Cairo')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('اتبعتت')
                    ->dateTime('Y-m-d H:i', 'Africa/Cairo')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                UserActions::resendInvitation(),
                UserActions::revokeInvitation(),
            ])
            ->emptyStateHeading('مفيش دعوات')
            ->emptyStateDescription('الدعوة بتتبعت من «دعوة عضو فريق» في شاشة المستخدمين.');
    }

    /**
     * @return Builder<TeamInvitation>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('inviter:id,name');
    }

    /** نفس صلاحية شاشة المستخدمين: Admin بس (مفيش Policy للدعوات). */
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', User::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeamInvitations::route('/'),
        ];
    }
}
