<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * Projects a validated browser diagnostic sample onto the context stored with a
 * `browser.sample` breadcrumb.
 *
 * Keys arrive bounded by App\Http\Requests\BrowserDiagnosticSampleRequest, so
 * this class owns only what is left before the sample becomes durable.
 */
final class BrowserDiagnosticSampleFormatter
{
    /**
     * @param  array<string, mixed>  $sample
     * @return array<string, mixed>
     */
    public function format(array $sample): array
    {
        $path = $this->urlPath($sample['url'] ?? null);

        return [
            'reason' => $sample['reason'] ?? 'unknown',
            'path' => $this->redactedUrlPath($path),
            'path_hash' => $path === null ? null : hash('xxh128', $path),
            'hidden' => (bool) ($sample['hidden'] ?? false),
            'focused' => (bool) ($sample['focused'] ?? false),
            'viewport' => $sample['viewport'] ?? null,
            'screen' => $sample['screen'] ?? null,
            'visibility' => $sample['visibility'] ?? null,
            'activity' => $sample['activity'] ?? null,
            'scroll' => $sample['scroll'] ?? null,
            'heap' => $sample['heap'] ?? null,
            'dom' => $sample['dom'] ?? null,
            'animations' => $this->animations($sample['animations'] ?? null),
            'navigation' => $sample['navigation'] ?? null,
            'poll' => $sample['poll'] ?? null,
            'timings' => $this->presentSections($sample['timings'] ?? null),
        ];
    }

    /**
     * Sections the renderer left out arrive as nulls, which are noise once the
     * sample is a log line.
     *
     * @return array<string, mixed>|null
     */
    private function presentSections(mixed $value): ?array
    {
        return is_array($value) ? Arr::whereNotNull($value) : null;
    }

    /** @return array<string, mixed>|null */
    private function animations(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $detailLimit = $this->boundedLimit('animation_detail_limit');

        $animations = $this->withoutEmptyCells([
            ...$value,
            'classSummary' => $this->animationRows($value['classSummary'] ?? null, $this->boundedLimit('animation_class_summary_limit')),
            'elementGroups' => $this->animationRows($value['elementGroups'] ?? null, $detailLimit),
            'elements' => $this->animationRows($value['elements'] ?? null, $detailLimit),
        ]);

        return $animations === [] ? null : $animations;
    }

    /**
     * A renderer that stops mid-frame can post far more animated rows than a
     * log line should carry, so each collection is capped and its empty cells
     * dropped.
     *
     * @return list<array<string, mixed>>|null
     */
    private function animationRows(mixed $rows, int $limit): ?array
    {
        if (! is_array($rows)) {
            return null;
        }

        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): array => $this->withoutEmptyCells($row))
            ->filter()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withoutEmptyCells(array $values): array
    {
        return array_filter($values, fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function boundedLimit(string $setting): int
    {
        return Number::clamp((int) config("rfa.diagnostics.{$setting}", 20), 0, 50);
    }

    private function redactedUrlPath(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $segments = explode('/', trim($path, '/'));

        if ($segments[0] === 'p' && isset($segments[1])) {
            $segments[1] = '{project}';
        }

        if (isset($segments[2], $segments[3])) {
            $segments[3] = match ($segments[2]) {
                'c' => '{hash}',
                'r', 'rw' => '{range}',
                default => $segments[3],
            };
        }

        return '/'.implode('/', array_filter($segments, fn (string $segment): bool => $segment !== ''));
    }

    /** The query string never reaches the log: it carries the CSRF token. */
    private function urlPath(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || ! Str::startsWith($path, '/')) {
            return null;
        }

        return Str::limit($path, 253);
    }
}
