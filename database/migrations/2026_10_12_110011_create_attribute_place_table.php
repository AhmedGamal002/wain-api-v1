<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_place', function (Blueprint $table) {
            $table->foreignId('place_id')->constrained('places')->cascadeOnDelete();
            // الخاصية المستخدمة ما تتمسحش، بتتخفى بس
            $table->foreignId('attribute_id')->constrained('attributes')->restrictOnDelete();

            $table->primary(['place_id', 'attribute_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_place');
    }
};
