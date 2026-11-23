// Classic only, CO-7: the Classic half of hr-v2-appraisal, hr-v2-appraisals, hr-v2-discipline, hr-v2-disciplinecase,
// hr-v2-incident, hr-v2-incidents and hr-v2-letters, kept when the V2 HR pages were removed. Each Classic page takes
// every request from its shared composable; the composable checks are carried over unchanged.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

for (const [page, composable] of [
    ['Appraisal', 'useAppraisalPage'],
    ['Appraisals', 'useAppraisalsPage'],
    ['Discipline', 'useDisciplinePage'],
    ['DisciplineCase', 'useDisciplineCasePage'],
    ['Incident', 'useIncidentPage'],
    ['Incidents', 'useIncidentsPage'],
    ['Letters', 'useLettersPage'],
]) {
    test(`Classic HR/${page} sends no request of its own; ${composable} holds them all`, () => {
        const source = read(`resources/js/pages/HR/${page}.vue`);
        assert.match(source, new RegExp(`${composable}\\(props\\)`));
        assert.doesNotMatch(
            source,
            /router\.(post|put|patch|delete)\(|fetch\(/,
        );
    });
}

test('the appraisal composable sends the lock version, a key and the sign-off', () => {
    const shared = read('resources/js/composables/hr/useAppraisalPage.ts');
    assert.match(
        shared,
        /expected_lock_version: props\.appraisal\.lockVersion/,
    );
    assert.match(shared, /idempotency_key: form\.key/);
    assert.match(shared, /\/sign-off/);
});

test('the appraisals composable opens cycles with a key', () => {
    const shared = read('resources/js/composables/hr/useAppraisalsPage.ts');
    assert.match(shared, /\/hr\/appraisals\/cycles/);
    assert.match(shared, /idempotency_key/);
});

test('the discipline composable posts with a key and keeps nothing in the browser', () => {
    const shared = read('resources/js/composables/hr/useDisciplinePage.ts');
    assert.match(shared, /'\/hr\/discipline'/);
    assert.match(shared, /idempotency_key/);
    assert.doesNotMatch(
        shared,
        /localStorage|sessionStorage|indexedDB|prefetch/i,
    );
});

test('the discipline case composable sends the lock version, a key and form data', () => {
    const shared = read('resources/js/composables/hr/useDisciplineCasePage.ts');
    assert.match(shared, /expected_lock_version: props\.case\.lockVersion/);
    assert.match(shared, /idempotency_key/);
    assert.match(shared, /forceFormData/);
});

test('the incident composable acknowledges with the lock version and a key', () => {
    const shared = read('resources/js/composables/hr/useIncidentPage.ts');
    assert.match(shared, /acknowledge/);
    assert.match(shared, /expected_lock_version/);
    assert.match(shared, /idempotency_key/);
});

test('the incidents composable posts form data with a key', () => {
    const shared = read('resources/js/composables/hr/useIncidentsPage.ts');
    assert.match(shared, /'\/hr\/incidents'/);
    assert.match(shared, /forceFormData: true/);
    assert.match(shared, /idempotency_key/);
});

test('the letters composable previews through the letters route', () => {
    const shared = read('resources/js/composables/hr/useLettersPage.ts');
    assert.match(shared, /\/hr\/letters\/preview/);
});
