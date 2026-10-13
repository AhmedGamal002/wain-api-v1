@php
    use App\Filament\Resources\Places\Schemas\PlaceForm;
    use App\Models\Place;
    use App\Support\PlacePublishCheck;

    /** @var \App\Models\Place|null $record */
    $record = $getRecord();
    $state = $getLivewire()->data ?? [];

    // نفس لقطة الحفظ: اللي مكتوب في الفورم دلوقتي (مش المحفوظ)، من غير منطق شرط تاني
    $result = PlacePublishCheck::check(PlaceForm::snapshot($state, $record));
    $recommended = PlacePublishCheck::recommendedFor([
        'phone' => $state['phone'] ?? null,
        'whatsapp' => $state['whatsapp'] ?? null,
        'description_en' => $state['description_en'] ?? null,
        'menu_url' => $state['menu_url'] ?? null,
        'gallery_count' => $record?->exists
            ? $record->photos()->where('type', 'gallery')->where('status', 'ready')->count()
            : 0,
    ]);

    $statusLabel = $record?->exists ? (Place::STATUS_LABELS[$record->status] ?? $record->status) : 'مسودة جديدة';
    $percent = (int) round($result->passedCount() / max($result->total(), 1) * 100);
    $complete = $result->isComplete();
    $barColor = $complete ? 'var(--success-500)' : ($percent >= 60 ? 'var(--warning-500)' : 'var(--danger-500)');
@endphp

<div x-data style="display: flex; flex-direction: column; gap: 1rem;">
    <div style="display: flex; align-items: center; justify-content: space-between; gap: .5rem;">
        <span style="font-size: .875rem; opacity: .7;">الحالة</span>
        <strong>{{ $statusLabel }}</strong>
    </div>

    @if ($record?->exists && filled($record->status_reason))
        <div style="font-size: .8125rem; opacity: .8;">السبب: {{ $record->status_reason }}</div>
    @endif

    <div>
        <div style="display: flex; align-items: baseline; justify-content: space-between; margin-bottom: .375rem;">
            <strong>شروط النشر</strong>
            <span style="font-size: .875rem;">{{ $result->passedCount() }} من {{ $result->total() }}</span>
        </div>
        <div style="height: .375rem; border-radius: 9999px; background: rgba(127, 127, 127, .25); overflow: hidden;">
            <div style="height: 100%; width: {{ $percent }}%; background: {{ $barColor }}; border-radius: 9999px;"></div>
        </div>
    </div>

    <ul style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .125rem;">
        @foreach ($result->items() as $item)
            <li>
                <button
                    type="button"
                    x-on:click="$dispatch('{{ PlaceForm::OPEN_TAB_EVENT }}', { tab: '{{ $item['tab'] }}' })"
                    style="display: flex; align-items: center; gap: .5rem; width: 100%; padding: .25rem .125rem; background: none; border: 0; cursor: pointer; text-align: start; font: inherit; color: inherit;"
                    title="افتح التاب"
                >
                    <span style="font-weight: 700; width: 1rem; text-align: center; color: {{ $item['ok'] ? 'var(--success-600)' : 'var(--danger-600)' }};">{{ $item['ok'] ? '✔' : '✖' }}</span>
                    <span style="{{ $item['ok'] ? 'opacity: .75;' : '' }}">{{ $item['label'] }}</span>
                </button>
            </li>
        @endforeach
    </ul>

    <div style="border-top: 1px solid rgba(127, 127, 127, .3); padding-top: .75rem;">
        <div style="font-size: .8125rem; opacity: .7; margin-bottom: .375rem;">مفضّل (مش شرط)</div>
        <ul style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .125rem; font-size: .875rem;">
            @foreach ($recommended as $item)
                <li style="display: flex; align-items: center; gap: .5rem;">
                    <span style="width: 1rem; text-align: center; opacity: .7;">{{ $item['ok'] ? '✔' : '○' }}</span>
                    <span style="opacity: {{ $item['ok'] ? '.75' : '.9' }};">{{ $item['label'] }}</span>
                </li>
            @endforeach
        </ul>
    </div>

    @if ($record?->exists)
        <dl style="margin: 0; border-top: 1px solid rgba(127, 127, 127, .3); padding-top: .75rem; display: grid; gap: .25rem; font-size: .8125rem;">
            <div style="display: flex; justify-content: space-between; gap: .5rem;">
                <dt style="opacity: .7;">أضافه</dt>
                <dd style="margin: 0;">{{ $record->createdBy?->name ?? '—' }} · {{ $record->created_at?->timezone('Africa/Cairo')->format('Y-m-d') }}</dd>
            </div>
            <div style="display: flex; justify-content: space-between; gap: .5rem;">
                <dt style="opacity: .7;">آخر تعديل</dt>
                <dd style="margin: 0;">{{ $record->updatedBy?->name ?? '—' }} · {{ $record->updated_at?->timezone('Africa/Cairo')->format('Y-m-d H:i') }}</dd>
            </div>
            <div style="display: flex; justify-content: space-between; gap: .5rem;">
                <dt style="opacity: .7;">الرابط</dt>
                <dd style="margin: 0; direction: ltr;"><code>wain://place/{{ $record->slug }}</code></dd>
            </div>
        </dl>
    @endif
</div>
