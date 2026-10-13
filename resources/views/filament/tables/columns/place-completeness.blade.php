@php
    /** @var \App\Models\Place $place */
    $place = $getRecord();
    $check = \App\Support\PlacePublishCheck::for($place);
    $percent = (int) round($check->passedCount() / max($check->total(), 1) * 100);
    $complete = $check->isComplete();
    $tooltip = $complete ? 'كل شروط النشر متحققة' : 'ناقص: '.implode('، ', $check->missingLabels());
@endphp

<div class="fi-ta-text-item" style="min-width: 7rem" title="{{ $tooltip }}">
    <div class="text-sm font-medium {{ $complete ? 'text-success-600 dark:text-success-400' : 'text-gray-950 dark:text-white' }}">
        {{ $check->passedCount() }} من {{ $check->total() }}
    </div>
    <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
        <div
            class="h-full rounded-full {{ $complete ? 'bg-success-500' : ($percent >= 60 ? 'bg-warning-500' : 'bg-danger-500') }}"
            style="width: {{ $percent }}%"
        ></div>
    </div>
</div>
