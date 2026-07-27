// Alpine state for a comment thread. Emits browser events only; page-level
// Livewire components own persistence and fan the canonical reply list out.
(function (root, factory) {
    const api = factory();

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (root) {
        root.commentThread = api;
        api.autoInstall(root);
    }
})(typeof window !== 'undefined' ? window : null, function () {
    function createCommentThread({ commentId, dispatch = null }) {
        return {
            commentId,
            replying: false,
            editingReplyId: null,
            body: '',
            returnFocusElement: null,

            reply() {
                this.rememberFocus();
                this.replying = true;
                this.editingReplyId = null;
                this.body = '';
                this.focusInput();
            },

            edit(reply) {
                this.rememberFocus();
                this.replying = false;
                this.editingReplyId = reply.id;
                this.body = reply.body;
                this.focusInput();
            },

            cancel() {
                this.replying = false;
                this.editingReplyId = null;
                this.body = '';
                this.restoreFocus();
            },

            submit() {
                const body = this.body.trim();
                if (body === '') return;

                if (this.editingReplyId !== null) {
                    this.emit('rfa-update-comment-reply', {
                        replyId: this.editingReplyId,
                        body,
                    });
                } else {
                    this.emit('rfa-add-comment-reply', {
                        commentId: this.commentId,
                        body,
                    });
                }

                this.cancel();
            },

            remove(replyId) {
                this.emit('rfa-delete-comment-reply', { replyId });
            },

            focusInput() {
                if (typeof this.$nextTick !== 'function') return;
                this.$nextTick(() => this.$refs.replyInput?.focus());
            },

            rememberFocus() {
                this.returnFocusElement = typeof document === 'undefined'
                    ? null
                    : document.activeElement;
            },

            restoreFocus() {
                const element = this.returnFocusElement;
                this.returnFocusElement = null;

                if (element === null || typeof this.$nextTick !== 'function') return;
                this.$nextTick(() => element.focus?.());
            },

            emit(name, detail) {
                if (dispatch !== null) {
                    dispatch(name, detail);
                    return;
                }

                this.$dispatch(name, detail);
            },
        };
    }

    function install(root) {
        if (typeof root.Alpine === 'undefined') return false;
        root.Alpine.data('commentThread', createCommentThread);

        return true;
    }

    function autoInstall(root) {
        if (root.Alpine) {
            install(root);
        } else {
            root.document.addEventListener('alpine:init', () => install(root));
        }
    }

    return {
        createCommentThread,
        install,
        autoInstall,
    };
});
