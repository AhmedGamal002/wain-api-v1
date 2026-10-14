<?php

namespace App\Support;

use App\Models\PlacePhoto;
use GdImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * معالجة صورة المكان (03 القسم 4.5): من الأصل المرفوع لـ 3 نسخ WebP (thumb وmedium وlarge) من غير EXIF.
 *
 * GD بس (مش Imagick): ده اللي مؤكد على الاستضافة مع WebP (FRD §6). إعادة الترميز لـ WebP بيشيل الـ EXIF
 * لوحده (GD ما بينسخش الـ metadata)، بعد ما الاتجاه اتصحّح منه. الصورة الأصغر من عرض النسخة بتفضل بحجمها
 * (من غير تكبير). الغلاف بيتقص 3:2 من النص (القص في المتصفح لو اشتغل، وده الضمان من السيرفر).
 *
 * الكتابة على `wain.photos.disk` بمسارات نسبية `places/{place}/{photo}-{size}.webp`، فنقلها لـ R2 في أسبوع 9
 * تغيير disk بس.
 */
final class PlacePhotoProcessor
{
    /** نسبة الغلاف (العرض ÷ الطول). */
    public const COVER_RATIO = 3 / 2;

    private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    /**
     * @return array{variants: array<string, string>, width: int, height: int}
     *
     * @throws RuntimeException لو الأصل مش موجود أو مش صورة أو أكبر من الحد أو GD مش قادر يفتحه
     */
    public function process(PlacePhoto $photo, bool $cover = false): array
    {
        if (! function_exists('imagewebp')) {
            throw new RuntimeException('GD من غير دعم WebP على السيرفر');
        }

        $binary = Storage::disk(config('wain.photos.original_disk'))->get($photo->path);
        if (! is_string($binary) || $binary === '') {
            throw new RuntimeException("الأصل مش موجود: {$photo->path}");
        }

        $info = @getimagesizefromstring($binary);
        if ($info === false || ! in_array($info[2], self::TYPES, true)) {
            throw new RuntimeException('الملف مش صورة JPG أو PNG أو WebP');
        }

        if ((int) config('wain.photos.max_pixels') < $info[0] * $info[1]) {
            throw new RuntimeException('الصورة أكبر من الحد المسموح بالبكسلات');
        }

        $source = @imagecreatefromstring($binary);
        if (! $source instanceof GdImage) {
            throw new RuntimeException('مش قادر يفتح الصورة');
        }

        $source = $this->orient($source, $binary, $info[2]);
        $source = $this->flatten($source);

        if ($cover) {
            $source = $this->cropToRatio($source, self::COVER_RATIO);
        }

        $width = imagesx($source);
        $height = imagesy($source);

        $disk = Storage::disk(config('wain.photos.disk'));
        $quality = (int) config('wain.photos.quality');
        $variants = [];

        foreach ((array) config('wain.photos.widths') as $name => $target) {
            $resized = $width > $target ? imagescale($source, (int) $target, -1) : $source;

            if (! $resized instanceof GdImage) {
                throw new RuntimeException("فشل تصغير النسخة {$name}");
            }

            ob_start();
            $encoded = imagewebp($resized, null, $quality);
            $data = (string) ob_get_clean();

            if (! $encoded || $data === '') {
                throw new RuntimeException("فشل ترميز النسخة {$name}");
            }

            $path = "places/{$photo->place_id}/{$photo->id}-{$name}.webp";
            $disk->put($path, $data);
            $variants[$name] = $path;
        }

        return ['variants' => $variants, 'width' => $width, 'height' => $height];
    }

    /**
     * يمسح نسخ الصورة (لما الصورة تتحذف أو تتعالج تاني).
     */
    public function deleteVariants(PlacePhoto $photo): void
    {
        $paths = array_values(array_filter((array) $photo->variants, fn ($path) => is_string($path)
            && ! str_starts_with($path, 'http')));

        if ($paths !== []) {
            Storage::disk(config('wain.photos.disk'))->delete($paths);
        }
    }

    /**
     * يصحّح اتجاه الصورة من EXIF (موبايلات بتحفظ الصورة مقلوبة وبعلامة اتجاه). JPEG بس عنده EXIF.
     */
    private function orient(GdImage $image, string $binary, int $type): GdImage
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }

        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            return $image;
        }
        fwrite($stream, $binary);
        rewind($stream);

        $exif = @exif_read_data($stream);
        fclose($stream);

        $rotated = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    /** palette ← truecolor، والشفافية محفوظة (PNG) عشان WebP يرمّزها. */
    private function flatten(GdImage $image): GdImage
    {
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    private function cropToRatio(GdImage $image, float $ratio): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if (abs($width / $height - $ratio) < 0.005) {
            return $image;
        }

        if ($width / $height > $ratio) {
            $cropWidth = (int) round($height * $ratio);
            $rect = ['x' => (int) floor(($width - $cropWidth) / 2), 'y' => 0, 'width' => $cropWidth, 'height' => $height];
        } else {
            $cropHeight = (int) round($width / $ratio);
            $rect = ['x' => 0, 'y' => (int) floor(($height - $cropHeight) / 2), 'width' => $width, 'height' => $cropHeight];
        }

        $cropped = imagecrop($image, $rect);

        return $cropped instanceof GdImage ? $cropped : $image;
    }
}
