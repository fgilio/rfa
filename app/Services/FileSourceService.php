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

        $content = match ($source->type) {
            FileSourceSpec::TYPE_GIT => $this->gitContent($repoPath, $source),
            FileSourceSpec::TYPE_ABSOLUTE => $source->absolutePath === null
                ? null
                : $this->gitFileContentService->contentAtAbsolute($source->absolutePath),
            default => null,
        };

        if ($content === null) {
            return SourceText::missing($source);
        }

        $byteSize = strlen($content);
        if ($byteSize > $maxBytes) {
            return SourceText::tooLarge($source, $byteSize);
        }

        return SourceText::loaded($source, $content);
    }

    private function gitContent(string $repoPath, FileSourceSpec $source): ?string
    {
        if ($source->ref === null || $source->path === null) {
            return null;
        }

        return $this->gitFileContentService->contentAt($repoPath, $source->ref, $source->path);
    }
}
