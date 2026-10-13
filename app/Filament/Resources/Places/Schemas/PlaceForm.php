<?php

namespace App\Filament\Resources\Places\Schemas;

use App\Models\Area;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\City;
use App\Models\Place;
use App\Support\AreaSuggestion;
use App\Support\GoogleMapsUrl;
use App\Support\OpeningHours;
use App\Support\OpeningHoursForm;
use App\Support\PhoneNumber;
use App\Support\PlaceLocation;
use App\Support\PlacePublishCheck;
use App\Support\PlaceSlug;
use App\Support\PriceRange;
use Closure;
use Fahiem\FilamentPinpoint\Pinpoint;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * فورم المكان (03 القسم 4.3): 7 تابز على اليمين ولوحة جانبية على الشمال فيها الحالة وchecklist شروط النشر.
 *
 * مفاتيح التابز هي نفسها قيم `PlacePublishCheck::CONDITIONS`، فالضغط على بند في الـ checklist بيفتح تابه.
 * حالة الفورم (`$livewire->data`) هي اللي بتتحول للقطة بيانات (`snapshot()`) وبتتحقق منها `PlacePublishCheck`
 * قبل الحفظ، فشروط النشر هنا مش منطق تاني.
 *
 * ملحوظات للي هيعدّل:
 *  - حقل الخريطة اسمه `map_pin` ومش `location`: `places.location` عمود POINT الـ Observer بيملاه، والحقل بيكتب
 *    في `latitude` و`longitude` مباشرةً ومش بيتحفظ (`dehydrated(false)`).
 *  - المواعيد في الفورم بشكل حقول (`OpeningHoursForm`) وبتتحوّل للتخزين في صفحتي الإضافة والتعديل.
 *  - الخصائص 4 CheckboxLists (واحدة لكل مجموعة) من غير relationship: الـ sync في `HandlesPlaceSave`.
 */
class PlaceForm
{
    /** مفاتيح التابز بترتيبها (الأربعة الأولى والمواعيد والصور هما `PlacePublishCheck::CONDITIONS`). */
    public const TAB_BASIC = 'basic';

    public const TAB_CATEGORY = 'category';

    public const TAB_LOCATION = 'location';

    public const TAB_CONTACT = 'contact';

    public const TAB_HOURS = 'hours';

    public const TAB_PHOTOS = 'photos';

    public const TAB_ADMIN = 'admin';

    /** اسم حدث المتصفح اللي بيبدّل التاب (الـ checklist بتبعته، والـ Tabs بتسمعه). */
    public const OPEN_TAB_EVENT = 'wain-open-tab';

    /** حقل الفورم (أول جزء من المفتاح بعد `data.`) ← التاب، عشان النقطة الحمرا على التاب اللي فيه خطأ. */
    private const FIELD_TABS = [
        'name_ar' => self::TAB_BASIC, 'name_en' => self::TAB_BASIC, 'search_aliases' => self::TAB_BASIC,
        'slug' => self::TAB_BASIC, 'description_ar' => self::TAB_BASIC, 'description_en' => self::TAB_BASIC,
        'categories' => self::TAB_CATEGORY, 'primary_category_id' => self::TAB_CATEGORY,
        'price_min' => self::TAB_CATEGORY, 'price_max' => self::TAB_CATEGORY,
        'google_maps_url' => self::TAB_LOCATION, 'map_pin' => self::TAB_LOCATION, 'latitude' => self::TAB_LOCATION,
        'longitude' => self::TAB_LOCATION, 'city_id' => self::TAB_LOCATION, 'area_id' => self::TAB_LOCATION,
        'address_ar' => self::TAB_LOCATION, 'address_en' => self::TAB_LOCATION,
        'phone' => self::TAB_CONTACT, 'whatsapp' => self::TAB_CONTACT, 'instagram' => self::TAB_CONTACT,
        'facebook_url' => self::TAB_CONTACT, 'website' => self::TAB_CONTACT, 'menu_url' => self::TAB_CONTACT,
        'opening_hours' => self::TAB_HOURS, 'ramadan_hours' => self::TAB_HOURS,
        'internal_notes' => self::TAB_ADMIN,
    ];

    /** خريطة الـ picker بتفتح على القاهرة لحد ما الـ pin يتحط. */
    private const MAP_DEFAULT_LAT = 30.0444;

