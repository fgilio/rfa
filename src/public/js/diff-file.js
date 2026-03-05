// Alpine component for livewire/⚡diff-file.blade.php
(function () {
    function init() {
        Alpine.data('diffFile', ({ fileId, filePath, isViewed }) => ({
            fileId,
            filePath,
            collapsed: isViewed,
            viewed: isViewed,

            // Comment form state
            formLine: null,
            formEndLine: null,
            formSide: 'right',
            formBody: '',
            lastClickedLine: null,
            showForm: false,
            editingCommentId: null,
            escHint: false,
            escTimer: null,
            autoExpandedForComment: false,

            // Line-drag state
            isDragging: false,
            dragStartLine: null,
            dragSide: null,

            focusCommentInput() {
                this.$nextTick(() => { this.$refs.commentInput?.focus(); });
            },

            handleLineMousedown(lineNum, side, event) {
                this.autoExpandedForComment = false;
                if (event.button !== 0) return;
                if (event.shiftKey && this.lastClickedLine !== null) {
                    this.formLine = Math.min(this.lastClickedLine, lineNum);
                    this.formEndLine = Math.max(this.lastClickedLine, lineNum);
                    this.formSide = side;
                    this.showForm = true;
                    this.focusCommentInput();
                    return;
                }
                // Check for existing draft comment on this line
                const comments = this.$wire.fileComments || [];
                const existingDraft = comments.find(c => c.isDraft && c.side === side && c.startLine !== null && lineNum >= c.startLine && lineNum <= (c.endLine ?? c.startLine));
                if (existingDraft) {
                    this.editDraft(existingDraft);
                    return;
                }
                this.isDragging = true;
                this.dragStartLine = lineNum;
                this.dragSide = side;
                this.formLine = lineNum;
                this.formEndLine = lineNum;
                this.formSide = side;
                this.showForm = false;
            },

            onDragOver(newLineNum, oldLineNum) {
                if (!this.isDragging) return;
                let lineNum = this.dragSide === 'left' ? oldLineNum : newLineNum;
                if (lineNum === null) return;
                this.formLine = Math.min(this.dragStartLine, lineNum);
                this.formEndLine = Math.max(this.dragStartLine, lineNum);
            },

            endDrag() {
                if (!this.isDragging) return;
                this.isDragging = false;
                this.showForm = true;
                this.lastClickedLine = this.formEndLine;
                this.focusCommentInput();
            },

            cancelForm() {
                this.showForm = false;
                this.formBody = '';
                this.formLine = null;
                this.formEndLine = null;
                this.escHint = false;
                if (this.escTimer) { clearTimeout(this.escTimer); this.escTimer = null; }
                this.editingCommentId = null;
                if (this.autoExpandedForComment) {
                    this.autoExpandedForComment = false;
                    this.collapsed = true;
                    this.$nextTick(() => { this.$refs.fileCommentBtn?.focus(); });
                }
            },

            handleEscape() {
                if (this.formBody.trim() === '') {
                    if (this.editingCommentId) {
                        this.$wire.dispatch('delete-comment', { commentId: this.editingCommentId });
                    }
                    this.cancelForm();
                    return;
                }
                if (!this.escHint) {
                    this.escHint = true;
                    this.escTimer = setTimeout(() => { this.escHint = false; this.escTimer = null; }, 1500);
                    return;
                }
                // Second Esc - save as draft
                if (this.escTimer) { clearTimeout(this.escTimer); this.escTimer = null; }
                this.submitComment(true);
            },

            editDraft(comment) {
                this.formBody = comment.body;
                this.formLine = comment.startLine;
                this.formEndLine = comment.endLine;
                this.formSide = comment.side;
                this.editingCommentId = comment.id;
                this.showForm = true;
                this.focusCommentInput();
            },

            openFileComment() {
                this.formLine = null;
                this.formEndLine = null;
                this.formSide = 'file';
                this.showForm = true;

                if (this.collapsed) {
                    this.autoExpandedForComment = true;
                    this.collapsed = false;
                }

                this.$nextTick(() => {
                    requestAnimationFrame(() => {
                        this.$refs.commentInput?.focus();
                        this.$refs.fileCommentForm?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    });
                });
            },

            submitComment(isDraft = false) {
                if (this.formBody.trim() === '') return;
                if (this.editingCommentId) {
                    this.$wire.dispatch('update-comment', { commentId: this.editingCommentId, body: this.formBody, isDraft });
                } else {
                    const event = isDraft ? 'add-draft-comment' : 'add-comment';
                    this.$wire.dispatch(event, { fileId: this.fileId, side: this.formSide, startLine: this.formLine, endLine: this.formEndLine, body: this.formBody });
                }
                this.cancelForm();
            },

            isLineInSelection(lineNum) {
                if (this.formLine === null) return false;
                if (!this.showForm && !this.isDragging) return false;
                return lineNum >= this.formLine && lineNum <= (this.formEndLine ?? this.formLine);
            },

            onViewedChange() {
                this.collapsed = this.viewed;
                this.$dispatch('file-viewed-changed', { id: this.fileId, viewed: this.viewed });
                this.$wire.dispatch('toggle-viewed', { filePath: this.filePath });
            },
        }));
    }

    window.Alpine ? init() : document.addEventListener('alpine:init', init);
})();
