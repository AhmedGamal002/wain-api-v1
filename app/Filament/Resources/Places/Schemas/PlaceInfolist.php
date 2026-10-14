<?php

namespace App\Filament\Resources\Places\Schemas;

use App\Models\Place;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

/**
 * صفحة عرض المكان (03 القسم 4.7): المكان زي ما هيظهر تقريباً (البيانات والصور)، وتحتها تابز السجل
 * (`ActivitiesRelationManager`). التقييمات (أسبوع 11) والإحصائيات (أسبوع 14) بيتضافوا لما بياناتهم تبقى موجودة.
 * المكان المحذوف بيتفتح هنا (مش في التعديل) وفيه زرار «استرجاع».
 */
final class PlaceInfolist
{
    private const TIERS = ['free' => 'مجاني', 'featured' => 'مميز', 'premium' => 'بريميوم'];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('المكان')
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    TextEntry::make('name_ar')->label('الاسم بالعربي')->weight('bold'),
                    TextEntry::make('name_en')->label('الاسم بالإنجليزي'),
                    TextEntry::make('slug')->label('الرابط المختصر')->copyable(),

                    TextEntry::make('status')
                        ->label('الحالة')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => Place::STATUS_LABELS[$state] ?? $state)
                        ->color(fn (string $state) => match ($state) {
                            Place::STATUS_PENDING => 'warning',
                            Place::STATUS_ACTIVE => 'success',
                            Place::STATUS_HIDDEN => 'warning',
                            Place::STATUS_REJECTED => 'danger',
                            default => 'gray',
                        }),
                    TextEntry::make('status_reason')->label('سبب آخر رفض أو إخفاء')->placeholder('—'),
                    TextEntry::make('listing_tier')
                        ->label('الباقة')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => self::TIERS[$state] ?? $state)
                        ->color(fn (string $state) => match ($state) {
                            'premium' => 'primary',
                            'featured' => 'info',
                            default => 'gray',
                        }),

                    TextEntry::make('primaryCategory.name_ar')->label('التصنيف الرئيسي'),
                    TextEntry::make('city.name_ar')->label('المدينة'),
                    TextEntry::make('area.name_ar')->label('المنطقة'),

                    TextEntry::make('address_ar')->label('العنوان')->columnSpan(['md' => 2]),
                    TextEntry::make('phone')->label('التليفون')->placeholder('—'),

                    TextEntry::make('price')
                        ->label('سعر الفرد')
                        ->state(fn (Place $record) => $record->price_min !== null && $record->price_max !== null
                            ? "{$record->price_min} – {$record->price_max} ج.م"
                            : null)
                        ->placeholder('—'),
                    TextEntry::make('verified_at')
                        ->label('آخر تأكيد للبيانات')
                        ->formatStateUsing(fn ($state) => Carbon::parse($state)->timezone('Africa/Cairo')->format('Y-m-d'))
                        ->placeholder('ما اتأكدش'),
                    TextEntry::make('published_at')
                        ->label('تاريخ النشر')
                        ->formatStateUsing(fn ($state) => Carbon::parse($state)->timezone('Africa/Cairo')->format('Y-m-d'))
                        ->placeholder('—'),

                    TextEntry::make('description_ar')->label('الوصف بالعربي')->columnSpanFull()->placeholder('—'),
                    TextEntry::make('internal_notes')->label('ملاحظات داخلية')->columnSpanFull()->placeholder('—'),
                ]),

            Section::make('الصور')
                ->schema([
                    View::make('filament.places.photos')->viewData(['readOnly' => true]),
                ]),
        ]);
    }
}
