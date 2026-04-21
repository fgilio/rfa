// Alpine component for global find-in-page search (Cmd/Ctrl+F)
//
// Wraps every match in the document with <span class="rfa-search-match"> so
// all matches are highlighted at once. The active match additionally gets the
// `rfa-search-match--current` modifier (stronger background + badge showing
// "N of total" rendered via CSS ::after).
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
            totalMatches: 0,
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
                    this.totalMatches = 0;
                    this.currentMatch = 0;
                    return;
                }
                this.matchElements = this.markMatches(this.query);
                this.totalMatches = this.matchElements.length;
                this.currentMatch = this.totalMatches > 0 ? 1 : 0;
                this.updateCurrent(true);
            },

            find(backwards) {
                if (this.totalMatches === 0) {
                    if (this.query) {
                        this.refresh();
                    }
                    return;
                }
                if (backwards) {
                    this.currentMatch = this.currentMatch <= 1 ? this.totalMatches : this.currentMatch - 1;
                } else {
                    this.currentMatch = this.currentMatch >= this.totalMatches ? 1 : this.currentMatch + 1;
                }
                this.updateCurrent(true);
            },

            updateCurrent(scroll) {
                const badge = `${this.currentMatch} of ${this.totalMatches}`;
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
                this.totalMatches = 0;
                this.currentMatch = 0;
            },

            clearMarks() {
                const existing = document.querySelectorAll('.' + MATCH_CLASS);
                existing.forEach(el => {
                    const parent = el.parentNode;
                    if (!parent) return;
                    parent.replaceChild(document.createTextNode(el.textContent), el);
                    parent.normalize();
                });
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
                            // Use a lowercase substring check for filtering to
                            // avoid mutating the shared regex's lastIndex.
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
