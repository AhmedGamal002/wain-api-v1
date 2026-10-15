<?php

namespace App\Filament\Resources\Team\Tables;

use App\Filament\Resources\Users\Actions\UserActions;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * جدول «الفريق» (A7): أعضاء لوحة التحكم بس، أدمن أو مشرف. الاسم والإيميل والدور والحالة وآخر نشاط وتاريخ الانضمام.
 * مستخدمو التطبيق العاديين في شاشة تانية (`UsersTable`).
 *
 * الإجراءات: تغيير الدور (أدمن ← مشرف ← «يتشال من الفريق» فيرجع مستخدم تطبيق)، وإيقاف وإلغاء إيقاف بسبب.
 * الأدمن مش بيوقف نفسه ولا بيغيّر دوره (`UserPolicy`).
 */
class TeamTable
{
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

                TextColumn::make('role')
                    ->label('الدور')
                    ->state(fn (User $record) => UsersTable::roleLabels($record))
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        UsersTable::ROLE_LABELS['admin'] => 'danger',
                        UsersTable::ROLE_LABELS['moderator'] => 'warning',
                        default => 'info',
                    }),

                UsersTable::statusColumn(),

                TextColumn::make('last_active_at')
                    ->label('آخر نشاط')
                    ->dateTime('Y-m-d', 'Africa/Cairo')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('انضم في')
                    ->dateTime('Y-m-d', 'Africa/Cairo')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'asc')
            ->filters([
                SelectFilter::make('role')
                    ->label('الدور')
                    ->options(['admin' => UsersTable::ROLE_LABELS['admin'], 'moderator' => UsersTable::ROLE_LABELS['moderator']])
                    ->query(fn (Builder $query, array $data) => ($data['value'] ?? null)
                        ? $query->whereHas('roles', fn (Builder $roles) => $roles->where('name', $data['value']))
                        : $query),
            ])
            ->recordActions([
                ActionGroup::make([
                    UserActions::changeRole(),
                    UserActions::suspend(),
                    UserActions::unsuspend(),
                ]),
            ]);
    }
}
