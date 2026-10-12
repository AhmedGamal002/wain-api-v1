<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // من 1 لـ 3 تصنيفات لكل مكان، والتصنيف الرئيسي لازم يكون منهم (القاعدة في PlacePublishCheck)
        Schema::create('place_category', function (Blueprint $table) {
            $table->foreignId('place_id')->constrained('places')->cascadeOnDelete();
            // التصنيف اللي فيه أماكن ما يتمسحش، بيتخفى بس
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();

            $table->primary(['place_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_category');
    }
};
