<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجل البحث (06 القسم 5): الموبايل بيبعت `log=1` بس لما المستخدم يدوس Enter أو يفتح نتيجة أو تثبت شاشة
 * «مفيش نتائج» لثانية، فمفيش صف لكل حرف. مصدر «أكتر عمليات بحث» و«بحث من غير نتائج» في A9 (جمال، أسبوع 12).
 * بيتمسح بعد 90 يوم (`search:prune-logs`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_logs', function (Blueprint $table) {
            $table->id();
            // كلمة البحث مطبَّعة (SearchNormalizer)، زي ما اتبحث بيها
            $table->string('query', 120);
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
            // العدد اللي المستخدم شافه (بعد التصحيح الإملائي لو اتقبل)
            $table->unsignedInteger('results_count');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('device_id', 36)->nullable();
            $table->timestamp('created_at')->useCurrent();

            // أكتر عمليات بحث / المسح
            $table->index('created_at');
            // بحث من غير نتائج (results_count = 0) في فترة
            $table->index(['results_count', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_logs');
    }
};
