<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\RemoteTarget;

/**
 * Composes web URLs from a parsed remote + RemoteTarget. GitLab prefixes
 * tree/commit/blob with `/-/`; line-range anchors are `#L10-L20` on GitHub
 * and `#L10-20` on GitLab.
 */
final class RemoteUrlBuilderService
{
    /**
     * @param  array{provider: string, host: string, owner: string, repo: string, webBaseUrl: string}  $remote
     */
    public function build(array $remote, RemoteTarget $target): ?string
    {
        $base = $remote['webBaseUrl'];
        $prefix = $remote['provider'] === 'gitlab' ? '/-' : '';

        return match ($target->type) {
            RemoteTarget::TYPE_REPO => $base,
            RemoteTarget::TYPE_BRANCH => $base.$prefix.'/tree/'.$this->encodeRef((string) $target->params['name']),
            RemoteTarget::TYPE_COMMIT => $base.$prefix.'/commit/'.rawurlencode((string) $target->params['sha']),
            RemoteTarget::TYPE_FILE => $base.$prefix.'/blob/'
                .$this->encodeRef((string) $target->params['ref']).'/'
                .$this->encodePath((string) $target->params['path']),
            RemoteTarget::TYPE_LINE => $base.$prefix.'/blob/'
                .$this->encodeRef((string) $target->params['ref']).'/'
                .$this->encodePath((string) $target->params['path'])
                .$this->lineAnchor($remote['provider'], (int) $target->params['start'], $target->params['end'] ?? null),
            default => null,
        };
    }

    /**
     * GitLab supports `/` inside branch names (e.g. `release/1.0`); both providers
     * resolve those as path segments, so we encode each segment and re-join with `/`.
     */
    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function encodeRef(string $ref): string
    {
        return $this->encodePath($ref);
    }

    private function lineAnchor(string $provider, int $start, ?int $end): string
    {
        if ($end === null || $end === $start) {
            return '#L'.$start;
        }

        return $provider === 'gitlab'
            ? '#L'.$start.'-'.$end
            : '#L'.$start.'-L'.$end;
    }
}
