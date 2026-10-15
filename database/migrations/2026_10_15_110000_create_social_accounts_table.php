<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * حسابات Google وApple المربوطة بمستخدم (06 القسم 2.1). بتحدد «طريقة التسجيل» في شاشة المستخدمين (A7)،
     * وتسجيل الدخول نفسه (`POST /auth/social/{provider}`) بيتضاف في أسبوع 14.
     */
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('provider', ['google', 'apple']);
            $table->string('provider_user_id', 191);
            $table->string('email')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['provider', 'provider_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
