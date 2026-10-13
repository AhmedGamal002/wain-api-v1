<?php

namespace App\Filament\Resources\Cities\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name_ar')
                    ->label('الاسم بالعربي')
                    ->required()
                    ->maxLength(60),

                TextInput::make('name_en')
                    ->label('الاسم بالإنجليزي')
                    ->required()
                    ->maxLength(60)
                    ->helperText('الرابط المختصر (slug) بيتعمل منه تلقائياً ومايتغيرش بعد كده'),

                TextInput::make('slug')
                    ->label('الرابط المختصر (slug)')
                    ->disabled()
                    ->dehydrated(false)
                    ->visibleOn('edit'),

                Toggle::make('is_active')
                    ->label('ظاهرة')
                    ->default(true)
                    ->helperText('المدينة المخفية بتخفي كل مناطقها من التطبيق'),
            ]);
    }
}
