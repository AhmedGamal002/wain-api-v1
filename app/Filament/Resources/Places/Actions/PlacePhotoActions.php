<?php

namespace App\Filament\Resources\Places\Actions;

use App\Models\Place;
use App\Models\User;
use App\Support\PlacePhotoException;
use App\Support\PlacePhotos;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * أزرار رفع الصور في تاب «الصور» (03 القسم 4.5): الغلاف والمعرض والمنيو. القواعد (النوع والحجم والمقاس والعدد)
 * في `PlacePhotos`، والـ modal هنا بيحدّد الحدود للمستخدم بس. الملفات بتتبعت للـ service من غير ما Filament
 * يحفظها (`storeFiles(false)`): الأصل بيتحفظ على الـ disk الخاص والنسخ بيعملها `ProcessPlacePhoto`.
 */
final class PlacePhotoActions
{
    private const ACCEPTED = ['image/jpeg', 'image/png', 'image/webp'];

    public static function uploadCover(): Action
    {
        $rules = (array) config('wain.photos.types.cover');

        return Action::make('uploadCover')
            ->label(fn (Place $record) => $record->cover_photo_id !== null ? 'تغيير الغلاف' : 'رفع صورة الغلاف')
            ->icon(Heroicon::OutlinedPhoto)
            ->modalHeading('صورة الغلاف')
            ->modalDescription("بنسبة 3:2، أقل مقاس {$rules['min_width']}×{$rules['min_height']}، وحتى ".($rules['max_kb'] / 1024).' ميجا. هتتقص 3:2 لو مش كده.')
            ->modalSubmitActionLabel('رفع')
            ->schema([
                FileUpload::make('file')
                    ->label('الصورة')
                    ->image()
                    ->acceptedFileTypes(self::ACCEPTED)
                    ->maxSize((int) $rules['max_kb'])
                    ->imageEditor()
                    ->imageEditorAspectRatioOptions(['3:2'])
                    ->imageCropAspectRatio('3:2')
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (Place $record, array $data) {
                self::store($record, PlacePhotos::KIND_COVER, [$data['file'] ?? null], 'اترفع الغلاف');
            });
    }

    public static function uploadGallery(): Action
    {
        $rules = (array) config('wain.photos.types.gallery');

        return Action::make('uploadGallery')
            ->label('إضافة صور للمعرض')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->color('gray')
            ->modalHeading('صور المعرض')
            ->modalDescription("لحد {$rules['max']} صورة، أقل عرض {$rules['min_width']}px، وحتى ".($rules['max_kb'] / 1024).' ميجا لكل صورة.')
            ->modalSubmitActionLabel('رفع')
            ->schema([
                FileUpload::make('files')
                    ->label('الصور')
                    ->image()
                    ->multiple()
                    ->maxFiles((int) $rules['max'])
                    ->acceptedFileTypes(self::ACCEPTED)
                    ->maxSize((int) $rules['max_kb'])
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (Place $record, array $data) {
                self::store($record, PlacePhotos::KIND_GALLERY, (array) ($data['files'] ?? []), 'اترفعت صور المعرض');
            });
    }

    public static function uploadMenu(): Action
    {
        $rules = (array) config('wain.photos.types.menu');

        return Action::make('uploadMenu')
            ->label('إضافة صور المنيو')
            ->icon(Heroicon::OutlinedDocumentText)
            ->color('gray')
            ->modalHeading('صور المنيو')
            ->modalDescription("لحد {$rules['max']} صور، وحتى ".($rules['max_kb'] / 1024).' ميجا لكل صورة عشان النص يفضل مقروء.')
            ->modalSubmitActionLabel('رفع')
            ->schema([
                FileUpload::make('files')
                    ->label('الصور')
                    ->image()
                    ->multiple()
                    ->maxFiles((int) $rules['max'])
                    ->acceptedFileTypes(self::ACCEPTED)
                    ->maxSize((int) $rules['max_kb'])
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (Place $record, array $data) {
                self::store($record, PlacePhotos::KIND_MENU, (array) ($data['files'] ?? []), 'اترفعت صور المنيو');
            });
    }

    /**
     * يرفع الملفات واحد واحد: اللي اترفض بيتبلّغ بسببه (اسم الملف والمشكلة) والباقي بيكمّل.
     *
     * @param  array<int, mixed>  $files
     */
    private static function store(Place $place, string $kind, array $files, string $success): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $uploaded = 0;
        $errors = [];

        // الحقل الواحد ممكن يرجّع الملف نفسه أو array فيه ملف (حسب نوع الحقل)، والمتعدد array مفاتيحها uuid
        $files = collect($files)->flatten()->all();

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile && ! $file instanceof TemporaryUploadedFile) {
                continue;
            }

            try {
                app(PlacePhotos::class)->upload($place, $file, $kind, $user);
                $uploaded++;
            } catch (PlacePhotoException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($uploaded > 0) {
            Notification::make()
                ->success()
                ->title($success)
                ->body('بتتعالج دلوقتي وبتظهر جاهزة في دقيقة تقريباً.')
                ->send();
        }

        if ($errors !== []) {
            Notification::make()
                ->danger()
                ->title($uploaded > 0 ? 'بعض الصور اترفضت' : 'الصور اترفضت')
                ->body(implode("\n", $errors))
                ->persistent()
                ->send();
        }
    }
}
