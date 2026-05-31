{{-- Parent Alpine scope contract: fileId, filePath, oldPath, status, reviewed, collapsed,
     toggleCollapse(), openFileComment(), onReviewedChange(), $wire.fileComments,
     $wire.copyContent(), $dispatch('open-remote-menu' | 'discard-file' | 'copy-to-clipboard') --}}
@props([
    'file',
    'diffData' => null,
    'hasRemote' => false,
    'diffTo' => null,
    'repoPath' => '',
])

@php
    $showContentCopy = ! ($file['isBinary'] ?? false)
        && ! ($file['isSymlink'] ?? false)
        && ! ($diffData['tooLarge'] ?? false);
    $isAdded = ($file['status'] ?? '') === 'added' || ($file['isUntracked'] ?? false);
    $isDeleted = ($file['status'] ?? '') === 'deleted';
@endphp

<div data-testid="file-header"
     @if($hasRemote) @contextmenu.prevent="$dispatch('open-remote-menu', {target: 'file', fileId, filePath, oldPath, status, clientX: $event.clientX, clientY: $event.clientY})" @endif
     class="sticky top-[var(--header-h)] z-10 bg-gh-surface/80 backdrop-blur-sm border-b border-gh-border px-5 py-2.5 flex items-center gap-2.5">

    <div data-testid="toggle-zone"
         @click="toggleCollapse($event)"
         class="flex items-center gap-2.5 flex-1 min-w-0 cursor-pointer">
        <button :aria-label="collapsed ? 'Expand file' : 'Collapse file'"
                :aria-expanded="!collapsed"
                class="text-gh-muted hover:text-gh-text transition-colors">
            <flux:icon icon="chevron-down" variant="outline" x-show="!collapsed" />
            <flux:icon icon="chevron-right" variant="outline" x-show="collapsed" x-cloak />
        </button>

        <x-file-path
            :path="$file['path']"
            :old-path="$file['oldPath'] ?? null"
            class="text-sm"
        />

        @if($file['isSymlink'] ?? false)
            <flux:icon icon="link" variant="outline" class="!size-3.5 text-gh-muted shrink-0" aria-hidden="true" />
            <span class="font-mono text-xs text-gh-muted">&rarr; {{ $file['symlinkTarget'] }}</span>
        @endif
    </div>

    <div class="flex items-center gap-2 text-xs shrink-0 font-mono">
        <div class="flex items-center gap-0.5 opacity-60 group-hover:opacity-100 transition-opacity">
            <x-copy-paths-button
                mode="single"
                size="sm"
                :path="$file['path']"
                :repo-path="$repoPath"
                testid-prefix="file-header-copy-path"
            />

            @if($showContentCopy)
                <flux:dropdown position="bottom" align="end">
                    <flux:button icon="clipboard-document" icon:variant="outline" variant="ghost" size="sm" tooltip="Copy content" aria-label="Copy content" />
                    <flux:menu>
                        <flux:menu.item icon="code-bracket" icon:variant="outline" @click="$wire.copyContent('diff')">
                            Copy diff
                        </flux:menu.item>
                        <flux:menu.item icon="minus" icon:variant="outline" @click="$wire.copyContent('original')" :disabled="$isAdded">
                            Copy original
                        </flux:menu.item>
                        <flux:menu.item icon="plus" icon:variant="outline" @click="$wire.copyContent('new')" :disabled="$isDeleted">
                            Copy new
                        </flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            @endif

            @if($diffTo === null && ($file['status'] ?? '') !== 'commented' && ! ($file['isExternal'] ?? false))
                <flux:button
                    tooltip="Discard changes"
                    aria-label="Discard changes"
                    icon="arrow-uturn-left"
                    icon:variant="outline"
                    variant="ghost"
                    size="sm"
                    class="data-loading:pointer-events-none data-loading:opacity-50"
                    wire:click="$dispatch('discard-file', { fileId: @js($file['id']) })"
                />
            @endif
        </div>

        @if($file['additions'] > 0)
            <span class="text-gh-green">+{{ $file['additions'] }}</span>
        @endif
        @if($file['deletions'] > 0)
            <span class="text-gh-red">-{{ $file['deletions'] }}</span>
        @endif

        <div class="flex items-center gap-0.5">
            <flux:button
                x-ref="fileCommentBtn"
                tooltip="Add file comment"
                aria-label="Add file comment"
                icon="chat-bubble-left"
                icon:variant="outline"
                variant="ghost"
                size="sm"
                @click="openFileComment()"
            />
            <span
                x-show="$wire.fileComments.length"
                x-text="$wire.fileComments.length"
                class="text-[10px] font-mono text-gh-muted tabular-nums"
            ></span>
        </div>

        <flux:tooltip content="Mark as reviewed">
            <flux:checkbox x-model="reviewed" @change="onReviewedChange()" aria-label="Reviewed" class="cursor-pointer" />
        </flux:tooltip>
    </div>
</div>
