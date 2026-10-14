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

// Daily database backup to R2 (FRD section 8, week 6). Only when the backups bucket is configured
// (BACKUP_R2_*), so local development and CI never try to reach R2. Failures notify the admins in the panel bell.
Schedule::command('backup:database')
    ->dailyAt('03:10')
    ->withoutOverlapping()
    ->when(fn () => filled(config('filesystems.disks.backups.bucket')));

// Listing tiers (03 section 7.4): expired ones go back to free and the admins get a bell notice 7 days ahead.
// Soft-deleted places are removed for good (with their photos) 30 days after the delete (03 "transitions").
Schedule::command('places:expire-tiers')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('places:purge-deleted')->dailyAt('03:20')->withoutOverlapping();

// Search log (06 section 5): searches are user data, kept 90 days for the A9 reports.
Schedule::command('search:prune-logs')->dailyAt('03:35')->withoutOverlapping();

// Mobile events (03 section 5.5, 06 section 4): yesterday (and the day before, for events that sat in the queue
// past midnight) rolled up into place_stats_daily, then raw events older than 90 days are dropped. In that order.
Schedule::command('places:aggregate-stats')->dailyAt('03:25')->withoutOverlapping();
Schedule::command('events:prune')->dailyAt('03:45')->withoutOverlapping();

// File cache (06 section 5): the file store never deletes an expired entry until someone reads it, and the places list
// keys carry a version that changes with every edit, so the old ones would pile up on disk.
Schedule::command('cache:prune-expired')->dailyAt('03:55')->withoutOverlapping();
