<?php

namespace App\Console\Commands;

use App\Support\PlaceHoursBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildPlaceHoursCommand extends Command
{
    protected $signature = 'places:rebuild-hours {--place= : id مكان واحد بدل الكل}';

    protected $description = 'Rebuild the derived place_hours table from opening_hours and ramadan_hours';

    public function handle(): int
    {
        $query = DB::table('places')->orderBy('id');

        if ($this->option('place') !== null) {
            $query->where('id', (int) $this->option('place'));
        }

        $places = 0;
        $rows = 0;

        $query->pluck('id')->each(function (int $id) use (&$places, &$rows): void {
            $rows += PlaceHoursBuilder::rebuild($id);
            $places++;
        });

        $this->info("Rebuilt {$rows} hour rows for {$places} places.");

        return self::SUCCESS;
    }
}
