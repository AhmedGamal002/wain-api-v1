<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // التجميع الليلي لـ place_events، وبيفضل بعد مسح الأحداث الخام
        Schema::create('place_stats_daily', function (Blueprint $table) {
            $table->foreignId('place_id')->constrained('places')->cascadeOnDelete();
            $table->date('date');
            $table->enum('type', ['view', 'directions', 'call', 'whatsapp', 'instagram', 'menu', 'share', 'save']);
            $table->unsignedInteger('count')->default(0);

            $table->primary(['place_id', 'date', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_stats_daily');
    }
};
