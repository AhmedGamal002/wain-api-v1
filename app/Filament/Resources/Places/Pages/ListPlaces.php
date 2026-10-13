<?php

namespace App\Filament\Resources\Places\Pages;

use App\Filament\Resources\Places\PlaceResource;
use App\Models\Place;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPlaces extends ListRecords
{
    protected static string $resource = PlaceResource::class;

    /**
     * التابز فوق الجدول، كل تاب بعدده (03 القسم 4.2): الكل · قيد المراجعة · منشور · مخفي · مسودة · مرفوض · مغلق.
     * العدد بيستثني المحذوف (الـ global scope)، وبيتحسب باستعلام واحد.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        /** @var array<string, int> $counts */
        $counts = Place::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total)
            ->all();

        $byStatus = fn (string $label, string $status): Tab => Tab::make($label)
            ->badge($counts[$status] ?? 0)
            ->modifyQueryUsing(fn (Builder $query) => $query->where('places.status', $status));

        return [
            'all' => Tab::make('الكل')->badge(array_sum($counts)),
            Place::STATUS_PENDING => $byStatus('قيد المراجعة', Place::STATUS_PENDING),
            Place::STATUS_ACTIVE => $byStatus('منشور', Place::STATUS_ACTIVE),
            Place::STATUS_HIDDEN => $byStatus('مخفي', Place::STATUS_HIDDEN),
            Place::STATUS_DRAFT => $byStatus('مسودة', Place::STATUS_DRAFT),
            Place::STATUS_REJECTED => $byStatus('مرفوض', Place::STATUS_REJECTED),
            Place::STATUS_CLOSED => $byStatus('مغلق', Place::STATUS_CLOSED),
        ];
    }
}
