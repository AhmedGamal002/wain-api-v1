<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * حسابات الأدمن من `config('wain.admins')` (اللي بتيجي من `.env`).
 *
 * الحساب اللي ناقصه إيميل أو باسورد بيتتخطى. لو الحساب موجود قبل كده بياخد دور `admin` بس، والباسورد
 * ما بيتغيّرش (إعادة التشغيل مش بتعمل reset لباسورد حد). بيشتغل على production.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        foreach ((array) config('wain.admins') as $index => $admin) {
            $email = strtolower(trim((string) ($admin['email'] ?? '')));
            $password = (string) ($admin['password'] ?? '');

            if ($email === '' || $password === '') {
                $this->command?->warn('Admin account #'.($index + 1).' skipped: WAIN_ADMIN_'.($index + 1).'_EMAIL / _PASSWORD not set.');

                continue;
            }

            $user = User::query()->where('email', $email)->first() ?? new User;

            if (! $user->exists) {
                $user->forceFill([
                    'name' => filled($admin['name'] ?? null) ? $admin['name'] : null,
                    'email' => $email,
                    'password' => $password,
                    'email_verified_at' => now(),
                ])->save();
            }

            $user->assignRole('admin');
        }
    }
}
