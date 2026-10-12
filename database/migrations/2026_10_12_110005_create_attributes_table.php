<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            // ما يتغيرش بعد الإنشاء: الفلاتر والـ API والـ CSV بيستخدموه
            $table->string('slug')->unique();
            $table->string('name_ar', 30);
            $table->string('name_en', 30);
            $table->enum('group', ['vibe', 'services', 'suitable_for', 'facilities']);
            $table->string('icon');
            $table->boolean('is_filterable')->default(true);
            // أقصى 6 chips تحت خانة البحث (القيد في Filament مش في الداتابيز)
            $table->boolean('is_quick_filter')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attributes');
    }
};
