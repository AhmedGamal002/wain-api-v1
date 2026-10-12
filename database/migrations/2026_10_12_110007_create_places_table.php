<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرجع: 03-Feature-Places.md القسم 2.
 * الأعمدة «للنشر» nullable هنا وإجبارية وقت النشر بس (PlacePublishCheck).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('places', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 140)->unique();
            $table->string('name_ar', 120);
            $table->string('name_en', 120);
            // قائمة كلمات بحث إضافية (فرانكو وأخطاء شائعة)، لحد 10
            $table->json('search_aliases')->nullable();
            // نص مطبَّع بيبنيه PlaceObserver، ومش بيطلع في أي response
            $table->text('search_text')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();

            $table->foreignId('primary_category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('city_id')->constrained('cities')->restrictOnDelete();
            $table->foreignId('area_id')->constrained('areas')->restrictOnDelete();

            $table->string('address_ar', 255);
            $table->string('address_en', 255)->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            // POINT(lng lat) من غير SRID، ومن PlaceLocation helper بس
            $table->geometry('location', 'point');
            $table->string('google_maps_url', 500)->nullable();

            // بصيغة دولية +20…
            $table->string('phone', 20)->nullable();
            $table->string('whatsapp', 20)->nullable();
            // اسم الحساب بس
            $table->string('instagram', 60)->nullable();
            $table->string('facebook_url', 255)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('menu_url', 500)->nullable();

            // بالجنيه للفرد
            $table->unsignedSmallInteger('price_min')->nullable();
            $table->unsignedSmallInteger('price_max')->nullable();
            // محسوب من price_max وحدود settings
            $table->enum('price_range', ['budget', 'mid', 'premium'])->nullable();
            $table->json('opening_hours')->nullable();
            $table->json('ramadan_hours')->nullable();
            // FK بيتضاف بعد place_photos (مرجع دايري)
            $table->unsignedBigInteger('cover_photo_id')->nullable();

            $table->enum('status', ['draft', 'pending', 'active', 'hidden', 'rejected', 'closed'])->default('draft');
            $table->string('status_reason', 255)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->date('closed_at')->nullable();

            $table->enum('listing_tier', ['free', 'featured', 'premium'])->default('free');
            // 1 premium، 2 featured، 3 free: للترتيب من غير CASE
            $table->unsignedTinyInteger('tier_rank')->default(3);
            $table->dateTime('tier_starts_at')->nullable();
            $table->dateTime('tier_ends_at')->nullable();

            // «آخر تحديث للبيانات»
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            // ما يطلعش في الـ API أبداً
            $table->text('internal_notes')->nullable();
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // القوائم
            $table->index(['status', 'area_id', 'tier_rank', 'rating_avg'], 'places_list_index');
            // الأحدث
            $table->index(['status', 'published_at'], 'places_status_published_index');
            // القريب والخريطة
            $table->spatialIndex('location');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('places');
    }
};
