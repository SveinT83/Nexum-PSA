<script>
/* Livewire supplies Alpine; this component does not initialize another Alpine runtime. */
window.nexumReportLookup = function (config) {
    return {
        text: config.text || '', selectedId: config.value || '', items: [], active: -1,
        open: false, loading: false, nextPage: null, error: '', sequence: 0, timer: null, controller: null,
        focus() { this.open = true; this.load(false, this.selectedId ? '' : this.text); },
        validity() { this.$refs?.input?.setCustomValidity(!config.allowText && this.text.trim() && !this.selectedId ? 'Choose a suggestion or clear this field.' : ''); },
        changed() {
            this.selectedId = ''; this.active = -1; this.open = true; this.sequence++; this.validity();
            if (this.controller) this.controller.abort();
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.load(false), 120);
        },
        async load(append = false, search = null) {
            if (append && !this.nextPage) return;
            if (this.controller) this.controller.abort();
            this.controller = new AbortController();
            const sequence = ++this.sequence;
            this.loading = true; this.error = '';
            const url = new URL(config.url, window.location.origin);
            url.searchParams.set('q', search === null ? this.text : search);
            url.searchParams.set('page', append ? this.nextPage : 1);
            // Keep the original search when scrolling an initially unfiltered selected directory.
            if (append) url.searchParams.set('q', this.loadedSearch || '');
            try {
                const response = await fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/json'}, signal: this.controller.signal});
                if (!response.ok) throw new Error('lookup');
                const result = await response.json();
                if (sequence !== this.sequence) return;
                this.loadedSearch = url.searchParams.get('q');
                this.items = append ? this.items.concat(result.data) : result.data;
                this.nextPage = result.meta.next_page;
                if (!append) this.active = -1;
            } catch (error) {
                if (sequence === this.sequence && error.name !== 'AbortError') {
                    this.items = []; this.nextPage = null; this.error = 'Unable to load suggestions. Focus the field to try again.';
                }
            } finally { if (sequence === this.sequence) this.loading = false; }
        },
        choose(item) {
            if (!item) return;
            clearTimeout(this.timer); this.sequence++;
            if (this.controller) this.controller.abort();
            this.text = item.label; this.selectedId = item.id;
            this.open = false; this.loading = false; this.active = -1; this.validity();
        },
        move(delta) {
            if (!this.open) { this.open = true; this.load(); return; }
            if (!this.items.length) return;
            this.active = Math.max(0, Math.min(this.items.length - 1, this.active < 0 ? (delta > 0 ? 0 : this.items.length - 1) : this.active + delta));
            this.$nextTick(() => document.getElementById(config.id + '-option-' + this.active)?.scrollIntoView({block: 'nearest'}));
        },
        destroy() { clearTimeout(this.timer); if (this.controller) this.controller.abort(); }
    };
};
</script>
