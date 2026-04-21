// Alpine component for global find-in-page search (Cmd/Ctrl+F)
(function () {
    const MATCH_CLASS = 'rfa-search-match';
    const CURRENT_CLASS = 'rfa-search-match--current';
    const SKIP_SELECTOR = 'script,style,noscript,iframe,input,textarea,[data-search-ignore]';

    function escapeRegex(value) {
        return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function init() {
        Alpine.data('pageSearch', () => ({
            open: false,
            query: '',
            currentMatch: 0,
            matchElements: [],

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
                if (!this.query) {
                    this.currentMatch = 0;
                    return;
                }
                this.matchElements = this.markMatches(this.query);
                this.currentMatch = this.matchElements.length > 0 ? 1 : 0;
                this.updateCurrent(true);
            },

            find(backwards) {
                const total = this.matchElements.length;
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
                const badge = `${this.currentMatch} of ${this.matchElements.length}`;
                this.matchElements.forEach((el, i) => {
                    const isCurrent = (i + 1) === this.currentMatch;
                    el.classList.toggle(CURRENT_CLASS, isCurrent);
                    if (isCurrent) {
                        el.setAttribute('data-match-number', badge);
                        if (scroll) {
                            el.scrollIntoView({ block: 'center', behavior: 'smooth' });
                        }
                    } else {
                        el.removeAttribute('data-match-number');
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
                this.matchElements = [];
            },

            markMatches(query) {
                const pattern = new RegExp(escapeRegex(query), 'gi');
                const needle = query.toLowerCase();
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
                            if (!parent.checkVisibility({
                                checkOpacity: true,
                                checkVisibilityCSS: true,
                            })) {
                                return NodeFilter.FILTER_REJECT;
                            }
                            // Substring check (not pattern.test) avoids
                            // mutating the shared regex's lastIndex.
                            return node.nodeValue.toLowerCase().includes(needle)
                                ? NodeFilter.FILTER_ACCEPT
                                : NodeFilter.FILTER_REJECT;
                        },
                    }
                );

                const textNodes = [];
                let node;
                while ((node = walker.nextNode())) {
                    textNodes.push(node);
                }

                const matches = [];
                textNodes.forEach(textNode => {
                    const text = textNode.nodeValue;
                    pattern.lastIndex = 0;
                    const fragments = [];
                    let cursor = 0;
                    let match;
                    while ((match = pattern.exec(text)) !== null) {
                        if (match.index > cursor) {
                            fragments.push(document.createTextNode(text.slice(cursor, match.index)));
                        }
                        const span = document.createElement('span');
                        span.className = MATCH_CLASS;
                        span.textContent = match[0];
                        fragments.push(span);
                        matches.push(span);
                        cursor = match.index + match[0].length;
                        if (match[0].length === 0) {
                            pattern.lastIndex++;
                        }
                    }
                    if (fragments.length === 0) return;
                    if (cursor < text.length) {
                        fragments.push(document.createTextNode(text.slice(cursor)));
                    }
                    const parent = textNode.parentNode;
                    fragments.forEach(fragment => parent.insertBefore(fragment, textNode));
                    parent.removeChild(textNode);
                });
                return matches;
            },
        }));
    }

    if (window.Alpine) {
        init();
    } else {
        document.addEventListener('alpine:init', init);
    }
})();
