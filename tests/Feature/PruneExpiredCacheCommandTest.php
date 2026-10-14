<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * `cache:prune-expired`: الـ file cache مبيمسحش الملف المنتهي غير لما حد يقراه، فالأمر بيمسح المنتهي كل ليلة.
 */
class PruneExpiredCacheCommandTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path('framework/testing/prune-'.uniqid());
        config(['cache.default' => 'file', 'cache.stores.file.path' => $this->path]);
        Cache::purge();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Cache::purge();
        (new Filesystem)->deleteDirectory($this->path);

        parent::tearDown();
    }

    private function fileCount(): int
    {
        return is_dir($this->path) ? count((new Filesystem)->allFiles($this->path)) : 0;
    }

    public function test_it_deletes_expired_entries_and_keeps_the_valid_and_the_forever_ones(): void
    {
        Cache::put('short', 'a', 30);
        Cache::put('long', 'b', 3600);
        Cache::forever('forever', 'c');

        $this->assertSame(3, $this->fileCount());

        $this->travel(2)->minutes();

        $this->artisan('cache:prune-expired')
            ->expectsOutputToContain('Deleted 1 expired cache files, kept 2')
            ->assertSuccessful();

        $this->assertSame(2, $this->fileCount());
        $this->assertSame('b', Cache::get('long'));
        $this->assertSame('c', Cache::get('forever'));
        $this->assertNull(Cache::get('short'));
    }

    public function test_it_is_a_no_op_without_a_cache_directory(): void
    {
        $this->artisan('cache:prune-expired')->assertSuccessful();
        $this->assertSame(0, $this->fileCount());
    }
}
