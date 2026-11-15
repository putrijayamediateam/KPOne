import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const guard = read('resources/js/components/v2/V2LeaveGuard.vue');

const loadRule = async () => {
    const ts = (await import('typescript')).default;
    const js = ts.transpileModule(read('resources/js/lib/v2-leave-rule.ts'), {
        compilerOptions: {
            target: ts.ScriptTarget.ES2022,
            module: ts.ModuleKind.ES2022,
        },
    }).outputText;

    return import(
        `data:text/javascript;base64,${Buffer.from(js).toString('base64')}`
    );
};

test('V2LeaveGuard: the same words as the dialog guard, and one shared mechanism', () => {
    assert.match(guard, /useLeaveGuard\(/);
    assert.doesNotMatch(guard, /router\./);
    assert.match(guard, /title="Discard your changes\?"/);
    assert.match(guard, /confirm-label="Discard changes"/);
    assert.match(guard, /cancel-label="Keep editing"/);
    assert.doesNotMatch(guard, /components\/ui/);
});

test('useLeaveGuard: router.on before cancels a held visit; beforeunload; both listeners are removed on unmount', () => {
    const c = read('resources/js/composables/useLeaveGuard.ts');
    assert.match(c, /router\.on\('before'/);
    assert.match(c, /holdTarget\(/);
    assert.match(c, /return false;/);
    assert.match(c, /addEventListener\('beforeunload', warnOnUnload\)/);
    assert.match(
        c,
        /onBeforeUnmount\(\(\) => \{\s+stop\(\);\s+window\.removeEventListener\('beforeunload', warnOnUnload\);/,
    );
    assert.match(
        c,
        /\(1\) A same-path visit and a partial reload or a polling visit/,
    );
    assert.match(c, /\(2\) a successful save never asks/);
    assert.match(
        c,
        /\(3\) the listener is removed when the page or sheet unmounts/,
    );
    assert.match(c, /\(4\) browser Back and Forward are held too/);
});

test('holdTarget: held only for a GET to another path while dirty; a poll, a reload and a save are never held', async () => {
    const { holdTarget } = await loadRule();
    const here = { pathname: '/registration', origin: 'http://kpone.test' };
    const dirty = { dirty: true, bypass: false };

    // Held: a link to another page.
    assert.equal(
        holdTarget(
            {
                method: 'get',
                url: new URL('http://kpone.test/clinic-management?tab=1'),
            },
            here,
            dirty,
        ),
        '/clinic-management?tab=1',
    );
    assert.equal(
        holdTarget({ method: 'GET', url: '/staff' }, here, dirty),
        '/staff',
    );

    // Never held: the Registration and Queue live refresh (a GET reload of the same path, even with filters or `only`).
    assert.equal(
        holdTarget(
            { method: 'get', url: new URL('http://kpone.test/registration') },
            here,
            dirty,
        ),
        null,
    );
    assert.equal(
        holdTarget(
            {
                method: 'get',
                url: new URL(
                    'http://kpone.test/registration?tab=waiting&page=2',
                ),
            },
            here,
            dirty,
        ),
        null,
    );
    assert.equal(
        holdTarget(
            { method: 'get', url: '/registration?search=ahmad' },
            here,
            dirty,
        ),
        null,
    );
    assert.equal(
        holdTarget(
            { method: 'get', url: new URL('http://kpone.test/queue') },
            { pathname: '/queue', origin: here.origin },
            dirty,
        ),
        null,
    );

    // Never held: a save and any non-GET.
    for (const method of ['post', 'patch', 'put', 'delete', 'POST']) {
        assert.equal(
            holdTarget({ method, url: '/staff/13' }, here, dirty),
            null,
            method,
        );
    }

    // Never held when nothing changed, or after "Discard changes".
    assert.equal(
        holdTarget({ method: 'get', url: '/staff' }, here, {
            dirty: false,
            bypass: false,
        }),
        null,
    );
    assert.equal(
        holdTarget({ method: 'get', url: '/staff' }, here, {
            dirty: true,
            bypass: true,
        }),
        null,
    );
});

test('Staff/Edit: leave guard, person crumb, plain wording', () => {
    const page = read('resources/js/pages/Staff/V2/Edit.vue');
    assert.match(page, /<V2LeaveGuard :dirty="form\.isDirty" \/>/);
    assert.match(page, /usePageCrumbs/);
    assert.match(page, /Clinic management/);
    assert.match(page, /changed on the person's own page/);
    assert.doesNotMatch(page, /Protected ownership/);
    assert.match(page, /That email is already used by another staff member\./);
});

test('V2LeaveGuard.ask: in-page actions, the five rules', () => {
    assert.match(guard, /defineExpose\(\{ ask \}\)/);
    // (3) a clean form runs at once and asks nothing
    assert.match(guard, /if \(!props\.dirty\) \{\s*run\(\);\s*return;/);
    // (1) a second click while the question is open does nothing
    assert.match(guard, /if \(open\.value\) \{\s*return;/);
    // (2) Esc is Keep editing (the dialog's update:open path)
    assert.match(guard, /!value && keepEditing\(\)/);
    // (5) the page resets (discard is emitted) before run
    assert.ok(
        guard.indexOf("emit('discard')") <
            guard.indexOf('run();', guard.indexOf('const discard')),
    );

    // the docs carry the rules
    for (const words of [
        'a second click on any trigger does nothing, and the focus stays on Keep editing',
        'Esc is Keep editing',
        'a clean form runs at once and asks nothing, and a',
        'never for Save',
        'after Discard changes the page resets before `run`, so the next',
    ]) {
        assert.ok(guard.replace(/\s*\n\s*\*\s*/g, ' ').includes(words), words);
    }
});

test('Browser Back and Forward are held while dirty (one popstate listener, before Inertia)', () => {
    const pop = read('resources/js/lib/v2-leave-popstate.ts');
    const composable = read('resources/js/composables/useLeaveGuard.ts');
    const app = read('resources/js/app.ts');
    // the module is imported before Inertia starts, so its listener runs first
    assert.match(app.split('\n')[0], /import '@\/lib\/v2-leave-popstate';/);
    assert.match(pop, /event\.stopImmediatePropagation\(\)/);
    assert.match(
        pop,
        /window\.history\.pushState\(shown\.state, '', shown\.url\)/,
    );
    assert.match(pop, /document\.addEventListener\('inertia:navigate'/);
    // clean forms are never held
    assert.match(pop, /\.reverse\(\)\.find\(\(g\) => g\.dirty\(\)\)/);
    assert.match(composable, /registerPopStateGuard/);
    assert.match(composable, /stopPop\(\)/);
    // Discard goes through the same bypassing visit as a held link
    assert.match(composable, /bypass = true;/);
});
