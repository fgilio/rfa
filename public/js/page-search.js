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

    function createPageSearch() {
        return {
            open: false,
            query: '',
            currentMatch: 0,
            // Each entry is one logical match: an array of one or more spans.
            // A query that crosses sibling text nodes wraps each piece in its
            // own `.rfa-search-match` span, all grouped under a single entry.
            matches: [],

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
                if (backwards) {
                    this.currentMatch = this.currentMatch <= 1 ? total : this.currentMatch - 1;
                } else {
                    this.currentMatch = this.currentMatch >= total ? 1 : this.currentMatch + 1;
                }
                this.updateCurrent(true);
            },

            updateCurrent(scroll) {
                const total = this.matches.length;
                const badge = `${this.currentMatch} of ${total}`;
                this.matches.forEach((spans, i) => {
                    const isCurrent = (i + 1) === this.currentMatch;
                    spans.forEach((el, j) => {
                        el.classList.toggle(CURRENT_CLASS, isCurrent);
                        if (isCurrent && j === 0) {
                            el.setAttribute('data-match-number', badge);
                        } else {
                            el.removeAttribute('data-match-number');
                        }
                    });
                    if (isCurrent && scroll && spans[0]) {
                        spans[0].scrollIntoView({ block: 'center', behavior: 'smooth' });
                    }
                });
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
                // Sibling text nodes share a parent (very common in diff rows),
                // so memoize the per-walk visibility answer to avoid re-walking
                // the style chain for each one.
                const visibility = new WeakMap();
                const blockCache = new WeakMap();
                const walker = document.createTreeWalker(
                    document.body,
                    NodeFilter.SHOW_TEXT,
                    {
                        acceptNode(node) {
                            if (!node.nodeValue || !node.nodeValue.length) {
                                return NodeFilter.FILTER_REJECT;
                            }
                            const parent = node.parentElement;
                            if (!parent || parent.closest(SKIP_SELECTOR)) {
                                return NodeFilter.FILTER_REJECT;
                            }
                            // Skip collapsed/hidden sections (x-show, [hidden],
                            // display:none, visibility:hidden, opacity:0) so
                            // the counter and next/prev navigation only target
                            // visible matches.
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
                    }
                );

                // Group accepted text nodes by their nearest block-level
                // ancestor. Each block becomes one searchable string, so a
                // query can cross sibling token spans inside a line but not
                // hop between lines/cells/paragraphs.
                const groups = new Map();
                let node;
                while ((node = walker.nextNode())) {
                    const block = findBlockAncestor(node.parentElement, blockCache);
                    let bucket = groups.get(block);
                    if (!bucket) {
                        bucket = [];
                        groups.set(block, bucket);
                    }
                    bucket.push(node);
                }

                const matches = [];
                groups.forEach(textNodes => {
                    const segments = [];
                    let joined = '';
                    textNodes.forEach(n => {
                        const value = n.nodeValue;
                        segments.push({ node: n, start: joined.length, length: value.length });
                        joined += value;
                    });
                    if (joined.length === 0) return;
                    // Cheap substring pre-check: the regex is just an escaped
                    // literal, so if the lowercased needle isn't in the joined
                    // string the regex can't match either.
                    if (!joined.toLowerCase().includes(needle)) return;

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
                    if (ranges.length === 0) return;

                    // Wrap each match's text-node ranges in their own
                    // `.rfa-search-match` span. Process matches back-to-front
                    // and segments back-to-front within a match so each DOM
                    // split only invalidates offsets we've already handled.
                    const wrapped = new Array(ranges.length);
                    for (let i = ranges.length - 1; i >= 0; i--) {
                        const [matchStart, matchEnd] = ranges[i];
                        const spans = [];
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
                            try {
                                range.surroundContents(span);
                                spans.unshift(span);
                            } catch (_) {
                                // surroundContents only throws when the range
                                // partially selects a non-text node, which our
                                // text-only ranges never do.
                            }
                        }
                        wrapped[i] = spans;
                    }

                    wrapped.forEach(spans => {
                        if (spans && spans.length > 0) matches.push(spans);
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
