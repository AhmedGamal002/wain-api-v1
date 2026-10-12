<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * places.cover_photo_id ← place_photos.id مرجع دايري (place_photos.place_id → places)،
 * فالـ FK بيتضاف بعد ما الجدولين يتعملوا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->foreign('cover_photo_id')->references('id')->on('place_photos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->dropForeign(['cover_photo_id']);
        });
    }
};
