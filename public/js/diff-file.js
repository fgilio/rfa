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

    function trimTrailingUrlPunctuation(candidate) {
        let url = candidate.replace(/[.,;:!?]+$/u, '');
        const bracketPairs = [
            ['(', ')'],
            ['[', ']'],
            ['{', '}'],
        ];

        bracketPairs.forEach(([opening, closing]) => {
            while (url.endsWith(closing) && url.split(closing).length > url.split(opening).length) {
                url = url.slice(0, -1);
            }
        });

        return url;
    }

    function urlMatchesInText(text) {
        if (typeof text !== 'string') {
            return [];
        }

        return Array.from(text.matchAll(/\bhttps?:\/\/[^\s<>"'`]+/giu))
            .map((match) => {
                const url = trimTrailingUrlPunctuation(match[0]);
                const start = match.index;

                return { url, start, end: start + url.length };
            })
            .filter((match) => match.url.length > 0);
    }

    function urlMatchAtTextOffset(text, offset) {
        if (!Number.isInteger(offset) || offset < 0) {
            return null;
        }

        return urlMatchesInText(text)
            .find((match) => offset >= match.start && offset < match.end) ?? null;
    }

    function urlAtTextOffset(text, offset) {
        return urlMatchAtTextOffset(text, offset)?.url ?? null;
    }

    function caretAtPoint(document, x, y) {
        if (typeof document.caretPositionFromPoint === 'function') {
            const position = document.caretPositionFromPoint(x, y);

            return position ? { node: position.offsetNode, offset: position.offset } : null;
        }

        if (typeof document.caretRangeFromPoint === 'function') {
            const range = document.caretRangeFromPoint(x, y);

            return range ? { node: range.startContainer, offset: range.startOffset } : null;
        }

        return null;
    }

    function urlMatchAtPoint(event) {
        const target = event.target?.nodeType === 3 ? event.target.parentElement : event.target;
        const cell = target?.closest?.('.diff-cell-content');
        if (!cell) {
            return null;
        }

        const textRoot = target.closest('.diff-md-td') ?? cell;
        const document = textRoot.ownerDocument;
        const caret = caretAtPoint(document, event.clientX, event.clientY);
        if (!caret || !textRoot.contains(caret.node)) {
            return null;
        }

        const range = document.createRange();
        range.selectNodeContents(textRoot);
        range.setEnd(caret.node, caret.offset);
        const textOffset = range.toString().length;
        const text = textRoot.textContent ?? '';
        const offsets = [textOffset, textOffset - 1].filter((offset, index, values) => offset >= 0 && values.indexOf(offset) === index);

        for (const offset of offsets) {
            const match = urlMatchAtTextOffset(text, offset);
            const scopedMatch = match ? { ...match, cell: textRoot } : null;
            const urlRange = rangeForUrlMatch(scopedMatch);

            if (rangeContainsPoint(urlRange, event.clientX, event.clientY)) {
                return scopedMatch;
            }
        }

        return null;
    }

    function urlAtClick(event) {
        const control = event?.target?.closest?.('[data-diff-url-control]');
        if (control && event.detail === 0) {
            return urlMatchForKeyboardControl(control)?.url ?? null;
        }

        if (!event?.metaKey || event.button !== 0) {
            return null;
        }

        return urlMatchAtPoint(event)?.url ?? null;
    }

    function textPositionAtOffset(root, offset) {
        const walker = root.ownerDocument.createTreeWalker(root, 4);
        let remaining = offset;
        let textNode = walker.nextNode();

        while (textNode) {
            if (remaining <= textNode.data.length) {
                return { node: textNode, offset: remaining };
            }

            remaining -= textNode.data.length;
            textNode = walker.nextNode();
        }

        return null;
    }

    function rangeForUrlMatch(match) {
        if (!match?.cell) {
            return null;
        }

        const start = textPositionAtOffset(match.cell, match.start);
        const end = textPositionAtOffset(match.cell, match.end);
        if (!start || !end) {
            return null;
        }

        const range = match.cell.ownerDocument.createRange();
        range.setStart(start.node, start.offset);
        range.setEnd(end.node, end.offset);

        return range;
    }

    function rangeContainsPoint(range, x, y) {
        if (!range || typeof range.getClientRects !== 'function') {
            return false;
        }

        return Array.from(range.getClientRects()).some((rect) => (
            rect.width > 0
            && rect.height > 0
            && x >= rect.left
            && x < rect.right
            && y >= rect.top
            && y < rect.bottom
        ));
    }

    function urlTextRoots(root) {
        if (!root) {
            return [];
        }

        return Array.from(root.querySelectorAll('.diff-cell-content:not([aria-hidden="true"])'))
            .flatMap((cell) => {
                const tableCells = Array.from(cell.querySelectorAll('.diff-md-td'));

                return tableCells.length > 0 ? tableCells : [cell];
            });
    }

    function urlMatchForKeyboardControl(control) {
        if (!control?.matches?.('[data-diff-url-control]')) {
            return null;
        }

        const textRoot = control.closest('.diff-md-td') ?? control.closest('.diff-cell-content');
        const start = Number(control.dataset.diffUrlStart);
        const end = Number(control.dataset.diffUrlEnd);
        const match = urlMatchesInText(textRoot?.textContent ?? '')
            .find((candidate) => candidate.start === start && candidate.end === end && candidate.url === control.dataset.diffUrl);

        return match && textRoot ? { ...match, cell: textRoot } : null;
    }

    function installKeyboardUrlControls(root, tooltipId) {
        urlTextRoots(root).forEach((textRoot) => {
            Array.from(textRoot.children)
                .filter((child) => child.matches('[data-diff-url-control]'))
                .forEach((control) => control.remove());

            urlMatchesInText(textRoot.textContent ?? '').forEach((match) => {
                const control = textRoot.ownerDocument.createElement('button');
                control.type = 'button';
                control.className = 'sr-only';
                control.dataset.diffUrlControl = '';
                control.dataset.diffUrl = match.url;
                control.dataset.diffUrlStart = String(match.start);
                control.dataset.diffUrlEnd = String(match.end);
                control.setAttribute('aria-label', `Open ${match.url} in the system browser`);
                if (tooltipId) {
                    control.setAttribute('aria-describedby', tooltipId);
                }
                textRoot.append(control);
            });
        });
    }

    const hoveredUrlHighlightName = 'rfa-hovered-diff-url';

    function showUrlHighlight(match) {
        const view = match?.cell?.ownerDocument?.defaultView;
        const range = rangeForUrlMatch(match);
        if (!view?.CSS?.highlights || typeof view.Highlight !== 'function' || !range) {
            return false;
        }

        view.CSS.highlights.set(hoveredUrlHighlightName, new view.Highlight(range));

        return true;
    }

    function clearUrlHighlight(document) {
        document?.defaultView?.CSS?.highlights?.delete(hoveredUrlHighlightName);
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
    // follows so the cursor lands below the quote. Blank (whitespace-only) lines
    // at the top and bottom of the selection are dropped (e.g. a trailing
    // newline), but each remaining line keeps its own indentation so quoted code
    // isn't dedented; interior blank lines are preserved (and still quoted).
    // Returns '' when the selection is empty/whitespace-only.
    function formatCitation(text) {
        if (typeof text !== 'string') return '';
        const lines = text.split('\n');
        while (lines.length && lines[0].trim() === '') { lines.shift(); }
        while (lines.length && lines[lines.length - 1].trim() === '') { lines.pop(); }
        if (lines.length === 0) return '';

        return lines.map((line) => `> ${line}`).join('\n') + '\n\n';
    }

    // Reconstructs the selected text from `range`, scoped to `root` (one diff
    // file), reading only the source-text content cells. `Selection.toString()`
    // serializes every Text node the range spans, including the line-number and
    // +/- prefix gutters — which are `user-select: none` (so they never render
    // as selected) yet still land in the string once a selection crosses a row
    // boundary, leaking `6`/`+` chrome into a citation. Walking the
    // `.diff-cell-content` cells instead keeps the quote to real source: each
    // row contributes one line, clamped to the selection's start/end offsets,
    // joined with newlines so the citation mirrors the source's own line breaks
    // and indentation. Returns '' when the range covers no content cell.
    function selectionSourceText(range, root) {
        if (!range || !root) return '';
        const doc = root.ownerDocument;

        const seenRows = new Set();
        const pieces = [];
        root.querySelectorAll('.diff-cell-content').forEach((cell) => {
            if (!range.intersectsNode(cell)) return;
            const row = cell.closest('.diff-line');
            // A split-view context row carries the same text in its primary and
            // mirror cells; keep the first that the range touches so the line
            // isn't quoted twice.
            if (row) {
                if (seenRows.has(row)) return;
                seenRows.add(row);
            }

            const cellRange = doc.createRange();
            cellRange.selectNodeContents(cell);
            if (cell.contains(range.startContainer)) {
                cellRange.setStart(range.startContainer, range.startOffset);
            }
            if (cell.contains(range.endContainer)) {
                cellRange.setEnd(range.endContainer, range.endOffset);
            }
            pieces.push(cellRange.toString());
        });

        return pieces.join('\n');
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

    // Picks the diff side a selection belongs to. Single-sided rows (add /
    // remove) are unambiguous. A context row carries both line numbers and, in
    // split view, renders the original text in the primary content cell (left /
    // old) and a mirror cell for the new (right) side — so honor whichever cell
    // the selection started in. Unified view only shows the primary cell, so it
    // keeps the new-side default.
    function sideForSelection(node, row) {
        if (!row.dataset.lineOld) { return 'right'; }
        if (!row.dataset.lineNew) { return 'left'; }

        const el = node && node.nodeType === 3 ? node.parentElement : node;
        if (el && typeof el.closest === 'function' && el.closest('.diff-cell-content-mirror')) {
            return 'right';
        }

        return row.closest?.('.diff-grid')?.dataset.viewMode === 'split' ? 'left' : 'right';
    }

    // Maps a text-selection Range onto the diff line(s) it covers, scoped to
    // `root` (a single diff-file element). The side is anchored off the start
    // row (see sideForSelection) and both endpoints are read on that side.
    // Returns null when the selection doesn't start on a diff row inside `root`,
    // so non-matching files ignore it.
    function selectionLineRange(range, root) {
        if (!range) return null;
        const startRow = closestDiffLine(range.startContainer);
        if (!startRow || (root && !root.contains(startRow))) return null;

        const side = sideForSelection(range.startContainer, startRow);
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

    function createDiffFile({ fileId, filePath, oldPath = null, status = 'modified', isReviewed, singleFile = false, urlHintId = null }) {
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
            urlHintId,

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
            hoveredUrl: null,
            _hoveredUrlCell: null,
            _hoveredUrlStart: null,
            urlHintVisible: false,
            urlHintLeft: 0,
            urlHintTop: 0,
            urlHintTimer: null,
            urlHintMode: 'pointer',

            // Line-drag state
            isDragging: false,
            dragStartPoint: null,
            dragSide: null,

            // Markdown heading fold state (id -> true when collapsed)
            foldedHeadings: {},

            toggleHeadingFold(id) {
                this.foldedHeadings[id] = !this.foldedHeadings[id];
            },

            openUrlAtClick(event) {
                const url = urlAtClick(event);
                if (url === null) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();
                this.$wire.openExternalUrl(url);
            },

            previewUrlAtPoint(event) {
                const match = urlMatchAtPoint(event);
                if (!match) {
                    this.clearUrlPreview();
                    return;
                }

                if (this._hoveredUrlCell === match.cell && this._hoveredUrlStart === match.start) {
                    this.positionUrlHint(event);
                    return;
                }

                this.clearUrlPreview();
                showUrlHighlight(match);
                this.hoveredUrl = match.url;
                this._hoveredUrlCell = match.cell;
                this._hoveredUrlStart = match.start;
                this.urlHintMode = 'pointer';
                this.positionUrlHint(event);
                this.urlHintTimer = setTimeout(() => {
                    this.urlHintVisible = this.hoveredUrl !== null;
                    this.urlHintTimer = null;
                }, 350);
            },

            positionUrlHint(event) {
                const view = event.target?.ownerDocument?.defaultView ?? window;
                this.positionUrlHintAt(event.clientX, event.clientY, view);
            },

            positionUrlHintAt(x, y, view = window) {
                this.urlHintLeft = Math.max(8, Math.min(x + 12, view.innerWidth - 120));
                this.urlHintTop = y >= 40 ? y - 32 : y + 18;
            },

            previewUrlForKeyboard(event) {
                const match = urlMatchForKeyboardControl(event.target);
                if (!match) {
                    return;
                }

                this.clearUrlPreview();
                showUrlHighlight(match);
                this.hoveredUrl = match.url;
                this._hoveredUrlCell = match.cell;
                this._hoveredUrlStart = match.start;
                this.urlHintMode = 'keyboard';
                this.urlHintVisible = true;

                const range = rangeForUrlMatch(match);
                const rect = range?.getBoundingClientRect?.();
                if (rect) {
                    this.positionUrlHintAt(rect.left, rect.top, match.cell.ownerDocument.defaultView);
                }
            },

            clearUrlPreviewAfterFocus(event) {
                if (!event.relatedTarget?.matches?.('[data-diff-url-control]')) {
                    this.clearUrlPreview();
                }
            },

            clearUrlPreview() {
                if (this._hoveredUrlCell !== null) {
                    clearUrlHighlight(this.$root?.ownerDocument ?? document);
                }
                if (this.urlHintTimer !== null) {
                    clearTimeout(this.urlHintTimer);
                    this.urlHintTimer = null;
                }
                this.hoveredUrl = null;
                this._hoveredUrlCell = null;
                this._hoveredUrlStart = null;
                this.urlHintVisible = false;
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

                if (event.shiftKey && this.lastClickedPoint?.side === clickedPoint.side) {
                    this.setLineSelection(this.lastClickedPoint, clickedPoint);
                    // The gutter's `mousedown.prevent` keeps the text selection
                    // alive, so it's still readable here to seed the citation.
                    this._prefillCitation(formatCitation(this._selectionTextWithinFile()));
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
                // Snapshot the selection now (mousedown.prevent keeps it alive);
                // it's applied when the drag ends and the form opens.
                this._pendingCitation = formatCitation(this._selectionTextWithinFile());
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
                this._prefillCitation(this._pendingCitation);
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

            refreshKeyboardUrlControls() {
                const installControls = () => installKeyboardUrlControls(this.$root, this.urlHintId);
                if (typeof this.$nextTick === 'function') {
                    this.$nextTick(installControls);

                    return;
                }

                installControls();
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
                    this.refreshKeyboardUrlControls();
                };
                window.addEventListener('rfa:diff-action-completed', this._onDiffActionCompleted);
                this.refreshKeyboardUrlControls();
            },

            destroy() {
                this.persistPendingCommentForm();
                this.clearUrlPreview();
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

            // The window's current text selection, or null when there is none,
            // it's collapsed, or it carries no range. Centralizes the
            // window-resolution + empty-guard for the citation readers below.
            _activeSelection() {
                const win = this.$el?.ownerDocument?.defaultView || (typeof window !== 'undefined' ? window : null);
                const selection = win?.getSelection?.();
                if (!selection || selection.rangeCount === 0 || selection.isCollapsed) {
                    return null;
                }

                return selection;
            },

            // Seeds an empty composer with a citation; a non-empty body (an
            // in-progress draft) is left untouched. Shared by every form-open
            // path that can carry a selection (shift-click, drag end, keyboard).
            _prefillCitation(citation) {
                if (citation && this.formBody.trim() === '') {
                    this.formBody = citation;
                }
            },

            // Keyboard entry point (catalog id `review.comment-selection`, 'c'):
            // open the line-comment composer on the row(s) under the current text
            // selection, seeded with that text as a citation. The page resolves
            // which file owns the selection and targets this handler by id; the
            // selectionLineRange guard keeps it correct if called directly.
            commentOnSelection() {
                const selection = this._activeSelection();
                if (!selection) return;

                const lineRange = selectionLineRange(selection.getRangeAt(0), this.$el);
                if (!lineRange) return;

                this.autoExpandedForComment = false;
                this.editingCommentId = null;
                this.setLineSelection(
                    createLinePoint(lineRange.startLine, lineRange.side),
                    createLinePoint(lineRange.endLine, lineRange.side),
                );
                this.lastClickedPoint = this.formEndPoint ? { ...this.formEndPoint } : null;
                this._prefillCitation(formatCitation(selectionSourceText(selection.getRangeAt(0), this.$el)));
                this.showForm = true;
                this.focusCommentInput();
            },

            // Current text selection as source text, but only when it begins
            // inside this file's diff rows — so a stray selection elsewhere (a
            // comment, another file) never leaks into this file's citation. Reads
            // through selectionSourceText so the diff chrome (line-number / +-
            // prefix gutters) is excluded. '' otherwise.
            _selectionTextWithinFile() {
                const selection = this._activeSelection();
                if (!selection) return '';
                const row = closestDiffLine(selection.anchorNode);
                if (!row || !this.$el?.contains(row)) {
                    return '';
                }

                return selectionSourceText(selection.getRangeAt(0), this.$el);
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
                const row = closestDiffLine(document.elementFromPoint(this._dragMouseX, this._dragMouseY));
                if (!row || !this.$el.contains(row)) return;

                const point = createLinePoint(rowLineForSide(row, this.dragSide), this.dragSide);
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
        trimTrailingUrlPunctuation,
        urlMatchesInText,
        urlMatchAtTextOffset,
        urlAtTextOffset,
        caretAtPoint,
        urlMatchAtPoint,
        urlAtClick,
        textPositionAtOffset,
        rangeForUrlMatch,
        rangeContainsPoint,
        urlMatchForKeyboardControl,
        installKeyboardUrlControls,
        showUrlHighlight,
        clearUrlHighlight,
        expanderToRefocus,
        createLinePoint,
        areLinePointsEqual,
        rowContainsLinePoint,
        formatCitation,
        selectionSourceText,
        closestDiffLine,
        selectionLineRange,
        createDiffFile,
        install,
        autoInstall,
    };
});
