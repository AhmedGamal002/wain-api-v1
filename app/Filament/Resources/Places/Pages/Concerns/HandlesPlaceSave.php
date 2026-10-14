<?php

namespace App\Filament\Resources\Places\Pages\Concerns;

use App\Events\PlaceChanged;
use App\Filament\Resources\Places\PlaceSaveIntent;
use App\Filament\Resources\Places\Schemas\PlaceForm;
use App\Filament\Resources\Places\Schemas\PlaceFormData;
use App\Models\Attribute;
use App\Models\Place;
use App\Support\PlaceDuplicates;
use App\Support\PlacePublishCheck;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;

/**
 * منطق الحفظ المشترك بين صفحتي الإضافة والتعديل: التحقق حسب الزرار (`PlaceSaveIntent`)، والحالة الجديدة،
 * ومجموعات الخصائص، وإطلاق `PlaceChanged` بعد ما كل العلاقات اتحفظت.
 *
 * شروط النشر نفسها من `PlacePublishCheck` (ماتتكررش هنا): اللقطة بتتبني من حالة الفورم بـ `PlaceForm::snapshot`.
 */
trait HandlesPlaceSave
{
    /** مفاتيح شروط النشر اللي منعت آخر حفظ (`PlacePublishCheck::CONDITIONS`): بتظهر كنقطة حمرا على التابز. */
    public array $missingConditions = [];

    protected ?PlaceSaveIntent $saveIntent = null;

    /** تحذير التكرار (200 متر): الأماكن الشبه اللي اتلقت. */
    public array $duplicateCandidates = [];

    /** الزرار اللي اتضغط قبل ما التحذير يوقّف الحفظ (`saveIntent` protected فمش بيعدّي بين الطلبات). */
    public ?string $pendingIntent = null;

    /** «كمّل، ده مكان مختلف»: الحفظ اللي بعده بيعدّي من التحذير مرة واحدة. */
    public bool $duplicateConfirmed = false;

    /** الإجراء اللي بيحصل لو الفورم اتبعت من غير زرار بعينه (Enter). */
    abstract protected function defaultSaveIntent(): PlaceSaveIntent;

    /** يكمّل الحفظ بعد «كمّل، ده مكان مختلف» (كل صفحة بتنادي save أو create بتاعها). */
    abstract protected function resumeSave(): void;

    protected function currentSaveIntent(): PlaceSaveIntent
    {
        return $this->saveIntent ?? $this->defaultSaveIntent();
    }

    /** ديما بنحفظ جوه transaction: منع الحفظ بسبب شرط ناقص لازم يرجّع كل حاجة (العلاقات كمان). */
    public function hasDatabaseTransactions(): bool
    {
        return true;
    }

    protected function hasUnsavedDataChangesAlert(): bool
    {
        return true;
    }

    /**
     * يتحقق من الحفظ حسب الزرار، ولو ممنوع بيبعت رسالة بتسمّي الناقص ويوقف الحفظ (rollback).
     * بيتنادى بعد validation الحقول وقبل أي كتابة.
     */
    protected function guardPlaceSave(?Place $record): void
    {
        $intent = $this->currentSaveIntent();
        $this->missingConditions = [];

        $user = auth()->user();
        $target = $record ?? new Place;

        if ($intent === PlaceSaveIntent::Publish) {
            abort_unless($user !== null && Gate::forUser($user)->allows('publish', $target), 403);
        }

        if ($intent === PlaceSaveIntent::Submit) {
            abort_unless($user !== null && Gate::forUser($user)->allows('submit', $target), 403);
        }

        $after = PlacePublishCheck::check(PlaceForm::snapshot($this->data, $record));

        if ($intent->requiresCompletePlace() && ! $after->isComplete()) {
            $this->missingConditions = $after->missing();

            $this->failSave(
                $intent === PlaceSaveIntent::Publish
                    ? $after->message()
                    : 'ما ينفعش يتبعت للمراجعة. ناقص: '.implode('، ', $after->missingLabels()),
            );
        }

        // «حفظ» على مكان في حالة تانية: ممنوع يكسر شرط كان متحقق (اللي كان ناقص قبل كده ما بيمنعش)
        if ($intent === PlaceSaveIntent::Keep && $record?->exists) {
            $before = PlacePublishCheck::for($record);
            $broken = array_values(array_diff($after->missing(), $before->missing()));

            if ($broken !== []) {
                $this->missingConditions = $broken;

                $this->failSave('الحفظ ده هيكسر شرط نشر: '.implode('، ', array_map(
                    fn (string $key) => PlacePublishCheck::LABELS[$key],
                    $broken,
                )));
            }
        }

        // آخر حاجة: المستخدم يصلّح الناقص الأول، وبعدين يتحذّر من التكرار
        $this->guardDuplicates($record);
    }

