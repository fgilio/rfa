<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\DiffSide;

class Comment
{
    public function __construct(
        public readonly string $id,
        public readonly string $fileId,
        public readonly string $file,
        public readonly DiffSide $side,
        public readonly ?int $startLine,
        public readonly ?int $endLine,
        public readonly string $body,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'fileId' => $this->fileId,
            'file' => $this->file,
            'side' => $this->side->value,
            'startLine' => $this->startLine,
            'endLine' => $this->endLine,
            'body' => $this->body,
        ];
    }

    /** @return array<string, mixed> */
    public function toExportArray(): array
    {
        return [
            'id' => $this->id,
            'file' => $this->file,
            'side' => $this->side->value,
            'start_line' => $this->startLine,
            'end_line' => $this->endLine,
            'body' => $this->body,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            fileId: $data['fileId'],
            file: $data['file'],
            side: DiffSide::from($data['side']),
            startLine: $data['startLine'],
            endLine: $data['endLine'],
            body: $data['body'],
        );
    }
}
