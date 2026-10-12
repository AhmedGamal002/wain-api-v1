<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            // ما يتغيرش بعد الإنشاء: التطبيق بيفلتر بيه
            $table->string('slug')->unique();
            $table->string('name_ar', 40);
            $table->string('name_en', 40);
            // إيموجي أو مسار SVG
            $table->string('icon');
            $table->char('color', 7);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
