<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التجميع الليلي (`places:aggregate-stats`) ومسح الأحداث الأقدم من 90 يوم (`events:prune`) بيفلتروا على
 * `created_at` لوحده من غير `place_id`، فالـ indexes الموجودة (اللي أولها `place_id` أو `device_id`) ما بتخدمهمش:
 * كانوا هيعدّوا الجدول كله كل ليلة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('place_events', function (Blueprint $table) {
            $table->index('created_at', 'place_events_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('place_events', function (Blueprint $table) {
            $table->dropIndex('place_events_created_at_index');
        });
    }
};
