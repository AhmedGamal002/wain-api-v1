<?php

namespace App\Filament\Resources\Places;

use App\Filament\Resources\Places\Pages\CreatePlace;
use App\Filament\Resources\Places\Pages\EditPlace;
use App\Filament\Resources\Places\Pages\ListPlaces;
use App\Filament\Resources\Places\Pages\ViewPlace;
use App\Filament\Resources\Places\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\Places\Schemas\PlaceForm;
use App\Filament\Resources\Places\Schemas\PlaceInfolist;
use App\Filament\Resources\Places\Tables\PlacesTable;
use App\Models\Place;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * A1 الأماكن (03 القسم 4): المحتوى الأساسي لـ Wain. أسبوع 4: صفحة القائمة (التابز والأعمدة والفلاتر).
 * أسبوع 5: صفحتي الإضافة والتعديل (7 تابز والمواعيد والـ map picker وchecklist شروط النشر).
 * أسبوع 6: الإجراءات والـ modals (`Actions\PlaceActions`) والصور (`PlacePhotos`) وصفحة العرض وسجل التغييرات.
 *
 * الصلاحيات من `PlacePolicy` (Admin وModerator): الأزرار الممنوعة ما بتظهرش أصلاً والـ Policy بترفض الطلب المباشر.
 */
class PlaceResource extends Resource
{
    protected static ?string $model = Place::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $modelLabel = 'مكان';

    protected static ?string $pluralModelLabel = 'الأماكن';

    protected static ?string $navigationLabel = 'الأماكن';

    protected static string|\UnitEnum|null $navigationGroup = 'المحتوى';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name_ar';

    public static function form(Schema $schema): Schema
    {
        return PlaceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PlaceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlacesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlaces::route('/'),
            'create' => CreatePlace::route('/create'),
            'view' => ViewPlace::route('/{record}'),
            'edit' => EditPlace::route('/{record}/edit'),
        ];
    }

    /**
     * المكان المحذوف بيتفتح من الراوت (عرض واسترجاع). الـ scope بتاع الـ soft delete بيتشال هنا بس، فالقايمة
     * لسه بتستثني المحذوف لحد ما `TrashedFilter` يطلبه.
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /**
     * عدد الأماكن قيد المراجعة على بند القايمة الجانبية (03 القسم 4.1).
     */
    public static function getNavigationBadge(): ?string
    {
        $pending = Place::query()->where('status', Place::STATUS_PENDING)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'أماكن قيد المراجعة';
    }
}
