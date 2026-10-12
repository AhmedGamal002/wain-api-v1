<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained('cities')->restrictOnDelete();
            $table->string('slug')->unique();
            $table->string('name_ar');
            $table->string('name_en');
            // قائمة نصوص (أسماء بديلة). MariaDB بيخزّن JSON كنص فمفيش فلترة على محتواه.
            $table->json('aliases')->nullable();
            $table->decimal('center_lat', 10, 7);
            $table->decimal('center_lng', 10, 7);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->unique(['city_id', 'name_ar']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('areas');
    }
};
