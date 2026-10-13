<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * الأدوار (06 القسم 6): `admin` و`moderator` فريق اللوحة، و`owner` لصاحب المكان بعد قبول طلب الملكية (F13).
 * المستخدم العادي من غير دور.
 *
 * الأدوار بس من غير permissions منفصلة: مصفوفة الصلاحيات (02 «مصفوفة الصلاحيات») فيها دورين واضحين،
 * والـ Policies بتحكم بالدور (مثلاً PlacePolicy في أسبوع 4). بيشتغل على production وبيتعاد من غير ضرر.
 */
class RoleSeeder extends Seeder
{
    public const ROLES = ['admin', 'moderator', 'owner'];

    public function run(): void
    {
        foreach (self::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }

        // الـ seeder بيشتغل من غير model events، فمسح cache الأدوار صريح
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
