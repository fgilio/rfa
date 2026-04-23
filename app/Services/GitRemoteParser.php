<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Parses `git remote` URLs into a provider, host, owner, and repo name so we
 * can build web URLs for GitHub / GitLab (and recognise self-hosted instances
 * of either by hostname substring).
 *
 * Accepts:
 *   - SSH:          git@github.com:owner/repo(.git)
 *   - SSH URL:      ssh://git@host[:port]/owner/repo(.git)
 *   - HTTPS / HTTP: https://host/owner/repo(.git)
 *   - git protocol: git://host/owner/repo(.git)
 *
 * Returns null if the URL cannot be parsed or the provider is not recognised.
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

        // Strip trailing `.git` and trailing slash.
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
        // SCP-style SSH: git@host:owner/repo.git
        if (preg_match('#^(?:[^@\s]+@)?([^:\s/]+):([^\s]+)$#', $remoteUrl, $m) === 1 && ! str_contains($m[1], '/')) {
            // Reject matches that are really URLs (contain "://") via the parts already,
            // but be defensive: if the "path" begins with "//", drop through to url parsing.
            if (! str_starts_with($m[2], '/')) {
                return [strtolower($m[1]), ltrim($m[2], '/')];
            }
        }

        // URL-style: ssh://, https://, http://, git://
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
