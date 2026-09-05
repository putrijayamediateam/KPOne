import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const source = readFileSync(
    new URL('../../resources/js/lib/patient-registration.ts', import.meta.url),
    'utf8',
);
const exports = {};
runInNewContext(
    ts.transpileModule(source, {
        compilerOptions: { module: ts.ModuleKind.CommonJS },
    }).outputText,
    { exports, require: createRequire(import.meta.url) },
);
const { phoneError, identityError } = exports;

test('passport validation matches released PHP Unicode whitespace and issuer normalization', () => {
    for (const [value, issuer] of [
        ['ABC\u0085123', 'MY'],
        ['ABC123', 'ＭＹ'],
        ['\0ABC123\0', 'MY'],
    ]) {
        assert.equal(identityError('passport', value, issuer), '');
    }

    for (const value of ['ABC\uFEFF123', '\f\0ABC123']) {
        assert.notEqual(identityError('passport', value, 'MY'), '');
    }
});

test('switching Passport to IC clears stale issuer without altering passport issuer text', () => {
    assert.equal(exports.identifierIssuer('nric', 'S'), 'MY');
    assert.equal(exports.identifierIssuer('passport', 'GB'), 'GB');
    assert.equal(
        identityError(
            'nric',
            '000101011234',
            exports.identifierIssuer('nric', 'S'),
        ),
        '',
    );
    const page = readFileSync(
        new URL('../../resources/js/pages/Patient/Create.vue', import.meta.url),
        'utf8',
    );
    assert.match(page, /identifierIssuer\(\s*identity\.identifier_type/);
    assert.match(page, /identifierIssuer\(\s*identifier\.identifier_type/);
});

test('live phone validation exempts only exact unchanged legacy contact on Edit', () => {
    assert.equal(exports.phoneInputError('123', 'MY', false, '123'), '');
    assert.equal(exports.phoneInputError('', 'MY', false, ''), '');
    assert.notEqual(exports.phoneInputError('124', 'MY', false, '123'), '');
    assert.notEqual(exports.phoneInputError('', 'MY', false, '123'), '');
    assert.notEqual(exports.phoneInputError('123', 'MY', true), '');
    const edit = readFileSync(
        new URL('../../resources/js/pages/Patient/Edit.vue', import.meta.url),
        'utf8',
    );
    const fields = readFileSync(
        new URL(
            '../../resources/js/components/patient/PatientFormFields.vue',
            import.meta.url,
        ),
        'utf8',
    );
    const phone = readFileSync(
        new URL(
            '../../resources/js/components/patient/PhoneInput.vue',
            import.meta.url,
        ),
        'utf8',
    );
    assert.ok(edit.includes(':original-phone="patient.contact.mobilePhone'));
    assert.ok(fields.includes(':unchanged-value="originalPhone"'));
    assert.ok(phone.includes('props.unchangedValue'));
});

test('additional synthetic Malaysia Singapore US and Australia vectors match backend', () => {
    for (const [value, country] of [
        ['01112345678', 'MY'],
        ['81234567', 'SG'],
        ['2025550123', 'US'],
        ['0412345678', 'AU'],
    ]) {
        assert.equal(phoneError(value, country), '');
    }

    for (const value of ['0991234567', '0123456789012']) {
        assert.notEqual(phoneError(value, 'MY'), '');
    }
});

test('explicit international numbers cannot have digits silently repaired', () => {
    for (const value of ['+600123456789', '+4402079460018']) {
        assert.equal(
            phoneError(value, 'MY'),
            'Semak kod negara dan nombor telefon.',
        );
    }
});

test('failed submission focuses the first marked field after rendering', () => {
    let focused = false;
    const context = {};
    runInNewContext(
        ts.transpileModule(source, {
            compilerOptions: { module: ts.ModuleKind.CommonJS },
        }).outputText,
        {
            exports: context,
            require: createRequire(import.meta.url),
            requestAnimationFrame: (callback) => callback(),
            document: {
                querySelector: (selector) => {
                    assert.equal(selector, '[aria-invalid="true"]');

                    return {
                        focus: () => {
                            focused = true;
                        },
                    };
                },
            },
        },
    );
    context.focusInvalidField();
    assert.equal(focused, true);
});

test('control-only and Unicode line separators are never treated as empty valid contact data', () => {
    for (const value of [
        '\n',
        '\r',
        '\t',
        '0123456789\u2028',
        '0123456789\u0000',
    ]) {
        assert.notEqual(phoneError(value, 'MY', false), '');
    }

    assert.notEqual(identityError('nric', '000101011234\u2028', 'MY'), '');
});

test('Registration recognizes formatted phone searches without changing IC priority', () => {
    const page = readFileSync(
        new URL(
            '../../resources/js/pages/Registration/Create.vue',
            import.meta.url,
        ),
        'utf8',
    );
    const classifier = page.match(
        /const patientSearchType = ([\s\S]*?)\n};/,
    )?.[0];
    assert.ok(classifier);
    const context = {};
    runInNewContext(
        ts.transpileModule(
            classifier + '\nexports.classify = patientSearchType;',
            { compilerOptions: { module: ts.ModuleKind.CommonJS } },
        ).outputText,
        { exports: context },
    );
    assert.equal(context.classify('(012) 345-6789'), 'phone');
    assert.equal(context.classify('+60 12-3456789'), 'phone');
    assert.equal(context.classify('000101-01-1234'), 'nric');
});

test('metadata phone validation supports MY, explicit international and selected foreign country', () => {
    for (const value of [
        '0123456789',
        '(012) 345-6789',
        '+60 12-3456789',
        '+44 20 7946 0018',
    ]) {
        assert.equal(phoneError(value, 'MY'), '');
    }

    assert.equal(phoneError('020 7946 0018', 'GB'), '');

    for (const value of [
        '',
        '123',
        123,
        null,
        '0123456789x1',
        '0123456789\n',
        '\t0123456789',
        '+60+123456789',
    ]) {
        assert.notEqual(phoneError(value, 'MY'), '');
    }
});

test('IC leading zeroes and compatible passport text are accepted without IC coercion', () => {
    assert.equal(identityError('nric', '000101-01-1234', 'MY'), '');

    for (const value of [
        '',
        '123',
        '1234567890123',
        '00010101123a',
        '000101011234\n',
    ]) {
        assert.notEqual(identityError('nric', value, 'MY'), '');
    }

    assert.equal(identityError('passport', ' syn - p123 ', 'MY'), '');

    for (const [value, issuer] of [
        ['AB', 'MY'],
        ['SYN!', 'MY'],
        ['SYN-P123', ''],
    ]) {
        assert.notEqual(identityError('passport', value, issuer), '');
    }
});

test('phone component retains typed input, uses tel semantics and linked inline errors', () => {
    const component = readFileSync(
        new URL(
            '../../resources/js/components/patient/PhoneInput.vue',
            import.meta.url,
        ),
        'utf8',
    );

    for (const text of [
        'type="tel"',
        'autocomplete="tel"',
        'aria-describedby',
        'role="alert"',
        'update:country',
        'update:modelValue',
    ]) {
        assert.ok(component.includes(text));
    }

    assert.ok(!component.includes('.format('));
});

test('visible Gender labels keep released sex API field', () => {
    for (const file of [
        'components/patient/PatientFormFields.vue',
        'pages/Registration/Create.vue',
        'pages/Patient/Show.vue',
    ]) {
        const page = readFileSync(
            new URL('../../resources/js/' + file, import.meta.url),
            'utf8',
        );
        assert.ok(page.includes('Gender'));
        assert.ok(page.includes('.sex'));
        assert.ok(!/>Sex</.test(page));
    }
});
