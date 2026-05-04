<?php

use App\Actions\ExpandDiffGapAction;
use App\Actions\GetFileCopyContentAction;
use App\Actions\LoadFileDiffAction;
use App\DTOs\DiffTarget;
use App\Enums\DiffSide;
use App\Support\DiffCacheKey;
use App\View\DiffFileViewModel;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    /** @var array<string, mixed> */
    #[Locked]
    public array $file = [];

    #[Locked]
    public string $repoPath = '';

    #[Locked]
    public int $projectId = 0;

    #[Locked]
    public bool $hasRemote = false;

    #[Locked]
    public int $loadDelay = 0;

    #[Locked]
    public string $diffFrom = 'HEAD';

    #[Locked]
    public ?string $diffTo = null;

    public bool $isReviewed = false;

    public bool $singleFile = false;

    /** @var array<int, array<string, mixed>> */
    public array $fileComments = [];

    /** @var array<string, mixed>|null */
    protected ?array $diffData = null;

    private bool $contextExpanded = false;

    private ?DiffTarget $cachedTarget = null;

    public function placeholder(): string
    {
        return <<<'HTML'
<div class="group">
    <div class="sticky top-[var(--header-h)] z-10 bg-gh-surface/80 backdrop-blur-sm border-b border-gh-border px-5 py-2.5 flex items-center gap-2.5 h-10">
        <span class="size-3.5 rounded bg-gh-muted/20"></span>
        <span class="h-3 w-3 rounded-sm bg-gh-muted/20"></span>
        <span class="h-3 flex-1 max-w-md rounded bg-gh-muted/15"></span>
        <span class="h-3 w-14 rounded bg-gh-muted/15"></span>
    </div>
</div>
HTML;
    }

    public function hydrate(): void
    {
        $cached = Cache::get($this->diffCacheKey());
        $this->diffData = DiffCacheKey::isCurrentShape($cached) ? $cached : null;
    }

    /** @param array<int, array<string, mixed>> $comments */
    public function updateComments(array $comments): void
    {
        $this->fileComments = $comments;
        $this->ensureCommentedLinesVisible();
    }

    public function loadFileDiff(): void
    {
        if ($this->diffData !== null) {
            return;
        }

        $this->diffData = app(LoadFileDiffAction::class)->handle(
            $this->repoPath,
            $this->file['path'],
            $this->file['isUntracked'] ?? false,
            cacheKey: $this->diffCacheKey(),
            target: $this->buildDiffTarget(),
            oldPath: $this->file['oldPath'] ?? null,
            externalAbsolutePath: $this->file['externalAbsolutePath'] ?? null,
        );

        $this->ensureCommentedLinesVisible();
    }

    public function copyContent(string $kind): void
    {
        $text = app(GetFileCopyContentAction::class)->handle(
            $kind,
            $this->repoPath,
            $this->file['path'],
            $this->file['isUntracked'] ?? false,
            $this->buildDiffTarget(),
            oldPath: $this->file['oldPath'] ?? null,
        );

        if ($text !== null) {
            $toast = match ($kind) {
                'diff' => 'Copied diff',
                'original' => 'Copied original',
                'new' => 'Copied new',
                default => 'Copied',
            };
            $this->dispatch('copy-to-clipboard', text: $text, toast: $toast);
        }
    }

    public function expandContext(): void
    {
        $cacheKey = $this->diffCacheKey();
        Cache::forget($cacheKey);

        $this->diffData = app(LoadFileDiffAction::class)->handle(
            $this->repoPath,
            $this->file['path'],
            $this->file['isUntracked'] ?? false,
            cacheKey: $cacheKey,
            contextLines: 99999,
            target: $this->buildDiffTarget(),
            oldPath: $this->file['oldPath'] ?? null,
            externalAbsolutePath: $this->file['externalAbsolutePath'] ?? null,
        );
    }

    public function expandGap(int $hunkIndex, ?int $lineCount = null): void
    {
        if ($this->diffData === null || empty($this->diffData['hunks'])) {
            return;
        }

        $fullDiff = app(LoadFileDiffAction::class)->handle(
            $this->repoPath,
            $this->file['path'],
            $this->file['isUntracked'] ?? false,
            cacheKey: $this->diffCacheKey(':full-context'),
            contextLines: 99999,
            target: $this->buildDiffTarget(),
            oldPath: $this->file['oldPath'] ?? null,
            externalAbsolutePath: $this->file['externalAbsolutePath'] ?? null,
        );

        if (empty($fullDiff['hunks'])) {
            return;
        }

        $this->diffData['hunks'] = app(ExpandDiffGapAction::class)->handle(
            hunks: $this->diffData['hunks'],
            hunkIndex: $hunkIndex,
            fullDiffLines: $fullDiff['hunks'][0]['lines'],
            lineCount: $lineCount,
            newFileLineCount: $this->diffData['newFileLineCount'] ?? null,
        );

        if (! empty($fullDiff['syntaxStyles'])) {
            $this->diffData['syntaxStyles'] = ($this->diffData['syntaxStyles'] ?? '').$fullDiff['syntaxStyles'];
        }

        Cache::put($this->diffCacheKey(), $this->diffData, now()->addHours($this->buildDiffTarget()->cacheTtlHours()));
    }

    private function ensureCommentedLinesVisible(): void
    {
        if ($this->contextExpanded || empty($this->fileComments)) {
            return;
        }

        $inlineComments = array_filter($this->fileComments, fn ($c) => ($c['side'] ?? '') !== DiffSide::File->value);
        if (empty($inlineComments)) {
            return;
        }

        // No hunks but has inline comments - load full file
        if ($this->diffData === null || empty($this->diffData['hunks'])) {
            $this->contextExpanded = true;
            $this->expandContext();

            return;
        }

        $visible = $this->getVisibleLineKeys();

        foreach ($inlineComments as $c) {
            if (! isset($visible[DiffFileViewModel::anchorKeyFor($c)])) {
                $this->contextExpanded = true;
                $this->expandContext();

                return;
            }
        }
    }

    /** @return array<string, true> */
    private function getVisibleLineKeys(): array
    {
        $visible = [];
        foreach ($this->diffData['hunks'] ?? [] as $hunk) {
            foreach ($hunk['lines'] as $line) {
                if (isset($line['oldLineNum'])) {
                    $visible['left:'.$line['oldLineNum']] = true;
                }
                if (isset($line['newLineNum'])) {
                    $visible['right:'.$line['newLineNum']] = true;
                }
            }
        }

        return $visible;
    }

    private function buildDiffTarget(): DiffTarget
    {
        return $this->cachedTarget ??= DiffTarget::fromRefs($this->diffFrom, $this->diffTo);
    }

    private function diffCacheKey(string $variant = ''): string
    {
        $projectKey = $this->projectId > 0 ? $this->projectId : $this->repoPath;

        return DiffCacheKey::for($projectKey, $this->file['id'], $this->buildDiffTarget()->contextKey().$variant);
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return $this->view(['diffData' => $this->diffData]);
    }
};
?>

