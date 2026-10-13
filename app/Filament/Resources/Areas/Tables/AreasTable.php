<?php

namespace App\Filament\Resources\Areas\Tables;

use App\Models\Area;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class AreasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('city.name_ar')
                    ->label('المدينة'),

                TextColumn::make('name_ar')
                    ->label('الاسم بالعربي')
                    ->searchable(),

                TextColumn::make('name_en')
                    ->label('الاسم بالإنجليزي')
                    ->searchable(),

                TextColumn::make('aliases')
                    ->label('أسماء تانية')
                    ->badge()
                    ->limitList(3),

                TextColumn::make('center')
                    ->label('المنتصف')
                    ->state(fn (Area $record) => $record->center_lat.', '.$record->center_lng),

                // عدد الأماكن المنشورة (status = active) في المنطقة
                TextColumn::make('places_count')
                    ->label('الأماكن المنشورة')
                    ->counts(['places' => fn (Builder $query) => $query->where('places.status', 'active')])
                    ->numeric(),

                ToggleColumn::make('is_active')
                    ->label('متاحة')
                    ->disabled(fn (Area $record) => Gate::denies('update', $record)),
            ])
            ->filters([
                SelectFilter::make('city_id')
                    ->label('المدينة')
                    ->relationship('city', 'name_ar'),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->afterReordering(fn () => Area::forgetCatalogCache())
            ->recordActions([
                EditAction::make(),
                // مخفي لمنطقة فيها أماكن (CatalogPolicy::delete): بتتخفى بدل ما تتحذف
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
