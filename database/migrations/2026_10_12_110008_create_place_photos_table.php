<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('place_id')->constrained('places')->cascadeOnDelete();
            $table->enum('type', ['gallery', 'menu'])->default('gallery');
            // الأصل على R2 (private)
            $table->string('path', 255);
            // {"thumb":…, "medium":…, "large":…} مسارات WebP، بتتملى بعد المعالجة
            $table->json('variants')->nullable();
            // مقاس الأصل
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('caption_ar', 120)->nullable();
            $table->string('caption_en', 120)->nullable();
            $table->smallInteger('sort_order')->default(0);
            // الصورة ما تظهرش غير وهي ready
            $table->enum('status', ['processing', 'ready', 'failed'])->default('processing');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();

            $table->index(['place_id', 'type', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_photos');
    }
};
