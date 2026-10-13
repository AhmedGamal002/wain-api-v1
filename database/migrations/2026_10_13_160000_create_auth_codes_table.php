<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أكواد نسيت الباسورد وتأكيد الإيميل (06 القسم 2.1). الكود 6 أرقام بيتخزّن hash وبيعيش 15 دقيقة ولـ5 محاولات،
 * و«ابعت تاني» بعد 60 ثانية. بعد الكود الصح (للباسورد بس) بيتعمل reset_token لمرة واحدة صالح 10 دقايق.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_codes', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['password_reset', 'email_verify']);
            $table->string('email');
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at');
            $table->dateTime('last_sent_at');
            // بيتملى بعد الكود الصح (نسيت الباسورد بس)، وبيتمسح بعد الاستخدام
            $table->string('reset_token_hash')->nullable();
            $table->dateTime('reset_token_expires_at')->nullable();
            $table->dateTime('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'email']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_codes');
    }
};
