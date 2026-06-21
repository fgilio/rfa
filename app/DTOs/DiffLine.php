<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\LineType;

class DiffLine
{
    /**
     * @param  int[]  $headingAncestors
     * @param  'old'|'new'|null  $moved
     * @param  array{separator: true}|array{separator: true, cells: string[], template: string, maxWidth: int}|array{separator: false, header: bool, cells: string[], template: string, maxWidth: int}|null  $table
     */
    public function __construct(
        public readonly LineType $type,
        public readonly string $content,
        public readonly ?int $oldLineNum,
        public readonly ?int $newLineNum,
        public readonly ?string $highlightedContent = null,
        public readonly ?int $headingLevel = null,
        public readonly ?int $headingId = null,
        public readonly array $headingAncestors = [],
        public readonly ?string $moved = null,
        public readonly ?array $table = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $array = [
            'type' => $this->type,
            'content' => $this->content,
            'oldLineNum' => $this->oldLineNum,
            'newLineNum' => $this->newLineNum,
        ];

        if ($this->highlightedContent !== null) {
            $array['highlightedContent'] = $this->highlightedContent;
        }

        if ($this->headingLevel !== null) {
            $array['headingLevel'] = $this->headingLevel;
            $array['headingId'] = $this->headingId;
        }

        if ($this->headingAncestors !== []) {
            $array['headingAncestors'] = $this->headingAncestors;
        }

        if ($this->moved !== null) {
            $array['moved'] = $this->moved;
        }

        if ($this->table !== null) {
            $array['table'] = $this->table;
        }

        return $array;
    }
}
