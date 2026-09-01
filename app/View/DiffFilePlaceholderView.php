<?php

declare(strict_types=1);

namespace App\View;

use Illuminate\Contracts\View\View;
use Illuminate\View\Factory;

final class DiffFilePlaceholderView implements View
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param  array{
     *     file?: array{
     *         path?: string,
     *         oldPath?: string|null,
     *         status?: string,
     *         additions?: int,
     *         deletions?: int,
     *         isBinary?: bool,
     *         isSymlink?: bool,
     *         symlinkTarget?: string|null,
     *         isExternal?: bool
     *     },
     *     fileComments?: list<array<string, mixed>>,
     *     isReviewed?: bool,
     *     allowDiscard?: bool,
     *     diffTo?: string|null
     * }  $params
     */
    public function __construct(
        private readonly Factory $factory,
        private readonly array $params,
    ) {}

    public function name(): string
    {
        return 'livewire.placeholders.diff-file';
    }

    /**
     * Livewire adds component properties before it renders a placeholder.
     * The pre-rendered header uses only the constructor parameters.
     *
     * @param  string|array<string, mixed>  $key
     */
    public function with($key, $value = null): static
    {
        if (is_array($key)) {
            $this->data = array_merge($this->data, $key);
        } else {
            $this->data[$key] = $value;
        }

        return $this;
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }

    public function getFactory(): Factory
    {
        return $this->factory;
    }

    public function render(?callable $callback = null): string
    {
        $contents = $this->renderContents();
        $response = $callback !== null ? $callback($this, $contents) : null;

        return is_string($response) ? $response : $contents;
    }

    private function renderContents(): string
    {
        $file = is_array($this->params['file'] ?? null) ? $this->params['file'] : [];
        $fileComments = is_array($this->params['fileComments'] ?? null) ? $this->params['fileComments'] : [];
        $isReviewed = (bool) ($this->params['isReviewed'] ?? false);
        $allowDiscard = (bool) ($this->params['allowDiscard'] ?? true);
        $showContentCopy = DiffFileViewModel::showsContentCopy($file);
        $showDiscard = DiffFileViewModel::showsDiscard(
            $file,
            $allowDiscard,
            is_string($this->params['diffTo'] ?? null) ? $this->params['diffTo'] : null,
        );
        $path = (string) ($file['path'] ?? '');
        $oldPath = is_string($file['oldPath'] ?? null) ? $file['oldPath'] : null;
        $title = e($oldPath ? $oldPath.' → '.$path : $path);
        $pathPosition = strrpos($path, '/');
        [$directory, $basename] = $pathPosition === false
            ? ['', $path]
            : [substr($path, 0, $pathPosition + 1), substr($path, $pathPosition + 1)];
        $pathLabel = '<span class="rfa-lazy-directory">'.e($directory).'</span>'.e($basename);
        $oldPathLabel = $oldPath ? '<span class="rfa-lazy-old-path">'.e($oldPath).'&nbsp;→&nbsp;</span>' : '';
        $chevron = $isReviewed
            ? $this->icon('chevron-right', 'size-4')
            : $this->icon('chevron-down', 'size-4');
        $reviewedClass = $isReviewed ? ' rfa-lazy-checkbox--reviewed' : '';
        $additions = (int) ($file['additions'] ?? 0);
        $deletions = (int) ($file['deletions'] ?? 0);
        $additionsLabel = $additions > 0 ? '<span class="text-gh-green">+'.$additions.'</span>' : '';
        $deletionsLabel = $deletions > 0 ? '<span class="text-gh-red">-'.$deletions.'</span>' : '';
        $commentsCount = count($fileComments);
        $commentsLabel = $commentsCount > 0 ? '<span class="rfa-lazy-comment-count">'.$commentsCount.'</span>' : '';
        $symlinkLabel = ($file['isSymlink'] ?? false)
            ? $this->icon('link', 'rfa-lazy-icon--link').'<span class="rfa-lazy-link-label">→ '.e((string) ($file['symlinkTarget'] ?? '')).'</span>'
            : '';
        $contentButton = $showContentCopy ? $this->button('copy-content') : '';
        $discardButton = $showDiscard ? $this->button('discard') : '';

        return <<<HTML
<div data-rfa-render-blocker class="group">
    <div data-rfa-static-file-header class="rfa-lazy-header">
        <div class="rfa-lazy-main">
            <span class="rfa-lazy-chevron" aria-hidden="true">{$chevron}</span>
            <span class="rfa-lazy-path" title="{$title}">{$oldPathLabel}{$pathLabel}</span>
            {$symlinkLabel}
        </div>
        <div class="rfa-lazy-meta">
            <div class="rfa-lazy-actions">{$this->button('copy-path')}{$contentButton}{$discardButton}</div>
            {$additionsLabel}
            {$deletionsLabel}
            <div class="rfa-lazy-comments">{$this->button('comment')}{$commentsLabel}</div>
            <span class="rfa-lazy-checkbox{$reviewedClass}" aria-hidden="true"></span>
        </div>
    </div>
</div>
HTML;
    }

    private function button(string $name): string
    {
        return '<span class="rfa-lazy-button rfa-lazy-icon--'.$name.'" data-rfa-static-icon="'.$name.'" aria-hidden="true"></span>';
    }

    private function icon(string $name, string $class = 'size-5'): string
    {
        return '<span class="rfa-lazy-icon rfa-lazy-icon--'.$name.' '.$class.'" data-rfa-static-icon="'.$name.'" aria-hidden="true"></span>';
    }
}
