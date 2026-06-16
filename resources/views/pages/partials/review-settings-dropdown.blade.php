{{-- Settings dropdown for the review page: global .gitignore toggle, default base branch, and linked external paths. Rendered inside the page, so wire: bindings and component props resolve against it. The caller guards on isCommitMode(). --}}

<flux:dropdown position="bottom" align="end">
    <flux:tooltip content="Settings">
        <flux:button variant="ghost" size="sm" icon="cog-6-tooth" icon:variant="outline"
            aria-label="Settings" />
    </flux:tooltip>
    <flux:menu>
        <flux:menu.item keep-open>
            <flux:checkbox wire:model.live="respectGlobalGitignore" label="Global .gitignore" class="text-xs whitespace-nowrap" />
        </flux:menu.item>
        <p class="px-3 pb-2 text-[10px] font-mono text-gh-muted/80 leading-snug w-56">
            Hide files matched by your global .gitignore (e.g. ~/.gitignore_global).
        </p>
        <flux:menu.separator />
        <div class="px-3 py-2 w-56 space-y-1.5" wire:ignore.self>
            <label for="default-base-branch-input" class="block text-[10px] font-display font-semibold uppercase tracking-brutal text-gh-muted">
                Base branch
            </label>
            <flux:input
                id="default-base-branch-input"
                data-testid="default-base-branch-input"
                wire:model.live.debounce.400ms="defaultBaseBranch"
                placeholder="dev, master, main..."
                size="sm"
                variant="filled"
                class="!font-mono text-xs"
            />
            <p class="text-[10px] font-mono text-gh-muted/80 leading-snug">
                Default branch to compare against. Pre-fills the
                @if($defaultBaseBranch !== '')
                    <span class="text-gh-text">Since {{ $defaultBaseBranch }}</span>
                    shortcut
                @else
                    Since shortcut (e.g. <span class="text-gh-text">Since dev</span>)
                @endif
                in the branch picker.
            </p>
        </div>
        <flux:menu.separator />
        <div class="px-3 py-2 w-72 space-y-2" wire:ignore.self>
            <label class="block text-[10px] font-display font-semibold uppercase tracking-brutal text-gh-muted">
                Linked external paths
            </label>
            <p class="text-[10px] font-mono text-gh-muted/80 leading-snug">
                Folders or single files outside the repo that show up as commentable files (e.g. design notes, a Claude Code plan).
            </p>
            @if(count($externalPaths) > 0)
                <ul class="space-y-1" data-testid="external-paths-list">
                    @foreach($externalPaths as $index => $row)
                        <li class="flex items-center gap-2 group" wire:key="external-path-{{ $index }}">
                            <div class="min-w-0 flex-1">
                                <div class="text-xs font-display text-gh-text truncate" title="{{ $row['path'] }}">{{ $row['label'] }}</div>
                                <x-file-path :path="$row['path']" class="text-[10px] text-gh-muted/70" />
                            </div>
                            <flux:tooltip content="Unlink">
                                <flux:button
                                    size="xs"
                                    variant="ghost"
                                    icon="x-mark"
                                    icon:variant="outline"
                                    wire:click="removeExternalPath({{ $index }})"
                                    aria-label="Unlink {{ $row['label'] }}"
                                    data-testid="external-path-remove-{{ $index }}"
                                />
                            </flux:tooltip>
                        </li>
                    @endforeach
                </ul>
            @endif
            <div class="flex gap-1">
                <flux:button
                    size="xs"
                    variant="ghost"
                    icon="folder-plus"
                    icon:variant="outline"
                    wire:click="addExternalPath"
                    wire:loading.attr="disabled"
                    wire:target="addExternalPath"
                    data-testid="external-path-add"
                    class="flex-1"
                >
                    <span wire:loading.remove wire:target="addExternalPath">Link folder…</span>
                    <span wire:loading wire:target="addExternalPath">Opening…</span>
                </flux:button>
                <flux:button
                    size="xs"
                    variant="ghost"
                    icon="document-plus"
                    icon:variant="outline"
                    wire:click="addExternalFile"
                    wire:loading.attr="disabled"
                    wire:target="addExternalFile"
                    data-testid="external-file-add"
                    class="flex-1"
                >
                    <span wire:loading.remove wire:target="addExternalFile">Link file…</span>
                    <span wire:loading wire:target="addExternalFile">Opening…</span>
                </flux:button>
            </div>
        </div>
    </flux:menu>
</flux:dropdown>
