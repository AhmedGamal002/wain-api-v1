<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `submitted_at`: آخر مرة المكان دخل فيها «قيد المراجعة» (A6). طابور المراجعة بيعرض عمر كل عنصر وبيلوّن اللي
     * عدّى 48 ساعة، و`updated_at` ما ينفعش لإن أي تعديل بيغيّره. بيتملى من `PlaceObserver` لما الحالة تبقى
     * `pending`، وبيفضل زي ما هو بعد القرار (تاريخ آخر إرسال).
     */
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('published_at');
            // الطابور بيجيب `status = pending` بالأقدم أول
            $table->index(['status', 'submitted_at'], 'places_review_queue_index');
        });

        // اللي قيد المراجعة دلوقتي: آخر تعديل هو أقرب تقدير للإرسال
        DB::table('places')->where('status', 'pending')->update(['submitted_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->dropIndex('places_review_queue_index');
            $table->dropColumn('submitted_at');
        });
    }
};
