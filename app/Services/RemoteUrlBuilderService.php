<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\RemoteTarget;

/**
 * Builds an https URL for a parsed remote + RemoteTarget, honouring each
 * provider's URL conventions (GitLab prefixes with `/-/`, GitHub does not;
 * both use the same `#L10` / `#L10-L20` line-anchor format).
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

    /** Preserve `/` in nested paths; encode every other segment component. */
    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * Encode branch / tag / sha refs. GitLab allows `/` in branch names
     * (e.g. `release/1.0`); both providers resolve these as path segments.
     */
    private function encodeRef(string $ref): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $ref)));
    }

    private function lineAnchor(string $provider, int $start, ?int $end): string
    {
        if ($end === null || $end === $start) {
            return '#L'.$start;
        }

        // GitHub: #L10-L20. GitLab: #L10-20.
        return $provider === 'gitlab'
            ? '#L'.$start.'-'.$end
            : '#L'.$start.'-L'.$end;
    }
}
