<?php

namespace App\Filament\Resources\Places;

use App\Filament\Resources\Places\Pages\ListPlaces;
use App\Filament\Resources\Places\Tables\PlacesTable;
use App\Models\Place;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * A1 الأماكن (03 القسم 4): المحتوى الأساسي لـ Wain. أسبوع 4 فيه صفحة القائمة بس (التابز والأعمدة والفلاتر)،
 * وصفحتي الإضافة والتعديل (7 تابز) في أسبوع 5، والإجراءات والـ modals والصور والسجل في أسبوع 6.
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

    public static function table(Table $table): Table
    {
        return PlacesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlaces::route('/'),
        ];
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
