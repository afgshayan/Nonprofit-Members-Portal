<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ZipArchive;

class UpdateController extends Controller
{
    private const CACHE_KEY = 'update_check_result';

    /** Paths that must NEVER be overwritten during an update */
    private const PROTECTED = [
        '.env',
        'storage/',
        'bootstrap/cache/',
        '.git/',
        'node_modules/',
        'public/install/',
    ];

    public function __construct()
    {
        if (!auth()->user()->isAdmin()) {
            abort(403, 'Only administrators can manage updates.');
        }
    }

    // -------------------------------------------------------------------------
    // Show update page
    // -------------------------------------------------------------------------

    public function index()
    {
        $localVersion = $this->getLocalVersion();
        $updateInfo   = Cache::get(self::CACHE_KEY);

        return view('update.index', compact('localVersion', 'updateInfo'));
    }

    // -------------------------------------------------------------------------
    // Force a fresh version check (called via AJAX or page button)
    // -------------------------------------------------------------------------

    public function check()
    {
        Cache::forget(self::CACHE_KEY);
        $result = $this->fetchRemoteVersion();

        if ($result) {
            $days = (int) config('update.cache_days', 7);
            Cache::put(self::CACHE_KEY, $result, now()->addDays($days));
        }

        return response()->json($result ?? ['error' => 'Could not reach update server.']);
    }

    // -------------------------------------------------------------------------
    // Perform the update
    // -------------------------------------------------------------------------

    public function doUpdate()
    {
        // Re-check remote before doing anything
        $remote = $this->fetchRemoteVersion();

        if (!$remote || empty($remote['has_update'])) {
            return back()->with('info', 'No update available or update server unreachable.');
        }

        $repo   = config('update.repo');
        $branch = config('update.branch', 'main');
        $zipUrl = "https://github.com/{$repo}/archive/refs/heads/{$branch}.zip";

        // ── 1. Download ZIP ───────────────────────────────────────────────────
        $tmpZip = storage_path('app/update_tmp.zip');
        $tmpDir = storage_path('app/update_extracted');

        try {
            $response = Http::timeout(180)
                ->withOptions(['verify' => false])
                ->get($zipUrl);

            if (!$response->successful()) {
                return back()->withErrors(['update' => 'Download failed. HTTP status: ' . $response->status()]);
            }

            file_put_contents($tmpZip, $response->body());
        } catch (\Throwable $e) {
            return back()->withErrors(['update' => 'Download error: ' . $e->getMessage()]);
        }

        // ── 2. Extract ZIP ────────────────────────────────────────────────────
        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            @unlink($tmpZip);
            return back()->withErrors(['update' => 'Could not open the downloaded ZIP file.']);
        }

        $this->rmdirRecursive($tmpDir);
        @mkdir($tmpDir, 0755, true);
        $zip->extractTo($tmpDir);
        $zip->close();
        @unlink($tmpZip);

        // ── 3. Locate the top-level folder inside the ZIP (e.g. "aelso-main/")
        $topDirs = glob($tmpDir . '/*', GLOB_ONLYDIR);
        if (empty($topDirs)) {
            $this->rmdirRecursive($tmpDir);
            return back()->withErrors(['update' => 'Unexpected ZIP structure — no top-level folder found.']);
        }

        $extractedRoot = rtrim($topDirs[0], '/\\') . DIRECTORY_SEPARATOR;

        // ── 4. Copy files (skipping protected paths) ──────────────────────────
        $this->copyDirectory($extractedRoot, base_path() . DIRECTORY_SEPARATOR);

        // ── 5. Cleanup temp directory ─────────────────────────────────────────
        $this->rmdirRecursive($tmpDir);

        // ── 6. Update local version.json ──────────────────────────────────────
        if (isset($remote['version'])) {
            file_put_contents(base_path('version.json'), json_encode([
                'version'   => $remote['version'],
                'changelog' => $remote['changelog'] ?? '',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        // ── 7. Run DB migrations ──────────────────────────────────────────────
        try {
            Artisan::call('migrate', ['--force' => true]);
        } catch (\Throwable) {}

        // ── 8. Clear caches ───────────────────────────────────────────────────
        foreach (['config:clear', 'cache:clear', 'view:clear', 'route:clear'] as $cmd) {
            try { Artisan::call($cmd); } catch (\Throwable) {}
        }

        Cache::forget(self::CACHE_KEY);

        $newVersion = $remote['version'] ?? 'latest';

        return redirect()->route('persons.index')
            ->with('success', "Successfully updated to version {$newVersion}!");
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function getLocalVersion(): string
    {
        try {
            $data = json_decode(file_get_contents(base_path('version.json')), true);
            return $data['version'] ?? '0.0.0';
        } catch (\Throwable) {
            return '0.0.0';
        }
    }

    private function fetchRemoteVersion(): ?array
    {
        $repo   = config('update.repo');
        $branch = config('update.branch', 'main');
        $url    = "https://raw.githubusercontent.com/{$repo}/{$branch}/version.json";

        try {
            $response = Http::timeout(10)->withOptions(['verify' => false])->get($url);

            if (!$response->successful()) return null;

            $data = $response->json();
            if (!isset($data['version'])) return null;

            $local              = $this->getLocalVersion();
            $data['has_update'] = version_compare($data['version'], $local, '>');
            $data['local']      = $local;
            $data['checked_at'] = now()->toDateTimeString();

            return $data;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Recursively copy $src directory into $dst, skipping protected paths.
     */
    private function copyDirectory(string $src, string $dst): void
    {
        $src = rtrim($src, '/\\') . DIRECTORY_SEPARATOR;
        $dst = rtrim($dst, '/\\') . DIRECTORY_SEPARATOR;

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iter as $item) {
            $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($src)));

            if ($this->isProtected($rel)) continue;

            $target = $dst . $rel;

            if ($item->isDir()) {
                @mkdir($target, 0755, true);
            } else {
                @mkdir(dirname($target), 0755, true);
                @copy($item->getPathname(), $target);
            }
        }
    }

    private function isProtected(string $rel): bool
    {
        foreach (self::PROTECTED as $p) {
            $p = ltrim($p, '/');
            if ($rel === $p || str_starts_with($rel, $p)) {
                return true;
            }
        }
        return false;
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) return;

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iter as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }

        @rmdir($dir);
    }
}
