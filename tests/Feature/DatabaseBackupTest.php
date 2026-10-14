<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DatabaseBackup;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * النسخ الاحتياطي اليومي لقاعدة البيانات (`backup:database`): الرفع على disk `backups` (R2 في الاستضافة)
 * والتحقق من الحجم ومسح القديم وإبلاغ الأدمنز لو فشل. الـ dump الفعلي (mariadb-dump) بيتبدّل بـ closure.
 */
class DatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['wain.backup' => [
            'disk' => 'backups', 'prefix' => 'wain/staging', 'keep_days' => 30, 'min_keep' => 3,
            'dump_binary' => 'mariadb-dump', 'min_bytes' => 100,
        ]]);
        Storage::fake('backups');
    }

    /** الـ dump المزيّف: ملف gzip بحجم معقول (محتوى عشوائي عشان الضغط ما يصغّرهوش تحت الحد الأدنى). */
    private function fakeDump(string $marker = 'INSERT INTO places VALUES (1);'): \Closure
    {
        return fn (string $target) => file_put_contents($target, gzencode($marker.'
'.bin2hex(random_bytes(300))));
    }

    public function test_it_uploads_a_gzip_dump_under_the_dated_prefix(): void
    {
        $backup = new DatabaseBackup($this->fakeDump());

        $result = $backup->run(Carbon::parse('2026-11-22 03:10:00', 'UTC'));

        $this->assertSame('wain/staging/db/2026/11/wain-20261122-031000.sql.gz', $result['path']);
        Storage::disk('backups')->assertExists($result['path']);
        $this->assertSame($result['bytes'], Storage::disk('backups')->size($result['path']));
        $this->assertStringContainsString('INSERT INTO places', (string) gzdecode(Storage::disk('backups')->get($result['path'])));
        $this->assertSame(0, $result['pruned']);
    }

    public function test_a_tiny_dump_is_refused_and_nothing_is_uploaded(): void
    {
        $backup = new DatabaseBackup(fn (string $target) => file_put_contents($target, 'x'));

        try {
            $backup->run();
            $this->fail('النسخة الفاضية المفروض تترفض');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('صغيرة', $e->getMessage());
        }

        $this->assertSame([], Storage::disk('backups')->allFiles());
    }

    public function test_old_copies_are_pruned_but_the_newest_few_always_stay(): void
    {
        $disk = Storage::disk('backups');
        $now = now('UTC');

        // خمس نسخ أقدم من 30 يوم (أعمار 31 لـ 35 يوم) ونسخة حديثة (أمس)
        foreach ([31, 32, 33, 34, 35] as $age) {
            $path = "wain/staging/db/old/wain-{$age}.sql.gz";
            $disk->put($path, 'old');
            touch($disk->path($path), $now->copy()->subDays($age)->getTimestamp());
        }
        $disk->put('wain/staging/db/recent/wain-1.sql.gz', 'recent');
        touch($disk->path('wain/staging/db/recent/wain-1.sql.gz'), $now->copy()->subDay()->getTimestamp());

        $result = (new DatabaseBackup($this->fakeDump()))->run($now);

        // النسخة الجديدة + أمس + أحدث نسخة قديمة (31) = 3 باقيين، والباقي (32 و33 و34 و35) اتمسح
        $this->assertSame(4, $result['pruned']);
        $this->assertEqualsCanonicalizing([
            (new DatabaseBackup)->path($now),
            'wain/staging/db/recent/wain-1.sql.gz',
            'wain/staging/db/old/wain-31.sql.gz',
        ], $disk->allFiles());
    }

    public function test_the_other_environment_prefix_is_never_touched(): void
    {
        $disk = Storage::disk('backups');
        $disk->put('wain/production/db/2025/01/wain-ancient.sql.gz', 'prod');
        touch($disk->path('wain/production/db/2025/01/wain-ancient.sql.gz'), 1);

        (new DatabaseBackup($this->fakeDump()))->run();

        $disk->assertExists('wain/production/db/2025/01/wain-ancient.sql.gz');
    }

    public function test_the_command_reports_success(): void
    {
        $this->app->bind(DatabaseBackup::class, fn () => new DatabaseBackup($this->fakeDump()));

        $this->artisan('backup:database')
            ->expectsOutputToContain('اتعملت نسخة')
            ->assertSuccessful();

        $this->assertCount(1, Storage::disk('backups')->allFiles());
    }

    public function test_a_failing_dump_exits_with_an_error_and_notifies_the_admins(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create()->assignRole('admin');
        $moderator = User::factory()->create()->assignRole('moderator');

        $this->app->bind(DatabaseBackup::class, fn () => new DatabaseBackup(
            fn (string $target) => throw new RuntimeException('Access denied for user'),
        ));

        $this->artisan('backup:database')->assertFailed();

        $this->assertSame([], Storage::disk('backups')->allFiles());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $admin->id)->count());
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $moderator->id)->count());
    }
}
