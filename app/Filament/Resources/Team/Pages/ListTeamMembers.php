<?php

namespace App\Filament\Resources\Team\Pages;

use App\Filament\Resources\Team\TeamMemberResource;
use App\Filament\Resources\Users\Actions\UserActions;
use App\Models\User;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ListTeamMembers extends ListRecords
{
    protected static string $resource = TeamMemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UserActions::invite(),
        ];
    }

    /**
     * تابز فوق الجدول بأعدادها: الكل · أدمن · مشرف · موقوف.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        // «الكل» بيعدّ اللي محذوف كمان (بيظهر بحالة «محذوف»)، والباقي الشغّالين بس
        $everyone = fn () => User::query()->withoutGlobalScopes([SoftDeletingScope::class])->team();
        $team = fn () => $everyone()->whereNull('deleted_at');
        $role = fn (string $name) => fn (Builder $query) => $query
            ->whereNull('users.deleted_at')
            ->whereHas('roles', fn (Builder $roles) => $roles->where('name', $name));

        return [
            'all' => Tab::make('الكل')->badge($everyone()->count()),

            'admin' => Tab::make('أدمن')
                ->badge($team()->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'admin'))->count())
                ->modifyQueryUsing($role('admin')),

            'moderator' => Tab::make('مشرف')
                ->badge($team()->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'moderator'))->count())
                ->modifyQueryUsing($role('moderator')),

            'suspended' => Tab::make('موقوف')
                ->badge($team()->where('status', 'suspended')->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('users.deleted_at')->where('users.status', 'suspended')),
        ];
    }
}
