import { describe, expect, it, vi } from 'vitest';
import commentThread from '../../public/js/comment-thread.js';

const { createCommentThread, install, autoInstall } = commentThread;

function state(dispatch = vi.fn()) {
    return Object.assign(
        createCommentThread({ commentId: 'c-1', dispatch }),
        {
            $nextTick: (callback) => callback(),
            $refs: { replyInput: { focus: vi.fn() } },
        },
    );
}

describe('commentThread', () => {
    it('opens a blank reply composer and focuses it', () => {
        const thread = state();

        thread.reply();

        expect(thread.replying).toBe(true);
        expect(thread.editingReplyId).toBeNull();
        expect(thread.body).toBe('');
        expect(thread.$refs.replyInput.focus).toHaveBeenCalledOnce();
    });

    it('emits an add event with trimmed content', () => {
        const dispatch = vi.fn();
        const thread = state(dispatch);
        thread.reply();
        thread.body = '  Follow-up  ';

        thread.submit();

        expect(dispatch).toHaveBeenCalledWith('add-comment-reply', {
            commentId: 'c-1',
            body: 'Follow-up',
        });
        expect(thread.replying).toBe(false);
        expect(thread.body).toBe('');
    });

    it('edits a reply through the update event', () => {
        const dispatch = vi.fn();
        const thread = state(dispatch);
        thread.edit({ id: 'r-1', body: 'Before' });
        thread.body = 'After';

        thread.submit();

        expect(dispatch).toHaveBeenCalledWith('update-comment-reply', {
            replyId: 'r-1',
            body: 'After',
        });
        expect(thread.editingReplyId).toBeNull();
    });

    it('does not emit an empty reply', () => {
        const dispatch = vi.fn();
        const thread = state(dispatch);
        thread.reply();
        thread.body = '   ';

        thread.submit();

        expect(dispatch).not.toHaveBeenCalled();
        expect(thread.replying).toBe(true);
    });

    it('emits reply deletion without owning persistence', () => {
        const dispatch = vi.fn();
        const thread = state(dispatch);

        thread.remove('r-1');

        expect(dispatch).toHaveBeenCalledWith('delete-comment-reply', { replyId: 'r-1' });
    });

    it('dispatches reply events globally through Livewire by default', () => {
        const dispatch = vi.fn();
        window.Livewire = { dispatch };
        const thread = state(null);
        thread.body = 'Follow-up';

        thread.submit();

        expect(dispatch).toHaveBeenCalledWith('add-comment-reply', {
            commentId: 'c-1',
            body: 'Follow-up',
        });

        delete window.Livewire;
    });

    it('installs immediately when Alpine exists', () => {
        const data = vi.fn();
        const root = { Alpine: { data } };

        expect(install(root)).toBe(true);
        expect(data).toHaveBeenCalledWith('commentThread', createCommentThread);
    });

    it('defers installation until alpine:init', () => {
        const listener = vi.fn();
        const root = {
            document: { addEventListener: listener },
        };

        autoInstall(root);

        expect(listener).toHaveBeenCalledWith('alpine:init', expect.any(Function));
    });
});
