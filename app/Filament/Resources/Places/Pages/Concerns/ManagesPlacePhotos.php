<?php

namespace App\Filament\Resources\Places\Pages\Concerns;

use App\Models\PlacePhoto;
use App\Models\User;
use App\Support\PlacePhotoException;
use App\Support\PlacePhotos;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * عمليات تاب «الصور» على صفحة التعديل (ترتيب وغلاف وحذف و«حاول تاني» ووصف). الميثودات public عشان الـ Blade
 * (`filament.places.photos`) بينادي عليها بـ `$wire`، فكل واحدة بتتحقق من الصلاحية جوه `PlacePhotos` (الطلب
 * ممكن يتبعت من المتصفح مباشرة). القواعد في `PlacePhotos`، والـ trait بس بيوصّلها بالصفحة ويعرض الرفض.
 */
trait ManagesPlacePhotos
{
    /** @param  list<int|string>  $ids  ترتيب جديد لنوع (`gallery` أو `menu`) بعد السحب */
    public function reorderPhotos(string $type, array $ids): void
    {
        if (! in_array($type, [PlacePhoto::TYPE_GALLERY, PlacePhoto::TYPE_MENU], true)) {
            return;
        }

        $this->photoOperation(fn (PlacePhotos $photos, User $user) => $photos->reorder($this->getRecord(), $type, $ids, $user));
    }

    public function movePhoto(int $photoId, int $delta): void
    {
        $this->withPhoto($photoId, fn (PlacePhotos $photos, PlacePhoto $photo, User $user) => $photos->move($this->getRecord(), $photo, $delta, $user));
    }

    public function makePhotoCover(int $photoId): void
    {
        $this->withPhoto($photoId, function (PlacePhotos $photos, PlacePhoto $photo, User $user) {
            $photos->makeCover($this->getRecord(), $photo, $user);

            Notification::make()->success()->title('اتحطت غلاف')->send();
        });
    }

    public function deletePhoto(int $photoId): void
    {
        $this->withPhoto($photoId, function (PlacePhotos $photos, PlacePhoto $photo, User $user) {
            $photos->delete($this->getRecord(), $photo, $user);

            Notification::make()->success()->title('اتمسحت الصورة')->send();
        });
    }

    public function retryPhoto(int $photoId): void
    {
        $this->withPhoto($photoId, function (PlacePhotos $photos, PlacePhoto $photo, User $user) {
            $photos->retry($this->getRecord(), $photo, $user);

            Notification::make()->success()->title('بنعالج الصورة تاني')->send();
        });
    }

    /** @param  'ar'|'en'  $language */
    public function savePhotoCaption(int $photoId, string $language, ?string $caption): void
    {
        $this->withPhoto($photoId, function (PlacePhotos $photos, PlacePhoto $photo, User $user) use ($language, $caption) {
            $photos->caption(
                $this->getRecord(),
                $photo,
                $language === 'ar' ? $caption : $photo->caption_ar,
                $language === 'en' ? $caption : $photo->caption_en,
                $user,
            );
        });
    }

    /** @param  callable(PlacePhotos, PlacePhoto, User): void  $do */
    private function withPhoto(int $photoId, callable $do): void
    {
        $this->photoOperation(function (PlacePhotos $photos, User $user) use ($photoId, $do) {
            $photo = $this->getRecord()->photos()->whereKey($photoId)->first();

            if ($photo === null) {
                throw new ModelNotFoundException;
            }

            $do($photos, $photo, $user);
        });
    }

    /** @param  callable(PlacePhotos, User): void  $do */
    private function photoOperation(callable $do): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        try {
            $do(app(PlacePhotos::class), $user);
        } catch (PlacePhotoException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
        } catch (ModelNotFoundException) {
            Notification::make()->warning()->title('الصورة دي مبقتش موجودة')->send();
        } catch (AuthorizationException) {
            abort(403);
        }

        // الغلاف والصور اتغيّروا: العرض والـ checklist يقروا من الجديد
        $this->getRecord()->refresh()->unsetRelation('photos')->unsetRelation('coverPhoto');
    }
}
