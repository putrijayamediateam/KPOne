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
    assert.match(
        c,
        /\(4\) browser Back stays a known limit, with `beforeunload` for reload and tab close\./,
    );
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
