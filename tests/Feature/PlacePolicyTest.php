<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `PlacePolicy` (03 القسم 4.8): Admin ينشر ويخفي ويحذف، والـ Moderator يعدّل ويبعت للمراجعة.
 */
class PlacePolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function user(?string $role): User
    {
        $user = User::factory()->create();

        return $role === null ? $user : $user->assignRole($role);
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function abilities(): array
    {
        return [
            // الـ ability => [Admin، Moderator]
            'view' => ['view', true, true],
            'create' => ['create', true, true],
            'update' => ['update', true, true],
            'submit' => ['submit', true, true],
            'publish' => ['publish', true, false],
            'hide' => ['hide', true, false],
            'show' => ['show', true, false],
            'reject' => ['reject', true, false],
            'close' => ['close', true, false],
            'setTier' => ['setTier', true, false],
            'delete' => ['delete', true, false],
            'restore' => ['restore', true, false],
            'forceDelete' => ['forceDelete', false, false],
        ];
    }

    #[DataProvider('abilities')]
    public function test_the_matrix(string $ability, bool $admin, bool $moderator): void
    {
        $place = Place::factory()->create();
        $arguments = in_array($ability, ['create'], true) ? Place::class : $place;

        $this->assertSame($admin, $this->user('admin')->can($ability, $arguments), "admin {$ability}");
        $this->assertSame($moderator, $this->user('moderator')->can($ability, $arguments), "moderator {$ability}");
        $this->assertFalse($this->user(null)->can($ability, $arguments), "no role {$ability}");
        $this->assertFalse($this->user('owner')->can($ability, $arguments), "owner {$ability}");
    }

    public function test_the_list_level_abilities_follow_the_same_roles(): void
    {
        $admin = $this->user('admin');
        $moderator = $this->user('moderator');

        $this->assertTrue($admin->can('viewAny', Place::class));
        $this->assertTrue($moderator->can('viewAny', Place::class));
        $this->assertTrue($admin->can('deleteAny', Place::class));
        $this->assertFalse($moderator->can('deleteAny', Place::class));
        $this->assertTrue($admin->can('restoreAny', Place::class));
        $this->assertFalse($moderator->can('restoreAny', Place::class));
        $this->assertFalse($admin->can('forceDeleteAny', Place::class));
    }
}
