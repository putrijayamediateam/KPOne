<script setup lang="ts">
// HR-7c Exports tab (HR-PLAN 17.3 and 17.7): the Director only in v1. One card per locked run with the bank file, the
// accountant summary and the statutory summary for the portals. Each download is recorded first and never stored.
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { EmptyState, ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { myr } from '@/types/billing';
import type { ExportRun } from './types';

defineProps<{ runs: ExportRun[] }>();

const busy = ref<string | null>(null);
const failure = ref('');
const download = async (run: ExportRun, kind: 'bank' | 'accountant') => {
    if (busy.value) {
        return;
    }

    busy.value = `${run.id}:${kind}`;
    failure.value = '';

    try {
        const response = await fetch(
            `/hr/payroll/runs/${run.id}/export/${kind}`,
            {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>(
                            'meta[name="csrf-token"]',
                        )?.content ?? '',
                },
            },
        );

        if (!response.ok) {
            const payload = await response.json().catch(() => null);

            throw new Error(
                payload?.errors
                    ? Object.values(payload.errors).flat().join(' ')
                    : response.status === 429
                      ? 'Too many downloads. Try again in a minute.'
                      : 'Unable to prepare the file. Try again.',
            );
        }

        const disposition = response.headers.get('Content-Disposition') ?? '';
        const name =
            /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? `${kind}.csv`;
        const url = URL.createObjectURL(await response.blob());
        const link = document.createElement('a');
        link.href = url;
        link.download = name;
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (error) {
        failure.value =
            error instanceof Error
                ? error.message
                : 'Unable to prepare the file.';
    } finally {
        busy.value = null;
    }
};
</script>

<template>
    <section aria-labelledby="exports-title" class="flex flex-col gap-4">
        <div>
            <h3 id="exports-title" class="text-base font-semibold">Exports</h3>
            <p class="text-sm text-muted-foreground">
                From locked runs only. Each download is recorded and no file is
                kept in KPOne. KPOne makes no payment and files nothing.
            </p>
            <p class="text-sm text-muted-foreground">
                Open the bank file in a spreadsheet as text, so account numbers
                keep their leading zeros.
            </p>
        </div>

        <ErrorState v-if="failure" :message="failure" />

        <EmptyState
            v-if="!runs.length"
            title="No locked runs yet."
            description="A run can be exported once it is approved and locked."
        />

        <article
            v-for="run in runs"
            :key="run.id"
            class="flex flex-col gap-3 rounded-xl border bg-card p-4"
            data-test="export-run"
        >
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                    <h4 class="font-semibold break-words">
                        {{ run.group }} · {{ run.month
                        }}<template v-if="run.kind === 'adjustment'">
                            (adjustment)</template
                        >
                    </h4>
                    <p class="text-sm text-muted-foreground tabular-nums">
                        {{ run.people }}
                        {{ run.people === 1 ? 'person' : 'people' }} · net
                        {{ myr(run.netSen) }}
                        <template v-if="run.exports">
                            · downloaded {{ run.exports }}
                            {{ run.exports === 1 ? 'time' : 'times' }}</template
                        >
                    </p>
                </div>
                <StatusBadge status="locked" label="Locked" tone="success" />
            </div>

            <p
                v-if="run.missingBank.length"
                class="text-sm text-muted-foreground"
                data-test="export-missing-bank"
            >
                Not in the bank file: {{ run.missingBank.join(', ') }}. They had
                no confirmed bank details when the run was locked, so pay them
                outside the file.
            </p>

            <div class="flex flex-wrap gap-2">
                <Button
                    variant="outline"
                    class="max-lg:min-h-11"
                    :disabled="!!busy || run.missingBank.length === run.people"
                    data-test="export-bank"
                    @click="download(run, 'bank')"
                    >{{
                        busy === `${run.id}:bank`
                            ? 'Preparing…'
                            : 'Download bank file'
                    }}</Button
                >
                <Button
                    variant="outline"
                    class="max-lg:min-h-11"
                    :disabled="!!busy"
                    data-test="export-accountant"
                    @click="download(run, 'accountant')"
                    >{{
                        busy === `${run.id}:accountant`
                            ? 'Preparing…'
                            : 'Download accountant summary'
                    }}</Button
                >
            </div>

            <details v-if="run.statutory.length" class="rounded-lg border p-3">
                <summary class="cursor-pointer text-sm font-medium">
                    Statutory summary for the portals
                </summary>
                <p class="mt-2 text-sm text-muted-foreground">
                    Totals to type into the KWSP, PERKESO and LHDN portals by
                    hand. KPOne files nothing.
                </p>
                <div class="mt-2 overflow-x-auto">
                    <table class="w-full min-w-[28rem] text-sm">
                        <thead>
                            <tr class="text-left">
                                <th scope="col" class="py-1 pr-3 font-medium">
                                    Scheme
                                </th>
                                <th
                                    scope="col"
                                    class="py-1 pr-3 text-right font-medium"
                                >
                                    People
                                </th>
                                <th
                                    scope="col"
                                    class="py-1 pr-3 text-right font-medium"
                                >
                                    Employee
                                </th>
                                <th
                                    scope="col"
                                    class="py-1 text-right font-medium"
                                >
                                    Employer
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="s in run.statutory"
                                :key="s.scheme"
                                class="border-t tabular-nums"
                            >
                                <th
                                    scope="row"
                                    class="py-1 pr-3 text-left font-normal"
                                >
                                    {{ s.scheme }}
                                </th>
                                <td class="py-1 pr-3 text-right">
                                    {{ s.people }}
                                </td>
                                <td class="py-1 pr-3 text-right">
                                    {{ myr(s.employeeSen) }}
                                </td>
                                <td class="py-1 text-right">
                                    {{
                                        s.scheme === 'MTD (PCB)'
                                            ? '—'
                                            : myr(s.employerSen)
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </details>
        </article>
    </section>
</template>
