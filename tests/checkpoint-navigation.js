const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync('dashboard.php', 'utf8');
const inline = source.match(/<script>\s*\/\/ Keep AJAX handlers[^\n]*\n([\s\S]*?)<\/script>/)[1];
function runPreference(explicit, selected, saved, blocked = false) {
    const href = 'https://example.com/storage/custom/ioa/dashboard.php?period=checkpoint&activity=admin';
    const parsed = new URL(href);
    const writes = [];
    const redirects = [];
    let canonical;
    const script = inline.replaceAll('<?= json_encode($checkpointSelectionExplicit) ?>', JSON.stringify(explicit))
        .replaceAll('<?= json_encode($checkpointSelection) ?>', JSON.stringify(selected))
        .replaceAll('<?= json_encode($activity) ?>', '"admin"');
    vm.runInNewContext(script, {URL, window: {location: {href}, history: {state: null, replaceState: (_, __, value) => canonical = String(value)}},
        location: {origin: parsed.origin, pathname: parsed.pathname, replace: url => redirects.push(String(url))},
        localStorage: {getItem: key => { if (blocked) throw Error('Blocked'); return saved; }, setItem: (key, value) => writes.push([key, value])}});
    return {writes, redirects, canonical};
}
assert.equal(new URL(runPreference(false, 'latest', null).canonical).searchParams.get('checkpoint'), 'latest');
assert.equal(new URL(runPreference(false, 'latest', '42').redirects[0]).searchParams.get('checkpoint'), '42');
const explicit = runPreference(true, '3', '42');
assert.equal(explicit.redirects.length, 0);
assert.deepEqual(explicit.writes, [['ioa_checkpoint:https://example.com/storage/custom/ioa/dashboard.php', '3']]);
assert.equal(runPreference(true, 'latest', 'deleted').writes[0][1], 'latest');
assert.equal(new URL(runPreference(false, 'latest', null, true).canonical).searchParams.get('activity'), 'admin');
const handlers = new Map();
let destination;
vm.runInNewContext(fs.readFileSync('assets/checkpoints.js', 'utf8'), {URL,
    location: {href: 'https://example.com/dashboard.php?activity=admin&photo_tab=views&session_visit=5&checkpoint=latest', assign: url => destination = String(url)},
    window: {addEventListener: () => {}},
    document: {addEventListener: () => {}, querySelectorAll: selector => selector.includes('[data-activity-select]') ? [{addEventListener: (event, callback) => handlers.set(event, callback)}] : [], querySelector: () => null}});
handlers.get('change')({target: {value: 'visits', selectedOptions: [{dataset: {activityUrl: '?period=30&activity=visits&checkpoint=latest&photo_tab=views'}}]}});
const selected = new URL(destination);
assert.equal(selected.searchParams.get('checkpoint'), 'latest');
assert.equal(selected.searchParams.get('period'), '30');
assert.equal(selected.searchParams.get('activity'), 'visits');
assert.equal(selected.searchParams.get('photo_tab'), 'views');
assert.equal(selected.searchParams.has('session_visit'), false);
for (const [period, checkpoint] of [['today', '2'], ['checkpoint', 'latest'], ['checkpoint', '42']]) {
    handlers.get('change')({target: {selectedOptions: [{dataset: {filterUrl: `?period=${period}&activity=admin&checkpoint=${checkpoint}&photo_tab=downloads`}}]}});
    const url = new URL(destination);
    assert.equal(url.searchParams.get('period'), period);
    assert.equal(url.searchParams.get('checkpoint'), checkpoint);
    assert.equal(url.searchParams.get('activity'), 'admin');
    assert.equal(url.searchParams.get('photo_tab'), 'downloads');
}
console.log('Checkpoint URL, localStorage precedence and mobile filter navigation cases passed');
