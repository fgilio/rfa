<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Collection;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Compiles every Blade template into the compiled-views directory the
 * running server reads from, without clearing it first.
 *
 * `view:cache` empties the directory before compiling, which a live
 * request can trip over. Blade itself writes each compiled file through
 * an atomic rename and skips templates whose output is unchanged, so
 * compiling in place is safe next to a serving PHP process.
 */
final readonly class CompileViewsAction
{
    public function __construct(
        private Factory $views,
        private BladeCompiler $compiler,
    ) {}

    /** @return array{paths: int, compiled: int} */
    public function handle(): array
    {
        $paths = $this->paths();

        $compiled = $paths
            ->flatMap(fn (string $path): Collection => $this->bladeFilesIn($path))
            ->each(fn (SplFileInfo $file) => $this->compiler->compile($file->getRealPath()))
            ->count();

        return ['paths' => $paths->count(), 'compiled' => $compiled];
    }

    /**
     * The view roots and namespace hints, with roots nested inside another
     * root dropped so no template is compiled twice.
     *
     * @return Collection<int, string>
     */
    private function paths(): Collection
    {
        $finder = $this->views->getFinder();

        if (! $finder instanceof FileViewFinder) {
            return collect();
        }

        $paths = collect($finder->getPaths())
            ->merge(collect($finder->getHints())->flatten())
            ->filter(fn (mixed $path): bool => is_string($path))
            ->unique()
            ->values();

        $directory = fn (string $path): string => rtrim(realpath($path) ?: $path, '/').'/';

        return $paths
            ->reject(fn (string $path): bool => $paths->contains(
                fn (string $existing): bool => $existing !== $path && str_starts_with($directory($path), $directory($existing)),
            ))
            ->values();
    }

    /** @return Collection<int, SplFileInfo> */
    private function bladeFilesIn(string $path): Collection
    {
        if (! is_dir($path)) {
            return collect();
        }

        return collect(iterator_to_array(
            Finder::create()->in($path)->exclude('vendor')->name('*.blade.php')->files(),
            false,
        ));
    }
}
