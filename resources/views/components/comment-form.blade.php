{{-- Parent Alpine scope contract: formBody, escHint, handleEscape(), cancelForm(), commentInput (x-ref) --}}
@props(['save', 'placeholder' => 'Write a comment...', 'borderClass' => 'border-y'])

{{-- ⌘↵ saves the comment form holding focus. Registered here (not globally) so
     the shortcut's owner is the thing it acts on; the generic focus-routing
     handler tolerates multiple open forms (Map-keyed by combo). --}}
<flux:card
    size="sm"
    class="!rounded-none {{ $borderClass }} border-gh-border"
    data-comment-form
    x-init="$store.shortcuts.register('comment.save', (e) => {
        const form = e.target.closest('[data-comment-form]');
        if (form) form.querySelector('[data-comment-save]')?.click();
    })"
>
    {{-- ⌘↵ to save is registered globally (config: comment.save) and routed to
         the focused form's save button; Esc-to-draft stays element-local. --}}
    <flux:textarea
        x-ref="commentInput"
        x-model="formBody"
        x-on:keydown.escape.stop="handleEscape()"
        placeholder="{{ $placeholder }} ({{ \App\Support\Shortcuts::display('comment.save') }} to save, Esc to cancel)"
        rows="auto"
        resize="none"
        class="font-mono text-xs"
    />
    <div x-show="escHint" x-cloak class="text-xs text-gh-muted mt-1" data-testid="esc-hint">Press Esc again to save as draft</div>
    <div class="flex justify-end gap-2 mt-2">
        <flux:button variant="ghost" size="sm" x-on:click="cancelForm()">Cancel</flux:button>
        <flux:button variant="primary" size="sm" data-comment-save x-on:click="{{ $save }}()" x-bind:disabled="!formBody.trim()">Save</flux:button>
    </div>
</flux:card>
