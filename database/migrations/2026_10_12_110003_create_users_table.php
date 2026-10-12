<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // null بعد المسح النهائي للبيانات الشخصية (حذف الحساب)
            $table->string('name', 50)->nullable();
            // lowercase، وبعد المسح النهائي بيبقى deleted-{id}@wain.invalid
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            // null لحسابات Google/Apple من غير باسورد
            $table->string('password')->nullable();
            $table->string('avatar_path')->nullable();
            $table->enum('locale', ['ar', 'en'])->default('ar');
            $table->foreignId('default_area_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->enum('status', ['active', 'suspended'])->default('active');
            $table->string('suspended_reason')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('terms_version', 20)->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            // بيتحدّث مرة في اليوم على الأكتر
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('anonymized_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            // وقت طلب حذف الحساب
            $table->softDeletes();

            $table->index('status');
            $table->index('last_active_at');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // جلسات لوحة Filament (الـ API بيستخدم Sanctum tokens مش sessions)
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
