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

    // Formats selected diff text as a GitHub-style blockquote citation that
    // seeds a fresh comment. Every line is prefixed with `> ` and a blank line
    // follows so the cursor lands below the quote. Leading/trailing blank lines
    // are trimmed; interior blank lines are preserved (and still quoted).
    // Returns '' for empty/whitespace-only input so callers can skip pre-filling.
    function formatCitation(text) {
        if (typeof text !== 'string') return '';
        const trimmed = text.replace(/^\s+|\s+$/g, '');
        if (trimmed === '') return '';

        return trimmed.split('\n').map((line) => `> ${line}`).join('\n') + '\n\n';
    }

    // Walks up from a Selection/Range node to the `.diff-line` row it sits in.
    // Text nodes resolve through their parent element. Returns null when the
    // node isn't inside a diff row (e.g. a selection in a comment box).
    function closestDiffLine(node) {
        const el = node && node.nodeType === 3 ? node.parentElement : node;
        return el && typeof el.closest === 'function' ? el.closest('.diff-line') : null;
    }

    function rowLineForSide(row, side) {
        if (!row) return null;
        const raw = side === 'left' ? row.dataset.lineOld : row.dataset.lineNew;
        if (raw === undefined || raw === '') return null;
        const value = parseInt(raw, 10);

        return Number.isFinite(value) ? value : null;
    }

    // Maps a text-selection Range onto the diff line(s) it covers, scoped to
    // `root` (a single diff-file element). The side is anchored off the start
    // row — new (right) when it carries a `data-line-new`, else old (left) — and
    // both endpoints are read on that side. Returns null when the selection
    // doesn't start on a diff row inside `root`, so non-matching files ignore it.
    function selectionLineRange(range, root) {
        if (!range) return null;
        const startRow = closestDiffLine(range.startContainer);
        if (!startRow || (root && !root.contains(startRow))) return null;

        const side = startRow.dataset.lineNew ? 'right' : 'left';
        const startLine = rowLineForSide(startRow, side);
        if (startLine === null) return null;

        let endRow = closestDiffLine(range.endContainer);
        if (!endRow || (root && !root.contains(endRow))) endRow = startRow;
        const endLine = rowLineForSide(endRow, side) ?? startLine;

        return { side, startLine, endLine };
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
            // Citation captured at line-number mousedown, applied when the form
            // opens (drag end / shift-click). Holds a formatted `> …` block or ''.
            _pendingCitation: '',
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
                this.$nextTick(() => {
                    const input = this.$refs.commentInput;
                    if (!input) return;
                    input.focus();
                    // Drop the caret after any pre-filled text (citation / edited
                    // body) so the user types below the quote instead of inside it.
                    const end = input.value?.length ?? 0;
                    input.setSelectionRange?.(end, end);
                });
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

                // Snapshot any active text selection as a citation now: the
                // gutter's `mousedown.prevent` stops the click from collapsing it,
                // but it must be read before the form (drag end / shift-click) opens.
                const citation = formatCitation(this._selectionTextWithinFile());

                if (event.shiftKey && this.lastClickedPoint?.side === clickedPoint.side) {
                    this.setLineSelection(this.lastClickedPoint, clickedPoint);
                    if (citation && this.formBody.trim() === '') {
                        this.formBody = citation;
                    }
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
                this._pendingCitation = citation;
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
                if (this._pendingCitation && this.formBody.trim() === '') {
                    this.formBody = this._pendingCitation;
                }
                this._pendingCitation = '';
                this.lastClickedPoint = this.formEndPoint ? { ...this.formEndPoint } : null;
                this.focusCommentInput();
            },

            cancelForm() {
                this.stopDragTracking();
                this.showForm = false;
                this.formBody = '';
                this._pendingCitation = '';
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

            // Keyboard entry point (catalog id `review.comment-selection`, 'c'):
            // open the line-comment composer on the row(s) under the current text
            // selection, seeded with that text as a citation. Every diff-file
            // receives the window event; only the one containing the selection
            // acts (selectionLineRange returns null for the rest).
            commentOnSelection() {
                const win = this.$el?.ownerDocument?.defaultView || (typeof window !== 'undefined' ? window : null);
                const selection = win?.getSelection?.();
                if (!selection || selection.rangeCount === 0 || selection.isCollapsed) {
                    return;
                }

                const range = selection.getRangeAt(0);
                const lineRange = selectionLineRange(range, this.$el);
                if (!lineRange) return;

                const citation = formatCitation(selection.toString());

                this.autoExpandedForComment = false;
                this.editingCommentId = null;
                this.setLineSelection(
                    createLinePoint(lineRange.startLine, lineRange.side),
                    createLinePoint(lineRange.endLine, lineRange.side),
                );
                this.lastClickedPoint = this.formEndPoint ? { ...this.formEndPoint } : null;
                if (citation && this.formBody.trim() === '') {
                    this.formBody = citation;
                }
                this.showForm = true;
                this.focusCommentInput();
            },

            // Current text selection as a string, but only when it begins inside
            // this file's diff rows — so a stray selection elsewhere (a comment,
            // another file) never leaks into this file's citation. '' otherwise.
            _selectionTextWithinFile() {
                const win = this.$el?.ownerDocument?.defaultView || (typeof window !== 'undefined' ? window : null);
                const selection = win?.getSelection?.();
                if (!selection || selection.rangeCount === 0 || selection.isCollapsed) {
                    return '';
                }
                const row = closestDiffLine(selection.anchorNode);
                if (!row || !this.$el?.contains(row)) {
                    return '';
                }

                return selection.toString();
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
        };
    }

    function install(root) {
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

        // pendingCommentForms is page-lifetime by design — it survives a diff-file
        // remount from filtering/hide-reviewed. But it must NOT outlive a
        // wire:navigate to another page: file ids are content-path hashes, so a
        // different review can mount a colliding id and resurrect a stale draft.
        // Clear on navigation, registered once (see public/js/CLAUDE.md).
        if (root.document && !root.__diffFilePendingFormsCleanup) {
            root.__diffFilePendingFormsCleanup = true;
            root.document.addEventListener('livewire:navigating', () => pendingCommentForms.clear());
        }
    }

    return {
        getScrollSpeed,
        extractLineSnippet,
        expanderToRefocus,
        createLinePoint,
        areLinePointsEqual,
        rowContainsLinePoint,
        formatCitation,
        closestDiffLine,
        selectionLineRange,
        createDiffFile,
        install,
        autoInstall,
    };
});
