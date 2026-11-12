<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { formatDate } from '@/lib/presentation';
import { formatRinggit, trimQuantity } from '@/lib/supplier-terms';

type CheckedRow = {
    line: number;
    location: string;
    sku: string;
    stock_unit: string | null;
    batch_number: string;
    new_batch: boolean;
    expiry_date: string;
    quantity: string;
    unit_cost_sen: number | null;
    problems: string[];
};

const props = defineProps<{
    import: {
        branchName: string;
        expectedBranchId: number;
        columns: string[];
        maxRows: number;
    };
}>();

// The file stays in this browser tab: it is checked, then sent again to record. KPOne never stores it.
const file = ref<File | null>(null);
const checking = ref(false);
const recording = ref(false);
const checked = ref<{
    hash: string;
    errors: number;
    newBatches: number;
    rows: CheckedRow[];
} | null>(null);
const fileError = ref<string | undefined>();
const importKey = ref(crypto.randomUUID());

const csrf = (): string =>
    decodeURIComponent(
        document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );

const choose = (event: Event) => {
    file.value = (event.target as HTMLInputElement).files?.[0] ?? null;
    checked.value = null;
    fileError.value = undefined;
    importKey.value = crypto.randomUUID();
};

const check = async () => {
    if (!file.value) {
        fileError.value = 'Choose a CSV file.';

        return;
    }

    checking.value = true;
    fileError.value = undefined;
    checked.value = null;

    try {
        const body = new FormData();
        body.append(
            'expected_branch_id',
            String(props.import.expectedBranchId),
        );
        body.append('file', file.value);
        const response = await fetch('/inventory/opening-import/check', {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrf(),
            },
        });
        const payload = await response.json();

        if (!response.ok) {
            fileError.value =
                payload?.errors?.file?.[0] ??
                payload?.message ??
                'The file could not be checked.';

            return;
        }

        checked.value = payload;
    } catch {
        fileError.value = 'The file could not be checked. Try again.';
    } finally {
        checking.value = false;
    }
};

const ready = computed(
    () => checked.value !== null && checked.value.errors === 0,
);
const record = () => {
    if (!file.value || !checked.value || !ready.value) {
        return;
    }

    router.post(
        '/inventory/opening-import',
        {
            expected_branch_id: props.import.expectedBranchId,
            idempotency_key: importKey.value,
            file_hash: checked.value.hash,
            file: file.value,
        },
        {
            forceFormData: true,
            onStart: () => (recording.value = true),
            onFinish: () => (recording.value = false),
            onError: (errors) => {
                fileError.value =
                    errors.file ??
                    errors.idempotency_key ??
                    Object.values(errors)[0];
                checked.value = null;
            },
        },
    );
};
</script>

