<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Parses git remote URLs (scp-style SSH, ssh://, https://, git://) and
 * recognises GitHub / GitLab (including self-hosted) by hostname substring.
 * Returns null for unrecognised providers or malformed input.
 */
final class GitRemoteParser
{
    /**
     * @return array{provider: string, host: string, owner: string, repo: string, webBaseUrl: string}|null
     */
    public function parse(string $remoteUrl): ?array
    {
        $remoteUrl = trim($remoteUrl);
        if ($remoteUrl === '') {
            return null;
        }

        [$host, $ownerRepo] = $this->extractHostAndPath($remoteUrl) ?? [null, null];
        if ($host === null || $ownerRepo === null || $ownerRepo === '') {
            return null;
        }

        $ownerRepo = preg_replace('/\.git$/i', '', $ownerRepo) ?? $ownerRepo;
        $ownerRepo = rtrim($ownerRepo, '/');

        $lastSlash = strrpos($ownerRepo, '/');
        if ($lastSlash === false) {
            return null;
        }

        $owner = substr($ownerRepo, 0, $lastSlash);
        $repo = substr($ownerRepo, $lastSlash + 1);

        if ($owner === '' || $repo === '') {
            return null;
        }

        $provider = $this->detectProvider($host);
        if ($provider === null) {
            return null;
        }

        return [
            'provider' => $provider,
            'host' => $host,
            'owner' => $owner,
            'repo' => $repo,
            'webBaseUrl' => "https://{$host}/{$owner}/{$repo}",
        ];
    }

    /**
     * @return array{0: string, 1: string}|null [host, ownerAndRepoPath]
     */
    private function extractHostAndPath(string $remoteUrl): ?array
    {
        // SCP-style: `git@host:owner/repo.git`. Defer to parse_url() if the path starts
        // with `/`, otherwise this regex would mis-claim true URLs.
        if (preg_match('#^(?:[^@\s]+@)?([^:\s/]+):([^\s]+)$#', $remoteUrl, $m) === 1
            && ! str_contains($m[1], '/')
            && ! str_starts_with($m[2], '/')
        ) {
            return [strtolower($m[1]), ltrim($m[2], '/')];
        }

        $parts = parse_url($remoteUrl);
        if (! is_array($parts) || ! isset($parts['host'], $parts['path'])) {
            return null;
        }

        return [strtolower((string) $parts['host']), ltrim((string) $parts['path'], '/')];
    }

    private function detectProvider(string $host): ?string
    {
        if (str_contains($host, 'github')) {
            return 'github';
        }
        if (str_contains($host, 'gitlab')) {
            return 'gitlab';
        }

        return null;
    }
}
