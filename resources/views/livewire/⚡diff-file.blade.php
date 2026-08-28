<?php

use App\Actions\ExpandDiffGapAction;
use App\Actions\GetFileCopyContentAction;
use App\Actions\LoadFileDiffAction;
use App\Actions\OpenExternalUrlAction;
use App\Actions\RecordRuntimeDiagnosticAction;
use App\Actions\ResolveReviewConfigAction;
use App\DTOs\DiffTarget;
use App\DTOs\LoadedDiff;
use App\DTOs\ReviewConfig;
use App\Enums\DiffLoadOutcome;
use App\Enums\DiffSide;
use App\Enums\GitRef;
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

    /** False suppresses the discard affordance where it would be destructive, e.g. the empty-tree "Since the beginning" view. */
    #[Locked]
    public bool $allowDiscard = true;

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
        $this->diffData = LoadedDiff::tryFrom(Cache::get($this->diffCacheKey()))?->toArray();
    }

    /** @param array<int, array<string, mixed>> $comments */
    public function updateComments(array $comments): void
    {
        $this->fileComments = $comments;
        $this->ensureCommentedLinesVisible();
    }

    /** @param list<array<string, mixed>> $replies */
    public function updateCommentReplies(string $commentId, array $replies): void
    {
        $index = collect($this->fileComments)->search(
            fn (array $comment): bool => ($comment['id'] ?? null) === $commentId,
        );

        if ($index === false) {
            return;
        }

        $this->fileComments[$index]['replies'] = $replies;
    }

    public function loadFileDiff(): void
    {
        if ($this->diffData !== null) {
            $this->dispatchDiffActionCompleted('loadFileDiff', 0, cached: true);

            return;
        }

        $startedAt = microtime(true);

        $this->diffData = app(LoadFileDiffAction::class)->handle(
            $this->repoPath,
            $this->file['path'],
            $this->file['isUntracked'] ?? false,
            cacheKey: $this->diffCacheKey(),
            target: $this->buildDiffTarget(),
            oldPath: $this->file['oldPath'] ?? null,
            externalAbsolutePath: $this->file['externalAbsolutePath'] ?? null,
        )->toArray();

        $durationMs = $this->durationSince($startedAt);

        app(RecordRuntimeDiagnosticAction::class)->handle('diff.loaded', $this->diagnosticContext($this->diffData) + [
            'duration_ms' => $durationMs,
        ]);

        $this->dispatchDiffActionCompleted('loadFileDiff', $durationMs);

        $this->ensureCommentedLinesVisible();
    }

    public function copyContent(string $kind): void
    {
        $result = app(GetFileCopyContentAction::class)->handle(
            $kind,
            $this->repoPath,
            $this->file['path'],
            $this->file['isUntracked'] ?? false,
            $this->buildDiffTarget(),
            oldPath: $this->file['oldPath'] ?? null,
            status: $this->file['status'] ?? 'modified',
            isExternal: $this->file['isExternal'] ?? false,
            externalAbsolutePath: $this->file['externalAbsolutePath'] ?? null,
        );

        if ($result->isOk()) {
            $this->dispatch('copy-to-clipboard', text: $result->content, toast: match ($kind) {
                'diff' => 'Copied diff',
                'original' => 'Copied original',
                'new' => 'Copied new',
                default => 'Copied',
            });

            return;
        }

        Flux::toast(variant: 'warning', text: $this->copyUnavailableMessage($kind, $result));
    }

    public function openExternalUrl(string $url): void
    {
        app(OpenExternalUrlAction::class)->handle($url);

        $this->skipRender();
    }

    private function copyUnavailableMessage(string $kind, \App\DTOs\CopyContentResult $result): string
    {
        if ($result->status === \App\DTOs\CopyContentResult::STATUS_TOO_LARGE) {
            $size = $result->byteSize !== null ? ' ('.\Illuminate\Support\Number::fileSize($result->byteSize).')' : '';

            return 'File is too large to copy'.$size;
        }

        return match ($kind) {
            'diff' => 'No diff available to copy',
            'original' => 'Original content is unavailable to copy',
            'new' => 'New content is unavailable to copy',
            default => 'Nothing to copy',
        };
    }

    public function expandContext(): void
    {
        $cacheKey = $this->diffCacheKey();
        Cache::forget($cacheKey);

        $startedAt = microtime(true);

        $this->diffData = app(LoadFileDiffAction::class)->handle(
            $this->repoPath,
            $this->file['path'],
            $this->file['isUntracked'] ?? false,
            cacheKey: $cacheKey,
            contextLines: 99999,
            target: $this->buildDiffTarget(),
            oldPath: $this->file['oldPath'] ?? null,
            externalAbsolutePath: $this->file['externalAbsolutePath'] ?? null,
        )->toArray();

        $durationMs = $this->durationSince($startedAt);

        app(RecordRuntimeDiagnosticAction::class)->handle('diff.context_expanded', $this->diagnosticContext($this->diffData) + [
            'duration_ms' => $durationMs,
        ]);

        $this->dispatchDiffActionCompleted('expandContext', $durationMs);
    }

    public function expandGap(int $hunkIndex, ?int $lineCount = null): void
    {
        $loaded = LoadedDiff::tryFrom($this->diffData);

        if ($loaded === null || $loaded->hunks() === []) {
            // Diff fell out of cache between render and click: nothing to expand.
            // Still settle the action so the client clears the optimistic loading
            // spinner and the paired runtime-diagnostics start mark isn't orphaned.
            $this->dispatchDiffActionCompleted('expandGap', 0);

            return;
        }

        $startedAt = microtime(true);

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

        if ($fullDiff->hunks() === []) {
            // The full-context reload found no diff: the file changed underneath
            // the cached hunks. Same settle contract as the guard above so the
            // expander's spinner can't get stuck on a no-op that morphs nothing.
            $this->dispatchDiffActionCompleted('expandGap', $this->durationSince($startedAt));

            return;
        }

        $expanded = $loaded->withExpandedHunks(
            app(ExpandDiffGapAction::class)->handle(
                hunks: $loaded->hunks(),
                hunkIndex: $hunkIndex,
                fullDiffLines: $fullDiff->hunks()[0]['lines'],
                lineCount: $lineCount,
                newFileLineCount: $loaded->newFileLineCount,
            ),
            $fullDiff->syntaxStyles,
        );

        $this->diffData = $expanded->toArray();

        Cache::put(
            $this->diffCacheKey(),
            $this->diffData,
            now()->addHours($this->buildDiffTarget()->cacheTtlHours($this->reviewConfig()->cacheTtlHours)),
        );

        $durationMs = $this->durationSince($startedAt);

        app(RecordRuntimeDiagnosticAction::class)->handle('diff.gap_expanded', $this->diagnosticContext($this->diffData) + [
            'hunk_index' => $hunkIndex,
            'line_count' => $lineCount,
            'duration_ms' => $durationMs,
        ]);

        $this->dispatchDiffActionCompleted('expandGap', $durationMs);
    }

    /** @param array<string, mixed>|null $diffData */
    private function diagnosticContext(?array $diffData): array
    {
        $lineCount = 0;
        $lineContentBytes = 0;

        foreach (($diffData['hunks'] ?? []) as $hunk) {
            if (is_array($hunk) && isset($hunk['lines']) && is_array($hunk['lines'])) {
                $lineCount += count($hunk['lines']);

                foreach ($hunk['lines'] as $line) {
                    if (! is_array($line)) {
                        continue;
                    }

                    $lineContentBytes += $this->diagnosticStringBytes($line['content'] ?? null);
                    $lineContentBytes += $this->diagnosticStringBytes($line['highlightedContent'] ?? null);
                }
            }
        }

        return [
            'project_id' => $this->projectId,
            'file_id' => $this->file['id'] ?? null,
            'path_hash' => isset($this->file['path']) ? hash('xxh128', (string) $this->file['path']) : null,
            'extension' => isset($this->file['path']) ? pathinfo((string) $this->file['path'], PATHINFO_EXTENSION) : '',
            'status' => ($this->file['isUntracked'] ?? false) ? 'added' : ($this->file['status'] ?? 'modified'),
            'target' => $this->buildDiffTarget()->contextKey(),
            'too_large' => $this->outcome($diffData) === DiffLoadOutcome::TooLarge,
            'binary' => (bool) ($diffData['isBinary'] ?? false),
            'hunk_count' => count($diffData['hunks'] ?? []),
            'diff_line_count' => $lineCount,
            'comment_count' => count($this->fileComments),
            'line_content_bytes' => $lineContentBytes,
        ];
    }

    private function diagnosticStringBytes(mixed $value): int
    {
        return is_string($value) ? strlen($value) : 0;
    }

    private function durationSince(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function imageUrl(string $ref, string $path): string
    {
        $encodedPath = collect(explode('/', $path))
            ->map(fn (string $segment): string => rawurlencode($segment))
            ->implode('/');

        return '/api/image/'.$this->projectId.'/'.rawurlencode($ref).'/'.$encodedPath;
    }

    private function dispatchDiffActionCompleted(string $action, int $durationMs, bool $cached = false): void
    {
        $context = $this->diagnosticContext($this->diffData);

        $this->dispatch('rfa:diff-action-completed',
            fileId: (string) ($this->file['id'] ?? ''),
            action: $action,
            phpMs: $durationMs,
            hunkCount: $context['hunk_count'],
            diffLineCount: $context['diff_line_count'],
            lineContentBytes: $context['line_content_bytes'],
            tooLarge: $context['too_large'],
            binary: $context['binary'],
            cached: $cached,
        );
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

    private function reviewConfig(): ReviewConfig
    {
        // ReviewConfigService is a container singleton that memoizes resolve(),
        // so this is already cheap enough not to need a local copy.
        return app(ResolveReviewConfigAction::class)->handle();
    }

    /**
     * The load outcome of the currently-held diff, as an enum. The stored
     * envelope carries the backing string, so this is the single place that
     * converts it — the view compares cases, never strings.
     */
    private function outcome(?array $diffData): ?DiffLoadOutcome
    {
        $outcome = $diffData['outcome'] ?? null;

        return is_string($outcome) ? DiffLoadOutcome::tryFrom($outcome) : null;
    }

    private function diffCacheKey(string $variant = ''): string
    {
        $projectKey = $this->projectId > 0 ? $this->projectId : $this->repoPath;

        return DiffCacheKey::for(
            $projectKey,
            $this->file['id'],
            $this->reviewConfig()->cacheFingerprint(),
            $this->buildDiffTarget()->contextKey().$variant,
        );
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return $this->view([
            'diffData' => $this->diffData,
            'outcome' => $this->outcome($this->diffData),
        ]);
    }
};
?>

@assets
@localScript('js/diff-file.js')
@endassets

{{-- Single file diff rendering --}}
<div
    data-rfa-diff-file
    x-data="diffFile({
        fileId: @js($file['id']),
        filePath: @js($file['path']),
        oldPath: @js($file['oldPath'] ?? null),
        status: @js(($file['isUntracked'] ?? false) ? 'added' : ($file['status'] ?? 'modified')),
        isReviewed: @js($isReviewed ?? false),
        singleFile: @js($singleFile ?? false),
    })"
    :data-file-id="fileId"
    :data-collapsed="collapsed ? 'true' : 'false'"
    @mouseup.window="endDrag()"
    @comment-updated.window="if ($event.detail.fileId === fileId) $wire.updateComments($event.detail.comments)"
    @comment-thread-updated.window="if ($event.detail.fileId === fileId) $wire.updateCommentReplies($event.detail.commentId, $event.detail.replies)"
    @collapse-all-files.window="autoExpandedForComment = false; collapsed = true"
    @expand-all-files.window="autoExpandedForComment = false; collapsed = false"
    @expand-file.window="if ($event.detail.id === fileId) { autoExpandedForComment = false; collapsed = false }"
    @unfold-for-comment.window="if ($event.detail.fileId === fileId) { foldedHeadings = {} }"
    @reset-reviewed-files.window="reviewed = false; collapsed = false"
    @reviewed-files-reverted.window="if ($event.detail.fileIds?.includes(fileId)) { reviewed = false }"
    @comment-form-opened.window="closeEmptyFormFromAnotherFile($event.detail.fileId)"
    @rfa-comment-selection.window="if ($event.detail.fileId === fileId) commentOnSelection()"
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
        :outcome="$outcome"
        :has-remote="$hasRemote"
        :diff-to="$diffTo"
        :repo-path="$repoPath"
        :allow-discard="$allowDiscard"
    />

    {{-- File body --}}
    <div x-show="!collapsed" x-collapse.duration.150ms>
        <div x-ref="fileCommentForm">
            <template x-if="showForm && formSide === 'file'">
                <div class="comment-open">
                    <x-comment-form save="submitComment" placeholder="File comment..." border-class="border-b" />
                </div>
            </template>
            @foreach($fileComments as $comment)
                @if($comment['side'] === DiffSide::File->value)
                    <div id="comment-{{ $comment['id'] }}" x-data x-show="editingCommentId !== '{{ $comment['id'] }}'">
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
                <div id="comment-{{ $comment['id'] }}" x-data x-show="editingCommentId !== '{{ $comment['id'] }}'">
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
                <flux:icon icon="link" variant="outline" class="!size-4 inline-block text-gh-muted mr-1" aria-hidden="true" />
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
                // The before side is always the target's from-ref. Hardcoding HEAD
                // when $diffTo is null breaks "review since base" (base..working),
                // where $diffFrom is the base SHA, not HEAD.
                $beforeRef = $diffFrom;
                $afterRef = $diffTo ?? GitRef::Working->value;
            @endphp
            <div class="px-4 py-6 flex items-start justify-center gap-6">
                @if($hasBeforeImage)
                    <div class="flex flex-col items-center gap-2 {{ $hasAfterImage ? 'max-w-[50%]' : '' }}">
                        <span class="font-mono text-[11px] font-medium text-gh-red">{{ $status === 'deleted' ? 'Deleted' : 'Before' }}</span>
                        <div class="border border-gh-border rounded-lg p-1" style="background: repeating-conic-gradient(rgb(128 128 128 / 0.15) 0% 25%, transparent 0% 50%) 50% / 16px 16px;">
                            <img
                                src="{{ $this->imageUrl($beforeRef, $beforePath) }}"
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
                        <span class="font-mono text-[11px] font-medium text-gh-green">{{ $status === 'added' ? 'New' : 'After' }}</span>
                        <div class="border border-gh-border rounded-lg p-1" style="background: repeating-conic-gradient(rgb(128 128 128 / 0.15) 0% 25%, transparent 0% 50%) 50% / 16px 16px;">
                            <img
                                src="{{ $this->imageUrl($afterRef, $file['path']) }}"
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
            <div x-intersect.once="setTimeout(() => { markDiffActionStart('loadFileDiff'); $wire.loadFileDiff(); }, {{ $loadDelay }})">
                <x-diff-skeleton />
            </div>
        @elseif($outcome === DiffLoadOutcome::TooLarge)
            <div class="px-4 py-8 text-center">
                <flux:icon icon="exclamation-triangle" variant="outline" class="!size-4 inline-block text-gh-muted mr-1" />
                <flux:text variant="subtle" size="sm" inline>File diff too large to display</flux:text>
            </div>
        @elseif($outcome === DiffLoadOutcome::TransientError)
            <div class="px-4 py-8 text-center">
                <flux:icon icon="exclamation-triangle" variant="outline" class="!size-4 inline-block text-gh-red mr-1" />
                <flux:text variant="subtle" size="sm" inline>Git error: failed to load the diff for this file.</flux:text>
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
                :class="{ 'select-none': isDragging, 'cursor-pointer': hoveredUrl !== null }"
                @mousemove="previewUrlAtPoint($event)"
                @mouseleave="clearUrlPreview()"
                @click="openUrlAtClick($event)"
            >
                @if($hasGaps)
                    <x-diff.expand-control>
                        <x-diff.expand-button action="expandContext" aria-label="Show full file">full file</x-diff.expand-button>
                    </x-diff.expand-control>
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
                    <x-diff.expand-control wire:key="expand-gap-trailing-{{ count($hunks) }}-{{ $trailingHiddenCount }}">
                        <x-tiered-expand-gap :hunk-index="count($hunks)" :hidden-count="$trailingHiddenCount" />
                    </x-diff.expand-control>
                @endif
            </div>
        @endif

    </div>
</div>
