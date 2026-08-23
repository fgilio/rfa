<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Projects a validated browser diagnostic sample onto the context stored with a
 * `browser.sample` breadcrumb.
 *
 * Keys arrive bounded by App\Http\Requests\BrowserDiagnosticSampleRequest, so
 * this class owns what is left before the sample becomes durable: the URL
 * reduced to a redacted path plus a hash, animation detail capped to the
 * configured limits, and empty sections dropped.
 */
final class BrowserDiagnosticSampleFormatter
{
    /**
     * @param  array<string, mixed>  $sample
     * @return array<string, mixed>
     */
    public function format(array $sample): array
    {
        return [
            'reason' => $sample['reason'] ?? 'unknown',
            'path' => $this->redactedUrlPath($sample['url'] ?? null),
            'path_hash' => $this->urlPathHash($sample['url'] ?? null),
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
        if (! is_array($value)) {
            return null;
        }

        return array_filter($value, fn (mixed $section): bool => $section !== null);
    }

    /** @return array<string, mixed>|null */
    private function animations(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $detailLimit = $this->boundedLimit('animation_detail_limit');

        $animations = array_filter([
            ...$value,
            'classSummary' => $this->animationRows($value['classSummary'] ?? null, $this->boundedLimit('animation_class_summary_limit')),
            'elementGroups' => $this->animationRows($value['elementGroups'] ?? null, $detailLimit),
            'elements' => $this->animationRows($value['elements'] ?? null, $detailLimit),
        ], fn (mixed $item): bool => $item !== null && $item !== []);

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
            ->map(fn (array $row): array => array_filter(
                $row,
                fn (mixed $item): bool => $item !== null && $item !== [],
            ))
            ->filter()
            ->take($limit)
            ->values()
            ->all();
    }

    private function boundedLimit(string $setting): int
    {
        return max(0, min(50, (int) config("rfa.diagnostics.{$setting}", 20)));
    }

    private function redactedUrlPath(mixed $url): ?string
    {
        $path = $this->urlPath($url);

        if ($path === null) {
            return null;
        }

        $segments = explode('/', trim($path, '/'));

        if ($segments[0] === 'p' && isset($segments[1])) {
            $segments[1] = '{project}';
        }

        if (isset($segments[2], $segments[3]) && $segments[2] === 'c') {
            $segments[3] = '{hash}';
        }

        if (isset($segments[2], $segments[3]) && in_array($segments[2], ['r', 'rw'], true)) {
            $segments[3] = '{range}';
        }

        return '/'.implode('/', array_filter($segments, fn (string $segment): bool => $segment !== ''));
    }

    private function urlPathHash(mixed $url): ?string
    {
        $path = $this->urlPath($url);

        return $path === null ? null : hash('xxh128', $path);
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
