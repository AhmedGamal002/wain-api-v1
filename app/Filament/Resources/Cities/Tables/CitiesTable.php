<?php

namespace App\Filament\Resources\Cities\Tables;

use App\Models\City;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class CitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name_ar')
                    ->label('الاسم بالعربي')
                    ->searchable(),

                TextColumn::make('name_en')
                    ->label('الاسم بالإنجليزي')
                    ->searchable(),

                TextColumn::make('areas_count')
                    ->label('المناطق')
                    ->counts('areas')
                    ->numeric(),

                ToggleColumn::make('is_active')
                    ->label('ظاهرة')
                    ->disabled(fn (City $record) => Gate::denies('update', $record)),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->afterReordering(fn () => City::forgetCatalogCache())
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
