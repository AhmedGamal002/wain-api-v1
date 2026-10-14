@php
    use App\Models\PlacePhoto;

    /** @var \App\Models\Place|null $record */
    $record = $getRecord();
    $exists = $record?->exists === true;

    $photos = $exists ? $record->photos()->get() : collect();
    $cover = $photos->firstWhere('id', $record?->cover_photo_id);
    $gallery = $photos->where('type', PlacePhoto::TYPE_GALLERY)->reject(fn ($p) => $p->id === $record?->cover_photo_id)->values();
    $menu = $photos->where('type', PlacePhoto::TYPE_MENU)->values();
    $busy = $photos->contains(fn ($p) => $p->status === PlacePhoto::STATUS_PROCESSING);

    $readOnly = $readOnly ?? false;
    $limits = config('wain.photos.types');
    $isDraftLike = in_array($record?->status, ['draft', 'rejected'], true);
    $statusLabels = [
        PlacePhoto::STATUS_PROCESSING => 'بتتعالج...',
        PlacePhoto::STATUS_READY => 'جاهزة',
        PlacePhoto::STATUS_FAILED => 'فشلت',
    ];
    $statusColors = [
        PlacePhoto::STATUS_PROCESSING => 'warning',
        PlacePhoto::STATUS_READY => 'success',
        PlacePhoto::STATUS_FAILED => 'danger',
    ];
@endphp

@if (! $exists)
    <p style="margin: 0; opacity: .75;">
        احفظ المكان كمسودة الأول، وبعدها ضيف الصور من هنا (صورة الغلاف شرط نشر).
    </p>
