<?php

namespace App\Filament\Resources\Attributes\Tables;

use App\Models\Attribute;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class AttributesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('icon')
                    ->label('الأيقونة'),

                TextColumn::make('name_ar')
                    ->label('الاسم بالعربي')
                    ->searchable(),

                TextColumn::make('name_en')
                    ->label('الاسم بالإنجليزي')
                    ->searchable(),

                TextColumn::make('group')
                    ->label('المجموعة')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('places.attribute_groups.'.$state)),

                // عدد الأماكن المنشورة (status = active) اللي فيها الخاصية
                TextColumn::make('places_count')
                    ->label('الأماكن المنشورة')
                    ->counts(['places' => fn (Builder $query) => $query->where('places.status', 'active')])
                    ->numeric(),

                ToggleColumn::make('is_filterable')
                    ->label('في الفلاتر')
                    ->disabled(fn (Attribute $record) => Gate::denies('update', $record)),

                // الـ chip السريعة بتتغيّر من الـ form بس: الحد الأقصى (6) بيتفحص هناك
                IconColumn::make('is_quick_filter')
                    ->label('chip سريعة')
                    ->boolean(),

                ToggleColumn::make('is_active')
                    ->label('ظاهرة')
                    ->disabled(fn (Attribute $record) => Gate::denies('update', $record)),
            ])
            ->filters([
                SelectFilter::make('group')
                    ->label('المجموعة')
                    ->options(collect(Attribute::GROUPS)
                        ->mapWithKeys(fn (string $group) => [$group => __('places.attribute_groups.'.$group)])
                        ->all()),
            ])
            // الترتيب بالسحب (Admin بس: reorder في CatalogPolicy)
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            // الـ reorder بيعمل UPDATE واحد من غير model events، فمسح الـ cache صريح هنا
            ->afterReordering(fn () => Attribute::forgetCatalogCache())
            ->recordActions([
                EditAction::make(),
                // مخفي لخاصية مستخدمة (CatalogPolicy::delete): بتتخفى بدل ما تتحذف
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