    /**
     * تحذير التكرار (03 القسم 4.6): لو فيه مكان شبه ده جوه 200 متر بيوقف الحفظ ويفتح modal فيه الأماكن، و«كمّل،
     * ده مكان مختلف» بيكمّل الحفظ. على مكان موجود بيتحقق لما الاسم أو الموقع اتغيّر بس، عشان مكان له جار شبهه
     * ما يتحذّرش كل مرة يتعدّل فيها حاجة تانية.
     */
    protected function guardDuplicates(?Place $record): void
    {
        if ($this->duplicateConfirmed) {
            $this->duplicateConfirmed = false;

            return;
        }

        $lat = $this->data['latitude'] ?? null;
        $lng = $this->data['longitude'] ?? null;

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return;
        }

        if ($record?->exists
            && abs((float) $record->latitude - (float) $lat) < 0.0000001
            && abs((float) $record->longitude - (float) $lng) < 0.0000001
            && $record->name_ar === ($this->data['name_ar'] ?? null)
            && $record->name_en === ($this->data['name_en'] ?? null)) {
            return;
        }

        $similar = app(PlaceDuplicates::class)->near(
            (float) $lat,
            (float) $lng,
            $this->data['name_ar'] ?? null,
            $this->data['name_en'] ?? null,
            $record?->getKey(),
        );

        if ($similar === []) {
            return;
        }

        $this->duplicateCandidates = $similar;
        $this->pendingIntent = $this->currentSaveIntent()->value;

        // «حفظ ونشر» و«حفظ وإرسال» هما actions متفتحة وقت ما الحفظ بيحصل، والـ modal مش تابع ليهم: بنقفلهم
        // الأول عشان التحذير يبقى هو الـ action الأساسي (وإلا Filament بيشيله مع الأب)
        if ($this->mountedActions !== []) {
            $this->unmountAction(cancelParentActions: true);
        }

        $this->mountAction('duplicateWarning');

        $this->halt(shouldRollbackDatabaseTransaction: true);
    }

    /**
     * modal تحذير التكرار. بيتفتح من `guardDuplicates` بالاسم (`mountAction`)، وزرار التأكيد بيكمّل نفس الحفظ.
     */
    public function duplicateWarningAction(): Action
    {
        return Action::make('duplicateWarning')
            ->modalHeading('في مكان شبه ده قريب')
            ->modalDescription('اتأكد إنه مش نفس المكان قبل ما تكمّل.')
            ->modalContent(fn () => view('filament.places.duplicate-warning', ['places' => $this->duplicateCandidates]))
            ->modalSubmitActionLabel('كمّل، ده مكان مختلف')
            ->modalCancelActionLabel('رجوع')
            ->color('warning')
            ->action(function () {
                $this->duplicateConfirmed = true;
                $this->saveIntent = PlaceSaveIntent::tryFrom((string) $this->pendingIntent);
                $this->resumeSave();
            });
    }

    /**
     * أعمدة المكان من ناتج الفورم + الحالة الجديدة حسب الزرار.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function placeColumns(array $data, ?Place $record): array
    {
        $data = PlaceFormData::forSave($data, $this->data);
        $intent = $this->currentSaveIntent();

        $status = $intent->targetStatus($record?->status);
        if ($status !== null) {
            $data['status'] = $status;
        }

        // الإرسال والنشر بيمسحوا سبب الرفض القديم
        if ($intent->requiresCompletePlace()) {
            $data['status_reason'] = null;
        }

        return $data;
    }

    /**
     * مجموعات الخصائص وإطلاق `PlaceChanged`. بيتنادى بعد حفظ المكان وعلاقاته: ده اللي بيخلّي الـ listeners
     * (cache التفاصيل و`place_hours` و`search_text` عند معلوم) تشوف التصنيفات والخصائص الجديدة.
     *
     * الخاصية المخفية (`is_active = false`) مش ظاهرة في الفورم، فبتتحافظ عليها بدل ما الـ sync يشيلها.
     */
    protected function syncPlaceRelations(Place $place): void
    {
        $selected = Attribute::query()
            ->whereIn('id', PlaceForm::attributeIds($this->data))
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $hidden = $place->placeAttributes()
            ->where('attributes.is_active', false)
            ->pluck('attributes.id')
            ->all();

        $place->placeAttributes()->sync(array_values(array_unique([...$selected, ...$hidden])));

        PlaceChanged::dispatch($place->refresh());
    }

    private function failSave(string $message): never
    {
        Notification::make()
            ->danger()
            ->title($message)
            ->persistent()
            ->send();

        $this->halt(shouldRollbackDatabaseTransaction: true);
    }
}
