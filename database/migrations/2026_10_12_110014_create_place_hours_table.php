<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول مشتق لفلتر «مفتوح دلوقتي» (03 القسم 7.1): بيتبني من opening_hours و ramadan_hours.
 * الدقايق من أول الأسبوع (0 لـ 10079)، والفترة اللي بتعدّي نص الليل أو آخر الأسبوع بتتقسم لصفين.
 * الـ listener اللي بيبنيه عند معلوم (أسبوع 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('place_id')->constrained('places')->cascadeOnDelete();
            $table->boolean('is_ramadan')->default(false);
            $table->unsignedSmallInteger('opens_minute');
            $table->unsignedSmallInteger('closes_minute');

            $table->index(['place_id', 'is_ramadan', 'opens_minute', 'closes_minute'], 'place_hours_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_hours');
    }
};
