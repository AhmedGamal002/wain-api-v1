<?php

namespace App\Models;

use App\Observers\PlaceObserver;
use App\Support\OpenNow;
use Database\Factories\PlaceFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * المكان (03 القسم 2). الـ Observer (`PlaceObserver`) بيملى الحقول المشتقة (slug، location، price_range،
 * tier_rank، published_at) وبيطلق `PlaceChanged`، والـ Policy (`PlacePolicy`) بتحكم بالدور، وشروط النشر
 * في `PlacePublishCheck`.
 */
#[ObservedBy(PlaceObserver::class)]
class Place extends Model
{
    /** @use HasFactory<PlaceFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /** اسم سجل التغييرات (activitylog) للأماكن. */
    public const LOG_NAME = 'places';

    /**
     * الحقول اللي بتتسجل في السجل. المشتق (search_text، location، price_range، tier_rank) والمحسوب
     * (rating_*) مش بيتسجلوا: بيتغيّروا تبعاً لغيرهم.
     */
    public const LOGGED_FIELDS = [
        'slug', 'name_ar', 'name_en', 'search_aliases', 'description_ar', 'description_en',
        'primary_category_id', 'city_id', 'area_id', 'address_ar', 'address_en', 'latitude', 'longitude',
        'google_maps_url', 'phone', 'whatsapp', 'instagram', 'facebook_url', 'website', 'menu_url',
        'price_min', 'price_max', 'opening_hours', 'ramadan_hours', 'cover_photo_id',
        'status', 'status_reason', 'published_at', 'closed_at',
        'listing_tier', 'tier_starts_at', 'tier_ends_at', 'verified_at', 'claimed_by', 'internal_notes',
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_HIDDEN = 'hidden';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CLOSED = 'closed';

    /** الحالات بالاسم اللي بيظهر في اللوحة (03 القسم 2 و4.2). */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'مسودة',
        self::STATUS_PENDING => 'قيد المراجعة',
        self::STATUS_ACTIVE => 'منشور',
        self::STATUS_HIDDEN => 'مخفي',
        self::STATUS_REJECTED => 'مرفوض',
        self::STATUS_CLOSED => 'مغلق نهائياً',
    ];

    /** `tier_rank` لكل باقة: للترتيب من غير CASE (1 premium، 2 featured، 3 free). */
    public const TIER_RANKS = ['premium' => 1, 'featured' => 2, 'free' => 3];

    /** المحذوف بيتسترجع خلال المدة دي، وبعدها job الحذف النهائي (أسبوع 7) بيمسحه هو وصوره. */
    public const PURGE_AFTER_DAYS = 30;

    /**
     * ملاحظة بتتضاف لسجل التغييرات في أول حفظ بعد ما تتحط (رقم فاتورة الباقة مثلاً). مش عمود: مؤقتة.
     */
    public ?string $logNote = null;

