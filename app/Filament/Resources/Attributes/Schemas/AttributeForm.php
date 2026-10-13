<?php

namespace App\Filament\Resources\Attributes\Schemas;

use App\Models\Attribute;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AttributeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name_ar')
                    ->label('الاسم بالعربي')
                    ->required()
                    ->maxLength(30)
                    ->helperText('لحد 30 حرف'),

                TextInput::make('name_en')
                    ->label('الاسم بالإنجليزي')
                    ->required()
                    ->maxLength(30)
                    ->helperText('لحد 30 حرف. الرابط المختصر (slug) بيتعمل منه تلقائياً ومايتغيرش بعد كده'),

                // الـ slug بيتولّد من الاسم الإنجليزي وقت الإنشاء ومايتغيرش (الفلاتر والـ API والـ CSV بيستخدموه)
                TextInput::make('slug')
                    ->label('الرابط المختصر (slug)')
                    ->disabled()
                    ->dehydrated(false)
                    ->visibleOn('edit'),

                Select::make('group')
                    ->label('المجموعة')
                    ->required()
                    ->options(collect(Attribute::GROUPS)
                        ->mapWithKeys(fn (string $group) => [$group => __('places.attribute_groups.'.$group)])
                        ->all()),

                TextInput::make('icon')
                    ->label('الأيقونة')
                    ->required()
                    ->maxLength(16)
                    ->helperText('إيموجي (مثلاً 📶). رفع SVG لسه مش متاح.'),

                Toggle::make('is_filterable')
                    ->label('يظهر في الفلاتر')
                    ->default(true),

                Toggle::make('is_quick_filter')
                    ->label('chip سريعة تحت خانة البحث')
                    ->default(false)
                    ->helperText('أقصى '.Attribute::MAX_QUICK_FILTERS.' chips. لازم تكون ظاهرة في الفلاتر ومفعّلة.')
                    ->rules([
                        fn (?Attribute $record, Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record, $get) {
                            if (! $value) {
                                return;
                            }

                            if (! $get('is_filterable') || ! $get('is_active')) {
                                $fail('الـ chip السريعة لازم تكون ظاهرة في الفلاتر ومفعّلة.');

                                return;
                            }

                            if (Attribute::quickFilterLimitReached($record)) {
                                $fail('وصلت للحد الأقصى ('.Attribute::MAX_QUICK_FILTERS.' chips). شيل واحدة الأول.');
                            }
                        },
                    ]),

                Toggle::make('is_active')
                    ->label('ظاهرة')
                    ->default(true)
                    ->helperText('المخفية ما بتظهرش في الفلاتر ولا في صفحة المكان'),
            ]);
    }
}
