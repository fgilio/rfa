<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\FileSourceSpec;
use App\DTOs\SourceText;

class FileSourceService
{
    public function __construct(
        private readonly GitFileContentService $gitFileContentService,
        ?ReviewConfigService $reviewConfigService = null,
    ) {
        $this->reviewConfigService = $reviewConfigService ?? new ReviewConfigService;
    }

    private readonly ReviewConfigService $reviewConfigService;

    public function fetch(string $repoPath, FileSourceSpec $source, ?int $maxBytes = null): SourceText
    {
        $maxBytes ??= $this->reviewConfigService->resolve()->sourceMaxBytes;

        if ($source->isNone()) {
            return SourceText::none($source);
        }

        // Probe the size before reading so an oversized source is skipped
        // without ever materializing its content; reading first would load
        // the whole file or blob into memory just to discard it.
        $byteSize = $this->byteSize($repoPath, $source);
        if ($byteSize === null) {
            return SourceText::missing($source);
        }

        if ($byteSize > $maxBytes) {
            return SourceText::tooLarge($source, $byteSize);
        }

        $content = $this->content($repoPath, $source);
        if ($content === null) {
            return SourceText::missing($source);
        }

        return SourceText::loaded($source, $content);
    }

    private function byteSize(string $repoPath, FileSourceSpec $source): ?int
    {
        return match ($source->type) {
            FileSourceSpec::TYPE_GIT => $source->ref === null || $source->path === null
                ? null
                : $this->gitFileContentService->byteSizeAt($repoPath, $source->ref, $source->path),
            FileSourceSpec::TYPE_ABSOLUTE => $source->absolutePath === null
                ? null
                : $this->gitFileContentService->byteSizeAtAbsolute($source->absolutePath),
            default => null,
        };
    }

    private function content(string $repoPath, FileSourceSpec $source): ?string
    {
        return match ($source->type) {
            FileSourceSpec::TYPE_GIT => $source->ref === null || $source->path === null
                ? null
                : $this->gitFileContentService->contentAt($repoPath, $source->ref, $source->path),
            FileSourceSpec::TYPE_ABSOLUTE => $source->absolutePath === null
                ? null
                : $this->gitFileContentService->contentAtAbsolute($source->absolutePath),
            default => null,
        };
    }
}
