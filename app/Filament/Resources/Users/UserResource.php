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
 * A7 «مستخدمو التطبيق» (02 القسم A7): الناس اللي سجّلوا من التطبيق، بالحالات (نشط · موقوف · محذوف)، وإجراءات الأدمن:
 * إيقاف وإلغاء إيقاف بسبب، و«إضافة للفريق». فريق اللوحة (أدمن ومشرف) في شاشة لوحدها: `TeamMemberResource`.
 * Admin بس (`UserPolicy`، والـ Moderator مالوش دخول للشاشة).
 *
 * قايمة بس: مفيش إنشاء ولا تعديل ولا حذف لبيانات المستخدم من اللوحة (الحساب بيتعمل من التطبيق).
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'مستخدم';

    protected static ?string $pluralModelLabel = 'مستخدمو التطبيق';

    protected static ?string $navigationLabel = 'مستخدمو التطبيق';

    protected static string|\UnitEnum|null $navigationGroup = 'المستخدمين';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    /**
     * مستخدمو التطبيق بس (من غير أدمن ومشرف: دول في «الفريق»). المحذوف (soft delete) بيظهر بحالة «محذوف»،
     * والأدوار وحسابات الدخول الاجتماعي بتتحمّل مرة (من غير N+1).
     *
     * @return Builder<User>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->appUsers()
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
