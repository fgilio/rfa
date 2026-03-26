// Alpine component for global find-in-page search (Cmd/Ctrl+F)
(function () {
    function init() {
        Alpine.data('pageSearch', () => ({
            open: false,
            query: '',

            handleKeydown(e) {
                if ((e.metaKey || e.ctrlKey) && e.key === 'f') {
                    e.preventDefault();
                    this.open = true;
                    this.$nextTick(() => this.$refs.input?.select());
                }
            },

            find(backwards) {
                if (!this.query) return;
                window.find(this.query, false, backwards, true);
            },

            close() {
                this.open = false;
                this.query = '';
                window.getSelection()?.removeAllRanges();
            },
        }));
    }

    if (window.Alpine) {
        init();
    } else {
        document.addEventListener('alpine:init', init);
    }
})();
