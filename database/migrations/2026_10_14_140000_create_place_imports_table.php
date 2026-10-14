<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * استيراد CSV الأماكن (A10، 02 «استيراد وتصدير CSV»): كل رفع ملف سجل. السطور (المقروءة والمتحقق منها وحالتها
 * ونتيجة الاستيراد) في `rows` كـ JSON، لأنها حد أقصى 500 سطر وبتتقرا كلها مع بعض (المعاينة وملف الأخطاء)
 * ومبتتفلترش في SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_name');
            // previewed (اتحقق منه ومستني التأكيد) ← importing ← done أو failed
            $table->string('status', 20)->default('previewed');
            $table->unsignedSmallInteger('total_rows');
            $table->unsignedSmallInteger('valid_rows')->default(0);
            $table->unsignedSmallInteger('duplicate_rows')->default(0);
            $table->unsignedSmallInteger('error_rows')->default(0);
            $table->unsignedSmallInteger('imported_rows')->default(0);
            // المكرر المحتمل بيتخطّى لحد ما اللي بيأكّد يختار يستوردهم كمان
            $table->boolean('include_duplicates')->default(false);
            $table->longText('rows');
            // أعمدة في الملف مش من القالب (اتجاهلت)
            $table->json('ignored_columns')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_imports');
    }
};
