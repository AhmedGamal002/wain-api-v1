# تجربة استضافة Hostinger (أسبوع 1)

ملف PHP واحد (`hosting-check.php`) بيفحص كل اللي الخطة (`01-FRD-and-Plan.md` القسم 6) مفترضاه عن الاستضافة، وبيطبع تقرير `PASS` / `WARN` / `FAIL` تبعته لـ Claude. مفيش Laravel ولا composer.

**اتجرّب محلي** على MariaDB 11.8: `PASS 46 · WARN 1 · FAIL 0` (الـ WARN هو `symlink()` على Windows).

## بيفحص إيه

| القسم | اللي بيتأكد منه | السبب في الخطة |
| --- | --- | --- |
| PHP | النسخة 8.3 وكل الـ extensions (`intl` و`gd` + `imagewebp` و`zip` و`exif`...) | Laravel 13 وFilament 5 والصور WebP |
| Limits | `memory_limit` و`max_execution_time` و`upload_max_filesize` و`disable_functions` | معالجة الصور على دفعات، رفع 8MB |
| Time zone | قواعد التوقيت الصيفي لـ `Africa/Cairo` (8 انتقالات 2026 و2027، حوالين 30 أكتوبر) | «مفتوح دلوقتي» |
| Filesystem | الكتابة و`flock` و`symlink` | الـ file cache و`storage:link` |
| Shell | `composer` و`mariadb-dump` / `mysqldump` و`exec` | الـ backup اليومي على R2 |
| Network | HTTPS لـ Expo Push وCloudflare وApple وGoogle وSentry | F11 وF2 وR2 |
| Database | `SELECT VERSION()` (المتوقع `11.8.9-MariaDB`)، `POINT` + `SPATIAL INDEX`، ترتيب المحاور، `EXPLAIN`، generated column + `UNIQUE`، `JSON`، توقيت الداتابيز، dump فعلي | النموذج الكامل والـ `nearby` و`place_claims` |
| Images | فك صورة 1 و6 و12 و24 ميجابكسل وعمل 3 نسخ WebP: الوقت والذاكرة | الـ `memory_limit` على المشتركة |
| Cron | كل دقيقة؟ بأنهي PHP؟ وهل job مدته 55 ثانية بتكمّل ولا بتتقتل؟ | الـ scheduler والـ queue worker |

## الخطوات (حوالي 15 دقيقة)

### 0. جهّز داتابيز للتجربة
من hPanel: **Databases ← Management**، اعمل داتابيز ويوزر جداد (أو استخدم داتابيز الـ staging الفاضية). **ماتستخدمش داتابيز فيها داتا حقيقية**: السكريبت بيعمل جداول `wain_trial_*` ويمسحها في الآخر.

### 1. ارفع الملفين على SSH (مش على public_html)

**ماتعدّلش `.hosting-check.env.example`**: ده ملف متتبَّع في git، وأي باسورد فيه هيتعمله commit. انسخه الأول لـ `.hosting-check.env` (اللي git بيتجاهله) واملى النسخة دي.

```bash
# من جهازك، من جذر الـ repo (البورت واليوزر من hPanel ← Advanced ← SSH Access)
cp scripts/hosting-check/.hosting-check.env.example scripts/hosting-check/.hosting-check.env
```
افتح `scripts/hosting-check/.hosting-check.env` وحط بيانات الداتابيز من hPanel (الـ host غالباً `127.0.0.1` أو `localhost`، والبورت 3306).

الـ `scp` **مابيعملش مجلدات**، فاعمل المجلد الأول (من غيره بيطلع `dest open "wain-trial/": Failure`):
```bash
ssh -p <port> <user>@<host> "mkdir -p ~/wain-trial && chmod 700 ~/wain-trial"
```
```bash
scp -P <port> scripts/hosting-check/hosting-check.php <user>@<host>:~/wain-trial/hosting-check.php
```
```bash
scp -P <port> scripts/hosting-check/.hosting-check.env <user>@<host>:~/wain-trial/.hosting-check.env
```
```bash
ssh -p <port> <user>@<host> "chmod 600 ~/wain-trial/.hosting-check.env"
```
**`~/wain-trial` برّه `public_html`** عشان الباسورد ما يتخدّمش للإنترنت. وبعد ما تخلّص التجربة امسح المجلد من السيرفر وغيّر باسورد يوزر الداتابيز.

### 2. شغّله من SSH (الفحص الكامل)
```bash
cd ~/wain-trial
php -v                      # لازم 8.3. لو لأ شوف الملحوظة تحت
php hosting-check.php | tee report-cli.txt
```

> **لو `php -v` مش 8.3:** على الاستضافات المشتركة الـ `php` بتاع SSH ممكن يبقى غير نسخة الويب. جرّب `php8.3 -v` أو `ls /opt/alt` (على CloudLinux بتلاقي `php83/usr/bin/php`)، وقولّي اللي لقيته. نفس المسار الكامل هو اللي هيتحط في أمر الـ cron.