    private const MAP_DEFAULT_LNG = 31.2357;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Grid::make(['default' => 1, 'lg' => 4])
                    ->columnSpanFull()
                    ->schema([
                        Tabs::make('place')
                            ->key('place-tabs')
                            ->persistTabInQueryString()
                            ->columnSpan(['lg' => 3])
                            ->extraAlpineAttributes([
                                'x-on:'.self::OPEN_TAB_EVENT.'.window' => 'tab = $event.detail.tab',
                            ])
                            ->tabs([
                                self::basicTab(),
                                self::categoryTab(),
                                self::locationTab(),
                                self::contactTab(),
                                self::hoursTab(),
                                self::photosTab(),
                                self::adminTab(),
                            ]),

                        Group::make([
                            Section::make('الحالة وشروط النشر')
                                ->schema([View::make('filament.places.sidebar')])
                                ->extraAttributes(['style' => 'position: sticky; top: 5rem; align-self: start;']),
                        ])->columnSpan(['lg' => 1]),
                    ]),
            ]);
    }

    // ───────────────────────── اللقطة والشروط ─────────────────────────

    /**
     * حالة الفورم الخام ← لقطة `PlacePublishCheck::check()`. بتشتغل على `$livewire->data` (قبل الحفظ)، فالـ
     * checklist والتحقق وقت الحفظ بيقروا من نفس الدالة. الغلاف من المكان المحفوظ (الصور في أسبوع 6).
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function snapshot(array $state, ?Place $record = null): array
    {
        return [
            'name_ar' => $state['name_ar'] ?? null,
            'name_en' => $state['name_en'] ?? null,
            'primary_category_id' => self::intOrNull($state['primary_category_id'] ?? null),
            'category_ids' => array_values(array_filter((array) ($state['categories'] ?? []), fn ($id) => filled($id))),
            'area_id' => self::intOrNull($state['area_id'] ?? null),
            'latitude' => $state['latitude'] ?? null,
            'longitude' => $state['longitude'] ?? null,
            'address_ar' => $state['address_ar'] ?? null,
            'description_ar' => $state['description_ar'] ?? null,
            'attribute_count' => count(self::attributeIds($state)),
            'price_min' => self::intOrNull($state['price_min'] ?? null),
            'price_max' => self::intOrNull($state['price_max'] ?? null),
            'opening_hours' => OpeningHoursForm::fromFormState(is_array($state['opening_hours'] ?? null) ? $state['opening_hours'] : null),
            'cover_ready' => $record?->coverPhoto?->isReady() ?? false,
        ];
    }

    /**
     * ids الخصائص المختارة من الأربع مجموعات.
     *
     * @param  array<string, mixed>  $state
     * @return list<int>
     */
    public static function attributeIds(array $state): array
    {
        $ids = [];
        foreach (Attribute::GROUPS as $group) {
            foreach ((array) ($state['attributes_'.$group] ?? []) as $id) {
                if (filled($id)) {
                    $ids[] = (int) $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * التاب اللي بيتفتح لخطأ validation على حقل (`data.opening_hours.sat.state` ← hours)، أو null.
     */
    public static function tabForErrorKey(string $key): ?string
    {
        $field = explode('.', preg_replace('/^data\./', '', $key) ?? $key)[0];

        if (str_starts_with($field, 'attributes_')) {
            return self::TAB_CATEGORY;
        }

        return self::FIELD_TABS[$field] ?? null;
    }

    // ───────────────────────── التابز ─────────────────────────

    private static function basicTab(): Tab
    {
        return self::tab(self::TAB_BASIC, 'الأساسية', Heroicon::OutlinedDocumentText)
            ->schema([
                TextInput::make('name_ar')
                    ->label('الاسم بالعربي')
                    ->required()
                    ->minLength(2)
                    ->maxLength(120)
                    ->helperText('الاسم زي ما مكتوب على اليافطة')
                    ->live(onBlur: true),

                TextInput::make('name_en')
                    ->label('الاسم بالإنجليزي')
                    ->required()
                    ->minLength(2)
                    ->maxLength(120)
                    ->helperText('بيتعمل منه الرابط')
                    ->live(onBlur: true),

                TagsInput::make('search_aliases')
                    ->label('كلمات بحث إضافية')
                    ->placeholder('اكتب كلمة واضغط Enter')
                    ->helperText('اكتب الأسماء اللي الناس بتكتبها بالفرانكو أو بغلطات شائعة، زي «silantro». لحد 10 كلمات.')
                    ->rules(['array', 'max:10'])
                    ->nestedRecursiveRules(['string', 'max:40'])
                    ->columnSpanFull(),

                TextInput::make('slug')
                    ->label('الرابط المختصر (slug)')
                    ->placeholder('بيتعمل تلقائياً من الاسم الإنجليزي')
                    ->helperText(fn (?Place $record) => $record?->published_at !== null
                        ? 'اتقفل بعد أول نشر: تغييره بيكسر الروابط المتشاركة'
                        : 'سيبه فاضي ويتعمل تلقائياً. بيتقفل بعد أول نشر.')
                    ->disabled(fn (?Place $record) => $record?->published_at !== null)
                    ->dehydrated(fn (?Place $record) => $record?->published_at === null)
                    ->maxLength(140)
                    ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                    ->validationMessages(['regex' => 'حروف إنجليزية صغيرة وأرقام وشرطة بس'])
                    ->rules([
                        fn (?Place $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record) {
                            if (filled($value) && PlaceSlug::isTaken((string) $value, $record?->id)) {
                                $fail('الرابط ده مستخدم لمكان تاني أو محجوز');
                            }
                        },
                    ])
                    ->columnSpanFull(),

                Textarea::make('description_ar')
                    ->label('الوصف بالعربي')
                    ->rows(4)
                    ->maxLength(1000)
                    ->helperText(fn (Get $get) => self::descriptionHint($get('description_ar')))
                    ->live(onBlur: true)
                    ->columnSpanFull(),

                Textarea::make('description_en')
                    ->label('الوصف بالإنجليزي')
                    ->rows(3)
                    ->maxLength(1000)
                    ->live(onBlur: true)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    private static function categoryTab(): Tab
    {
        $groups = [];
        foreach (Attribute::GROUPS as $group) {
            $groups[] = CheckboxList::make('attributes_'.$group)
                ->label(__('places.attribute_groups.'.$group))
                ->options(fn () => Attribute::query()
                    ->where('is_active', true)
                    ->where('group', $group)
                    ->orderBy('sort_order')
                    ->pluck('name_ar', 'id')
                    ->all())
                ->columns(['default' => 2, 'md' => 3])
                ->live();
        }

        return self::tab(self::TAB_CATEGORY, 'التصنيف والسعر', Heroicon::OutlinedTag)
            ->schema([
                Select::make('categories')
                    ->label('التصنيفات')
                    ->relationship('categories', 'name_ar', fn ($query) => $query->where('is_active', true)->orderBy('sort_order'))
                    ->multiple()
                    ->preload()
                    ->required()
                    ->minItems(1)
                    ->maxItems(3)
                    ->helperText('من 1 لـ 3 تصنيفات')
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set) {
                        $selected = array_map('intval', (array) $get('categories'));
                        $primary = $get('primary_category_id');

                        // التصنيف الرئيسي لازم يكون من المختارين: واحد بس بيتحدد لوحده، والمشال بيتفضّى
                        if (count($selected) === 1) {
                            $set('primary_category_id', $selected[0]);
                        } elseif ($primary !== null && ! in_array((int) $primary, $selected, true)) {
                            $set('primary_category_id', null);
                        }
                    }),

                Select::make('primary_category_id')
                    ->label('التصنيف الرئيسي')
                    ->required()
                    ->options(fn (Get $get) => Category::query()
                        ->whereIn('id', array_map('intval', (array) $get('categories')))
                        ->orderBy('sort_order')
                        ->pluck('name_ar', 'id')
                        ->all())
                    ->helperText('هو اللي بيظهر على الكارت والخريطة')
                    ->live(),

                Section::make('الخصائص')
                    ->description('اختار اللي متأكد منه بس. 3 على الأقل للنشر.')
                    ->schema($groups)
                    ->columns(1)
                    ->columnSpanFull(),

                Grid::make(['default' => 1, 'md' => 3])
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('price_min')
                            ->label('سعر الفرد من')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(10000)
                            ->suffix('ج.م')
                            ->helperText('متوسط اللي بيدفعه شخص واحد')
                            ->live(onBlur: true),

                        TextInput::make('price_max')
                            ->label('إلى')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(10000)
                            ->suffix('ج.م')
                            ->live(onBlur: true)
                            ->rules([
                                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                                    $min = $get('price_min');
                                    if (is_numeric($value) && is_numeric($min) && (int) $value < (int) $min) {
                                        $fail('«إلى» لازم تكون أكبر من أو تساوي «من»');
                                    }
                                },
                            ]),

                        Text::make(fn (Get $get) => 'فئة السعر: '.self::priceRangeLabel($get('price_max')))
                            ->color('gray'),
                    ]),
            ])
            ->columns(2);
    }

    private static function locationTab(): Tab
    {
        return self::tab(self::TAB_LOCATION, 'الموقع', Heroicon::OutlinedMapPin)
            ->schema([
                TextInput::make('google_maps_url')
                    ->label('رابط Google Maps')
                    ->url()
                    ->maxLength(500)
                    ->helperText('الصق الرابط (طويل أو مختصر) واضغط «استخرج الموقع» والإحداثيات تتملى لوحدها')
                    ->columnSpanFull(),

                Actions::make([
                    Action::make('extractLocation')
                        ->label('استخرج الموقع')
                        ->icon(Heroicon::OutlinedMapPin)
                        ->color('gray')
                        ->action(fn (Get $get, Set $set) => self::extractLocation($get, $set)),
                ])->key('location-actions')->columnSpanFull(),

                Pinpoint::make('map_pin')
                    ->label('المكان على الخريطة')
                    ->latField('latitude')
                    ->lngField('longitude')
                    ->defaultLocation(self::MAP_DEFAULT_LAT, self::MAP_DEFAULT_LNG)
                    ->draggable()
                    ->searchable()
                    ->dehydrated(false)
                    ->helperText('قرّب لحد ما الـ pin يبقى على باب المكان، واسحبه أو اضغط على الخريطة')
                    ->columnSpanFull(),

                TextInput::make('latitude')
                    ->label('خط العرض (lat)')
                    ->required()
                    ->numeric()
                    ->readOnly()
                    ->minValue(PlaceLocation::EGYPT_LAT_MIN)
                    ->maxValue(PlaceLocation::EGYPT_LAT_MAX)
                    ->validationMessages([
                        'required' => 'حدّد مكان الـ pin على الخريطة',
                        'min' => 'الموقع برّه مصر',
                        'max' => 'الموقع برّه مصر',
                    ])
                    ->helperText('بيتملى من الخريطة'),

                TextInput::make('longitude')
                    ->label('خط الطول (lng)')
                    ->required()
                    ->numeric()
                    ->readOnly()
                    ->minValue(PlaceLocation::EGYPT_LNG_MIN)
                    ->maxValue(PlaceLocation::EGYPT_LNG_MAX)
                    ->validationMessages([
                        'required' => 'حدّد مكان الـ pin على الخريطة',
                        'min' => 'الموقع برّه مصر',
                        'max' => 'الموقع برّه مصر',
                    ])
                    ->helperText('بيتملى من الخريطة')
                    // بيشتغل لما الـ pin يتحرك (الخريطة بتكتب lat وlng مع بعض): اقتراح المنطقة من أقرب منتصف
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::suggestArea($get, $set)),

                Hidden::make('suggested_area_id')->dehydrated(false),

                Select::make('city_id')
                    ->label('المدينة')
                    ->required()
                    ->options(fn () => City::query()->orderBy('sort_order')->pluck('name_ar', 'id')->all())
                    ->default(fn () => City::query()->count() === 1 ? City::query()->value('id') : null)
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('area_id', null)),

                Select::make('area_id')
                    ->label('المنطقة')
                    ->required()
                    ->searchable()
                    ->options(fn (Get $get) => Area::query()
                        ->when(filled($get('city_id')), fn ($query) => $query->where('city_id', $get('city_id')))
                        ->orderBy('sort_order')
                        ->get(['id', 'name_ar', 'is_active'])
                        ->mapWithKeys(fn (Area $area) => [$area->id => $area->name_ar.($area->is_active ? '' : ' (غير مفعّلة)')])
                        ->all())
                    ->helperText('بتتقترح تلقائياً من أقرب منتصف منطقة للـ pin')
                    ->live(),

                TextInput::make('address_ar')
                    ->label('العنوان بالعربي')
                    ->required()
                    ->maxLength(255)
                    ->helperText('الشارع وأقرب علامة مميزة، زي «شارع التسعين، جنب كايرو فيستيفال»')
                    ->live(onBlur: true)
                    ->columnSpanFull(),

                TextInput::make('address_en')
                    ->label('العنوان بالإنجليزي')
                    ->maxLength(255)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    private static function contactTab(): Tab
    {
        return self::tab(self::TAB_CONTACT, 'التواصل', Heroicon::OutlinedPhone)
            ->schema([
                TextInput::make('phone')
                    ->label('التليفون')
                    ->maxLength(30)
                    ->extraInputAttributes(['inputmode' => 'tel', 'dir' => 'ltr'])
                    ->helperText('الرقم اللي بيردوا عليه: موبايل أو أرضي بكود المحافظة أو خط ساخن. بيتخزّن بصيغة +20…')
                    ->rules([fn (): Closure => self::phoneRule(mobileOnly: false)])
                    ->dehydrateStateUsing(fn (?string $state) => PhoneNumber::normalize($state))
                    ->live(onBlur: true),

                TextInput::make('whatsapp')
                    ->label('واتساب')
                    ->maxLength(30)
                    ->extraInputAttributes(['inputmode' => 'tel', 'dir' => 'ltr'])
                    ->helperText('رقم موبايل مصري')
                    ->rules([fn (): Closure => self::phoneRule(mobileOnly: true)])
                    ->dehydrateStateUsing(fn (?string $state) => PhoneNumber::normalize($state))
                    ->suffixAction(
                        Action::make('sameAsPhone')
                            ->label('نفس التليفون')
                            ->icon(Heroicon::OutlinedPhone)
                            ->action(function (Get $get, Set $set) {
                                $phone = PhoneNumber::normalize($get('phone'));

                                if (PhoneNumber::isMobile($phone)) {
                                    $set('whatsapp', $phone);

                                    return;
                                }

                                Notification::make()->warning()->title('التليفون مش رقم موبايل، فمينفعش يبقى واتساب')->send();
                            }),
                    )
                    ->live(onBlur: true),

                TextInput::make('instagram')
                    ->label('إنستجرام')
                    ->prefix('@')
                    ->maxLength(100)
                    ->extraInputAttributes(['dir' => 'ltr'])
                    ->helperText('اسم الحساب بس. لو لصقت الرابط هنقطع منه الاسم.')
                    ->formatStateUsing(fn (?string $state) => self::instagramHandle($state))
                    ->dehydrateStateUsing(fn (?string $state) => self::instagramHandle($state))
                    ->rules([
                        fn (): Closure => function (string $attribute, mixed $value, Closure $fail) {
                            $handle = self::instagramHandle(is_string($value) ? $value : null);
                            if ($handle !== null && preg_match('/^[A-Za-z0-9._]{1,30}$/', $handle) !== 1) {
                                $fail('اسم الحساب حروف وأرقام ونقطة و_ بس، لحد 30');
                            }
                        },
                    ]),

                TextInput::make('facebook_url')
                    ->label('فيسبوك')
                    ->url()
                    ->maxLength(255)
                    ->extraInputAttributes(['dir' => 'ltr'])
                    ->startsWith('https://')
                    ->validationMessages(['starts_with' => 'الرابط لازم يبدأ بـ https://']),

                TextInput::make('website')
                    ->label('الموقع')
                    ->url()
                    ->maxLength(255)
                    ->extraInputAttributes(['dir' => 'ltr'])
                    ->startsWith('https://')
                    ->validationMessages(['starts_with' => 'الرابط لازم يبدأ بـ https://']),

                TextInput::make('menu_url')
                    ->label('رابط المنيو')
                    ->url()
                    ->maxLength(500)
                    ->extraInputAttributes(['dir' => 'ltr'])
                    ->startsWith('https://')
                    ->validationMessages(['starts_with' => 'الرابط لازم يبدأ بـ https://'])
                    ->columnSpanFull()
                    ->live(onBlur: true),
            ])
            ->columns(2);
    }

    private static function hoursTab(): Tab
    {
        return self::tab(self::TAB_HOURS, 'المواعيد', Heroicon::OutlinedClock)
            ->schema([
                Section::make('مواعيد الأسبوع')
                    ->description('«إلى» أصغر من «من» معناها إن المكان بيقفل تاني يوم (مثلاً 6:00 م لـ 2:00 ص).')
                    ->headerActions(self::copyActions('opening_hours'))
                    ->schema([self::hoursTable('opening_hours')]),

                Toggle::make('has_ramadan_hours')
                    ->label('له مواعيد خاصة في رمضان')
                    ->helperText('بتشتغل تلقائياً بين بداية رمضان ونهايته من الإعدادات')
                    ->dehydrated(false)
                    ->live()
                    ->afterStateUpdated(function (bool $state, Get $get, Set $set) {
                        // أول مرة: نبدأ بنسخة من المواعيد العادية والفريق يعدّل الفرق بس
                        if ($state && OpeningHoursForm::fromFormState($get('ramadan_hours')) === null) {
                            $set('ramadan_hours', $get('opening_hours'));
                        }
                    }),

                Section::make('مواعيد رمضان')
                    ->headerActions(self::copyActions('ramadan_hours'))
                    ->schema([self::hoursTable('ramadan_hours')])
                    ->visible(fn (Get $get) => (bool) $get('has_ramadan_hours')),
            ]);
    }

    private static function photosTab(): Tab
    {
        return self::tab(self::TAB_PHOTOS, 'الصور', Heroicon::OutlinedPhoto)
            ->schema([
                Text::make('الصور (الغلاف والمعرض والمنيو) بتتضاف في الأسبوع 6. صورة الغلاف شرط نشر، فالمكان مش هيتنشر قبلها.')
                    ->color('gray'),
            ]);
    }

    private static function adminTab(): Tab
    {
        return self::tab(self::TAB_ADMIN, 'النشر والإدارة', Heroicon::OutlinedCog6Tooth)
            ->schema([
                View::make('filament.places.admin-summary')->columnSpanFull(),

                Actions::make([
                    Action::make('confirmData')
                        ->label('أكّدت البيانات النهارده')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->color('success')
                        ->visible(fn (?Place $record) => $record?->exists === true)
                        ->action(function (Place $record) {
                            $record->update(['verified_at' => now()]);

                            Notification::make()->success()->title('اتسجّل تأكيد البيانات')->send();
                        }),

                    Action::make('releaseClaim')
                        ->label('إلغاء الملكية')
                        ->icon(Heroicon::OutlinedUserMinus)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('صاحب المكان هيفقد الوصول للمكان ده.')
                        ->visible(fn (?Place $record) => $record?->claimed_by !== null && auth()->user()?->can('releaseClaim', $record))
                        ->action(function (Place $record) {
                            $record->update(['claimed_by' => null]);

                            Notification::make()->success()->title('اتلغت الملكية')->send();
                        }),
                ])->key('admin-actions')->columnSpanFull(),

                Textarea::make('internal_notes')
                    ->label('ملاحظات داخلية')
                    ->rows(4)
                    ->maxLength(2000)
                    ->helperText('ما بتطلعش بره اللوحة')
                    ->columnSpanFull(),
            ])
            ->columns(1);
    }

    // ───────────────────────── مساعدات ─────────────────────────

    /**
     * تاب بمفتاح ثابت (الـ checklist بتفتحه بيه) ونقطة حمرا بعدد الأخطاء: validation على حقوله، أو شروط نشر
     * ناقصة منعت آخر حفظ (`missingConditions` على الصفحة).
     */
    private static function tab(string $key, string $label, Heroicon $icon): Tab
    {
        return Tab::make($label)
            ->key($key)
            ->icon($icon)
            ->badge(function (Component $component) use ($key): ?int {
                $livewire = $component->getLivewire();
                $count = 0;

                foreach (array_keys($livewire->getErrorBag()->toArray()) as $errorKey) {
                    if (self::tabForErrorKey((string) $errorKey) === $key) {
                        $count++;
                    }
                }

                foreach ($livewire->missingConditions ?? [] as $condition) {
                    if ((PlacePublishCheck::CONDITIONS[$condition] ?? null) === $key) {
                        $count++;
                    }
                }

                return $count > 0 ? $count : null;
            })
            ->badgeColor('danger');
    }

    /**
     * جدول 7 أيام: لكل يوم حالة (مفتوح/مغلق/24 ساعة) وفترة أو اتنين بخطوات 15 دقيقة. الأسماء بتتطابق مع
     * `OpeningHoursForm::toFormState` والرسالة من `OpeningHoursForm::dayError`.
     */
    private static function hoursTable(string $path): Group
    {
        $times = OpeningHoursForm::timeOptions();
        $rows = [];

        foreach (OpeningHours::DAYS as $day) {
            $isOpen = fn (Get $get): bool => $get('state') === 'open';
            $hasSecond = fn (Get $get): bool => $get('state') === 'open' && (bool) $get('has_second');

            $rows[] = Group::make([
                Grid::make(['default' => 2, 'md' => 12])
                    ->schema([
                        Text::make(OpeningHoursForm::DAY_LABELS[$day])
                            ->weight('bold')
                            ->columnSpan(['default' => 2, 'md' => 2]),

                        Select::make('state')
                            ->hiddenLabel()
                            ->options(OpeningHoursForm::STATE_LABELS)
                            ->placeholder('الحالة')
                            ->live()
                            ->columnSpan(['default' => 2, 'md' => 3])
                            ->rules([
                                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($day, $get) {
                                    $message = OpeningHoursForm::dayError($day, [
                                        'state' => $get('state'),
                                        'slot1_from' => $get('slot1_from'), 'slot1_to' => $get('slot1_to'),
                                        'has_second' => $get('has_second'),
                                        'slot2_from' => $get('slot2_from'), 'slot2_to' => $get('slot2_to'),
                                    ]);

                                    if ($message !== null) {
                                        $fail($message);
                                    }
                                },
                            ]),

                        Select::make('slot1_from')->hiddenLabel()->options($times)->placeholder('من')
                            ->visible($isOpen)->live()->columnSpan(['default' => 1, 'md' => 2]),
                        Select::make('slot1_to')->hiddenLabel()->options($times)->placeholder('إلى')
                            ->helperText(fn (Get $get) => self::nextDayHint($get('slot1_from'), $get('slot1_to')))
                            ->visible($isOpen)->live()->columnSpan(['default' => 1, 'md' => 2]),

                        Toggle::make('has_second')->label('فترة تانية')
                            ->visible($isOpen)->live()->columnSpan(['default' => 2, 'md' => 3]),

                        Select::make('slot2_from')->hiddenLabel()->options($times)->placeholder('من')
                            ->visible($hasSecond)->live()->columnStart(['md' => 6])->columnSpan(['default' => 1, 'md' => 2]),
                        Select::make('slot2_to')->hiddenLabel()->options($times)->placeholder('إلى')
                            ->helperText(fn (Get $get) => self::nextDayHint($get('slot2_from'), $get('slot2_to')))
                            ->visible($hasSecond)->live()->columnSpan(['default' => 1, 'md' => 2]),
                    ]),
            ])->statePath($day);
        }

        return Group::make($rows)->statePath($path);
    }

    /**
     * أزرار نسخ يوم السبت على باقي الأيام (03 القسم 4.4).
     *
     * @return list<Action>
     */
    private static function copyActions(string $path): array
    {
        return [
            Action::make('copyAll_'.$path)
                ->label('انسخ السبت لكل الأيام')
                ->color('gray')
                ->size('sm')
                ->action(function (Get $get, Set $set) use ($path) {
                    $set($path, OpeningHoursForm::copyDay((array) $get($path), 'sat', OpeningHours::DAYS));
                }),

            Action::make('copyWeek_'.$path)
                ->label('انسخ لأيام الأسبوع بس (الأحد لـ الخميس)')
                ->color('gray')
                ->size('sm')
                ->action(function (Get $get, Set $set) use ($path) {
                    $set($path, OpeningHoursForm::copyDay((array) $get($path), 'sat', OpeningHoursForm::WORKWEEK_DAYS));
                }),
        ];
    }

    private static function nextDayHint(mixed $from, mixed $to): ?string
    {
        if (! is_string($from) || ! is_string($to) || $from === '' || $to === '') {
            return null;
        }

        $start = OpeningHours::toMinutes($from);
        $end = OpeningHours::toMinutes($to);

        return $start !== null && $end !== null && $end <= $start ? '(اليوم التالي)' : null;
    }

    private static function descriptionHint(mixed $value): string
    {
        $length = mb_strlen(trim((string) $value));
        $hint = "{$length} من 1000";

        if ($length < PlacePublishCheck::DESCRIPTION_MIN) {
            $hint .= ' (50 على الأقل للنشر)';
        }

        return $hint.'. إيه اللي يميّز المكان وجوّه عامل إزاي';
    }

    /**
     * فئة السعر من `price_max` وحدود الإعدادات، زي ما الـ Observer هيحسبها.
     */
    private static function priceRangeLabel(mixed $max): string
    {
        $labels = ['budget' => 'اقتصادي', 'mid' => 'متوسط', 'premium' => 'مرتفع'];

        if (! is_numeric($max)) {
            return '—';
        }

        $values = DB::table('settings')->whereIn('key', ['price_budget_max', 'price_mid_max'])->pluck('value', 'key');
        $range = PriceRange::forMaxPrice(
            (int) $max,
            (int) ($values['price_budget_max'] ?? PriceRange::BUDGET_MAX),
            (int) ($values['price_mid_max'] ?? PriceRange::MID_MAX),
        );

        return $labels[$range] ?? '—';
    }

    private static function phoneRule(bool $mobileOnly): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($mobileOnly) {
            if (! filled($value)) {
                return;
            }

            $normalized = PhoneNumber::normalize((string) $value);

            if ($normalized === null) {
                $fail('رقم مصري غير صالح: موبايل 01… أو أرضي بكود المحافظة أو خط ساخن من 5 أرقام');
            } elseif ($mobileOnly && ! PhoneNumber::isMobile($normalized)) {
                $fail('الواتساب لازم يكون رقم موبايل مصري');
            }
        };
    }

    /**
     * اسم الحساب من اسم أو @اسم أو رابط instagram.com/اسم/…
     */
    private static function instagramHandle(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('~instagram\.com/([^/?#\s]+)~i', $value, $match) === 1) {
            $value = $match[1];
        }

        $value = ltrim($value, '@');

        return $value === '' ? null : $value;
    }

    /**
     * «استخرج الموقع»: إحداثيات من رابط Google Maps في الفورم. الـ pin بيتحرك لوحده لإن الخريطة (الـ override في
     * `resources/views/vendor/filament-pinpoint`) بتراقب `latitude` و`longitude`.
     */
    private static function extractLocation(Get $get, Set $set): void
    {
        $url = trim((string) $get('google_maps_url'));

        if ($url === '') {
            Notification::make()->warning()->title('الصق رابط Google Maps الأول')->send();

            return;
        }

        if (! GoogleMapsUrl::isGoogleMapsUrl($url)) {
            Notification::make()->warning()->title('الرابط ده مش رابط Google Maps')->send();

            return;
        }

        $coordinates = GoogleMapsUrl::resolve($url);

        if ($coordinates === null) {
            Notification::make()->warning()
                ->title('مش لاقي إحداثيات في الرابط ده')
                ->body('حطّ الـ pin يدوياً على الخريطة.')
                ->send();

            return;
        }

        if (! PlaceLocation::isInsideEgypt($coordinates['lat'], $coordinates['lng'])) {
            Notification::make()->danger()->title('الموقع اللي في الرابط برّه مصر')->send();

            return;
        }

        $set('latitude', round($coordinates['lat'], 7));
        $set('longitude', round($coordinates['lng'], 7));

        self::suggestArea($get, $set, $coordinates['lat'], $coordinates['lng']);

        Notification::make()->success()->title('اتحدّد الموقع. راجع الـ pin على الخريطة.')->send();
    }

    /**
     * المنطقة والمدينة من أقرب منتصف منطقة للـ pin. بنكتب فوق المنطقة بس لو فاضية أو هي نفسها اللي اتقترحت
     * قبل كده (يعني الفريق ما غيّرهاش بإيده).
     */
    private static function suggestArea(Get $get, Set $set, ?float $lat = null, ?float $lng = null): void
    {
        $lat ??= is_numeric($get('latitude')) ? (float) $get('latitude') : null;
        $lng ??= is_numeric($get('longitude')) ? (float) $get('longitude') : null;

        if ($lat === null || $lng === null) {
            return;
        }

        $area = AreaSuggestion::nearest($lat, $lng);
        if ($area === null) {
            return;
        }

        $current = $get('area_id');
        $lastSuggestion = $get('suggested_area_id');

        if (blank($current) || (int) $current === (int) $lastSuggestion) {
            $set('area_id', $area->id);
            $set('city_id', $area->city_id);
            $set('suggested_area_id', $area->id);
        }
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
