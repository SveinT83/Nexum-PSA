const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../Views/Tech/lookup-script.blade.php'), 'utf8').split('<script>')[1].split('</script>')[0];
function make(fetch) {
    const window = {location: {origin: 'https://example.test'}};
    vm.runInNewContext(source, {window, fetch, URL, AbortController, setTimeout, clearTimeout, document: {getElementById: () => null}});
    const state = window.nexumReportLookup({url: '/lookup', id:'employee', value: 3, text:'Åse Østby'});
    state.$nextTick = callback => callback();
    return state;
}
test('focus offers an unfiltered directory even with a selected name; continuation keeps that search', async () => {
    const urls = [];
    const state = make(async url => {
        urls.push(String(url));
        return {ok:true, json:async () => ({data:[{id:urls.length,label:'Worker'}],meta:{next_page:urls.length===1?2:null}})};
    });
    state.focus();
    await new Promise(resolve => setTimeout(resolve,0));
    assert.equal(new URL(urls[0]).searchParams.get('q'),'');
    await state.load(true);
    assert.equal(new URL(urls[1]).searchParams.get('q'),'');
    assert.equal(new URL(urls[1]).searchParams.get('page'),'2');
    assert.equal(state.items.length,2);
});
test('typing clears the stable ID immediately; click selection cancels pending search and closes', () => {
    const state = make(async () => {throw new Error('unexpected request');});
    state.text = 'new name';
    state.changed();
    assert.equal(state.selectedId,'');
    assert.equal(state.open,true);
    state.choose({id:9,label:'Same name'});
    assert.equal(state.selectedId,9);
    assert.equal(state.text,'Same name');
    assert.equal(state.open,false);
    state.destroy();
});
test('late network results cannot overwrite more recent search results', async () => {
    const resolves = [];
    const state = make(() => new Promise(resolve => resolves.push(resolve)));
    const old = state.load(false,'old');
    const fresh = state.load(false,'fresh');
    resolves[1]({ok:true,json:async () => ({data:[{id:2,label:'Fresh'}],meta:{next_page:null}})});
    await fresh;
    resolves[0]({ok:true,json:async () => ({data:[{id:1,label:'Stale'}],meta:{next_page:null}})});
    await old;
    assert.equal(state.items[0].id,2);
});
test('keyboard highlight stays bounded and retains duplicate-name IDs', async () => {
    const state = make(async () => ({ok:true,json:async () => ({data:[{id:2,label:'Åse'},{id:3,label:'Åse'}],meta:{next_page:null}})}));
    state.open=true;
    await state.load();
    state.move(1);
    assert.equal(state.active,0);
    state.move(1);
    state.move(1);
    assert.equal(state.active,1);
    state.choose(state.items[state.active]);
    assert.equal(state.selectedId,3);
});
test('request failures are visible and do not leave obsolete suggestions', async () => {
    const state = make(async () => ({ok:false}));
    state.items=[{id:1,label:'Old'}];
    await state.load();
    assert.equal(state.items.length,0);
    assert.match(state.error,/Unable to load/);
    assert.equal(state.loading,false);
});