    /** @var array<string, mixed> نفس defaults الـ migration، عشان المكان اللي لسه متعمل يبقى زي اللي هيتحمّل. */
    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'listing_tier' => 'free',
        'tier_rank' => 3,
    ];

    /**
     * كلمات مسارات `/places/{...}` الثابتة. ما تنفعش slug لمكان (`PlaceSlug` بيتخطاها)، وراوت
     * `places/{slug}` بيستثنيها.
     */
    public const RESERVED_SLUGS = ['nearby', 'count', 'map'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'search_aliases' => 'array',
            'opening_hours' => 'array',
            'ramadan_hours' => 'array',
            'latitude' => 'float',
            'longitude' => 'float',
            'price_min' => 'integer',
            'price_max' => 'integer',
            'rating_avg' => 'float',
            'rating_count' => 'integer',
            'tier_rank' => 'integer',
            'published_at' => 'datetime',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'tier_starts_at' => 'datetime',
            'tier_ends_at' => 'datetime',
            'closed_at' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(self::LOG_NAME)
            ->logOnly(self::LOGGED_FIELDS)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * بيضيف `logNote` (لو متحط) لسطر السجل كـ `properties.note`.
     */
    public function tapActivity(Activity $activity, string $eventName): void
    {
        if (filled($this->logNote)) {
            $activity->properties = $activity->properties->put('note', $this->logNote);
        }
    }

    /**
     * مفتاح الـ cache (file) لتفاصيل المكان، 5 دقايق. بيتمسح من `PlaceChanged` (ForgetPlaceDetailCache)
     * بالـ slug الحالي والقديم.
     */
    public static function detailsCacheKey(string $slug): string
    {
        // الـ slug case-insensitive في الداتابيز (collation)، فالمفتاح lowercase عشان الاتنين يتمسحوا مع بعض
        return 'places:'.mb_strtolower($slug);
    }

    /**
     * id المكان اللي صفحته بتتفتح (منشور أو مغلق نهائياً، `scopeViewable`) بالـ slug أو بـ slug قديم، أو null.
     * استعلام بسيط من غير علاقات لـ endpoints الكتابة (الأحداث) اللي مش محتاجة التفاصيل.
     */
    public static function viewableIdForSlug(string $slug): ?int
    {
        $id = static::query()->viewable()->where('places.slug', $slug)->value('places.id')
            ?? static::query()->viewable()->whereIn(
                'places.id',
                DB::table('place_slug_redirects')->where('old_slug', $slug)->select('place_id'),
            )->value('places.id');

        return $id === null ? null : (int) $id;
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return BelongsTo<User, $this> */
    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }

    /** @return BelongsTo<Category, $this> */
    public function primaryCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'primary_category_id');
    }

    /** @return BelongsTo<Area, $this> */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /** @return BelongsTo<PlacePhoto, $this> */
    public function coverPhoto(): BelongsTo
    {
        return $this->belongsTo(PlacePhoto::class, 'cover_photo_id');
    }

    /**
     * كل تصنيفات المكان (من 1 لـ 3، والرئيسي منهم).
     *
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'place_category');
    }

    /**
     * خصائص المكان. اسمها placeAttributes مش attributes عشان `$place->attributes` هي خاصية Eloquent الداخلية.
     *
     * @return BelongsToMany<Attribute, $this>
     */
    public function placeAttributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'attribute_place');
    }

    /**
     * صور المعرض والمنيو (بالنوع `type`) بترتيبها.
     *
     * @return HasMany<PlacePhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(PlacePhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * قاعدة الظهور (03 القسم 5.1): الـ API بيرجّع المنشور بس. المخفي والمسودة وقيد المراجعة
     * والمرفوض والمغلق نهائياً ما بيظهروش في أي قائمة.
     *
     * @param  Builder<Place>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('places.status', 'active');
    }

    /**
     * قاعدة الظهور لصفحة المكان بس (03 القسم 5.1): المنشور والمغلق نهائياً. غير كده (مسودة، قيد
     * المراجعة، مرفوض، مخفي، محذوف) 404 واحدة.
     *
     * @param  Builder<Place>  $query
     */
    public function scopeViewable(Builder $query): void
    {
        $query->whereIn('places.status', ['active', 'closed']);
    }

    /**
     * فلتر «مفتوح دلوقتي» (03 القسم 7.1): `WHERE EXISTS` على الدقيقة الحالية في `place_hours`.
     * جوه رمضان بيستخدم صفوف رمضان للمكان اللي ليه مواعيد رمضان، والباقي بصفوفه العادية.
     * المكان من غير مواعيد ما بيظهرش (مفيش صفوف).
     *
     * @param  Builder<Place>  $query
     */
    public function scopeOpenNow(Builder $query, OpenNow $now): void
    {
        $query->whereExists(function ($hours) use ($now) {
            $hours->selectRaw('1')
                ->from('place_hours')
                ->whereColumn('place_hours.place_id', 'places.id')
                ->where('place_hours.opens_minute', '<=', $now->weekMinute)
                ->where('place_hours.closes_minute', '>', $now->weekMinute)
                ->where('place_hours.is_ramadan', function ($flag) use ($now) {
                    // صفوف رمضان بس لو إحنا في رمضان والمكان عنده صفوف رمضان، وإلا العادية
                    $flag->selectRaw('CASE WHEN ? AND EXISTS (SELECT 1 FROM place_hours r WHERE r.place_id = places.id AND r.is_ramadan = 1) THEN 1 ELSE 0 END', [$now->ramadan ? 1 : 0]);
                });
        });
    }

    /**
     * البيانات اللي `PlacePublishCheck` بيقراها من علاقات: التصنيفات المختارة وغلاف المكان وعدد الخصائص.
     * القائمة بتستخدمه عشان عمود «اكتمال البيانات» ما يعملش N+1.
     *
     * @param  Builder<Place>  $query
     */
    public function scopeWithPublishCheckData(Builder $query): void
    {
        $query->with(['categories:categories.id', 'coverPhoto:id,status'])->withCount('placeAttributes');
    }

    /**
     * الاسم بلغة الطلب، ولو الإنجليزي فاضي بيرجع العربي.
     */
    public function localizedName(): string
    {
        return $this->localized('name') ?? $this->name_ar;
    }

    /**
     * الوصف بلغة الطلب (الإنجليزي الفاضي بيرجع العربي)، أو null لو مفيش وصف.
     */
    public function localizedDescription(): ?string
    {
        return $this->localized('description');
    }

    public function localizedAddress(): string
    {
        return $this->localized('address') ?? '';
    }

    private function localized(string $field): ?string
    {
        if (app()->getLocale() === 'en' && filled($this->{$field.'_en'})) {
            return $this->{$field.'_en'};
        }

        return filled($this->{$field.'_ar'}) ? $this->{$field.'_ar'} : null;
    }
}