@else
    {{-- بيتحدّث لوحده طول ما فيه صورة بتتعالج (الـ queue بيشتغل بالدقيقة على الاستضافة) --}}
    <div @if ($busy) wire:poll.5s @endif style="display: flex; flex-direction: column; gap: 1.5rem;">

        {{-- الغلاف --}}
        <section>
            <div style="display: flex; align-items: baseline; justify-content: space-between; margin-bottom: .5rem;">
                <strong>صورة الغلاف</strong>
                <span style="font-size: .8125rem; opacity: .7;">{{ $limits['cover']['min_width'] }}×{{ $limits['cover']['min_height'] }} على الأقل، بنسبة 3:2</span>
            </div>

            @if ($cover)
                <div style="display: flex; gap: 1rem; align-items: flex-start; flex-wrap: wrap;">
                    <div style="width: 15rem; aspect-ratio: 3 / 2; border-radius: .5rem; overflow: hidden; background: rgba(127,127,127,.15); display: flex; align-items: center; justify-content: center;">
                        @if ($cover->isReady() && $cover->variantUrl('medium'))
                            <img src="{{ $cover->variantUrl('medium') }}" alt="الغلاف" style="width: 100%; height: 100%; object-fit: cover;">
                        @else
                            <span style="font-size: .875rem; opacity: .7;">{{ $statusLabels[$cover->status] ?? '' }}</span>
                        @endif
                    </div>
                    <div style="display: flex; flex-direction: column; gap: .5rem; align-items: flex-start;">
                        <x-filament::badge :color="$statusColors[$cover->status] ?? 'gray'">{{ $statusLabels[$cover->status] ?? $cover->status }}</x-filament::badge>
                        @if ($cover->width && $cover->height)
                            <span style="font-size: .8125rem; opacity: .7;">الأصل {{ $cover->width }}×{{ $cover->height }}</span>
                        @endif
                        @if ($cover->status === PlacePhoto::STATUS_FAILED && ! $readOnly)
                            <x-filament::button size="sm" color="warning" wire:click="retryPhoto({{ $cover->id }})">حاول تاني</x-filament::button>
                        @endif
                    </div>
                </div>
            @else
                <p style="margin: 0; opacity: .75;">مفيش غلاف لسه. المكان مش هيتنشر من غيره.</p>
            @endif
        </section>

        {{-- المعرض والمنيو --}}
        @foreach ([
            ['key' => PlacePhoto::TYPE_GALLERY, 'title' => 'المعرض', 'items' => $gallery, 'max' => $limits['gallery']['max'], 'canCover' => true],
            ['key' => PlacePhoto::TYPE_MENU, 'title' => 'صور المنيو', 'items' => $menu, 'max' => $limits['menu']['max'], 'canCover' => false],
        ] as $group)
            <section>
                <div style="display: flex; align-items: baseline; justify-content: space-between; margin-bottom: .5rem;">
                    <strong>{{ $group['title'] }}</strong>
                    <span style="font-size: .8125rem; opacity: .7;">{{ $group['items']->count() }} من {{ $group['max'] }}</span>
                </div>

                @if ($group['items']->isEmpty())
                    <p style="margin: 0; opacity: .75;">مفيش صور لسه.</p>
                @else
                    <div
                        @unless ($readOnly)
                            x-data
                            x-sortable
                            x-on:end.stop="$wire.reorderPhotos('{{ $group['key'] }}', $event.target.sortable.toArray())"
                            data-sortable-animation-duration="150"
                        @endunless
                        style="display: grid; grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr)); gap: .75rem;"
                    >
                        @foreach ($group['items'] as $photo)
                            <div
                                wire:key="photo-{{ $photo->id }}"
                                @unless ($readOnly) x-sortable-item="{{ $photo->id }}" @endunless
                                style="border: 1px solid rgba(127,127,127,.3); border-radius: .5rem; overflow: hidden; display: flex; flex-direction: column; background: rgba(127,127,127,.05);"
                            >
                                <div @unless ($readOnly) x-sortable-handle @endunless style="aspect-ratio: 3 / 2; background: rgba(127,127,127,.15); display: flex; align-items: center; justify-content: center; cursor: grab;">
                                    @if ($photo->isReady() && $photo->variantUrl('thumb'))
                                        <img src="{{ $photo->variantUrl('thumb') }}" alt="" style="width: 100%; height: 100%; object-fit: cover;">
                                    @else
                                        <span style="font-size: .8125rem; opacity: .7;">{{ $statusLabels[$photo->status] ?? '' }}</span>
                                    @endif
                                </div>

                                <div style="padding: .5rem; display: flex; flex-direction: column; gap: .375rem;">
                                    @if (! $photo->isReady())
                                        <x-filament::badge size="sm" :color="$statusColors[$photo->status] ?? 'gray'">{{ $statusLabels[$photo->status] ?? $photo->status }}</x-filament::badge>
                                    @endif

                                    @if ($readOnly)
                                        @if (filled($photo->caption_ar) || filled($photo->caption_en))
                                            <div style="font-size: .8125rem;">{{ $photo->caption_ar }}@if (filled($photo->caption_en)) <span dir="ltr" style="opacity: .7;">· {{ $photo->caption_en }}</span>@endif</div>
                                        @endif
                                    @else
                                    <input
                                        type="text"
                                        maxlength="120"
                                        placeholder="وصف بالعربي (اختياري)"
                                        value="{{ $photo->caption_ar }}"
                                        x-on:change="$wire.savePhotoCaption({{ $photo->id }}, 'ar', $event.target.value)"
                                        style="width: 100%; font-size: .8125rem; padding: .25rem .375rem; border-radius: .375rem; border: 1px solid rgba(127,127,127,.35); background: transparent; color: inherit;"
                                    >
                                    <input
                                        type="text"
                                        maxlength="120"
                                        dir="ltr"
                                        placeholder="Caption (optional)"
                                        value="{{ $photo->caption_en }}"
                                        x-on:change="$wire.savePhotoCaption({{ $photo->id }}, 'en', $event.target.value)"
                                        style="width: 100%; font-size: .8125rem; padding: .25rem .375rem; border-radius: .375rem; border: 1px solid rgba(127,127,127,.35); background: transparent; color: inherit;"
                                    >

                                    <div style="display: flex; flex-wrap: wrap; gap: .25rem;">
                                        <x-filament::icon-button icon="heroicon-m-arrow-small-right" size="sm" label="قدّام" wire:click="movePhoto({{ $photo->id }}, -1)" />
                                        <x-filament::icon-button icon="heroicon-m-arrow-small-left" size="sm" label="ورا" wire:click="movePhoto({{ $photo->id }}, 1)" />
                                        @if ($group['canCover'] && $photo->isReady())
                                            <x-filament::button size="xs" color="gray" wire:click="makePhotoCover({{ $photo->id }})">خلّيها الغلاف</x-filament::button>
                                        @endif
                                        @if ($photo->status === PlacePhoto::STATUS_FAILED)
                                            <x-filament::button size="xs" color="warning" wire:click="retryPhoto({{ $photo->id }})">حاول تاني</x-filament::button>
                                        @endif
                                        <x-filament::button size="xs" color="danger" wire:click="deletePhoto({{ $photo->id }})" wire:confirm="تمسح الصورة دي؟">مسح</x-filament::button>
                                    </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach

        @if ($cover && ! $isDraftLike && ! $readOnly)
            <p style="margin: 0; font-size: .8125rem; opacity: .7;">
                الغلاف ما ينفعش يتمسح من مكان {{ \App\Models\Place::STATUS_LABELS[$record->status] ?? '' }} إلا لما تختار غلاف تاني.
            </p>
        @endif
    </div>
@endif
