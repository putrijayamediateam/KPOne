import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, defineComponent, h } from 'vue';

const require = createRequire(import.meta.url);
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const transpile = (source) =>
    ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
const helper = { exports: {} };
runInNewContext(transpile(read('resources/js/lib/whatsapp-link.ts')), {
    exports: helper.exports,
});
const { whatsAppNumber } = helper.exports;

const valid = [
    ['012345678', '6012345678'],
    ['0123456789', '60123456789'],
    ['6012345678', '6012345678'],
    ['60123456789', '60123456789'],
    ['+60123456789', '60123456789'],
    [' (012) 345-6789 ', '60123456789'],
    ['+60 (12) 345.6789', '60123456789'],
    ['012\u00a0345\u20096789', '60123456789'],
    ['+12025550123', '12025550123'],
    ['+44 (20) 7946-0958', '442079460958'],
    ['+12345678', '12345678'],
    ['+123456789012345', '123456789012345'],
];

for (const [input, expected] of valid) {
    test(`normalises a complete displayed number: ${input}`, () => {
        assert.equal(whatsAppNumber(input), expected);
        assert.match(whatsAppNumber(input), /^[0-9]+$/);
    });
}

const invalid = [
    null,
    undefined,
    '',
    ' ',
    123456789,
    {},
    '01234567',
    '01234567890',
    '601234567890',
    '+601234567890',
    '012345678901',
    '6012345678901',
    '+6012345678901',
    '012***6789',
    '012xxx6789',
    '012XXX6789',
    '012•••6789',
    '012●●●6789',
    '012…6789',
    '012...6789',
    '012___6789',
    '012#456789',
    '012ABC6789',
    '０１２３４５６７８９',
    '+1234567',
    '+1234567890123456',
    '442079460958',
    '00442079460958',
    '+0123456789',
    '++60123456789',
    '0123456789/0198765432',
    '0123456789,0198765432',
    '0123456789;0198765432',
    '+60123456789 +60198765432',
    '0123456789\n0198765432',
    '012\t3456789',
    '0123456789?text=test',
    'https://wa.me/60123456789',
    '0'.repeat(129),
];
test('rejects masks, partial values, ambiguous countries, controls and multiple numbers', () => {
    for (const input of invalid) {
        assert.equal(whatsAppNumber(input), null, String(input));
    }
});

const sharedVectors = JSON.parse(read('tests/Fixtures/whatsapp-numbers.json'));

for (const [input, expected] of sharedVectors) {
    test(`PHP/JS shared phone vector: ${String(input)}`, () => {
        assert.equal(whatsAppNumber(input), expected);
    });
}

// Render the actual link component; only presentation wrappers/icons are replaced.
const passthrough = defineComponent({
    inheritAttrs: false,
    setup:
        (_, { slots }) =>
        () =>
            slots.default?.(),
});
const { descriptor } = parse(
    read('resources/js/components/patient/WhatsAppLink.vue'),
);
const compiled = compileScript(descriptor, {
    id: 'whatsapp-link',
    inlineTemplate: true,
});
const component = { exports: {} };
runInNewContext(transpile(compiled.content), {
    exports: component.exports,
    require: (name) => {
        if (name === '@/lib/whatsapp-link') {
            return helper.exports;
        }

        if (name === '@/components/ui/button') {
            return { Button: passthrough };
        }

        if (name === '@/components/ui/tooltip') {
            return Object.fromEntries(
                [
                    'Tooltip',
                    'TooltipContent',
                    'TooltipProvider',
                    'TooltipTrigger',
                ].map((key) => [key, passthrough]),
            );
        }

        if (name === '@lucide/vue') {
            return {
                MessageCircle: defineComponent({ render: () => h('svg') }),
            };
        }

        return require(name);
    },
});
const render = (canWhatsapp, routeKey = 'KP-TEST') =>
    renderToString(
        createSSRApp({ render: () => h(component.exports.default, { canWhatsapp, routeKey }) }),
    );
test('actual component renders the exact authorised route, privacy attributes, accessible name and disclosure', async () => {
    const html = await render(true);
    assert.match(html, /href="\/patient-contact\/patient\/KP-TEST\/whatsapp"/);
    assert.match(html, /target="_blank"/);
    assert.match(html, /rel="noopener noreferrer"/);
    assert.match(html, /referrerpolicy="no-referrer"/);
    assert.match(html, /aria-label="Open WhatsApp chat"/);
    assert.match(
        html,
        /title="Open WhatsApp chat — your browser will share this saved number with WhatsApp"/,
    );
    assert.match(html, /<svg/);
    assert.match(html, /#25D366/);
    assert.match(html, /<path/);
    assert.match(html, /M23\.3 8\.7A10\.2 10\.2/);
    assert.doesNotMatch(descriptor.scriptSetup.content, /MessageCircle|@lucide/);
    assert.doesNotMatch(html, />WhatsApp|wa\.me/);
    assert.doesNotMatch(html, /\?text=|onclick|<button/);
    assert.match(descriptor.template.content, /min-h-12 min-w-12/);
    assert.doesNotMatch(
        descriptor.scriptSetup.content,
        /fetch\(|axios|router\.|@click|window\.open|localStorage/,
    );
});
test('actual component exposes no anchor when the server refuses a number', async () => {
    assert.doesNotMatch(await render(true, ''), /<a\b/);

    for (const phone of invalid.filter(
        (value) => typeof value === 'string' || value == null,
    )) {
        assert.equal(whatsAppNumber(phone), null);
        assert.doesNotMatch(await render(false), /<a\b|wa\.me|WhatsApp/);
    }
});
test('only already displayed saved values are bound; correction drafts and new patient inputs are excluded', () => {
    const show = read('resources/js/pages/Patient/Show.vue');
    assert.match(show, /:can-whatsapp="patient\.canWhatsapp"/);
    assert.match(show, /:route-key="patient\.patientNumber"/);
    const fields = read(
        'resources/js/components/patient/PatientFormFields.vue',
    );
    assert.match(
        fields,
        /!newPatient[\s\S]*originalPhone !== undefined[\s\S]*fields\.mobile_phone === originalPhone/,
    );
    assert.match(fields, /:can-whatsapp="!!canWhatsapp"/);
    assert.match(fields, /:route-key="patientNumber/);
    const intake = read('resources/js/pages/RegistrationReview/Show.vue');

    // Pending and guardian numbers are not a saved Patient contact and cannot
    // use the single current-patient redirect route.
    assert.doesNotMatch(intake, /:phone=|:route-key="intake\.fields/);
    assert.match(intake, /:can-whatsapp="candidate\.canWhatsapp"/);

    const claim = read('resources/js/pages/PanelClaims/Show.vue');
    assert.match(claim, /v-if="claim\.patientPhone"/);
    assert.match(claim, /:can-whatsapp="claim\.canWhatsapp"/);
    assert.match(claim, /:route-key="claim\.publicId"/);

    const directory = read('resources/js/pages/Patient/Index.vue');
    assert.match(directory, /:can-whatsapp="patient\.canWhatsapp"/);
    assert.match(directory, /:route-key="patient\.patientNumber"/);
    assert.match(directory, /grid-cols-\[minmax\(0,1fr\)_auto\] md:table-row/);

    for (const path of [
        'pages/Registration/Show.vue',
        'pages/PublicCheckIn/Show.vue',
        'components/patient/PhoneInput.vue',
    ]) {
        assert.doesNotMatch(
            read(`resources/js/${path}`),
            /WhatsAppLink|wa\.me/,
        );
    }
});