@assets
<script src="/js/diff-file.js"></script>
@endassets

{{-- Single file diff rendering --}}
<div
    x-data="diffFile({
        fileId: @js($file['id']),
        filePath: @js($file['path']),
        oldPath: @js($file['oldPath'] ?? null),
        status: @js(($file['isUntracked'] ?? false) ? 'added' : ($file['status'] ?? 'modified')),
        isReviewed: @js($isReviewed ?? false),
        singleFile: @js($singleFile ?? false),
    })"
    :data-file-id="fileId"
    @mouseup.window="endDrag()"
    @comment-updated.window="if ($event.detail.fileId === fileId) $wire.updateComments($event.detail.comments)"
    @collapse-all-files.window="autoExpandedForComment = false; collapsed = true"
    @expand-all-files.window="autoExpandedForComment = false; collapsed = false"
    @expand-file.window="if ($event.detail.id === fileId) { autoExpandedForComment = false; collapsed = false }"
    @reset-reviewed-files.window="reviewed = false; collapsed = false"
    @reviewed-files-reverted.window="if ($event.detail.fileIds?.includes(fileId)) { reviewed = false }"
    @comment-form-opened.window="closeEmptyFormFromAnotherFile($event.detail.fileId)"
    {{-- Sync from external mark/un-mark (e.g. sidebar file-list button). Self-dispatched
         events from this component's own checkbox are no-ops since reviewed is already
         in the same state. Only flips collapsed on mark — un-mark leaves it alone so it
         doesn't auto-open and feel jarring (matches the cmd+z restore path). --}}
    @file-reviewed-changed.window="
        if ($event.detail.id !== fileId) return;
        reviewed = $event.detail.reviewed;
        if (reviewed) collapsed = true;
    "
    class="group"
>
    <x-diff.file-header
        :file="$file"
        :diff-data="$diffData"
        :has-remote="$hasRemote"
        :diff-to="$diffTo"
    />

    {{-- File body --}}
    <div x-show="!collapsed" x-collapse.duration.150ms>
        <div x-ref="fileCommentForm">
            <template x-if="showForm && formSide === 'file'">
                <x-comment-form save="submitComment" placeholder="File comment..." border-class="border-b" />
            </template>
            @foreach($fileComments as $comment)
                @if($comment['side'] === DiffSide::File->value)
                    <div x-data x-show="editingCommentId !== '{{ $comment['id'] }}'">
                        <x-comment-display :comment="$comment" border-class="border-b" />
                    </div>
                @endif
            @endforeach
        </div>
        {{-- Unplaced inline comments: either the anchor-resolver marked them unplaced
             (content hash mismatch) or the stored line no longer exists in the diff.
             Skip during lazy-load — getVisibleLineKeys() is empty until $diffData
             arrives, which would otherwise classify every comment as unplaced. --}}
        @php
            $unplacedComments = $diffData === null
                ? collect()
                : DiffFileViewModel::unplacedInlineComments($fileComments, $this->getVisibleLineKeys());
        @endphp
        @if($unplacedComments->isNotEmpty())
            @foreach($unplacedComments as $comment)
                <div x-data x-show="editingCommentId !== '{{ $comment['id'] }}'">
                    @if(! empty($comment['lineSnippet']))
                        <div class="border-b border-gh-border bg-gh-surface/40 px-4 py-2">
                            <div class="text-[10px] font-display uppercase tracking-brutal text-gh-muted mb-1">
                                Original snippet
                                @if(! empty($comment['startLine']))
                                    &middot; L{{ $comment['startLine'] }}@if(! empty($comment['endLine']) && $comment['endLine'] !== $comment['startLine'])-L{{ $comment['endLine'] }}@endif
                                @endif
                            </div>
                            <pre class="font-mono text-xs text-gh-muted whitespace-pre-wrap break-all">{{ $comment['lineSnippet'] }}</pre>
                        </div>
                    @endif
                    <x-comment-display :comment="$comment" border-class="border-b" />
                </div>
            @endforeach
        @endif
        @if(($file['isSymlink'] ?? false) || ($diffData['isSymlink'] ?? false))
            @php $target = $file['symlinkTarget'] ?? ($diffData['symlinkTarget'] ?? ''); @endphp
            <div class="px-4 py-8 text-center">
                <flux:icon icon="link" variant="outline" class="inline-block text-gh-muted mr-1" aria-hidden="true" />
                <flux:text variant="subtle" size="sm" inline>
                    Symbolic link &rarr; <span class="font-mono">{{ $target }}</span>
                </flux:text>
            </div>
        @elseif($file['isBinary'] && !($file['isImage'] ?? false))
            <div class="px-4 py-8 text-center">
                <flux:text variant="subtle" size="sm">Binary file not shown</flux:text>
                @if(($file['fileSize'] ?? null) || ($file['lastModified'] ?? null))
                    <div class="mt-1">
                        <flux:text variant="subtle" size="sm">
                            {{ $file['fileSize'] ?? '' }}@if(($file['fileSize'] ?? null) && ($file['lastModified'] ?? null)) &middot; @endif@if($file['lastModified'] ?? null)modified {{ $file['lastModified'] }}@endif
                        </flux:text>
                    </div>
                @endif
            </div>
        @elseif($file['isBinary'] && ($file['isImage'] ?? false))
            @php
                $status = $file['status'];
                $hasBeforeImage = in_array($status, ['modified', 'binary', 'renamed', 'deleted']);
                $hasAfterImage = in_array($status, ['modified', 'binary', 'renamed', 'added']);
                $beforePath = $file['oldPath'] ?? $file['path'];
                $beforeRef = $diffTo === null ? 'HEAD' : $diffFrom;
                $afterRef = $diffTo ?? 'working';
            @endphp
            <div class="px-4 py-6 flex items-start justify-center gap-6">
                @if($hasBeforeImage)
                    <div class="flex flex-col items-center gap-2 {{ $hasAfterImage ? 'max-w-[50%]' : '' }}">
                        <flux:badge color="red" size="sm">{{ $status === 'deleted' ? 'Deleted' : 'Before' }}</flux:badge>
                        <div class="border border-gh-border rounded-lg p-1" style="background: repeating-conic-gradient(rgb(128 128 128 / 0.15) 0% 25%, transparent 0% 50%) 50% / 16px 16px;">
                            <img
                                src="/api/image/{{ $projectId }}/{{ $beforeRef }}/{{ $beforePath }}"
                                alt="{{ $beforePath }}"
                                class="max-h-96 object-contain"
                                loading="lazy"
                                onerror="this.closest('[class*=flex-col]').style.display='none'"
                            >
                        </div>
                    </div>
                @endif
                @if($hasAfterImage)
                    <div class="flex flex-col items-center gap-2 {{ $hasBeforeImage ? 'max-w-[50%]' : '' }}">
                        <flux:badge color="green" size="sm">{{ $status === 'added' ? 'New' : 'After' }}</flux:badge>
                        <div class="border border-gh-border rounded-lg p-1" style="background: repeating-conic-gradient(rgb(128 128 128 / 0.15) 0% 25%, transparent 0% 50%) 50% / 16px 16px;">
                            <img
                                src="/api/image/{{ $projectId }}/{{ $afterRef }}/{{ $file['path'] }}"
                                alt="{{ $file['path'] }}"
                                class="max-h-96 object-contain"
                                loading="lazy"
                                onerror="this.closest('[class*=flex-col]').style.display='none'"
                            >
                        </div>
                    </div>
                @endif
            </div>
        @elseif($diffData === null)
            {{-- One spinner for both the pre-request setTimeout window and the
                 in-flight request, so the visual doesn't swap mid-load. --}}
            <div
                x-intersect.once="setTimeout(() => $wire.loadFileDiff(), {{ $loadDelay }})"
                class="px-4 py-8 text-center"
            >
                <flux:icon icon="arrow-path" variant="outline" class="animate-spin inline-block text-gh-muted mr-1" />
                <flux:text variant="subtle" size="sm" inline>Loading diff...</flux:text>
            </div>
        @elseif($diffData['tooLarge'] ?? false)
            <div class="px-4 py-8 text-center">
                <flux:icon icon="exclamation-triangle" variant="outline" class="inline-block text-gh-muted mr-1" />
                <flux:text variant="subtle" size="sm" inline>File diff too large to display</flux:text>
            </div>
        @elseif($diffData['error'] ?? false)
            <div class="px-4 py-8 text-center">
                <flux:icon icon="exclamation-triangle" variant="outline" class="inline-block text-red-400 mr-1" />
                <flux:text variant="subtle" size="sm" inline>Git error: {{ $diffData['error'] }}</flux:text>
            </div>
        @elseif(empty($diffData['hunks']))
            <div class="px-4 py-8 text-center">
                <flux:text variant="subtle" size="sm">No content changes</flux:text>
            </div>
        @else
            @php
                $commentsByLine = DiffFileViewModel::commentsByLine($fileComments);
                ['hunks' => $hunks, 'hasGaps' => $hasGaps, 'hasTrailingGap' => $hasTrailingGap, 'trailingHiddenCount' => $trailingHiddenCount]
                    = DiffFileViewModel::gapSummary($diffData['hunks'], $diffData['newFileLineCount'] ?? null);
            @endphp
            @if($diffData['syntaxStyles'] ?? '')
                {!! '<style>' . $diffData['syntaxStyles'] . '</style>' !!}
            @endif
            <div
                data-testid="diff-table"
                :data-view-mode="$store.settings.diffViewMode"
                class="diff-grid font-mono text-xs leading-5"
                :class="isDragging ? 'select-none' : ''"
            >
                @if($hasGaps)
                    <div class="diff-fullspan bg-gh-hunk-bg px-4 py-1 text-center">
                        <button
                            wire:click="expandContext"
                            wire:loading.attr="disabled"
                            wire:target="expandContext"
                            class="text-gh-link text-xs hover:underline inline-flex items-center gap-1 disabled:opacity-50"
                        >
                            <flux:icon wire:loading wire:target="expandContext" icon="arrow-path" variant="outline" class="animate-spin" />
                            Show full file
                        </button>
                    </div>
                @endif

                @foreach($hunks as $hunkIndex => $hunk)
                    <x-diff.hunk
                        :hunk="$hunk"
                        :hunk-index="$hunkIndex"
                        :prev-hunk="$hunkIndex > 0 ? $hunks[$hunkIndex - 1] : null"
                        :has-remote="$hasRemote"
                        :comments-by-line="$commentsByLine"
                    />
                @endforeach

                @if($hasTrailingGap)
                    <div class="diff-fullspan bg-gh-hunk-bg py-1.5 text-center text-xs border-y border-dashed border-gh-border/20">
                        <x-tiered-expand-gap :hunk-index="count($hunks)" :hidden-count="$trailingHiddenCount" />
                    </div>
                @endif
            </div>
        @endif

    </div>
</div>
