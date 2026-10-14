<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily clean-up (06 section 4). The cron on the host runs `schedule:run` every minute.
// Expired and used password reset / email verification codes, and Sanctum tokens past their 30 days.
Schedule::command('auth:prune-codes')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:40')->withoutOverlapping();

// Search text (02 F4): rebuilt every night so it heals what the listeners cannot see (a place written with
// DB::table, an import, a change made outside Eloquent), and the spelling vocabulary starts fresh.
Schedule::command('places:rebuild-search')->dailyAt('03:50')->withoutOverlapping();
