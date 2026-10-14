<?php

namespace App\Console\Commands;

use App\Support\Search\PlaceSearchText;
use App\Support\Search\SearchVocabulary;
use Illuminate\Console\Command;

class RebuildPlaceSearchCommand extends Command
{
    protected $signature = 'places:rebuild-search {--place= : id مكان واحد بدل الكل}';

    protected $description = 'Rebuild places.search_text (normalized search text) and the spelling vocabulary';

    public function handle(): int
    {
        $count = $this->option('place') !== null
            ? PlaceSearchText::rebuild([(int) $this->option('place')])
            : PlaceSearchText::rebuildAll();

        SearchVocabulary::forget();

        $this->info("Rebuilt search text for {$count} places.");

        return self::SUCCESS;
    }
}
