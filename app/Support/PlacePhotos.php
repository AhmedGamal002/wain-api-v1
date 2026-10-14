<?php

namespace App\Support;

use App\Events\PlaceChanged;
use App\Jobs\ProcessPlacePhoto;
use App\Models\Place;
use App\Models\PlacePhoto;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * قواعد صور المكان (03 القسم 4.5): الرفع والغلاف والترتيب والحذف و«حاول تاني». الـ UI (تاب الصور) بينادي عليه
 * بس، والمعالجة نفسها (النسخ والـ EXIF) في `ProcessPlacePhoto` و`PlacePhotoProcessor`.
 *
 * الغلاف صف من نوع `gallery` بيشاور عليه `places.cover_photo_id`، والمعرض كل صفوف `gallery` غيره (الـ API
 * بيطلّع الغلاف الأول في `photos`). أي تغيير هنا بيطلق `PlaceChanged` عشان cache التفاصيل والبحث يشوفوه
 * (الصور مش `save()` على `Place`: CLAUDE.md «الأماكن (A1)»).
 */
final class PlacePhotos
{
    public const KIND_COVER = 'cover';

    public const KIND_GALLERY = 'gallery';

    public const KIND_MENU = 'menu';

    private const MIME_TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

    /**
     * يرفع صورة ويبدأ معالجتها. الغلاف على مكان ما عندوش غلاف جاهز بيبقى الغلاف فوراً، وغير كده بيبقى
     * الغلاف لما المعالجة تخلص (المنشور ما بيفضلش من غير غلاف).
     *
     * @param  self::KIND_*  $kind
     */
    public function upload(Place $place, UploadedFile $file, string $kind, User $actor): PlacePhoto
    {
        Gate::forUser($actor)->authorize('update', $place);

        if (! $place->exists || $place->trashed()) {
            throw new PlacePhotoException('احفظ المكان الأول وبعدها ضيف الصور');
        }

        $rules = config("wain.photos.types.{$kind}");
        if (! is_array($rules)) {
            throw new PlacePhotoException('نوع الصورة مش معروف');
        }

        $name = $file->getClientOriginalName();
        $info = $this->inspect($file, $name, $rules);
        $this->guardCount($place, $kind, $rules, $name);

        $extension = self::MIME_TYPES[$info[2]];
        $path = $file->storeAs(
            "places/{$place->id}/original",
            Str::uuid().'.'.$extension,
            config('wain.photos.original_disk'),
        );

        if ($path === false) {
            throw new PlacePhotoException("مقدرتش أحفظ «{$name}»");
        }

        $type = $kind === self::KIND_MENU ? PlacePhoto::TYPE_MENU : PlacePhoto::TYPE_GALLERY;

        $photo = DB::transaction(function () use ($place, $type, $path, $info, $actor, $kind) {
            $photo = $place->photos()->create([
                'type' => $type,
                'path' => $path,
                'variants' => null,
                'width' => min($info[0], 65535),
                'height' => min($info[1], 65535),
                'sort_order' => ((int) $place->photos()->where('type', $type)->max('sort_order')) + 1,
                'status' => PlacePhoto::STATUS_PROCESSING,
                'uploaded_by' => $actor->id,
            ]);

            // مفيش غلاف جاهز: الصورة دي هي الغلاف من دلوقتي (ولو فشلت «حاول تاني» يفضل شغال)
            if ($kind === self::KIND_COVER && ! $this->hasReadyCover($place)) {
                $place->forceFill(['cover_photo_id' => $photo->id])->save();
            }

            return $photo;
        });

        $makeCover = $kind === self::KIND_COVER && $place->cover_photo_id !== $photo->id;
        ProcessPlacePhoto::dispatch($photo->id, $makeCover);

        $this->log($place, $actor, 'رفع صورة '.$this->kindLabel($kind), ['action' => 'upload', 'kind' => $kind, 'photo_id' => $photo->id]);
        PlaceChanged::dispatch($place->refresh());

        return $photo;
    }

