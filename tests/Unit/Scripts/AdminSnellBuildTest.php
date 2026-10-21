<?php

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class AdminSnellBuildTest extends TestCase
{
    private string $directory;
    private string $bundle;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $bundles = glob($root . '/public/assets/admin/assets/index-*.js');
        if (count($bundles) !== 1) {
            $this->markTestSkipped('Initialize the pinned public/assets/admin submodule to verify its build.');
        }
        $this->directory = sys_get_temp_dir() . '/xboard-admin-build-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/assets', 0700, true);
        $this->bundle = $this->directory . '/assets/' . basename($bundles[0]);
        copy($bundles[0], $this->bundle);
        foreach (['index.html', 'manifest.json'] as $name) {
            copy($root . '/public/assets/admin/' . $name, $this->directory . '/' . $name);
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->directory)) {
            return;
        }
        foreach (glob($this->directory . '/assets/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory . '/assets');
        foreach (glob($this->directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function test_build_updates_content_hash_references_and_is_idempotent(): void
    {
        $oldName = basename($this->bundle);
        $build = $this->patch($this->bundle);
        $this->assertTrue($build->isSuccessful(), $build->getErrorOutput());
        $bundles = glob($this->directory . '/assets/index-*.js');
        $this->assertCount(1, $bundles);
        $bundle = $bundles[0];
        $newName = 'index-' . substr(hash_file('sha256', $bundle), 0, 10) . '.js';
        $this->assertSame($newName, basename($bundle));
        $this->assertNotSame($oldName, $newName);
        foreach (['index.html', 'manifest.json'] as $name) {
            $contents = file_get_contents($this->directory . '/' . $name);
            $this->assertStringContainsString($newName, $contents);
            $this->assertStringNotContainsString($oldName, $contents);
        }
        $hash = hash_file('sha256', $bundle);
        $secondBuild = $this->patch($bundle);
        $this->assertTrue($secondBuild->isSuccessful(), $secondBuild->getErrorOutput());
        $this->assertSame($hash, hash_file('sha256', $bundle));
        $this->assertStringContainsString('already patched', $secondBuild->getOutput());
    }

    public function test_build_refuses_to_silently_keep_a_legacy_snell_form(): void
    {
        file_put_contents($this->bundle, 'e.Snell="snell";/* legacy form */');
        $before = file_get_contents($this->bundle);
        $build = $this->patch($this->bundle);
        $this->assertFalse($build->isSuccessful());
        $this->assertStringContainsString('restore the pinned admin submodule', $build->getErrorOutput());
        $this->assertSame($before, file_get_contents($this->bundle));
    }

    private function patch(string $bundle): Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/scripts/patch-admin-snell.php', $bundle]);
        $process->run();
        return $process;
    }
}
