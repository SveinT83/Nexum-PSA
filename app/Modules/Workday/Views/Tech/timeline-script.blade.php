{{-- The existing Livewire Alpine runtime owns this state; no second runtime or browser timezone conversion. --}}
<script>
window.workdayTimelineEditor = function (data) {
    return Object.assign(data, {
        fullDay: false, removed: [], selectionDirty: false, dirty: false, submitting: false, includeSelection: true,
        // Bootstrap supplies the focus trap, Escape and backdrop handling. Closing retains pending input.
        init() {
            if (!this.$refs || !this.$refs.intervalModal) return;
            this.onEditorShown = () => {
                const target = this.$refs.editorErrors || this.$refs.intervalModal.querySelector('#entry-start, #description');
                if (target) target.focus();
            };
            this.onEditorHidden = () => {
                if (this.editorTrigger && this.editorTrigger.isConnected) this.editorTrigger.focus({preventScroll: true});
            };
            this.$refs.intervalModal.addEventListener('shown.bs.modal', this.onEditorShown);
            this.$refs.intervalModal.addEventListener('hidden.bs.modal', this.onEditorHidden);
            if (this.reopenEditor) this.openEditor();
        },
        destroy() {
            if (!this.$refs || !this.$refs.intervalModal) return;
            this.$refs.intervalModal.removeEventListener('shown.bs.modal', this.onEditorShown);
            this.$refs.intervalModal.removeEventListener('hidden.bs.modal', this.onEditorHidden);
            window.bootstrap.Modal.getInstance(this.$refs.intervalModal)?.dispose();
        },
        openEditor(trigger = null) {
            if (!this.$refs || !this.$refs.intervalModal) return;
            if (trigger) this.editorTrigger = trigger;
            this.$nextTick(() => window.bootstrap.Modal.getOrCreateInstance(this.$refs.intervalModal).show());
        },
        local(value) { return (value || '').slice(0, 16); },
        offset(value) { return value && value.length > 16 ? value.slice(-6) : ''; },
        offsets(value) { const change = this.clockChanges.find(item => value >= item.from && value < item.until); return change ? change.offsets : []; },
        label(value) { return value ? value.slice(11, 16) : ''; },
        instant(value) {
            if (!value) return NaN;
            if (/(Z|[+-]\d{2}:\d{2})$/.test(value)) return Date.parse(value);
            const local = Date.parse(value + 'Z');
            const candidates = this.offsetPeriods.map(period => ({time: local - period.offset * 1000, period}))
                .filter(item => item.time >= item.period.from && item.time < item.period.until);
            return candidates.length === 1 ? candidates[0].time : NaN;
        },
        calendarStart() { return this.fullDay ? 0 : this.visibleStart; },
        calendarHeight() { return ((this.fullDay ? this.fullMinutes : this.visibleEnd) - this.calendarStart()) * 1.6; },
        position(minutes) { return (minutes - this.calendarStart()) * 1.6; },
        selectionGeometry() {
            if (!this.selection) return null;
            const start = this.instant(this.selection.start), end = this.instant(this.selection.end);
            if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start) return null;
            return {top: (start - Date.parse(this.origin)) / 60000, minutes: (end - start) / 60000};
        },
        selectionStyle() {
            const range = this.selectionGeometry();
            return range ? {top: this.position(range.top) + 'px', height: range.minutes * 1.6 + 'px'} : {display: 'none'};
        },
        selectionError() {
            if (!this.selection) return '';
            const start = this.instant(this.selection.start), end = this.instant(this.selection.end);
            if (!Number.isFinite(start) || !Number.isFinite(end)) return '';
            if (end <= start) return 'End must be after Start.';
            const busy = this.entries.filter((entry, index) => index !== this.editingIndex && !this.removed.includes(index)).concat(this.reserved);
            return busy.some(entry => start < this.instant(entry.end) && end > this.instant(entry.start))
                ? 'This time overlaps registered work. Adjust Start or End.' : '';
        },
        choose(range, index = null, trigger = null) {
            if (this.selectionDirty && !window.confirm('Discard changes to the selected interval?')) return;
            this.editingIndex = index;
            this.selection = Object.assign({description: ''}, index === null ? range : this.entries[index]);
            this.selectionDirty = false;
            this.openEditor(trigger);
        },
        payloadIntervals() {
            const result = this.entries.map(entry => ({start: entry.start, end: entry.end, description: entry.description || ''}));
            if (this.includeSelection && this.selection) {
                const entry = {start: this.selection.start, end: this.selection.end, description: this.selection.description || ''};
                if (this.editingIndex === null) result.push(entry);
                else result[this.editingIndex] = entry;
            }
            return result.filter((entry, index) => !this.removed.includes(index));
        },
        payloadIndex() {
            const index = this.editingIndex === null ? this.entries.length : this.editingIndex;
            return index - this.removed.filter(removed => removed < index).length;
        },
        removeSelection() {
            if (this.editingIndex !== null) {
                this.removed.push(this.editingIndex);
                this.dirty = true;
            }
            this.selection = null; this.editingIndex = null; this.selectionDirty = false;
        },
        beforeLeave(event) {
            if (!this.submitting && (this.dirty || this.selectionDirty)) { event.preventDefault(); event.returnValue = ''; }
        }
    });
};
</script>
