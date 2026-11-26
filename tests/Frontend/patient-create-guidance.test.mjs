import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, h } from 'vue';

const require = createRequire(import.meta.url);
const transpile = (source) =>
    ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
const guardian = { exports: {} };
runInNewContext(
    transpile(readFileSync('resources/js/lib/guardian-contact.ts', 'utf8')),
    { exports: guardian.exports },
);
const source = readFileSync(
    'resources/js/components/patient/PatientCreateGuidance.vue',
    'utf8',
);
const module = { exports: {} };
runInNewContext(
    transpile(
        compileScript(parse(source).descriptor, {
            id: 'patient-create-guidance',
            inlineTemplate: true,
        }).content,
    ),
    {
        exports: module.exports,
        require: (name) =>
            name === '@/lib/guardian-contact'
                ? guardian.exports
                : require(name),
    },
);
const defaults = {
    requireDateOfBirth: false,
    requireNationality: false,
    requireEmail: false,
    requireAddress: false,
    lockVersion: 1,
};
const render = (
    requirements = {},
    dateOfBirth = '',
    guardianToday = '2026-11-26',
) =>
    renderToString(
        createSSRApp({
            render: () =>
                h(module.exports.default, {
                    requirements: { ...defaults, ...requirements },
                    dateOfBirth,
                    guardianToday,
                }),
        }),
    );
const row = (label, value) =>
    new RegExp(`<dt>${label}</dt>\\s*<dd[^>]*>${value}</dd>`);
test('actual optional policy is shown without inventing required fields or a visit', async () => {
    const html = await render();

    for (const label of ['Date of birth', 'Nationality', 'Email', 'Address']) {
assert.match(html, row(label, 'Optional'));
}

    assert.match(
        html,
        /Address details are optional, including address line 2/,
    );
    assert.match(html, /full name and an IC or passport are required/);
    assert.match(html, /It does not register a visit/);
    assert.doesNotMatch(html, /<input|<button|<form/);
});
test('each supplied requirement flag is respected and required address never includes line2', async () => {
    const html = await render({
        requireDateOfBirth: true,
        requireNationality: false,
        requireEmail: true,
        requireAddress: true,
    });
    assert.match(html, row('Date of birth', 'Required'));
    assert.match(html, row('Nationality', 'Optional'));
    assert.match(html, row('Email', 'Required'));
    assert.match(html, row('Address', 'Required'));
    assert.match(
        html,
        /Address line 1, postcode, city, state and country are required/,
    );
    assert.match(html, /Address line 2 is optional/);
    assert.doesNotMatch(html, /adult confirmation/);
});
test('minor contact guidance uses the supplied clinical day including the eighteenth birthday boundary', async () => {
    const before = await render({}, '2008-11-27', '2026-11-26');
    assert.match(
        before,
        /For this minor, guardian name, relationship and phone are required/,
    );
    assert.match(before, /patient&#39;s own phone is optional/);
    const birthday = await render({}, '2008-11-27', '2026-11-27');
    assert.doesNotMatch(birthday, /For this minor/);
    assert.match(birthday, /A patient phone is required unless/);
});
test('unknown optional DOB shows existing adult confirmation; invalid/future DOB never asserts minor status', async () => {
    assert.match(await render(), /complete the form&#39;s adult confirmation/);

    for (const dob of ['invalid', '2030-01-01']) {
assert.doesNotMatch(await render({}, dob), /For this minor/);
}
});
