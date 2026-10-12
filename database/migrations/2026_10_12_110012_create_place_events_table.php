<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أحداث الموبايل الخام. بتتجمّع كل ليلة في place_stats_daily وبتتمسح بعد 90 يوم.
 * المرجع: 03 القسم 2 و06 القسم 2.8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('place_id')->constrained('places')->cascadeOnDelete();
            $table->enum('type', ['view', 'directions', 'call', 'whatsapp', 'instagram', 'menu', 'share', 'save']);
            // null للزائر
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // معرّف عشوائي بيتولّد من التطبيق (UUID)
            $table->char('device_id', 36)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['place_id', 'type', 'created_at']);
            // تجميع حدث view مرة في الساعة لكل جهاز
            $table->index(['device_id', 'place_id', 'type', 'created_at'], 'place_events_device_dedupe_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_events');
    }
};
