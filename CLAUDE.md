# Wain API

Backend لتطبيق وين: Laravel 13 + Filament 5 (لوحة الأدمن) + Sanctum. الوثايق (المتطلبات والـ schema والـ API) في المجلد الأب `../`:
`01-FRD-and-Plan.md` (الخطة والاستضافة) · `03-Feature-Places.md` (الأماكن، المرجع الوحيد) · `06-Data-Model-and-API.md` (الجداول واتفاقيات الـ API) · `02-Feature-Specs.md` · `05-Screen-Inventory.md`.

## أوامر

```bash
docker compose up -d mariadb          # MariaDB 11.8 على 3307 (wain_db + wain_test)
php artisan migrate:fresh --seed
php artisan test                      # على MariaDB wain_test، مش SQLite
vendor/bin/pint                       # Pint لازم يعدّي قبل أي PR
```

## قواعد مش بديهية

- **قاعدة البيانات MariaDB 11.8 (مطابقة للاستضافة 11.8.9)، مش MySQL ومش SQLite.** `DB_CONNECTION=mariadb` و`DB_PORT=3307`. MySQL بتاع WAMP على 3306 مايتستخدمش.
- **الـ spatial:** عمود `location` من نوع `POINT NOT NULL` + `SPATIAL INDEX`، من غير SRID ومن غير `axis-order`، بصيغة `ST_GeomFromText('POINT(lng lat)')` (الطول ثم العرض) ومن `App\Support\PlaceLocation` بس (`point()` و`distanceMetersSql()`). في migration: `$table->geometry('location', 'point')` (`point()` اتشالت من Laravel).
  - تست المسافة `PlaceLocationTest` بيحسم ترتيب المحاور. القاهرة–الإسكندرية لوحدها **مش كفاية** (صح 180.0 كم، ومبدّل 183.5)، فمعاها القاهرة–أسوان (682 صح، 591 مبدّل) وقراءة `ST_X`/`ST_Y`.
  - `EXPLAIN` على 5000 مكان: `MBRContains(POLYGON, location)` بيستخدم `places_location_spatialindex` (`type=range`)، فبديل الـ B-tree على lat/lng مش محتاجينه. الفلترة بالمستطيل بتكون `MBRContains` مش `BETWEEN` على lat/lng من غير index.
- **PHP 8.3 مثبّت** في `composer.json` (`config.platform.php`) لأن الاستضافة 8.3. ماتعملش `composer update` يرقّي حاجة تحتاج 8.4: `spatie/laravel-activitylog` بقى على `^4.12` (الـ 5.x محتاج PHP 8.4).
- **مفيش Redis ولا Meilisearch ولا Supervisor** (استضافة مشتركة): `CACHE_STORE=file` بمفاتيح بنسخة (مفيش tags)، و`QUEUE_CONNECTION=database` بيشتغل من cron كل دقيقة (`queue:work --stop-when-empty --max-time=55`)، والبحث على الداتابيز ورا interface `PlaceSearch`.
- **الـ cache: خزّن arrays وscalars بس، مش Eloquent models ولا collections.** `config/cache.php` فيه `serializable_classes = false`، فأي object بيتحوّل لـ `__PHP_Incomplete_Class` وبيرمي 500 عند القراءة التانية. وتستات الـ cache لازم تشتغل على `file` store فعلي (زي `test_second_request_served_from_a_real_file_cache_works`) لإن `array` store مابيعملش serialize فبيخبّي المشكلة.
- `JSON` في MariaDB بيتخزّن كنص: مفيش فلترة على محتواه.
- التخزين UTC، والعرض وحساب `open_now` بتوقيت `Africa/Cairo`.
- الـ collation `utf8mb4_unicode_ci` مثبّت صراحة (`DB_COLLATION`).

## الـ API

- المسار `/api/v1`، JSON فقط، Bearer token (Sanctum، 30 يوم)، هيدرز `Accept-Language` و`X-Device-Id`.
- شكل الخطأ `{ message, code, errors? }` وأكواد الأخطاء في `06` القسم 1. الـ `ApiErrorRenderer` بيحوّل أي خطأ على `/api/*` للشكل ده تلقائياً (validation و401 و403 و404 و429). لأخطاء الدومين: `throw new ApiException(401, 'INVALID_CREDENTIALS')`، والرسالة بتتاخد من `lang/{ar,en}/api.php` بالـ code، فضيف الكود الجديد هناك بالاتنين.
- اللغة من `Accept-Language` (`ar` الافتراضي) بـ `SetApiLocale` (global، بيشتغل كمان على الـ 404). في التستات: Symfony بيحط `Accept-Language: en-us` في أي request مصطنع، فابعت الهيدر صراحةً (`['Accept-Language' => 'ar']`) أو فاضي لـ «من غير هيدر».
- `GET /api/v1/categories` أول endpoint. شكل التصنيف `{ slug, name, icon, color }` زي كارت المكان (`03` القسم 5.2).
- أي تغيير في الـ API يتكتب في Scribe الأول ويتقال لمعلوم.

## الملكية والـ PR

- `PlaceObserver` ملكنا وبيطلق `PlaceChanged`، والـ listeners (`search_text` والـ cache و`place_hours`) عند معلوم. متحطّش منطقهم جوه الـ Observer.
- `PlaceCard` و`AuthGateSheet` والـ API client ملك معلوم.
- لوحة Filament مقفولة بـ `User::canAccessPanel` (دور `admin` أو `moderator` وحالة `active`).
- كل PR بيتراجع من التاني خلال يوم عمل.
