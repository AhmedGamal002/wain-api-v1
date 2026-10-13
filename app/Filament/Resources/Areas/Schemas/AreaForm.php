<?php

namespace App\Filament\Resources\Areas\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class AreaForm
{
    // حدود مصر التقريبية: بتمسك أي إحداثية برّه مصر (رقم ناقص أو زيادة). ماتمسكش الطول والعرض المبدّلين،
    // لإن مدى الاتنين متداخل (25 لـ 32) جوه مصر، فده بتراجعه العين على الخريطة (map picker في A1).
    public const LAT_MIN = 22;

    public const LAT_MAX = 32;

    public const LNG_MIN = 24;

    public const LNG_MAX = 37;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('city_id')
                    ->label('المدينة')
                    ->relationship('city', 'name_ar')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->live(),

                TextInput::make('name_ar')
                    ->label('الاسم بالعربي')
                    ->required()
                    ->maxLength(60)
                    ->helperText('مايتكررش جوه نفس المدينة')
                    ->unique(
                        table: 'areas',
                        column: 'name_ar',
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('city_id', $get('city_id')),
                    ),

                TextInput::make('name_en')
                    ->label('الاسم بالإنجليزي')
                    ->required()
                    ->maxLength(60)
                    ->helperText('الرابط المختصر (slug) بيتعمل منه تلقائياً ومايتغيرش بعد كده: التطبيق بيبعته في بارامتر area'),

                TextInput::make('slug')
                    ->label('الرابط المختصر (slug)')
                    ->disabled()
                    ->dehydrated(false)
                    ->visibleOn('edit'),

                TagsInput::make('aliases')
                    ->label('أسماء تانية')
                    ->placeholder('اكتب اسم واضغط Enter')
                    ->helperText('زي «التجمع» و«New Cairo» و«5th settlement». بتتبعت للبحث.'),

                TextInput::make('center_lat')
                    ->label('خط العرض (lat) لمنتصف المنطقة')
                    ->required()
                    ->numeric()
                    ->minValue(self::LAT_MIN)
                    ->maxValue(self::LAT_MAX)
                    ->helperText('مثلاً 30.0300 للتجمع الخامس. الخريطة بتفتح على المنتصف ده لما المستخدم يرفض الموقع.'),

                TextInput::make('center_lng')
                    ->label('خط الطول (lng) لمنتصف المنطقة')
                    ->required()
                    ->numeric()
                    ->minValue(self::LNG_MIN)
                    ->maxValue(self::LNG_MAX)
                    ->helperText('مثلاً 31.4700 للتجمع الخامس.'),

                Toggle::make('is_active')
                    ->label('متاحة للمستخدمين')
                    ->default(false)
                    ->helperText('ماتفعّلهاش قبل ما يبقى فيها عدد كافي من الأماكن المنشورة (مثلاً 50)'),
            ]);
    }
}
