<?php

namespace App\Filament\Resources\Team;

use App\Filament\Resources\Team\Pages\ListTeamMembers;
use App\Filament\Resources\Team\Tables\TeamTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * «الفريق» (A7): أعضاء لوحة التحكم، أدمن ومشرف. نفس جدول `users`، لكن شاشة لوحدها عشان فريق اللوحة مايتلخبطش مع
 * مستخدمي التطبيق (`UserResource`). الدعوة (إيميل ودور) من هنا، وتغيير الدور وإيقاف العضو من صفه.
 *
 * Admin بس (`UserPolicy`). مفيش إنشاء ولا تعديل ولا حذف: العضو بيتعمل بدعوة، وبيتشال بتغيير دوره.
 */
class TeamMemberResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'team';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $modelLabel = 'عضو فريق';

    protected static ?string $pluralModelLabel = 'الفريق';

    protected static ?string $navigationLabel = 'الفريق';

    protected static string|\UnitEnum|null $navigationGroup = 'المستخدمين';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return TeamTable::configure($table);
    }

    /**
     * أدمن ومشرف بس (حتى المحذوف بيبان بحالة «محذوف»)، وأدوارهم محمّلة مرة.
     *
     * @return Builder<User>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->team()
            ->with('roles');
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
            'index' => ListTeamMembers::route('/'),
        ];
    }
}
