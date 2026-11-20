// Classic only, CO-7: the Classic half of hr-v2-records.test.mjs, kept when its V2 page and parts were removed. The
// checks that read the V2 parts are pinned here on the Classic parts that hold the same rules.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/HR/Records.vue');
const page = read('resources/js/composables/hr/useRecordsPage.ts');
const dir = 'resources/js/components/hr/records/';

test('Classic Records and its parts take their requests from the shared composables', () => {
    assert.match(classic, /useRecordsPage\(props\)/);
    assert.match(
        read(`${dir}CorrectionSheet.vue`),
        /useCorrectionSheet\(props/,
    );
    assert.match(read(`${dir}OutsideExportCard.vue`), /useOutsideExport\(\)/);

    for (const url of [
        '/hr/records/my/contact',
        '/hr/records/my/corrections/${c.id}/withdraw',
        '/hr/records/settings/retention',
        '/hr/records/settings/checklist-items',
        '/hr/records/files',
        '/hr/records/locums',
    ]) {
        assert.ok(page.includes(url), url);
    }
});

test('sensitive values: your own stay hidden until Show and are never fetched; documents open by their audited route', () => {
    const own = read(`${dir}OwnValue.vue`);
    assert.doesNotMatch(own, /fetch\(|router\.|axios/);
    assert.match(own, /visibilitychange/);
    assert.match(own, /v-else/);
    const docs = read(`${dir}DocumentList.vue`);
    assert.match(
        docs,
        /`\/hr\/records\/\$\{workerId\}\/documents\/\$\{d\.id\}`/,
    );
    assert.doesNotMatch(docs, /<img|:src=/);
});

test('Classic Records uses its own OwnValue and no V2 part', () => {
    assert.doesNotMatch(classic, /V2WorkLayout|V2Dialog/);
    assert.match(classic, /<OwnValue/);
});
