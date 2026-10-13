<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminSeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_seeder_creates_the_three_roles_and_is_idempotent(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->assertEqualsCanonicalizing(['admin', 'moderator', 'owner'], Role::query()->pluck('name')->all());
    }

    public function test_admin_accounts_are_created_from_config_with_the_admin_role(): void
    {
        config(['wain.admins' => [
            ['name' => 'Gamal', 'email' => 'Gamal@Example.test', 'password' => 'a-strong-test-pass-1'],
            ['name' => 'Maaloum', 'email' => 'maaloum@example.test', 'password' => 'a-strong-test-pass-2'],
        ]]);

        $this->seed(AdminUserSeeder::class);

        $this->assertSame(2, User::role('admin')->count());

        $gamal = User::query()->where('email', 'gamal@example.test')->firstOrFail();
        $this->assertSame('Gamal', $gamal->name);
        $this->assertNotNull($gamal->email_verified_at);
        $this->assertSame('active', $gamal->status);
        $this->assertTrue(Hash::check('a-strong-test-pass-1', $gamal->password));
        $this->assertTrue($gamal->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_accounts_without_email_or_password_are_skipped(): void
    {
        config(['wain.admins' => [
            ['name' => 'No password', 'email' => 'nopass@example.test', 'password' => null],
            ['name' => 'No email', 'email' => '', 'password' => 'a-strong-test-pass-1'],
        ]]);

        $this->seed(AdminUserSeeder::class);

        $this->assertSame(0, User::query()->count());
    }

    public function test_rerun_keeps_the_existing_password(): void
    {
        config(['wain.admins' => [
            ['name' => 'Gamal', 'email' => 'gamal@example.test', 'password' => 'first-test-pass-1'],
        ]]);
        $this->seed(AdminUserSeeder::class);

        config(['wain.admins' => [
            ['name' => 'Gamal', 'email' => 'gamal@example.test', 'password' => 'second-test-pass-2'],
        ]]);
        $this->seed(AdminUserSeeder::class);

        $user = User::query()->where('email', 'gamal@example.test')->firstOrFail();
        $this->assertSame(1, User::query()->count());
        $this->assertTrue(Hash::check('first-test-pass-1', $user->password));
        $this->assertTrue($user->hasRole('admin'));
    }

    public function test_an_existing_regular_user_is_promoted_without_touching_the_password(): void
    {
        $existing = User::factory()->create(['email' => 'maaloum@example.test', 'password' => 'original-pass-123']);

        config(['wain.admins' => [
            ['name' => 'Maaloum', 'email' => 'maaloum@example.test', 'password' => 'a-strong-test-pass-2'],
        ]]);
        $this->seed(AdminUserSeeder::class);

        $existing->refresh();
        $this->assertTrue($existing->hasRole('admin'));
        $this->assertTrue(Hash::check('original-pass-123', $existing->password));
    }
}