    /** «خلّيها الغلاف»: صورة جاهزة من المعرض بتبقى الغلاف. */
    public function makeCover(Place $place, PlacePhoto $photo, User $actor): void
    {
        $this->authorizeFor($place, $photo, $actor);

        if ($photo->type !== PlacePhoto::TYPE_GALLERY) {
            throw new PlacePhotoException('صورة المنيو ما تنفعش غلاف');
        }

        if (! $photo->isReady()) {
            throw new PlacePhotoException('الصورة لسه بتتعالج أو فشلت، استنى لما تبقى جاهزة');
        }

        $place->forceFill(['cover_photo_id' => $photo->id])->save();
    }

    /**
     * حذف صورة (الأصل والنسخ والصف). الغلاف على مكان عدّى شروط النشر (مش مسودة أو مرفوض) ممنوع يتحذف إلا
     * لو اتحط غلاف بدالها.
     */
    public function delete(Place $place, PlacePhoto $photo, User $actor): void
    {
        $this->authorizeFor($place, $photo, $actor);

        $isCover = $place->cover_photo_id === $photo->id;

        if ($isCover && ! in_array($place->status, [Place::STATUS_DRAFT, Place::STATUS_REJECTED], true)) {
            throw new PlacePhotoException('دي صورة الغلاف، ومكان '.(Place::STATUS_LABELS[$place->status] ?? '').' لازم يفضل ليه غلاف. اختار غلاف تاني الأول');
        }

        DB::transaction(function () use ($place, $photo, $isCover) {
            if ($isCover) {
                $place->forceFill(['cover_photo_id' => null])->save();
            }

            $photo->delete();
        });

        app(PlacePhotoProcessor::class)->deleteVariants($photo);
        Storage::disk(config('wain.photos.original_disk'))->delete($photo->path);

        $this->log($place, $actor, 'حذف صورة', ['action' => 'delete', 'photo_id' => $photo->id, 'was_cover' => $isCover]);
        PlaceChanged::dispatch($place->refresh());
    }

    /**
     * ترتيب نوع بالسحب: `$ids` بالترتيب الجديد. اللي مش تبع المكان والنوع ده بيتتجاهل.
     *
     * @param  list<int|string>  $ids
     */
    public function reorder(Place $place, string $type, array $ids, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $place);

        $owned = $place->photos()->where('type', $type)->pluck('id')->all();
        $ordered = array_values(array_filter(array_map('intval', $ids), fn (int $id) => in_array($id, $owned, true)));

        // اللي ما اتبعتش (صور اترفعت وقت السحب) بيتحط في الآخر بترتيبه
        $ordered = [...$ordered, ...array_values(array_diff($owned, $ordered))];

        DB::transaction(function () use ($place, $ordered) {
            foreach ($ordered as $position => $id) {
                PlacePhoto::query()->whereKey($id)->where('place_id', $place->id)->update(['sort_order' => $position + 1]);
            }
        });

