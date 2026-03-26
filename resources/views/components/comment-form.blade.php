{{-- Parent Alpine scope contract: formBody, escHint, handleEscape(), cancelForm(), commentInput (x-ref) --}}
@props(['save', 'placeholder' => 'Write a comment...', 'borderClass' => 'border-y'])

<flux:card size="sm" class="!rounded-none {{ $borderClass }} border-gh-border" data-comment-form>
    <flux:textarea
        x-ref="commentInput"
        x-model="formBody"
        x-on:keydown.meta.enter="{{ $save }}()"
        x-on:keydown.ctrl.enter="{{ $save }}()"
        x-on:keydown.escape.stop="handleEscape()"
        placeholder="{{ $placeholder }} (Cmd/Ctrl+Enter to save, Esc to cancel)"
        rows="auto"
        resize="none"
        class="font-mono text-xs"
    />
    <div x-show="escHint" x-cloak class="text-xs text-gh-muted mt-1" data-testid="esc-hint">Press Esc again to save as draft</div>
    <div class="flex justify-end gap-2 mt-2">
        <flux:button variant="ghost" size="sm" x-on:click="cancelForm()">Cancel</flux:button>
        <flux:button variant="primary" size="sm" color="green" x-on:click="{{ $save }}()">Save</flux:button>
    </div>
</flux:card>
