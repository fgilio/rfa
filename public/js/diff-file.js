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
    // Returns velocity in px/sec; caller multiplies by frame delta. Velocity is
    // intentionally not clamped: when the cursor leaves the viewport vertically
    // `depth` exceeds 1 and the page should keep accelerating past 600.
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

    // Reads the `.diff-cell-content` of each `.diff-line[data-line-old|new="N"]`
    // row in [startLine, endLine]. Returns null for file-level comments, null
    // start, or no matching rows.
    function extractLineSnippet({ root, side, startLine, endLine }) {
        if (startLine == null || side === 'file') return null;
        const attr = side === 'left' ? 'data-line-old' : 'data-line-new';
        const start = Math.min(startLine, endLine ?? startLine);
        const end = Math.max(startLine, endLine ?? startLine);
        const lines = [];
        for (let n = start; n <= end; n++) {
            const row = root.querySelector(`.diff-line[${attr}="${n}"]`);
            if (!row) continue;
            const content = row.querySelector('.diff-cell-content')?.textContent;
            if (content !== undefined) lines.push(content);
        }
        return lines.length ? lines.join('\n').trimEnd() : null;
    }

    // The expander to refocus after a gap expand re-renders, keyed by hunk index.
    // A keyboard-activated expander loses focus when its node is replaced by the
    // post-expand morph; this hands focus to the expander that now occupies the
    // same gap. Returns null when the gap fully closed (the "all N hidden lines"
    // target) — nothing remains to focus there, so focus is left to fall back.
    function expanderToRefocus(root, gapKey) {
        if (!root || gapKey === null || gapKey === undefined || gapKey === '') {
            return null;
        }
        return root.querySelector(`[data-expand-gap="${gapKey}"]`);
    }

    function createLinePoint(lineNumber, side) {
        if (lineNumber == null || (side !== 'left' && side !== 'right')) return null;
        const line = Number(lineNumber);
        if (!Number.isFinite(line)) return null;

        return { line, side };
    }

    function areLinePointsEqual(first, second) {
        if (first == null || second == null) return first === second;

        return first.line === second.line && first.side === second.side;
    }

    function rowContainsLinePoint(rowSide, oldLineNumber, newLineNumber, point) {
        if (point == null) return false;
        if (point.side === 'left') return oldLineNumber === point.line && (rowSide === 'left' || rowSide === 'context');
        if (point.side === 'right') return newLineNumber === point.line && (rowSide === 'right' || rowSide === 'context');

        return false;
    }

    // In-progress comment form state, keyed by file id. Filtering or hiding
    // reviewed files unmounts diff-file components that leave the server-visible
    // list; snapshotting the open form on destroy lets a later remount restore
    // the user's unsent text instead of dropping it. Page-lifetime by design.
    const pendingCommentForms = new Map();

    function createDiffFile({ fileId, filePath, oldPath = null, status = 'modified', isReviewed, singleFile = false }) {
        const pending = window.__rfaPendingExpandFiles;
        const wantsExpand = pending && pending.has(fileId);
        if (wantsExpand) pending.delete(fileId);
        return {
            fileId,
            filePath,
            oldPath,
            status,
            collapsed: wantsExpand ? false : (singleFile ? false : (Alpine.store('settings')?.collapseAll || isReviewed)),
            reviewed: isReviewed,

            // Comment form state
            formLine: null,
            formEndLine: null,
            formSide: 'right',
            formStartPoint: null,
            formEndPoint: null,
            formBody: '',
            lastClickedPoint: null,
            showForm: false,
            editingCommentId: null,
            escHint: false,
            escTimer: null,
            autoExpandedForComment: false,

            // Line-drag state
            isDragging: false,
            dragStartPoint: null,
            dragSide: null,

            // Markdown heading fold state (id -> true when collapsed)
            foldedHeadings: {},

            toggleHeadingFold(id) {
                this.foldedHeadings[id] = !this.foldedHeadings[id];
            },

            markDiffActionStart(action) {
                this.$dispatch('rfa:diff-action-start', {
                    fileId: this.fileId,
                    action,
                });
            },

            // Hunk index of a keyboard-activated gap expander, remembered so focus
            // can return to that gap after the expand re-render replaces the
            // activated node. Null for mouse clicks (focus shouldn't jump) and for
            // the master "full file" expander (no gap remains to focus).
            _refocusExpandKey: null,
            _onDiffActionCompleted: null,

            armExpandRefocus(event, gapKey) {
                // Keyboard activation of a <button> fires a click with detail 0;
                // mouse clicks report detail >= 1. Only keyboard users lose their
                // place on the re-render, so only they get focus restored.
                this._refocusExpandKey = (event && event.detail === 0 && gapKey != null) ? gapKey : null;
            },

            restoreExpandFocus(action) {
                if (action !== 'expandGap' && action !== 'expandContext') {
                    return;
                }
                const gapKey = this._refocusExpandKey;
                this._refocusExpandKey = null;
                if (gapKey == null) {
                    return;
                }
                this.$nextTick(() => {
                    const target = expanderToRefocus(this.$root, gapKey);
                    if (target) {
                        target.focus();
                    }
                });
            },

            isLineFolded(ancestors) {
                if (!ancestors || ancestors.length === 0) return false;
                for (let i = 0; i < ancestors.length; i++) {
                    if (this.foldedHeadings[ancestors[i]]) return true;
                }
                return false;
            },

            onLineContextmenu(event, lineNum, side) {
                const commentSide = side === 'old' ? 'left' : 'right';
                const inSelection = this.formLine !== null
                    && this.formSide === commentSide
                    && lineNum >= this.formLine
                    && lineNum <= (this.formEndLine ?? this.formLine);
                this.$dispatch('open-remote-menu', {
                    target: 'line',
                    fileId: this.fileId,
                    filePath: this.filePath,
                    oldPath: this.oldPath,
                    status: this.status,
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
                this.$dispatch('comment-form-opened', { fileId: this.fileId });
                this.$nextTick(() => { this.$refs.commentInput?.focus(); });
            },

            closeEmptyFormFromAnotherFile(openedFileId) {
                if (openedFileId === this.fileId || !this.showForm || this.editingCommentId || this.formBody.trim() !== '') {
                    return;
                }

                this.cancelForm();
            },

            handleLineMousedown(lineNum, side, event) {
                this.autoExpandedForComment = false;
                if (event.button !== 0) return;

                const clickedPoint = createLinePoint(lineNum, side);
                if (clickedPoint == null) return;

                if (event.shiftKey && this.lastClickedPoint?.side === clickedPoint.side) {
                    this.setLineSelection(this.lastClickedPoint, clickedPoint);
                    this.showForm = true;
                    this.focusCommentInput();
                    return;
                }
                // Toggle: re-clicking the same line with an empty form closes it.
                if (
                    this.showForm
                    && !this.editingCommentId
                    && areLinePointsEqual(this.formStartPoint, clickedPoint)
                    && areLinePointsEqual(this.formEndPoint, clickedPoint)
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
                this.dragStartPoint = clickedPoint;
                this.dragSide = side;
                this.setLineSelection(clickedPoint, clickedPoint);
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
                const lineNum = this.dragSide === 'left' ? oldLineNum : newLineNum;
                const point = createLinePoint(lineNum, this.dragSide);
                if (point === null || this.dragStartPoint === null) return;
                this.setLineSelection(this.dragStartPoint, point);
            },

            endDrag() {
                if (!this.isDragging) return;
                this.stopDragTracking();
                this._cachedFileHeader = null;
                this.showForm = true;
                this.lastClickedPoint = this.formEndPoint ? { ...this.formEndPoint } : null;
                this.focusCommentInput();
            },

            cancelForm() {
                this.stopDragTracking();
                this.showForm = false;
                this.formBody = '';
                this.clearLineSelection();
                this.escHint = false;
                if (this.escTimer) { clearTimeout(this.escTimer); this.escTimer = null; }
                this.editingCommentId = null;
                if (this.autoExpandedForComment) {
                    this.autoExpandedForComment = false;
                    this.collapsed = true;
                    this.$nextTick(() => { this.$refs.fileCommentBtn?.focus(); });
                }
            },

            stopDragTracking() {
                this._stopScrollLoop();
                if (this._onDragPointerMove) {
                    window.removeEventListener('pointermove', this._onDragPointerMove);
                    this._onDragPointerMove = null;
                }
                if (this._onDragWindowBlur) {
                    window.removeEventListener('blur', this._onDragWindowBlur);
                    this._onDragWindowBlur = null;
                }
                this.isDragging = false;
                this.dragStartPoint = null;
            },

            restorePendingCommentForm() {
                const pendingForm = pendingCommentForms.get(this.fileId);
                if (!pendingForm) return;

                pendingCommentForms.delete(this.fileId);
                Object.assign(this, pendingForm);
                this.showForm = true;
                this.collapsed = false;
            },

            persistPendingCommentForm() {
                if (!this.showForm || this.formBody.trim() === '') return;

                pendingCommentForms.set(this.fileId, {
                    formLine: this.formLine,
                    formEndLine: this.formEndLine,
                    formSide: this.formSide,
                    formStartPoint: this.formStartPoint ? { ...this.formStartPoint } : null,
                    formEndPoint: this.formEndPoint ? { ...this.formEndPoint } : null,
                    formBody: this.formBody,
                    editingCommentId: this.editingCommentId,
                });
            },

            init() {
                this.restorePendingCommentForm();

                // expandGap/expandContext dispatch rfa:diff-action-completed after
                // their re-render. We attach imperatively rather than via @-binding
                // because the colon in the event name is awkward in Alpine's @
                // syntax — same reason runtime-diagnostics.js listens this way.
                this._onDiffActionCompleted = (event) => {
                    const detail = event.detail || {};
                    if (String(detail.fileId) !== String(this.fileId)) {
                        return;
                    }
                    this.restoreExpandFocus(detail.action);
                };
                window.addEventListener('rfa:diff-action-completed', this._onDiffActionCompleted);
            },

            destroy() {
                this.persistPendingCommentForm();
                this.stopDragTracking();
                if (this.escTimer) { clearTimeout(this.escTimer); this.escTimer = null; }
                this.escHint = false;
                if (this._onDiffActionCompleted) {
                    window.removeEventListener('rfa:diff-action-completed', this._onDiffActionCompleted);
                    this._onDiffActionCompleted = null;
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
                this.formSide = comment.side;
                if (comment.side === 'file') {
                    this.clearLineSelection();
                } else {
                    this.setLineSelection(
                        createLinePoint(comment.startLine, comment.side),
                        createLinePoint(comment.endLine ?? comment.startLine, comment.side)
                    );
                }
                this.editingCommentId = comment.id;
                this.showForm = true;
                this.focusCommentInput();
            },

            openFileComment() {
                this.clearLineSelection();
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

            setLineSelection(startPoint, endPoint = startPoint) {
                if (startPoint == null) {
                    this.clearLineSelection();
                    return;
                }

                const rangeEndPoint = endPoint?.side === startPoint.side ? endPoint : startPoint;
                const [start, end] = startPoint.line <= rangeEndPoint.line
                    ? [startPoint, rangeEndPoint]
                    : [rangeEndPoint, startPoint];

                this.formStartPoint = { ...start };
                this.formEndPoint = { ...end };
                this.formSide = start.side;
                this.formLine = start.line;
                this.formEndLine = end.line;
            },

            clearLineSelection() {
                this.formStartPoint = null;
                this.formEndPoint = null;
                this.formLine = null;
                this.formEndLine = null;
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
                const row = el.closest('.diff-line');
                if (!row || !this.$el.contains(row)) return;

                const lineNum = this.dragSide === 'left'
                    ? (row.dataset.lineOld ? parseInt(row.dataset.lineOld) : null)
                    : (row.dataset.lineNew ? parseInt(row.dataset.lineNew) : null);
                const point = createLinePoint(lineNum, this.dragSide);
                if (point === null || this.dragStartPoint === null) return;

                this.setLineSelection(this.dragStartPoint, point);
            },

            isLineInSelection(lineNum) {
                if (this.formLine === null) return false;
                if (!this.showForm && !this.isDragging) return false;
                return lineNum >= this.formLine && lineNum <= (this.formEndLine ?? this.formLine);
            },

            isRowInSelection(rowSide, oldLineNum, newLineNum) {
                if (this.formSide === 'file') return false;
                if (!this.showForm && !this.isDragging) return false;

                const lineNum = this.formSide === 'left' ? oldLineNum : newLineNum;
                return this.isLineInSelection(lineNum);
            },

            shouldShowLineCommentForm(rowSide, oldLineNum, newLineNum) {
                if (!this.showForm || this.formSide === 'file') return false;

                return rowContainsLinePoint(rowSide, oldLineNum, newLineNum, this.formEndPoint);
            },

            onReviewedChange() {
                this.collapsed = this.reviewed;
                this.$dispatch('file-reviewed-changed', { id: this.fileId, reviewed: this.reviewed });
                this.$wire.dispatch('toggle-reviewed', { filePath: this.filePath });
            },
        };
    }

    function install(root) {
        if (typeof root.Alpine === 'undefined' || root.__diffFileAttached) return false;
        root.__diffFileAttached = true;
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

    return {
        getScrollSpeed,
        extractLineSnippet,
        expanderToRefocus,
        createLinePoint,
        areLinePointsEqual,
        rowContainsLinePoint,
        createDiffFile,
        install,
        autoInstall,
    };
});
