<?php

namespace App\Filament\Resources\Places\RelationManagers;

use App\Filament\Resources\Places\Pages\ViewPlace;
use App\Support\PlaceActivityPresenter;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Spatie\Activitylog\Models\Activity;

/**
 * سجل تغييرات المكان (03 القسم 4.7): سطر لكل تغيير فيه التاريخ ومين ونوع التغيير والحقول بالقيمة القديمة
 * والجديدة. للقراءة فقط، وما بيتمسحش حتى لو المكان اتحذف (`activity_log` مش بيتأثر بالـ soft delete).
 */
class ActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'السجل';

    protected static ?string $modelLabel = 'تغيير';

    protected static ?string $pluralModelLabel = 'التغييرات';

    public function isReadOnly(): bool
    {
        return true;
    }

    /** السجل في صفحة العرض بس (03 القسم 4.7)، مش تحت فورم التعديل. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $pageClass === ViewPlace::class && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->formatStateUsing(fn ($state) => Carbon::parse($state)->timezone('Africa/Cairo')->format('Y-m-d H:i'))
                    ->sortable(),

                TextColumn::make('causer.name')
                    ->label('مين')
                    ->placeholder('النظام'),

                TextColumn::make('type')
                    ->label('نوع التغيير')
                    ->badge()
                    ->state(fn (Activity $record) => PlaceActivityPresenter::type($record))
                    ->color(fn (string $state) => match ($state) {
                        'إنشاء' => 'success',
                        'تغيير حالة' => 'warning',
                        'تغيير باقة' => 'info',
                        'حذف' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('changes')
                    ->label('التغيير')
                    ->state(fn (Activity $record) => new HtmlString(
                        collect(PlaceActivityPresenter::lines($record))
                            ->map(fn (string $line) => '<div>'.e($line).'</div>')
                            ->implode('')
                    ))
                    ->wrap(),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('مفيش تغييرات متسجلة')
            ->emptyStateIcon('heroicon-o-clock');
    }
}
