const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../Views/Tech/timeline-script.blade.php'), 'utf8').split('<script>')[1].split('</script>')[0];
const window = {confirm: () => true, innerWidth: 1400};
vm.runInNewContext(source, {window, Date, Object, Number});
const range = (start, end, description = '') => ({start: '2026-10-01T'+start+'+02:00', end: '2026-10-01T'+end+'+02:00', description});
function editor() {
    return window.workdayTimelineEditor({
        entries: [range('09:00','09:45','Meeting'), range('11:00','12:30','Work')],
        selection: range('09:45','10:45'), editingIndex: null, breaks: [], clockChanges: [],
        origin: '2026-10-01T00:00+02:00', visibleStart: 540, visibleEnd: 960, fullMinutes: 1440,
        offsetPeriods: [{from: Date.parse('2026-10-01T00:00+02:00'), until: Date.parse('2026-10-03T00:00+02:00'), offset: 7200}],
        reserved: []
    });
}
const clean = value => JSON.parse(JSON.stringify(value));
test('pending default is separate; save preserves existing intervals and adds exactly one selection', () => {
    const state = editor();
    assert.equal(state.entries.length, 2);
    assert.equal(state.payloadIntervals().length, 3);
    assert.deepEqual(clean(state.payloadIntervals().slice(0,2)), state.entries);
    state.includeSelection = false;
    assert.equal(state.payloadIntervals().length, 2);
});
test('45-minute editing resizes the selection, replaces one interval and preserves other work', () => {
    const state = editor(); state.choose(null,0);
    state.selection.start = '2026-10-01T09:15';
    state.selection.end = '2026-10-01T10:00';
    assert.equal(state.selectionGeometry().minutes,45);
    assert.equal(state.selectionStyle().height,'72px');
    assert.equal(state.selectionStyle().top,'24px');
    assert.equal(state.payloadIntervals().length,2);
    assert.deepEqual(clean(state.payloadIntervals()[1]),state.entries[1]);
    assert.equal(state.selectionError(),'');
});
test('overlap is reported while touching endpoints are allowed', () => {
    const state = editor(); state.choose(null,0);
    state.selection.end = '2026-10-01T11:01';
    assert.match(state.selectionError(),/overlaps/);
    state.selection.end = '2026-10-01T11:00';
    assert.equal(state.selectionError(),'');
    state.selection.end = '2026-10-01T08:45';
    assert.match(state.selectionError(),/after/);
});
test('removal preserves stable indexes and details-only save does not add default selection', () => {
    const state = editor(); state.choose(null,0); state.removeSelection();
    state.choose(null,1); state.selection.description = 'Edited remaining block';
    assert.equal(state.payloadIndex(),0);
    assert.equal(state.payloadIntervals().length,1);
    assert.equal(state.payloadIntervals()[0].description,'Edited remaining block');
    state.includeSelection=false;
    assert.equal(state.payloadIntervals()[0].description,'Work');
});
test('ambiguous local clock hour requires an offset and elapsed duration remains exact', () => {
    const state = editor();
    state.offsetPeriods = [
        {from:Date.parse('2026-10-25T00:00+02:00'),until:Date.parse('2026-10-25T03:00+02:00'),offset:7200},
        {from:Date.parse('2026-10-25T02:00+01:00'),until:Date.parse('2026-10-26T00:00+01:00'),offset:3600}
    ];
    assert.ok(Number.isNaN(state.instant('2026-10-25T02:15')));
    state.selection={start:'2026-10-25T02:15+02:00',end:'2026-10-25T02:00+01:00'};
    assert.equal(state.selectionGeometry().minutes,45);
});
test('unsaved selection requires an explicit discard before another block is selected', () => {
    const state=editor(); state.selectionDirty=true;
    window.confirm=()=>false; state.choose(null,0);
    assert.equal(state.editingIndex,null);
    window.confirm=()=>true; state.choose(null,0);
    assert.equal(state.editingIndex,0);
});

// Exercise the modal adapter independently of Bootstrap's own focus-trap implementation.
function dialog(state) {
    const events = {};
    const log = {shown: 0, inputFocus: 0, triggerFocus: 0, disposed: 0};
    const element = {
        addEventListener: (name, callback) => events[name] = callback,
        removeEventListener: name => delete events[name],
        querySelector: () => ({focus: () => log.inputFocus++})
    };
    const instance = {show: () => log.shown++, dispose: () => log.disposed++};
    window.bootstrap = {Modal: {getOrCreateInstance: () => instance, getInstance: () => instance}};
    state.$refs = {intervalModal: element};
    state.$nextTick = callback => callback();
    return {events, log, trigger: {isConnected: true, focus: () => log.triggerFocus++}};
}
test('calendar clicks open entry and edit dialogs; closing restores focus without saving or losing input', () => {
    const state = editor(), modal = dialog(state);
    state.init();
    assert.equal(modal.log.shown, 0);
    state.choose(range('13:00','14:00'), null, modal.trigger);
    assert.equal(modal.log.shown, 1);
    modal.events['shown.bs.modal']();
    assert.equal(modal.log.inputFocus, 1);
    state.selection.description = 'Unsaved meeting';
    state.selectionDirty = true;
    modal.events['hidden.bs.modal']();
    assert.equal(modal.log.triggerFocus, 1);
    assert.equal(state.selection.description, 'Unsaved meeting');
    assert.equal(state.entries.length, 2);
    assert.equal(state.submitting, false);
    state.openEditor(modal.trigger);
    assert.equal(state.selection.description, 'Unsaved meeting');
    state.choose(null, 0, modal.trigger);
    assert.equal(state.editingIndex, 0);
    assert.equal(modal.log.shown, 3);
    state.destroy();
    assert.equal(modal.log.disposed, 1);
    assert.equal(Object.keys(modal.events).length, 0);
});
test('server validation reopens the editor and focuses the visible error without changing payload', () => {
    const state = editor(), modal = dialog(state);
    state.reopenEditor = true;
    let errorFocus = 0;
    state.$refs.editorErrors = {focus: () => errorFocus++};
    const before = clean(state.payloadIntervals());
    state.init();
    assert.equal(modal.log.shown, 1);
    modal.events['shown.bs.modal']();
    assert.equal(errorFocus, 1);
    assert.deepEqual(clean(state.payloadIntervals()), before);
});
