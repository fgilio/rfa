<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\File;

/**
 * Learns which scripts a real page load compiles and re-compiles them into
 * opcache shared memory ahead of the first request of a launch.
 *
 * NativePHP starts a fresh PHP server on every launch, so its opcache starts
 * empty. The first request then pays for loading every framework class and
 * compiled Blade view (several hundred milliseconds, even from the opcache file
 * cache). The manifest written by {@see record()} lists the scripts earlier
 * page loads and Livewire updates needed. {@see warm()} compiles that list
 * while Electron is still finishing its own start-up, off the critical path
 * of the first window.
 *
 * The manifest is keyed by app version: an update ships new compiled views and
 * classes, so a stale list is discarded rather than trusted.
 */
final readonly class OpcacheWarmService
{
    /** Upper bound on manifest entries so the file cannot grow without limit. */
    public const int MAX_SCRIPTS = 6000;

    public function __construct(
        private OpcacheService $opcache,
    ) {}

    public function manifestPath(): string
    {
        return (string) config('rfa.opcache_warm_manifest_path');
    }

    public function version(): string
    {
        return (string) config('nativephp.version');
    }

    /**
     * Manifest scripts for the current app version, or an empty list when
     * there is no usable manifest.
     *
     * @return list<string>
     */
    public function manifestScripts(): array
    {
        $path = $this->manifestPath();

        if (! File::isFile($path)) {
            return [];
        }

        $decoded = json_decode((string) File::get($path), true);

        if (! is_array($decoded) || ($decoded['version'] ?? null) !== $this->version()) {
            return [];
        }

        $scripts = $decoded['scripts'] ?? [];

        if (! is_array($scripts)) {
            return [];
        }

        $isWarmable = $this->warmableFilter();

        return array_values(array_filter(
            $scripts,
            fn (mixed $script): bool => is_string($script) && $isWarmable($script),
        ));
    }

    /**
     * Merge the scripts the current request loaded into the manifest.
     *
     * @return array{total: int, added: int, written: bool}
     */
    public function record(): array
    {
        if (! $this->opcache->isEnabled()) {
            return ['total' => 0, 'added' => 0, 'written' => false];
        }

        $known = $this->manifestScripts();
        $current = array_values(array_filter($this->opcache->includedScripts(), $this->warmableFilter()));
        $merged = array_values(array_unique([...$known, ...$current]));
        $added = count($merged) - count($known);

        if ($added === 0) {
            return ['total' => count($known), 'added' => 0, 'written' => false];
        }

        $merged = array_slice($merged, 0, self::MAX_SCRIPTS);

        File::ensureDirectoryExists(dirname($this->manifestPath()));
        File::replace($this->manifestPath(), (string) json_encode([
            'version' => $this->version(),
            'scripts' => $merged,
        ], JSON_UNESCAPED_SLASHES));

        return ['total' => count($merged), 'added' => $added, 'written' => true];
    }

    /**
     * Compile every manifest script that is not already in shared memory.
     *
     * @return array{available: bool, compiled: int, cached: int, missing: int, failed: int}
     */
    public function warm(): array
    {
        $result = ['available' => $this->opcache->isEnabled(), 'compiled' => 0, 'cached' => 0, 'missing' => 0, 'failed' => 0];

        if (! $result['available']) {
            return $result;
        }

        foreach ($this->manifestScripts() as $script) {
            if ($this->opcache->isCached($script)) {
                $result['cached']++;

                continue;
            }

            if (! is_file($script)) {
                $result['missing']++;

                continue;
            }

            $this->opcache->compile($script) ? $result['compiled']++ : $result['failed']++;
        }

        return $result;
    }

    /**
     * Only scripts that belong to this install are worth replaying: the app
     * tree and the compiled views under the relocated storage path. Another
     * install sharing the same user data directory (a development build next
     * to the released app) must not have its files compiled here: compiling a
     * second copy of a file that declares functions is a fatal redeclaration.
     *
     * The prefixes are resolved once per call rather than per script, since
     * the filter runs over thousands of paths in the terminate phase.
     *
     * @return Closure(string): bool
     */
    private function warmableFilter(): Closure
    {
        $prefixes = [base_path().DIRECTORY_SEPARATOR, storage_path().DIRECTORY_SEPARATOR];

        return fn (string $script): bool => str_starts_with($script, $prefixes[0])
            || str_starts_with($script, $prefixes[1]);
    }
}
