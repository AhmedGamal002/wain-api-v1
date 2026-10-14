<?php

namespace App\Console\Commands;

use App\Models\Place;
use App\Support\PlacePurge;
use Illuminate\Console\Command;

/**
 * المسح النهائي للأماكن المحذوفة من أكتر من 30 يوم (03 «جدول الانتقالات») بصورها. بعد المسح الاسترجاع ما
 * بقاش ممكن، وده نفس الحد اللي `PlaceTransitions::restore` بيرفض عنده.
 */
class PurgeDeletedPlacesCommand extends Command
{
    protected $signature = 'places:purge-deleted';

    protected $description = 'Permanently delete places (and their photos) soft-deleted more than 30 days ago';

    public function handle(PlacePurge $purge): int
    {
        $purged = $purge->run();

        $this->info("Purged {$purged} place(s) deleted more than ".Place::PURGE_AFTER_DAYS.' days ago.');

        return self::SUCCESS;
    }
}
