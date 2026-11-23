import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, h } from 'vue';

// Classic only, CO-8: the Classic half of v2-audit-logs, kept when its V2 page was removed (synthetic data only).
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

            return require(name);
        },
    });

    return module.exports.default;
};
const classic = compile('resources/js/pages/AuditLogs/Index.vue');
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

test('Classic Audit Logs shows every field of each event and the total from the props', async () => {
    const a = await render(classic, { logs });

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
        }
    }

    assert.ok(a.includes('51 recorded events'));
});