### 3. شغّله من المتصفح (حدود الـ web PHP)
ده بيقيس `memory_limit` و`max_execution_time` و`upload_max_filesize` بتاعة الـ web، وهي بتختلف عن الـ CLI وهي اللي بتهمّ الـ API.

1. اعمل نسخة في مجلد الـ staging subdomain (جوه `public_html/<subdomain>/`) من `hosting-check.php` ومعاها `.hosting-check.env` فيه سطر واحد بس: `CHECK_KEY=<نص عشوائي 16 حرف أو أكتر>`. **من غير باسورد الداتابيز**.
2. افتح `https://<staging>/hosting-check.php?key=<المفتاح>` وانسخ الناتج في `report-web.txt`.
3. **امسح الملفين من السيرفر فوراً.**

(قسم الداتابيز بيتخطّاه الـ web mode عن قصد، وبيحذّر لو لقى `DB_PASSWORD` في الملف.)

### 4. تجربة الـ cron (5 دقايق)
من hPanel: **Advanced ← Cron Jobs**، أضف job كل دقيقة بالمسار الكامل لـ PHP 8.3 (من خطوة 2):

```
* * * * *  /full/path/to/php  /home/<user>/wain-trial/hosting-check.php --heartbeat --duration=55 >/dev/null 2>&1
```

استنى 5 دقايق وبعدين:
```bash
php hosting-check.php --cron-report | tee report-cron.txt
```
**امسح الـ cron job بعد التقرير.**

بيقول لك: هل اتشغّل كل دقيقة، وبأنهي PHP، وهل الـ job اللي مدته 55 ثانية (زي `queue:work --max-time=55`) بيخلص ولا بيتقتل.

## التقرير
ابعتلي `report-cli.txt` و`report-web.txt` و`report-cron.txt`. وبعدها:
1. أكتب النتايج في `01-FRD-and-Plan.md` القسم 6 (الخطة بتطلب كده قبل ما نكمّل).
2. أي `FAIL` أو `WARN` بنقرر فيه قبل staging.

## إزاي تقرا النتايج
- **`FAIL` في `SELECT VERSION()`** (طلع MySQL): صيغة الـ spatial في الخطة بتتغيّر، قولّي فوراً.
- **`WARN` في الـ EXPLAIN:** الـ spatial index مش بيتستخدم، بنستخدم الـ B-tree على `(status, latitude, longitude)` من الخطة.
- **`DB clock vs UTC` مش UTC:** نظبط `'timezone' => '+00:00'` في `config/database.php`.
- **صورة 12MP محتاجة حوالي 130MB:** لو `memory_limit` 128M هتفشل، فنصغّر الصورة قبل الفك أو نحدّد حجم الرفع.
- **`FAIL` في الـ cron:** الـ queue (صور وإشعارات) هيتأخر، وده قرار جديد زي ما الخطة بتقول.

## أمان
- `.hosting-check.env` فيه باسورد: `chmod 600`، وبرّه `public_html`، ومتجاهَل في git.
- الـ web mode بيرفض أي طلب من غير `CHECK_KEY` (16 حرف على الأقل)، ومابيلمسش الداتابيز.
- السكريبت مابيطبعش الباسورد، وبيمسح جداوله `wain_trial_*` في الآخر.

## مشاكل واجهتنا فعلاً (12 أكتوبر 2026)

| المشكلة | السبب | الحل |
| --- | --- | --- |
| `scp: dest open "wain-trial/": Failure` | `scp` مابيعملش مجلدات | `ssh … "mkdir -p ~/wain-trial"` الأول |
| `\n` اتشال والملف طلع سطر واحد مكرّر | PowerShell و`ssh` على Windows بياكلوا الـ backslash | علامات تنصيص **مفردة** حوالين الأمر كله، ومن غير `\n` (استخدم `echo`، و`[:space:]` بدل `" \n"`) |
| `$(…)` اتنفّذ على جهازك | علامات التنصيص المزدوجة في PowerShell بتفسّر `$()` محلي | نفس الحل: تنصيص مفرد |
| الويب بيرد 404 رغم إن الملف موجود | `.htaccess` في `public_html` بيحوّل كل الطلبات لـ `/home/` | حط الملف جوه المجلد اللي الـ rewrite بيوجّه له (`public_html/home/`) |
| الـ cron مابيشتغلش | حقل الأمر فيه الجدولة `* * * * *` كمان | حقل الأمر يبدأ من المسار مباشرةً، والجدولة في حقولها |
| تقرير الـ cron قال «run ما خلّصش» وهو لسه شغّال | التقرير كان بيحسب أي `start` من غير `end` | اتصلّح: بيتجاهل الـ runs اللي بدأت من أقل من 100 ثانية |
| `FAIL` في HTTPS محلي على Windows | PHP من غير CA bundle | `php -d curl.cainfo=<path>` (مش مشكلة على الاستضافة) |

بعد التجربة: امسح `~/wain-trial` من السيرفر والـ cron job من hPanel، وغيّر باسورد يوزر التجربة أو امسحه مع الداتابيز.
