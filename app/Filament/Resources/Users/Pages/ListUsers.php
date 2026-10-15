<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    /**
     * تابز الحالة فوق الجدول، كل تاب بعدده: الكل · موقوف · محذوف (مستخدمو التطبيق بس، من غير الفريق).
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $users = fn () => User::query()->withoutGlobalScopes([SoftDeletingScope::class])->appUsers();

        return [
            'all' => Tab::make('الكل')->badge($users()->count()),

            'suspended' => Tab::make('موقوف')
                ->badge($users()->whereNull('deleted_at')->where('status', 'suspended')->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('users.deleted_at')->where('users.status', 'suspended')),

            'deleted' => Tab::make('محذوف')
                ->badge($users()->whereNotNull('deleted_at')->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('users.deleted_at')),
        ];
    }
}
