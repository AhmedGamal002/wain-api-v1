<?php

namespace App\Filament\Resources\Categories\Tables;

use App\Models\Category;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

class CategoriesTable
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

                ColorColumn::make('color')
                    ->label('اللون'),

                // عدد الأماكن المنشورة (status = active) في التصنيف، رئيسي أو إضافي
                TextColumn::make('places_count')
                    ->label('الأماكن المنشورة')
                    ->counts(['places' => fn (Builder $query) => $query->where('places.status', 'active')])
                    ->numeric(),

                ToggleColumn::make('is_active')
                    ->label('ظاهر')
                    ->disabled(fn (Category $record) => Gate::denies('update', $record)),
            ])
            // الترتيب بالسحب (Admin بس: reorder في CategoryPolicy)
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            // الـ reorder بيعمل UPDATE واحد من غير model events، فمسح الـ cache صريح هنا
            ->afterReordering(fn () => Cache::forget(Category::CACHE_KEY))
            ->recordActions([
                EditAction::make(),
                // مخفي لتصنيف فيه أماكن (CategoryPolicy::delete): بيتخفى بدل ما يتحذف
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
