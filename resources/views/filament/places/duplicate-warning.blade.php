@php
    use App\Filament\Resources\Places\PlaceResource;
    use App\Models\Place;
@endphp

<ul style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .5rem;">
    @foreach ($places as $place)
        <li style="display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .5rem .75rem; border: 1px solid rgba(127, 127, 127, .3); border-radius: .5rem;">
            <div>
                <strong>{{ $place['name_ar'] }}</strong>
                @if (filled($place['name_en']))
                    <span dir="ltr" style="opacity: .7;">· {{ $place['name_en'] }}</span>
                @endif
                <div style="font-size: .8125rem; opacity: .75;">
                    {{ Place::STATUS_LABELS[$place['status']] ?? $place['status'] }} · على بعد {{ $place['distance_m'] }} متر
                </div>
            </div>
            <a
                href="{{ PlaceResource::getUrl('view', ['record' => $place['id']]) }}"
                target="_blank"
                rel="noopener"
                style="white-space: nowrap; color: var(--primary-600); text-decoration: underline;"
            >افتح الموجود</a>
        </li>
    @endforeach
</ul>
