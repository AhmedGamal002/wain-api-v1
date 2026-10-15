#!/usr/bin/env bash
#
# شغّله على السيرفر (SSH) بعد كل `git pull`: بينسخ public/ بتاع الـ app لمجلد الـ subdomain، لإن الاستضافة دي
# مابتسمحش بـ Document Root برّه public_html، فالمجلد نسخة حقيقية مش symlink وبيقدم لو محدّش حدّثه.
#
# مبني على hostinger-sync-public.sh بتاع GCM، بفرقين:
#   1. استبدال مسارات index.php بيقبل `__DIR__.'/../x'` (من غير مسافات، زي Laravel الافتراضي) و`__DIR__ . '/../x'`.
#      نسخة GCM الأصلية بتفترض مسافات، فمع index.php بتاع Wain كانت بتسيب المسارات نسبية والموقع يطلع 500.
#   2. لو الاستبدال ما اشتغلش بيفشل بصوت عالي وما بيكتبش index.php مكسور.
#
# الاستخدام:
#   hostinger-sync-public.sh <app_dir> <web_dir>
#   hostinger-sync-public.sh ~/apps/gcm/api_test ~/domains/digitswat.com/public_html/gcm/api_test
set -euo pipefail

APP_DIR="${1:?Usage: $0 <app_dir> <web_dir>}"
WEB_DIR="${2:?Usage: $0 <app_dir> <web_dir>}"

# مسارات مطلقة، لإن index.php هيتكتب بيها
APP_DIR="$(cd "$APP_DIR" && pwd)"
mkdir -p "$WEB_DIR"
WEB_DIR="$(cd "$WEB_DIR" && pwd)"

test -f "$APP_DIR/public/index.php" || { echo "مفيش $APP_DIR/public/index.php: الـ app_dir غلط؟" >&2; exit 1; }
test -d "$APP_DIR/vendor" || { echo "مفيش $APP_DIR/vendor: شغّل composer install الأول" >&2; exit 1; }

if command -v rsync >/dev/null 2>&1; then
  # --delete بيشيل اللي اتمسح من public/. index.php وstorage وملفات التحقق من الـ SSL ماتتمسّش.
  # --chmod بيفرض صلاحيات يقدر الـ web server يقراها.
  rsync -a --delete --chmod=D755,F644 \
    --exclude 'index.php' --exclude 'storage' --exclude '.well-known' \
    "$APP_DIR/public/" "$WEB_DIR/"
else
  # من غير rsync: أقل دقة (مابيشيلش القديم) لكن شغّال. tar بدل cp عشان نستثني index.php وstorage من غير ما
  # نكتب فوق القديم، و`.` عشان .htaccess يتنسخ (الـ glob `*` كان بيفوّته).
  (cd "$APP_DIR/public" && tar cf - --exclude=./index.php --exclude=./storage .) | (cd "$WEB_DIR" && tar xf -)
  find "$WEB_DIR" -type d ! -path "$WEB_DIR/storage*" -exec chmod 755 {} \;
  find "$WEB_DIR" -type f ! -path "$WEB_DIR/storage*" -exec chmod 644 {} \;
fi

# index.php: المسارات النسبية بتفترض إن public/ جوه الـ app، وده مش صح هنا. بنكتبها مطلقة، وبنتأكد قبل ما نبدّل.
TMP="$WEB_DIR/index.php.new"
sed \
  -e "s#__DIR__[[:space:]]*\.[[:space:]]*'/\.\./storage/framework/maintenance\.php'#'$APP_DIR/storage/framework/maintenance.php'#" \
  -e "s#__DIR__[[:space:]]*\.[[:space:]]*'/\.\./vendor/autoload\.php'#'$APP_DIR/vendor/autoload.php'#" \
  -e "s#__DIR__[[:space:]]*\.[[:space:]]*'/\.\./bootstrap/app\.php'#'$APP_DIR/bootstrap/app.php'#" \
  "$APP_DIR/public/index.php" > "$TMP"

for needle in "$APP_DIR/vendor/autoload.php" "$APP_DIR/bootstrap/app.php"; do
  if ! grep -qF "$needle" "$TMP"; then
    rm -f "$TMP"
    echo "فشل استبدال المسارات في index.php ($needle مش موجود بعد الـ sed). index.php القديم ماتلمسش." >&2
    echo "اتغيّر شكل public/index.php؟ راجع الـ regex في السكربت ده." >&2
    exit 1
  fi
done
mv -f "$TMP" "$WEB_DIR/index.php"

# storage/ لازم symlink للـ storage/app/public الحقيقي، مش نسخة (نسخة هتفضل قديمة مع كل رفع جديد)
[ -L "$WEB_DIR/storage" ] || rm -rf "$WEB_DIR/storage"
ln -sfn "$APP_DIR/storage/app/public" "$WEB_DIR/storage"

echo "تم: $APP_DIR/public/ -> $WEB_DIR (مسارات index.php مطلقة، وstorage/ symlink)."