<template>
    <Head title="Import opening stock" />
    <main class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-7">
        <ActionLink
            href="/inventory/readiness"
            variant="ghost"
            class="-ml-3 min-h-11 self-start"
            ><ArrowLeft class="size-4" aria-hidden="true" /> Pilot
            readiness</ActionLink
        >
        <PageHeader
            title="Import opening stock"
            :description="`${props.import.branchName} · Record this branch's opening stock from one CSV. Check it first: every row is recorded, or none.`"
        />
        <p
            class="rounded-lg border bg-muted/40 p-3 text-sm"
            data-testid="opening-import-notice"
        >
            Synthetic data only. Not a Yezza data migration. Each opening still
            needs sign-off on Pilot readiness by someone other than you.
        </p>

        <section class="space-y-3 rounded-lg border bg-card p-4">
            <h2 class="font-semibold">The file</h2>
            <p class="text-sm">
                A CSV with these columns, in this order, up to
                {{ props.import.maxRows }} rows:
            </p>
            <ul class="grid gap-1 text-sm sm:grid-cols-2">
                <li><code>location_code</code>: a location at this branch</li>
                <li><code>sku_code</code>: an active SKU</li>
                <li><code>batch_number</code>: new or existing</li>
                <li><code>expiry_date</code>: YYYY-MM-DD, after today</li>
                <li><code>quantity</code>: in the SKU's stock unit</li>
                <li data-testid="opening-import-cost-column">
                    <code>unit_cost</code> (optional): RM per stock unit. Cost
                    policy to be confirmed by the accountant.
                </li>
            </ul>
            <a
                href="/inventory/opening-import/template"
                class="inline-flex min-h-11 items-center text-sm underline underline-offset-4 lg:min-h-0"
                >Download the template</a
            >
            <form
                class="flex flex-wrap items-end gap-2"
                @submit.prevent="check"
            >
                <label class="grid min-w-0 flex-1 gap-1 text-sm"
                    >CSV file
                    <input
                        type="file"
                        accept=".csv,text/csv"
                        class="min-h-11 rounded-md border bg-background px-3 py-2 text-sm"
                        aria-describedby="opening-import-error"
                        @change="choose"
                    />
                </label>
                <Button
                    type="submit"
                    variant="outline"
                    class="min-h-11 w-full sm:w-auto lg:min-h-9"
                    :disabled="checking || !file"
                    >Check file</Button
                >
            </form>
            <InputError id="opening-import-error" :message="fileError" />
        </section>

        <section
            v-if="checked"
            class="space-y-3"
            aria-labelledby="opening-import-result"
        >
            <h2 id="opening-import-result" class="font-semibold">
                {{ checked.rows.length }}
                {{ checked.rows.length === 1 ? 'row' : 'rows' }} checked
            </h2>
            <p
                v-if="checked.errors > 0"
                class="font-medium text-destructive"
                role="alert"
            >
                {{ checked.errors }}
                {{ checked.errors === 1 ? 'row has' : 'rows have' }} a problem.
                Fix the file and check it again. Nothing is recorded until every
                row is OK.
            </p>
            <div
                v-else
                class="flex flex-wrap items-center gap-3 rounded-lg border p-3"
            >
                <p class="min-w-0 flex-1 text-sm">
                    Every row is OK. Recording adds
                    {{ checked.rows.length }} opening
                    {{ checked.rows.length === 1 ? 'line' : 'lines' }} and
                    {{ checked.newBatches }} new
                    {{ checked.newBatches === 1 ? 'batch' : 'batches' }}.
                </p>
                <Button
                    type="button"
                    class="min-h-11 w-full sm:w-auto lg:min-h-9"
                    :disabled="recording || !ready"
                    data-testid="opening-import-record"
                    @click="record"
                    >Record {{ checked.rows.length }}
                    {{
                        checked.rows.length === 1 ? 'opening' : 'openings'
                    }}</Button
                >
            </div>
            <ul class="space-y-2" aria-label="Checked rows">
                <li
                    v-for="row in checked.rows"
                    :key="row.line"
                    class="rounded-lg border bg-card p-3 text-sm"
                    :class="row.problems.length ? 'border-destructive' : ''"
                >
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <span class="text-muted-foreground"
                            >Row {{ row.line }}</span
                        >
                        <span class="font-medium break-words"
                            >{{ row.sku }} · {{ row.location }}</span
                        >
                        <span class="break-words"
                            >Batch {{ row.batch_number }}
                            <span
                                v-if="row.new_batch && !row.problems.length"
                                class="ml-1 rounded border px-1.5 py-0.5 text-xs"
                                >New batch</span
                            ></span
                        >
                        <span
                            >Exp
                            {{
                                /^\d{4}-\d{2}-\d{2}$/.test(row.expiry_date)
                                    ? formatDate(row.expiry_date)
                                    : row.expiry_date
                            }}</span
                        >
                        <span class="tabular-nums"
                            >{{ trimQuantity(row.quantity) }}
                            {{ row.stock_unit ?? '' }}</span
                        >
                        <span
                            v-if="row.unit_cost_sen !== null"
                            class="text-muted-foreground tabular-nums"
                            >RM {{ formatRinggit(row.unit_cost_sen) }} a
                            unit</span
                        >
                        <span
                            v-if="!row.problems.length"
                            class="ml-auto font-medium"
                            >OK</span
                        >
                    </div>
                    <ul
                        v-if="row.problems.length"
                        class="mt-1 list-disc pl-5 text-destructive"
                    >
                        <li v-for="problem in row.problems" :key="problem">
                            {{ problem }}
                        </li>
                    </ul>
                </li>
            </ul>
        </section>
    </main>
</template>
