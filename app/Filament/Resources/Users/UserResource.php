<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * A7 المستخدمين (02 القسم A7): قائمة بالحالات (نشط · موقوف · محذوف) والأدوار، وإجراءات الأدمن: إيقاف وإلغاء إيقاف
 * بسبب، وتغيير الدور، ودعوة عضو فريق. Admin بس (`UserPolicy`، والـ Moderator مالوش دخول للشاشة).
 *
 * قايمة بس: مفيش إنشاء ولا تعديل ولا حذف لبيانات المستخدم من اللوحة (الحساب بيتعمل من التطبيق أو بدعوة).
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'مستخدم';

    protected static ?string $pluralModelLabel = 'المستخدمين';

    protected static ?string $navigationLabel = 'المستخدمين';

    protected static string|\UnitEnum|null $navigationGroup = 'المستخدمين';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    /**
     * المحذوف (soft delete) بيظهر بحالة «محذوف»، والأدوار وحسابات الدخول الاجتماعي بتتحمّل مرة (من غير N+1).
     *
     * @return Builder<User>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with(['roles', 'socialAccounts']);
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
            'index' => ListUsers::route('/'),
        ];
    }
}
