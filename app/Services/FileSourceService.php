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
        $byteSize = $this->gitFileContentService->byteSizeForSource($repoPath, $source);
        if ($byteSize === null) {
            return SourceText::missing($source);
        }

        if ($byteSize > $maxBytes) {
            return SourceText::tooLarge($source, $byteSize);
        }

        $content = $this->gitFileContentService->contentForSource($repoPath, $source);
        if ($content === null) {
            return SourceText::missing($source);
        }

        return SourceText::loaded($source, $content);
    }
}
