<?php

namespace App\Filament\Resources\Categories\Schemas;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name_ar')
                    ->label('الاسم بالعربي')
                    ->required()
                    ->maxLength(40)
                    ->helperText('لحد 40 حرف (بيظهر في chip صغيرة)'),

                TextInput::make('name_en')
                    ->label('الاسم بالإنجليزي')
                    ->required()
                    ->maxLength(40)
                    ->helperText('لحد 40 حرف. الرابط المختصر (slug) بيتعمل منه تلقائياً ومايتغيرش بعد كده'),

                // الـ slug بيتولّد من الاسم الإنجليزي وقت الإنشاء (Category::booted) ومايتغيرش بعد كده
                TextInput::make('slug')
                    ->label('الرابط المختصر (slug)')
                    ->disabled()
                    ->dehydrated(false)
                    ->visibleOn('edit'),

                TextInput::make('icon')
                    ->label('الأيقونة')
                    ->required()
                    ->maxLength(16)
                    ->helperText('إيموجي (مثلاً ☕). رفع SVG لسه مش متاح.'),

                ColorPicker::make('color')
                    ->label('اللون')
                    ->required()
                    ->regex('/^#[0-9A-Fa-f]{6}$/')
                    ->helperText('لون الـ pin على الخريطة وشريط الرئيسية'),

                Toggle::make('is_active')
                    ->label('ظاهر')
                    ->default(true)
                    ->helperText('المخفي ما بيظهرش في الشريط والفلاتر'),
            ]);
    }
}
