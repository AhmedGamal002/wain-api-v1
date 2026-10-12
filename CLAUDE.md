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
- **الـ spatial:** عمود `location` من نوع `POINT NOT NULL` + `SPATIAL INDEX`، من غير SRID ومن غير `axis-order`، بصيغة `ST_GeomFromText('POINT(lng lat)')` (الطول ثم العرض) ومن `PlaceLocation` helper بس. تست المسافة المعروفة (القاهرة–الإسكندرية ≈ 180 كم) هو اللي بيحسم ترتيب المحاور.
- **PHP 8.3 مثبّت** في `composer.json` (`config.platform.php`) لأن الاستضافة 8.3. ماتعملش `composer update` يرقّي حاجة تحتاج 8.4: `spatie/laravel-activitylog` بقى على `^4.12` (الـ 5.x محتاج PHP 8.4).
- **مفيش Redis ولا Meilisearch ولا Supervisor** (استضافة مشتركة): `CACHE_STORE=file` بمفاتيح بنسخة (مفيش tags)، و`QUEUE_CONNECTION=database` بيشتغل من cron كل دقيقة (`queue:work --stop-when-empty --max-time=55`)، والبحث على الداتابيز ورا interface `PlaceSearch`.
- `JSON` في MariaDB بيتخزّن كنص: مفيش فلترة على محتواه.
- التخزين UTC، والعرض وحساب `open_now` بتوقيت `Africa/Cairo`.
- الـ collation `utf8mb4_unicode_ci` مثبّت صراحة (`DB_COLLATION`).

## الـ API

- المسار `/api/v1`، JSON فقط، Bearer token (Sanctum، 30 يوم)، هيدرز `Accept-Language` و`X-Device-Id`.
- شكل الخطأ `{ message, code, errors? }` وأكواد الأخطاء في `06` القسم 1.
- أي تغيير في الـ API يتكتب في Scribe الأول ويتقال لمعلوم.

## الملكية والـ PR

- `PlaceObserver` ملكنا وبيطلق `PlaceChanged`، والـ listeners (`search_text` والـ cache و`place_hours`) عند معلوم. متحطّش منطقهم جوه الـ Observer.
- `PlaceCard` و`AuthGateSheet` والـ API client ملك معلوم.
- لوحة Filament مقفولة بـ `User::canAccessPanel` (دور `admin` أو `moderator` وحالة `active`).
- كل PR بيتراجع من التاني خلال يوم عمل.
