import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, h } from 'vue';

// Audit Logs V2 renders the same fields as Classic from the same props (synthetic data only).
const require = createRequire(import.meta.url);
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const transpile = (code) =>
    ts.transpileModule(code, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
const labels = { exports: {} };
runInNewContext(transpile(read('resources/js/lib/displayLabels.ts')), {
    exports: labels.exports,
    require,
});
const stub = () => ({
    inheritAttrs: false,
    props: ['label', 'title', 'description', 'hint', 'href', 'emptyMessage'],
    render() {
        const slots = Object.values(this.$slots).flatMap((slot) =>
            slot({ id: 'x', describedBy: undefined }),
        );

        return h('div', { 'data-href': this.href }, [
            this.label ? h('span', this.label) : null,
            this.title ? h('span', this.title) : null,
            this.description ? h('span', this.description) : null,
            ...slots,
        ]);
    },
});
const icons = new Proxy({}, { get: () => stub() });
const compile = (path) => {
    const module = { exports: {} };
    const code = compileScript(parse(read(path)).descriptor, {
        id: path,
        inlineTemplate: true,
    }).content;
    runInNewContext(transpile(code), {
        exports: module.exports,
        require: (name) => {
            if (name === '@inertiajs/vue3') {
                return { Head: stub(), Link: stub() };
            }

            if (name === '@lucide/vue') {
                return icons;
            }

            if (name === '@/lib/displayLabels') {
                return labels.exports;
            }

            if (name === '@/composables/usePageCrumbs') {
                return { usePageCrumbs: () => undefined };
            }

            if (name === '@/components/ui/badge') {
                return { Badge: stub() };
            }

            if (name === '@/components/ui/button') {
                return { Button: stub() };
            }

            if (name === '@/components/ui/card') {
                return Object.fromEntries(
                    [
                        'Card',
                        'CardContent',
                        'CardDescription',
                        'CardHeader',
                        'CardTitle',
                    ].map((key) => [key, stub()]),
                );
            }

            if (name === '@/components/ui/page-frame') {
                return { PageFrame: stub() };
            }

            if (name.startsWith('@/components/v2/')) {
                return { default: stub() };
            }

            return require(name);
        },
    });

    return module.exports.default;
};
const classic = compile('resources/js/pages/AuditLogs/Index.vue');
const v2 = compile('resources/js/pages/AuditLogs/V2/Index.vue');
const flat = (html) =>
    html
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/<[^>]+>/g, ' ')
        .replace(/&quot;/g, '"')
        .replace(/\s+/g, ' ');
const render = async (page, props) =>
    flat(await renderToString(createSSRApp({ render: () => h(page, props) })));
const logs = {
    total: 51,
    prev_page_url: '/audit-logs?page=1',
    next_page_url: '/audit-logs?page=3',
    data: [
        {
            id: 11,
            event: 'queue.called',
            actor: 'Synthetic Doctor With A Very Long Display Name Indeed',
            branch: 'CHERAS',
            subjectType: 'QueueEntry',
            subjectId: 7,
            occurredAt: '2026-11-05T09:16:00Z',
            roleNames: ['resident_doctor'],
            detail: 'Via Call next, from the Queue board',
        },
        {
            id: 12,
            event: 'staff.updated',
            actor: 'Synthetic Admin',
            branch: null,
            subjectType: 'User',
            subjectId: null,
            occurredAt: '2026-11-05T08:00:00Z',
            roleNames: [],
        },
        {
            id: 13,
            event: 'access.role.attached',
            actor: 'Synthetic Director',
            branch: 'PUCHONG',
            subjectType: null,
            subjectId: null,
            occurredAt: '2026-11-04T01:00:00Z',
            roleNames: ['ca'],
        },
    ],
};

test('V2 shows every field Classic shows, from the same props', async () => {
    const a = await render(classic, { logs });
    const b = await render(v2, { logs });

    for (const log of logs.data) {
        for (const part of [
            labels.exports.formatAuditEventLabel(log.event, log.roleNames),
            log.actor,
            log.branch ?? 'Organisation',
            new Intl.DateTimeFormat('en-MY', {
                dateStyle: 'medium',
                timeStyle: 'short',
                timeZone: 'Asia/Kuala_Lumpur',
            }).format(new Date(log.occurredAt)),
            ...(log.detail ? [log.detail] : []),
            ...(log.subjectType
                ? [
                      log.subjectType +
                          (log.subjectId ? ` #${log.subjectId}` : ''),
                  ]
                : []),
        ]) {
            assert.ok(a.includes(part), `classic: ${part}`);
            assert.ok(b.includes(part), `v2: ${part}`);
        }
    }

    assert.ok(
        a.includes('51 recorded events') && b.includes('51 recorded events'),
    );
    assert.ok(
        b.includes(
            'Sensitive credentials and token values are never recorded.',
        ),
    );
});

test('Previous and Next are the server links, with the page read from the previous link and nothing hidden at the ends', async () => {
    const mid = await renderToString(
        createSSRApp({ render: () => h(v2, { logs }) }),
    );
    assert.ok(mid.includes('data-href="/audit-logs?page=1"'), mid);
    assert.ok(mid.includes('data-href="/audit-logs?page=3"'), mid);
    assert.ok(flat(mid).includes('Page 2'));

    const first = await renderToString(
        createSSRApp({
            render: () => h(v2, { logs: { ...logs, prev_page_url: null } }),
        }),
    );
    assert.ok(first.includes('aria-disabled="true"'));
    assert.ok(flat(first).includes('Page 1'));
    assert.ok(flat(first).includes('Previous') && flat(first).includes('Next'));
});

test('the page-only search sentence is always visible, and V2 sends nothing and stores nothing', async () => {
    const out = await render(v2, { logs });
    assert.ok(
        out.includes(
            'Showing 3 of 3 events on this page. Search covers this page only.',
        ),
        out,
    );
    const source = read('resources/js/pages/AuditLogs/V2/Index.vue');

    for (const banned of [
        'fetch(',
        'router.',
        'axios',
        'localStorage',
        'sessionStorage',
        'useForm',
    ]) {
        assert.ok(!source.includes(banned), banned);
    }
});

test('an empty log says "No events recorded yet."; the search wording is only for a search', () => {
    const source = read('resources/js/pages/AuditLogs/V2/Index.vue');
    const flatSource = source.replace(/\s+/g, ' ');
    assert.ok(
        flatSource.includes(
            "logs.data.length === 0 ? 'No events recorded yet.' : 'No events on this page match your search.'",
        ),
    );
});
