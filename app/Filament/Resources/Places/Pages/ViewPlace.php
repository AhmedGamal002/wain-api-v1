<?php

namespace App\Filament\Resources\Places\Pages;

use App\Filament\Resources\Places\Actions\PlaceActions;
use App\Filament\Resources\Places\PlaceResource;
use App\Models\Place;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * عرض المكان (03 القسم 4.7) وسجل تغييراته. المحذوف بيتفتح هنا بس (مش في التعديل)، وفيه «استرجاع».
 */
class ViewPlace extends ViewRecord
{
    protected static string $resource = PlaceResource::class;

    protected function getHeaderActions(): array
    {
        $actions = array_map(
            fn (Action $action) => $action->record($this->getRecord()),
            [PlaceActions::restore(), ...PlaceActions::headerActions()],
        );

        return [
            EditAction::make()
                ->label('تعديل')
                ->visible(fn (Place $record) => ! $record->trashed() && (auth()->user()?->can('update', $record) ?? false)),
            ...$actions,
        ];
    }
}
