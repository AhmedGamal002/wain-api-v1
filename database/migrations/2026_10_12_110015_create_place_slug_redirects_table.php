<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // الـ slug القديم بعد ما Admin يغيّره، عشان الروابط المتشاركة تفضل شغالة (03 القسم 7.7)
        Schema::create('place_slug_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('old_slug', 140)->unique();
            $table->foreignId('place_id')->constrained('places')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_slug_redirects');
    }
};
