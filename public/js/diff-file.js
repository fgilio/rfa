// Alpine component for livewire/⚡diff-file.blade.php
(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (root) {
        root.diffFile = api;
        api.autoInstall(root);
    }
})(typeof window !== 'undefined' ? window : null, function () {
    /**
     * Pure auto-scroll velocity computation. Returns px/sec; the caller
     * multiplies by frame delta to get pixel offset for the current tick.
     *
     * Boundaries match the original `_getScrollSpeed`:
     * - `y < headerBottom` strictly above sticky header → -600 (max up)
     * - `y < headerBottom + edgeZone` → proportional ramp -100..-600
     * - `y > viewportHeight - edgeZone` → proportional ramp +100..+600
     * - At exactly `headerBottom + edgeZone` and exactly `viewportHeight - edgeZone`
     *   the function returns 0 (`<` and `>` are exclusive in original).
     * - When the cursor leaves the window vertically (y > viewportHeight),
     *   `depth` exceeds 1 and velocity exceeds 600 — deliberate, no clamp.
     */
    function getScrollSpeed({ y, viewportHeight, headerBottom, edgeZone }) {
        if (y < headerBottom) {
            return -600;
        }
        if (y < headerBottom + edgeZone) {
            const depth = 1 - (y - headerBottom) / edgeZone;
            return -(100 + depth * 500);
        }
        if (y > viewportHeight - edgeZone) {
            const depth = 1 - (viewportHeight - y) / edgeZone;
            return 100 + depth * 500;
        }
        return 0;
    }

    /**
     * Walk a DOM root (typically the file's wrapper) and pull the textContent
     * of the rightmost `<td>` of each `tr[data-line-old="N"]` (or
     * `data-line-new`) row in the inclusive [startLine, endLine] range.
     *
     * Returns null when there's nothing to extract (file-level comment,
     * null start, or no matching rows).
     */
    function extractLineSnippet({ root, side, startLine, endLine }) {
        if (startLine == null || side === 'file') return null;
        const attr = side === 'left' ? 'data-line-old' : 'data-line-new';
        const start = Math.min(startLine, endLine ?? startLine);
        const end = Math.max(startLine, endLine ?? startLine);
        const lines = [];
        for (let n = start; n <= end; n++) {
            const row = root.querySelector(`tr[${attr}="${n}"]`);
            if (!row) continue;
            const cells = row.querySelectorAll('td');
            const content = cells[cells.length - 1]?.textContent;
            if (content !== undefined) lines.push(content);
        }
        return lines.length ? lines.join('\n').trimEnd() : null;
    }

    function createDiffFile({ fileId, filePath, oldPath = null, isReviewed, singleFile = false }) {
        return {
            fileId,
            filePath,
            oldPath,
            collapsed: singleFile ? false : (Alpine.store('settings')?.collapseAll || isReviewed),
            reviewed: isReviewed,

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

            // Markdown heading fold state (id -> true when collapsed)
            foldedHeadings: {},

            toggleHeadingFold(id) {
                this.foldedHeadings[id] = !this.foldedHeadings[id];
            },

            isLineFolded(ancestors) {
                if (!ancestors || ancestors.length === 0) return false;
                for (let i = 0; i < ancestors.length; i++) {
                    if (this.foldedHeadings[ancestors[i]]) return true;
                }
                return false;
            },

            onLineContextmenu(event, lineNum, side) {
                const inSelection = this.formLine !== null
                    && lineNum >= this.formLine
                    && lineNum <= (this.formEndLine ?? this.formLine);
                this.$dispatch('show-remote-menu', {
                    target: 'line',
                    fileId: this.fileId,
                    filePath: this.filePath,
                    oldPath: this.oldPath,
                    side,
                    start: inSelection ? this.formLine : lineNum,
                    end: inSelection ? (this.formEndLine ?? this.formLine) : null,
                    clientX: event.clientX,
                    clientY: event.clientY,
                });
            },
            _dragMouseX: 0,
            _dragMouseY: 0,
            _scrollRafId: null,
            _scrollLastTime: null,
            _cachedFileHeader: null,
            _onDragPointerMove: null,
            _onDragWindowBlur: null,
            _cursorOutside: false,

            toggleCollapse(event) {
                this.autoExpandedForComment = false;
                if (event.altKey) {
                    Alpine.store('settings').collapseAll = !this.collapsed;
                    this.$dispatch(this.collapsed ? 'expand-all-files' : 'collapse-all-files');
                } else {
                    this.collapsed = !this.collapsed;
                }
            },

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
                // Toggle: re-clicking the same line with an empty form closes it.
                if (
                    this.showForm
                    && !this.editingCommentId
                    && this.formSide === side
                    && this.formLine === lineNum
                    && this.formEndLine === lineNum
                    && this.formBody.trim() === ''
                ) {
                    this.cancelForm();
                    return;
                }
                // Check for existing draft comment on this line
                const comments = this.$wire.fileComments || [];
                const existingDraft = comments.find(c => c.isDraft && c.side === side && c.startLine !== null && lineNum >= c.startLine && lineNum <= (c.endLine ?? c.startLine));
                if (existingDraft) {
                    this.editComment(existingDraft);
                    return;
                }
                this.isDragging = true;
                this.dragStartLine = lineNum;
                this.dragSide = side;
                this.formLine = lineNum;
                this.formEndLine = lineNum;
                this.formSide = side;
                this.showForm = false;

                this._cachedFileHeader = this.$el.querySelector('[data-testid="file-header"]');
                this._cursorOutside = false;
                this._dragMouseX = event.clientX;
                this._dragMouseY = event.clientY;

                this._onDragPointerMove = (e) => {
                    if (e.buttons === 0) {
                        this.endDrag();
                        return;
                    }
                    this._dragMouseX = e.clientX;
                    this._dragMouseY = e.clientY;
                    this._cursorOutside = false;
                    this._ensureScrollLoop();
                };
                this._onDragWindowBlur = () => {
                    this._cursorOutside = true;
                };

                window.addEventListener('pointermove', this._onDragPointerMove);
                window.addEventListener('blur', this._onDragWindowBlur);
                this._ensureScrollLoop();
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
                this._stopScrollLoop();
                if (this._onDragPointerMove) {
                    window.removeEventListener('pointermove', this._onDragPointerMove);
                    this._onDragPointerMove = null;
                }
                if (this._onDragWindowBlur) {
                    window.removeEventListener('blur', this._onDragWindowBlur);
                    this._onDragWindowBlur = null;
                }
                this._cachedFileHeader = null;
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

            editComment(comment) {
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
                    const lineSnippet = this._extractLineSnippet(this.formSide, this.formLine, this.formEndLine);
                    this.$wire.dispatch(event, { fileId: this.fileId, side: this.formSide, startLine: this.formLine, endLine: this.formEndLine, body: this.formBody, lineSnippet });
                }
                this.cancelForm();
            },

            _extractLineSnippet(side, startLine, endLine) {
                const root = this.$el.closest(`[data-file-id="${this.fileId}"]`) ?? this.$el;
                return extractLineSnippet({ root, side, startLine, endLine });
            },

            _ensureScrollLoop() {
                if (this._scrollRafId) return;
                this._scrollLastTime = null;
                this._scrollRafId = requestAnimationFrame((ts) => this._scrollTick(ts));
            },

            _stopScrollLoop() {
                if (this._scrollRafId) {
                    cancelAnimationFrame(this._scrollRafId);
                    this._scrollRafId = null;
                }
                this._scrollLastTime = null;
            },

            _getScrollSpeed(deltaMs) {
                const headerBottom = this._cachedFileHeader
                    ? this._cachedFileHeader.getBoundingClientRect().bottom
                    : 0;
                const velocity = getScrollSpeed({
                    y: this._dragMouseY,
                    viewportHeight: window.innerHeight,
                    headerBottom,
                    edgeZone: 70,
                });
                return velocity * (deltaMs / 1000);
            },

            _scrollTick(timestamp) {
                this._scrollRafId = null;

                if (!this.isDragging || this._cursorOutside) {
                    this._scrollLastTime = null;
                    return;
                }

                const deltaMs = this._scrollLastTime
                    ? Math.min(timestamp - this._scrollLastTime, 32)
                    : 16;
                this._scrollLastTime = timestamp;

                const px = this._getScrollSpeed(deltaMs);
                if (px === 0) {
                    this._scrollLastTime = null;
                    return;
                }

                window.scrollBy(0, px);
                this._updateSelectionFromPoint();
                this._scrollRafId = requestAnimationFrame((ts) => this._scrollTick(ts));
            },

            _updateSelectionFromPoint() {
                const el = document.elementFromPoint(this._dragMouseX, this._dragMouseY);
                if (!el) return;
                const tr = el.closest('tr.diff-line');
                if (!tr || !this.$el.contains(tr)) return;

                const lineNum = this.dragSide === 'left'
                    ? (tr.dataset.lineOld ? parseInt(tr.dataset.lineOld) : null)
                    : (tr.dataset.lineNew ? parseInt(tr.dataset.lineNew) : null);
                if (lineNum === null) return;

                this.formLine = Math.min(this.dragStartLine, lineNum);
                this.formEndLine = Math.max(this.dragStartLine, lineNum);
            },

            isLineInSelection(lineNum) {
                if (this.formLine === null) return false;
                if (!this.showForm && !this.isDragging) return false;
                return lineNum >= this.formLine && lineNum <= (this.formEndLine ?? this.formLine);
            },

            onReviewedChange() {
                this.collapsed = this.reviewed;
                this.$dispatch('file-reviewed-changed', { id: this.fileId, reviewed: this.reviewed });
                this.$wire.dispatch('toggle-reviewed', { filePath: this.filePath });
            },
        };
    }

    function install(root) {
        if (root.__diffFileAttached) return false;
        root.__diffFileAttached = true;
        if (typeof root.Alpine === 'undefined') return false;
        root.Alpine.data('diffFile', createDiffFile);
        return true;
    }

    function autoInstall(root) {
        if (root.Alpine) {
            install(root);
        } else {
            root.document.addEventListener('alpine:init', () => install(root));
        }
    }

    return { getScrollSpeed, extractLineSnippet, createDiffFile, install, autoInstall };
});
