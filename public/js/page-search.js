// Alpine component for global find-in-page search (Cmd/Ctrl+F)
(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (root) {
        root.pageSearch = api;
        api.autoInstall(root);
    }
})(typeof window !== 'undefined' ? window : null, function () {
    const MATCH_CLASS = 'rfa-search-match';
    const CURRENT_CLASS = 'rfa-search-match--current';
    const SKIP_SELECTOR = 'script,style,noscript,iframe,input,textarea,[data-search-ignore]';

    // Tag whitelist used to find the nearest block-level ancestor of a text
    // node. Anything outside this set ends the walk; sibling text nodes
    // sharing a block ancestor get joined for matching, so a query can span
    // adjacent syntax-highlighter token spans (e.g. `'`, `local`, `'`)
    // without bleeding across rows, cells, or paragraphs. Tag-based instead
    // of getComputedStyle to keep the walker O(1) on big diffs.
    const INLINE_TAGS = new Set([
        'A', 'ABBR', 'B', 'BDI', 'BDO', 'BIG', 'CITE', 'CODE', 'DEL', 'DFN',
        'EM', 'FONT', 'I', 'INS', 'KBD', 'MARK', 'OUTPUT', 'Q', 'RP', 'RT',
        'RUBY', 'S', 'SAMP', 'SMALL', 'SPAN', 'STRONG', 'SUB', 'SUP', 'TIME',
        'TT', 'U', 'VAR', 'WBR',
    ]);

    function escapeRegex(value) {
        return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function findBlockAncestor(el, cache) {
        const cached = cache.get(el);
        if (cached) return cached;
        let cursor = el;
        while (cursor.parentElement && INLINE_TAGS.has(cursor.tagName)) {
            cursor = cursor.parentElement;
        }
        cache.set(el, cursor);
        return cursor;
    }

    // Walk every visible text node under `root` and bucket it by nearest
    // block-level ancestor. Each block becomes one searchable string, so a
    // query can cross sibling token spans inside a line but never hop between
    // lines, cells, or paragraphs. Returns Map<blockElement, Text[]>.
    function groupTextNodesByBlock(root) {
        // Sibling text nodes share a parent (very common in diff rows), so
        // memoize the per-walk visibility answer instead of re-walking the
        // style chain for each one.
        const visibility = new WeakMap();
        const blockCache = new WeakMap();
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode(node) {
                if (!node.nodeValue || !node.nodeValue.length) {
                    return NodeFilter.FILTER_REJECT;
                }
                const parent = node.parentElement;
                if (!parent || parent.closest(SKIP_SELECTOR)) {
                    return NodeFilter.FILTER_REJECT;
                }
                // Skip collapsed/hidden sections (x-show, [hidden],
                // display:none, visibility:hidden, opacity:0) so the counter
                // and next/prev navigation only target visible matches.
                let visible = visibility.get(parent);
                if (visible === undefined) {
                    visible = parent.checkVisibility({
                        checkOpacity: true,
                        checkVisibilityCSS: true,
                    });
                    visibility.set(parent, visible);
                }
                return visible ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
            },
        });

        const blocks = new Map();
        let node;
        while ((node = walker.nextNode())) {
            const block = findBlockAncestor(node.parentElement, blockCache);
            let bucket = blocks.get(block);
            if (!bucket) {
                bucket = [];
                blocks.set(block, bucket);
            }
            bucket.push(node);
        }
        return blocks;
    }

    // Join a block's text nodes into one string, recording where each node's
    // text lands in the joined string so a match offset can be mapped back to
    // the right node and its local range.
    function buildSegments(textNodes) {
        const segments = [];
        let joined = '';
        textNodes.forEach(node => {
            const value = node.nodeValue;
            segments.push({ node, start: joined.length, length: value.length });
            joined += value;
        });
        return { segments, joined };
    }

    // Find every occurrence of `pattern` in `joined` as [start, end) offset
    // pairs. `needle` is the lowercased query for a cheap pre-check: the regex
    // is just an escaped literal, so if the needle isn't present the regex
    // can't match either and we skip the scan entirely.
    function findRanges(joined, pattern, needle) {
        if (joined.length === 0) return [];
        if (!joined.toLowerCase().includes(needle)) return [];
        pattern.lastIndex = 0;
        const ranges = [];
        let m;
        while ((m = pattern.exec(joined)) !== null) {
            if (m[0].length === 0) {
                pattern.lastIndex++;
                continue;
            }
            ranges.push([m.index, m.index + m[0].length]);
        }
        return ranges;
    }

    // Wrap each match's text in its own `.rfa-search-match` span(s), returning
    // one group of pieces per match. A match that crosses sibling text nodes
    // wraps each piece separately. Matches and segments are processed
    // back-to-front so each DOM split only invalidates offsets already handled.
    function wrapRanges(ranges, segments) {
        const wrapped = new Array(ranges.length);
        for (let i = ranges.length - 1; i >= 0; i--) {
            const [matchStart, matchEnd] = ranges[i];
            const pieces = [];
            for (let s = segments.length - 1; s >= 0; s--) {
                const seg = segments[s];
                const segEnd = seg.start + seg.length;
                if (segEnd <= matchStart || seg.start >= matchEnd) continue;
                const localStart = Math.max(0, matchStart - seg.start);
                const localEnd = Math.min(seg.length, matchEnd - seg.start);
                if (localStart >= localEnd) continue;
                const range = document.createRange();
                range.setStart(seg.node, localStart);
                range.setEnd(seg.node, localEnd);
                const span = document.createElement('span');
                span.className = MATCH_CLASS;
                range.surroundContents(span);
                pieces.unshift(span);
            }
            wrapped[i] = pieces;
        }
        return wrapped;
    }

    function createPageSearch() {
        return {
            open: false,
            query: '',
            currentMatch: 0,
            // Each entry is one match's pieces: a single `.rfa-search-match`
            // span, or several when the match crosses sibling token spans.
            matches: [],

            // Cmd/Ctrl+F is handled here, not through the global keymap store,
            // because find must fire while focus is inside its own search input
            // (to re-select and re-run the query) — the very case the store
            // suppresses via its editable-target guard. It also owns local
            // open/refresh/$refs state the store can't reach.
            handleKeydown(e) {
                if ((e.metaKey || e.ctrlKey) && e.key === 'f') {
                    e.preventDefault();
                    this.open = true;
                    this.$nextTick(() => {
                        this.$refs.input?.select();
                        if (this.query) {
                            this.refresh();
                        }
                    });
                }
            },

            onQueryInput() {
                this.refresh();
            },

            refresh() {
                this.clearMarks();
                // Treat whitespace-only queries as empty so pressing space
                // doesn't wrap every whitespace run on the page.
                if (!/\S/.test(this.query)) {
                    this.currentMatch = 0;
                    return;
                }
                this.matches = this.markMatches(this.query);
                this.currentMatch = this.matches.length > 0 ? 1 : 0;
                this.updateCurrent(true);
            },

            find(backwards) {
                const total = this.matches.length;
                if (total === 0) {
                    if (this.query) {
                        this.refresh();
                    }
                    return;
                }
                const previousIndex = this.currentMatch - 1;
                if (backwards) {
                    this.currentMatch = this.currentMatch <= 1 ? total : this.currentMatch - 1;
                } else {
                    this.currentMatch = this.currentMatch >= total ? 1 : this.currentMatch + 1;
                }
                this.updateCurrent(true, previousIndex);
            },

            updateCurrent(scroll, previousIndex = null) {
                const total = this.matches.length;
                const badge = `${this.currentMatch} of ${total}`;
                const currentIndex = this.currentMatch - 1;

                if (previousIndex === null) {
                    this.matches.forEach((pieces, index) => {
                        this.updateMatch(pieces, index === currentIndex, badge, scroll);
                    });

                    return;
                }

                this.updateMatch(this.matches[previousIndex], false, badge, false);
                this.updateMatch(this.matches[currentIndex], true, badge, scroll);
            },

            updateMatch(pieces, isCurrent, badge, scroll) {
                if (!pieces) return;

                pieces.forEach((piece, index) => {
                    piece.classList.toggle(CURRENT_CLASS, isCurrent);
                    if (isCurrent && index === 0) {
                        piece.setAttribute('data-match-number', badge);
                    } else {
                        piece.removeAttribute('data-match-number');
                        piece.style.removeProperty('--rfa-match-center');
                    }
                });

                if (!isCurrent) return;

                this.centerBadge(pieces);
                if (scroll && pieces[0]) {
                    pieces[0].scrollIntoView({ block: 'center', behavior: 'smooth' });
                }
            },

            // Center the "X of Y" pill on the horizontal midpoint of the whole
            // match. The pill renders via ::after on the first piece only, but a
            // match can cross several token spans — without this it sits under
            // the first piece and reads as off-center. We hand CSS the offset
            // (measured from the first piece's left edge) via --rfa-match-center
            // and fall back to `left: 50%` for single-piece matches and any case
            // we can't measure (a wrapped match, or zero-size rects in tests).
            centerBadge(pieces) {
                const anchor = pieces[0];
                if (!anchor) return;
                anchor.style.removeProperty('--rfa-match-center');
                if (pieces.length < 2) return;
                const first = anchor.getBoundingClientRect();
                const last = pieces[pieces.length - 1].getBoundingClientRect();
                const sameLine = Math.abs(last.top - first.top) < 1;
                const width = last.right - first.left;
                if (!sameLine || width <= 0) return;
                anchor.style.setProperty('--rfa-match-center', `${width / 2}px`);
            },

            close() {
                this.clearMarks();
                this.open = false;
                this.query = '';
                this.currentMatch = 0;
            },

            clearMarks() {
                const existing = document.querySelectorAll('.' + MATCH_CLASS);
                const parents = new Set();
                existing.forEach(el => {
                    const parent = el.parentNode;
                    if (!parent) return;
                    parent.replaceChild(document.createTextNode(el.textContent), el);
                    parents.add(parent);
                });
                parents.forEach(parent => parent.normalize());
                this.matches = [];
            },

            markMatches(query) {
                const pattern = new RegExp(escapeRegex(query), 'gi');
                const needle = query.toLowerCase();
                const matches = [];
                groupTextNodesByBlock(document.body).forEach(textNodes => {
                    const { segments, joined } = buildSegments(textNodes);
                    const ranges = findRanges(joined, pattern, needle);
                    wrapRanges(ranges, segments).forEach(pieces => {
                        if (pieces.length > 0) matches.push(pieces);
                    });
                });
                return matches;
            },
        };
    }

    function install(root) {
        if (typeof root.Alpine === 'undefined' || root.__pageSearchAttached) return false;
        root.__pageSearchAttached = true;
        root.Alpine.data('pageSearch', createPageSearch);
        return true;
    }

    function autoInstall(root) {
        if (root.Alpine) {
            install(root);
        } else {
            root.document.addEventListener('alpine:init', () => install(root));
        }
    }

    return { escapeRegex, createPageSearch, install, autoInstall };
});
