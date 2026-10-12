<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    private function panel()
    {
        return Filament::getPanel('admin');
    }

    public function test_guest_is_redirected_to_panel_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_plain_user_without_role_cannot_access_panel(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->canAccessPanel($this->panel()));
        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_owner_role_cannot_access_panel(): void
    {
        Role::findOrCreate('owner', 'web');
        $user = User::factory()->create()->assignRole('owner');

        $this->assertFalse($user->canAccessPanel($this->panel()));
    }

    public function test_admin_and_moderator_can_access_panel(): void
    {
        foreach (['admin', 'moderator'] as $role) {
            Role::findOrCreate($role, 'web');
            $user = User::factory()->create()->assignRole($role);

            $this->assertTrue($user->canAccessPanel($this->panel()), "$role should access the panel");
        }
    }

    public function test_suspended_team_member_cannot_access_panel(): void
    {
        Role::findOrCreate('moderator', 'web');
        $user = User::factory()->create()->assignRole('moderator');
        $this->assertTrue($user->canAccessPanel($this->panel()));

        $user->forceFill(['status' => 'suspended'])->save();

        $this->assertFalse($user->fresh()->canAccessPanel($this->panel()));
    }
}
