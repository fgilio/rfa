<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DiffSide;
use Illuminate\Support\Str;

final readonly class AddCommentAction
{
    /**
     * @param  array<int, array<string, mixed>>  $files
     * @return array<string, mixed>|null
     */
    public function handle(array $files, string $fileId, string $side, ?int $startLine, ?int $endLine, string $body): ?array
    {
        if (trim($body) === '') {
            return null;
        }

        $file = collect($files)->keyBy('id')->get($fileId);
        if (! $file || DiffSide::tryFrom($side) === null) {
            return null;
        }

        return [
            'id' => 'c-'.Str::ulid(),
            'fileId' => $fileId,
            'file' => $file['path'],
            'side' => $side,
            'startLine' => $startLine,
            'endLine' => $endLine,
            'body' => $body,
        ];
    }
}