        $this->log($place, $actor, 'ترتيب الصور', ['action' => 'reorder', 'type' => $type]);
        PlaceChanged::dispatch($place->refresh());
    }

    /** تحريك صورة خطوة لقدّام (`-1`) أو ورا (`+1`) في نوعها. */
    public function move(Place $place, PlacePhoto $photo, int $delta, User $actor): void
    {
        $this->authorizeFor($place, $photo, $actor);

        $ids = $place->photos()->where('type', $photo->type)->pluck('id')->all();
        $index = array_search($photo->id, $ids, true);
        $target = $index + ($delta < 0 ? -1 : 1);

        if ($index === false || ! isset($ids[$target])) {
            return;
        }

        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

        $this->reorder($place, $photo->type, $ids, $actor);
    }

    /** «حاول تاني» لصورة فشلت معالجتها. */
    public function retry(Place $place, PlacePhoto $photo, User $actor): void
    {
        $this->authorizeFor($place, $photo, $actor);

        if ($photo->status !== PlacePhoto::STATUS_FAILED) {
            return;
        }

        $photo->forceFill(['status' => PlacePhoto::STATUS_PROCESSING])->save();
        ProcessPlacePhoto::dispatch($photo->id, false);
    }

    /** وصف الصورة (اختياري، لحد 120 حرف لكل لغة). */
    public function caption(Place $place, PlacePhoto $photo, ?string $ar, ?string $en, User $actor): void
    {
        $this->authorizeFor($place, $photo, $actor);

        $ar = filled($ar) ? trim($ar) : null;
        $en = filled($en) ? trim($en) : null;

        if (mb_strlen((string) $ar) > 120 || mb_strlen((string) $en) > 120) {
            throw new PlacePhotoException('الوصف لحد 120 حرف');
        }

        $photo->forceFill(['caption_ar' => $ar, 'caption_en' => $en])->save();
        PlaceChanged::dispatch($place->refresh());
    }

    // ───────────────────────── داخلي ─────────────────────────

    /**
     * يتأكد إنه صورة JPG أو PNG أو WebP، وفي حدود الحجم والمقاس.
     *
     * @param  array<string, mixed>  $rules
     * @return array{0: int, 1: int, 2: int}
     */
    private function inspect(UploadedFile $file, string $name, array $rules): array
    {
        $info = @getimagesize($file->getRealPath());

        if ($info === false || ! isset(self::MIME_TYPES[$info[2]])) {
            throw new PlacePhotoException("«{$name}» مش صورة JPG أو PNG أو WebP");
        }

        if ($file->getSize() > (int) $rules['max_kb'] * 1024) {
            throw new PlacePhotoException("«{$name}» أكبر من ".((int) $rules['max_kb'] / 1024).' ميجا');
        }

        if ((int) config('wain.photos.max_pixels') < $info[0] * $info[1]) {
            throw new PlacePhotoException("«{$name}» مقاسها كبير جداً");
        }

        $minWidth = $rules['min_width'] ?? null;
        $minHeight = $rules['min_height'] ?? null;

        if (($minWidth !== null && $info[0] < $minWidth) || ($minHeight !== null && $info[1] < $minHeight)) {
            $need = $minHeight !== null ? "{$minWidth}×{$minHeight}" : "عرض {$minWidth}";

            throw new PlacePhotoException("«{$name}» صغيرة ({$info[0]}×{$info[1]}). المطلوب {$need} على الأقل");
        }

        return [$info[0], $info[1], $info[2]];
    }

    /** @param  array<string, mixed>  $rules */
    private function guardCount(Place $place, string $kind, array $rules, string $name): void
    {
        if ($kind === self::KIND_COVER) {
            return;
        }

        $type = $kind === self::KIND_MENU ? PlacePhoto::TYPE_MENU : PlacePhoto::TYPE_GALLERY;
        $count = $place->photos()
            ->where('type', $type)
            ->when($type === PlacePhoto::TYPE_GALLERY && $place->cover_photo_id !== null,
                fn ($query) => $query->where('id', '!=', $place->cover_photo_id))
            ->count();

        if ($count >= (int) $rules['max']) {
            throw new PlacePhotoException("وصلت الحد ({$rules['max']} صورة). امسح صورة قبل ما تضيف «{$name}»");
        }
    }

    private function hasReadyCover(Place $place): bool
    {
        return $place->cover_photo_id !== null
            && PlacePhoto::query()->whereKey($place->cover_photo_id)->where('status', PlacePhoto::STATUS_READY)->exists();
    }

    private function authorizeFor(Place $place, PlacePhoto $photo, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $place);

        if ($photo->place_id !== $place->id) {
            throw new PlacePhotoException('الصورة دي مش تبع المكان ده');
        }
    }

    private function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::KIND_COVER => 'الغلاف',
            self::KIND_MENU => 'المنيو',
            default => 'المعرض',
        };
    }

    /**
     * سطر في سجل التغييرات (03 القسم 4.7: نوع التغيير «صور»).
     *
     * @param  array<string, mixed>  $properties
     */
    private function log(Place $place, User $actor, string $description, array $properties): void
    {
        activity(Place::LOG_NAME)
            ->performedOn($place)
            ->causedBy($actor)
            ->event('photos')
            ->withProperties($properties)
            ->log($description);
    }
}
