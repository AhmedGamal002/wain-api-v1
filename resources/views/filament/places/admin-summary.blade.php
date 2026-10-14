@php
    use App\Models\Place;
    use Illuminate\Support\Facades\DB;

    /** @var \App\Models\Place|null $record */
    $record = $getRecord();
    $tiers = ['free' => 'مجاني', 'featured' => 'مميز', 'premium' => 'بريميوم'];
    $exists = $record?->exists === true;

    $views30d = $exists
        ? (int) DB::table('place_stats_daily')
            ->where('place_id', $record->id)->where('type', 'view')
            ->where('date', '>=', now()->subDays(30)->toDateString())->sum('count')
        : 0;
@endphp

<dl style="margin: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 1rem;">
    <div>
        <dt style="font-size: .8125rem; opacity: .7;">الحالة</dt>
        <dd style="margin: 0;">
            <strong>{{ $exists ? (Place::STATUS_LABELS[$record->status] ?? $record->status) : 'مسودة جديدة' }}</strong>
            @if ($exists && filled($record->status_reason))
                <div style="font-size: .8125rem; opacity: .8;">السبب: {{ $record->status_reason }}</div>
            @endif
            <div style="font-size: .75rem; opacity: .6;">بتتغيّر من أزرار الحفظ والإجراءات بس</div>
        </dd>
    </div>

    <div>
        <dt style="font-size: .8125rem; opacity: .7;">الباقة</dt>
        <dd style="margin: 0;">
            <strong>{{ $tiers[$record?->listing_tier ?? 'free'] ?? $record?->listing_tier }}</strong>
            @if ($exists && $record->tier_ends_at)
                <div style="font-size: .8125rem; opacity: .8;">
                    من {{ $record->tier_starts_at?->timezone('Africa/Cairo')->format('Y-m-d') }}
                    لـ {{ $record->tier_ends_at->timezone('Africa/Cairo')->format('Y-m-d') }}
                </div>
            @endif
            <div style="font-size: .75rem; opacity: .6;">زرار «تغيير الباقة» تحت (Admin بس)</div>
        </dd>
    </div>

    <div>
        <dt style="font-size: .8125rem; opacity: .7;">آخر تأكيد للبيانات</dt>
        <dd style="margin: 0;">
            @if ($exists && $record->verified_at)
                <strong>من {{ (int) $record->verified_at->diffInDays(now()) }} يوم</strong>
                <div style="font-size: .8125rem; opacity: .8;">{{ $record->verified_at->timezone('Africa/Cairo')->format('Y-m-d') }}</div>
            @else
                <strong>ما اتأكدش</strong>
            @endif
        </dd>
    </div>

    <div>
        <dt style="font-size: .8125rem; opacity: .7;">صاحب المكان</dt>
        <dd style="margin: 0;">
            @if ($exists && $record->claimedBy)
                <strong>{{ $record->claimedBy->name }}</strong>
                <div style="font-size: .8125rem; opacity: .8;">{{ $record->claimedBy->email }}</div>
            @else
                <strong>—</strong>
            @endif
        </dd>
    </div>

    <div>
        <dt style="font-size: .8125rem; opacity: .7;">التقييم</dt>
        <dd style="margin: 0;">
            <strong>{{ $exists && $record->rating_count > 0 ? number_format($record->rating_avg, 1).' ('.$record->rating_count.')' : '—' }}</strong>
        </dd>
    </div>

    <div>
        <dt style="font-size: .8125rem; opacity: .7;">مشاهدات آخر 30 يوم</dt>
        <dd style="margin: 0;"><strong>{{ $views30d }}</strong></dd>
    </div>
</dl>
