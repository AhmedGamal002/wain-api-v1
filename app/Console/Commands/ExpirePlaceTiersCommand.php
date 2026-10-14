<?php

namespace App\Console\Commands;

use App\Support\PlaceTierExpiry;
use Illuminate\Console\Command;

/**
 * الـ job اليومي لانتهاء الباقات (03 القسم 7.4): المنتهي بيرجع free، والمنتهي بعد 7 أيام بيتنبّه عليه الأدمنز.
 */
class ExpirePlaceTiersCommand extends Command
{
    protected $signature = 'places:expire-tiers';

    protected $description = 'Return expired listing tiers to free and warn the admins about tiers ending in 7 days';

    public function handle(PlaceTierExpiry $tiers): int
    {
        $expired = $tiers->expire();
        $warned = $tiers->warn();

        $this->info("Expired {$expired} tier(s), warned about {$warned} tier(s) ending in ".PlaceTierExpiry::WARN_DAYS.' days.');

        return self::SUCCESS;
    }
}
