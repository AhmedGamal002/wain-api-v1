<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\Actions\UserActions;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UserActions::invite(),
        ];
    }

    /**
     * تابز الحالة فوق الجدول، كل تاب بعدده: الكل · الفريق · موقوف · محذوف.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $users = fn () => User::query()->withoutGlobalScopes([SoftDeletingScope::class]);

        return [
            'all' => Tab::make('الكل')->badge($users()->count()),

            'team' => Tab::make('الفريق')
                ->badge($users()->whereNull('deleted_at')->role(['admin', 'moderator'])->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('users.deleted_at')->role(['admin', 'moderator'])),

            'suspended' => Tab::make('موقوف')
                ->badge($users()->whereNull('deleted_at')->where('status', 'suspended')->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('users.deleted_at')->where('users.status', 'suspended')),

            'deleted' => Tab::make('محذوف')
                ->badge($users()->whereNotNull('deleted_at')->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('users.deleted_at')),
        ];
    }
}
