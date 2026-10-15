<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * التقييمات (F8، 06 القسم 2.3): تقييم واحد لكل مستخدم في المكان، بيدخل `pending` ويظهر بعد موافقة الفريق
     * (A6). تعديل تقييم منشور بيفضل ظاهر بقيمه القديمة و`pending_payload` فيه الجديد لحد الموافقة.
     *
     * `submitted_at` (مش في الـ spec الأصلي): آخر مرة التقييم أو تعديله دخل الطابور، وهو عمر العنصر في A6.
     */
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();
            // المستخدم المحذوف بيفضل صفه (بيتعرض «مستخدم محذوف») إلا لو اختار مسح تقييماته
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('body')->nullable();
            // أول يوم في الشهر
            $table->date('visited_at')->nullable();
            $table->enum('status', ['pending', 'published', 'rejected', 'hidden'])->default('pending');
            // سبب الرفض، أو `reports` لو اتخفى بسبب بلاغات
            $table->string('status_reason')->nullable();
            $table->enum('edit_status', ['none', 'pending', 'rejected'])->default('none');
            // التعديل المنتظر مراجعة: rating, body, visited_at, attributes (slugs), photo_ids
            $table->json('pending_payload')->nullable();
            $table->string('edit_reason')->nullable();
            // بلاغات مفتوحة. عند 3 بيتخفى مؤقتاً (F8، أسبوع 14)
            $table->unsignedSmallInteger('reports_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->timestamps();

            $table->unique(['place_id', 'user_id']);
            $table->index(['place_id', 'status', 'published_at'], 'reviews_place_listing_index');
            $table->index(['status', 'created_at'], 'reviews_status_created_index');
            $table->index(['status', 'submitted_at'], 'reviews_queue_index');
            $table->index(['edit_status', 'updated_at'], 'reviews_edit_queue_index');
        });

        Schema::create('review_attributes', function (Blueprint $table) {
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();

            $table->primary(['review_id', 'attribute_id']);
        });

        Schema::create('review_photos', function (Blueprint $table) {
            $table->id();
            // null لحد ما التقييم يتبعت. الصورة اللي ما اتربطتش بعد 24 ساعة بتتمسح (job)
            $table->foreignId('review_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // الأصل (disk خاص) ما بيطلعش في الـ API، والنسخ في `variants` بمسارات نسبية
            $table->string('path');
            $table->json('variants')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->enum('status', ['processing', 'ready', 'failed'])->default('processing');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index('review_id');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_photos');
        Schema::dropIfExists('review_attributes');
        Schema::dropIfExists('reviews');
    }
};
