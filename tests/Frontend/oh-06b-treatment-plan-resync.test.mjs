import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// OH-06b: the same investigation that found the clinical note re-sync gap in
// Clinical/Show.vue (OH-06) also flagged this panel - embedded in Show.vue -
// as exhibiting the same pattern. Show.vue's Hold/Resume actions use
// `preserveState: true` (by design, so a Hold/Resume click never disturbs a
// doctor's in-progress edits), so this panel is never remounted by those
// actions either, and its medicines/services draft `form` (seeded once from
// `props.plan` at setup) was only ever re-synced inside its own save's
// onSuccess - nothing reacted to the fresh `plan` prop that DOES arrive with
// the hold/resume response.
//
// The fix mirrors Show.vue exactly: a `watch()` on `plan.lockVersion` adopts
// the fresh medicines/services/lock_version together, atomically, but only
// while the form has no unsaved edits. A dirty draft is never overwritten
// and its lock_version is never advanced; a visible "record drifted" banner
// is shown instead, and the next Save is left to fail safely at the backend.
const source = readFileSync(
    new URL(
        '../../resources/js/pages/Clinical/Partials/TreatmentPlanPanel.vue',
        import.meta.url,
    ),
    'utf8',
);

test('watches plan.lockVersion, the single signal that fires whenever medicines or services change', () => {
    assert.match(
        source,
        /watch\(\s*\(\)\s*=>\s*props\.plan\.lockVersion/,
        'No watcher on plan.lockVersion was found.',
    );
});

test('a clean form adopts medicines, services and lock_version together, in one place', () => {
    const fn = source.match(
        /const adoptLatestRecord = \(\) => \{([\s\S]*?)\n\};/,
    );
    assert.ok(fn, 'No single adoptLatestRecord()-style resync function was found.');
    const body = fn[1];
    assert.match(
        body,
        /form\.lock_version\s*=\s*props\.plan\.lockVersion/,
        'lock_version is not resynced from the fresh prop.',
    );
    assert.match(
        body,
        /form\.medicines\s*=\s*props\.plan\.medicines\.map\(medicineRow\)/,
        'medicines is not resynced from the fresh prop.',
    );
    assert.match(
        body,
        /form\.services\s*=\s*props\.plan\.services\.map\(serviceRow\)/,
        'services is not resynced from the fresh prop.',
    );
});

test('a dirty form is never silently overwritten, and its lock_version is never advanced', () => {
    const watcher = source.match(
        /watch\(\s*\(\)\s*=>\s*props\.plan\.lockVersion,\s*\(\)\s*=>\s*\{([\s\S]*?)\n\s*\},?\n\);/,
    );
    assert.ok(watcher, 'Could not locate the plan.lockVersion watcher body.');
    const body = watcher[1];
    assert.match(
        body,
        /if\s*\(\s*form\.isDirty\s*\)\s*\{/,
        'The watcher does not guard on form.isDirty before resyncing.',
    );
    const dirtyBranch = body.slice(
        body.indexOf('if'),
        body.indexOf('return') + 'return'.length + 1,
    );
    assert.doesNotMatch(
        dirtyBranch,
        /form\.lock_version\s*=/,
        'lock_version must not be touched while the form is dirty.',
    );
    assert.doesNotMatch(
        dirtyBranch,
        /form\.medicines\s*=/,
        'medicines must not be overwritten while the form is dirty.',
    );
});

test('shows a visible drift warning with an explicit reload action, not a silent failure', () => {
    assert.match(
        source,
        /recordDrifted/,
        'No drift-detected flag was found for the warning banner to key off.',
    );
    assert.match(
        source,
        /v-if="recordDrifted"/,
        'No banner is conditionally rendered on the drift flag.',
    );
    assert.match(
        source,
        /@click="adoptLatestRecord"/,
        'No explicit reload action is wired to re-sync the form on demand.',
    );
});

test('the drift banner text is English, consistent with the rest of this screen', () => {
    const banner = source.match(
        /v-if="recordDrifted"[\s\S]*?<\/div>/,
    );
    assert.ok(banner, 'Could not locate the drift banner markup.');
    assert.match(banner[0], /Treatment Plan has changed elsewhere/);
    assert.match(banner[0], />\s*Reload\s*</);
});

test('Registration/Show.vue was left untouched by this fix', () => {
    const registrationShow = readFileSync(
        new URL('../../resources/js/pages/Registration/Show.vue', import.meta.url),
        'utf8',
    );
    assert.doesNotMatch(
        registrationShow,
        /adoptLatestRecord|recordDrifted/,
        'Registration/Show.vue was not in scope for OH-06b and must remain unchanged.',
    );
});
