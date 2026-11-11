<script setup lang="ts">
// HR-7c Exports tab (HR-PLAN 17.3 and 17.7). One card per locked run with the bank file, the accountant summary and the
// statutory summary for the portals. Each download is recorded first and never stored. PDPA 14 (owner, 2026-11-11):
// the Director and the HR Manager download, and every file is an AES-256 zip under a password typed here, which is sent
// once with the request and never kept in KPOne or in this page after the download.
import { reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { myr } from '@/types/billing';
import type { ExportRun } from './types';

defineProps<{ runs: ExportRun[] }>();

const MIN_PASSWORD = 12;
const busy = ref<string | null>(null);
const failure = ref('');
const asking = ref<{ run: ExportRun; kind: 'bank' | 'accountant' } | null>(
    null,
);
const secret = reactive({
    password: '',
    confirm: '',
    show: false,
    error: '',
});
const forget = () => {
    secret.password = '';
    secret.confirm = '';
    secret.show = false;
    secret.error = '';
};
const ask = (run: ExportRun, kind: 'bank' | 'accountant') => {
    forget();
    failure.value = '';
    asking.value = { run, kind };
};
const close = () => {
    asking.value = null;
    forget();
};

const download = async () => {
    if (busy.value || !asking.value) {
        return;
    }

    if (secret.password.length < MIN_PASSWORD) {
        secret.error = `Type a password of at least ${MIN_PASSWORD} characters for the file.`;

        return;
    }

    if (secret.password !== secret.confirm) {
        secret.error = 'The two passwords do not match.';

        return;
    }

    const { run, kind } = asking.value;
    busy.value = `${run.id}:${kind}`;
    failure.value = '';
    secret.error = '';

    try {
        const response = await fetch(
            `/hr/payroll/runs/${run.id}/export/${kind}`,
            {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>(
                            'meta[name="csrf-token"]',
                        )?.content ?? '',
                },
                body: JSON.stringify({
                    file_password: secret.password,
                    file_password_confirmation: secret.confirm,
                }),
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
            /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? `${kind}.zip`;
        const url = URL.createObjectURL(await response.blob());
        const link = document.createElement('a');
        link.href = url;
        link.download = name;
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
        close();
    } catch (error) {
        secret.error =
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
            <p class="text-sm text-muted-foreground">
                Each file comes as a password-protected zip. Keep it in the
                clinic's locked folder, delete other copies once sent, and send
                the password by a different way from the file.
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
                    @click="ask(run, 'bank')"
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
                    @click="ask(run, 'accountant')"
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

        <Dialog
            :open="asking !== null"
            @update:open="(open: boolean) => !open && close()"
        >
            <DialogContent class="sm:max-w-md" data-test="export-password">
                <DialogHeader>
                    <DialogTitle>Protect the file with a password</DialogTitle>
                    <DialogDescription>
                        {{
                            asking?.kind === 'bank'
                                ? 'Bank file'
                                : 'Accountant summary'
                        }}
                        for {{ asking?.run.group }} · {{ asking?.run.month }}.
                        KPOne never keeps this password, so note it somewhere
                        safe: without it the file cannot be opened.
                    </DialogDescription>
                </DialogHeader>
                <form
                    class="flex flex-col gap-3"
                    autocomplete="off"
                    @submit.prevent="download"
                >
                    <div class="flex flex-col gap-1">
                        <Label for="export-file-password"
                            >Password (at least
                            {{ MIN_PASSWORD }} characters)</Label
                        >
                        <Input
                            id="export-file-password"
                            v-model="secret.password"
                            :type="secret.show ? 'text' : 'password'"
                            autocomplete="new-password"
                            class="max-lg:min-h-11"
                            :aria-invalid="secret.error ? 'true' : undefined"
                            aria-describedby="export-file-password-error"
                            data-test="export-password-input"
                        />
                    </div>
                    <div class="flex flex-col gap-1">
                        <Label for="export-file-password-again"
                            >Type it again</Label
                        >
                        <Input
                            id="export-file-password-again"
                            v-model="secret.confirm"
                            :type="secret.show ? 'text' : 'password'"
                            autocomplete="new-password"
                            class="max-lg:min-h-11"
                            data-test="export-password-again"
                        />
                    </div>
                    <label class="flex min-h-11 items-center gap-2 text-sm">
                        <input
                            v-model="secret.show"
                            type="checkbox"
                            class="size-4"
                        />
                        Show password
                    </label>
                    <InputError
                        id="export-file-password-error"
                        :message="secret.error"
                    />
                    <p class="text-xs text-muted-foreground">
                        Open it with 7-Zip or WinZip on Windows, or The
                        Unarchiver on a Mac; the built-in zip opener cannot open
                        protected files. Many bank portals take only the CSV, so
                        unzip it before uploading.
                    </p>
                    <DialogFooter class="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            class="max-lg:min-h-11"
                            @click="close"
                            >Cancel</Button
                        >
                        <Button
                            type="submit"
                            class="max-lg:min-h-11"
                            :disabled="!!busy"
                            data-test="export-password-download"
                            >{{
                                busy ? 'Preparing…' : 'Download protected file'
                            }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </section>
</template>
