<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import FileChooseButton from '@/components/catalogue/FileChooseButton.vue';
import InputError from '@/components/InputError.vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { formatDate } from '@/lib/presentation';
import { formatRinggit, trimQuantity } from '@/lib/supplier-terms';

/**
 * Version 2 Import opening stock (inventory V2 page 9b). Everything from `type CheckedRow` to `record` is a verbatim
 * copy of Classic (a test compares them). Only the layout is new: the file steps, a checked-rows table with a chip per
 * row, and the V2 sheet in place of the Classic confirm dialog. The sheet calls Classic's `record` unchanged.
 */
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
        recorded: number;
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
// Daniel: ask before recording, because recorded openings cannot be edited.
const confirming = ref(false);
const recordCount = computed(() => checked.value?.rows.length ?? 0);
const askToRecord = () => {
    if (ready.value) {
        confirming.value = true;
    }
};
const record = () => {
    if (!file.value || !checked.value || !ready.value || recording.value) {
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
            onSuccess: () => {
                // The page shows the success panel; a new import starts from a new file and a new key.
                checked.value = null;
                file.value = null;
                importKey.value = crypto.randomUUID();
            },
            onFinish: () => {
                recording.value = false;
                confirming.value = false;
            },
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

// ---- V2 only below this line ----
usePageCrumbs(() => [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Pilot readiness', href: '/inventory/readiness' },
    { title: 'Import opening stock' },
]);
/** A code never breaks across lines ("SYN-SKU-1" stays whole). */
const unbroken = (code: string) => code.replaceAll('-', '\u2011');
const expiryText = (value: string) =>
    /^\d{4}-\d{2}-\d{2}$/.test(value) ? formatDate(value) : value;
const problemRows = computed(
    () => checked.value?.rows.filter((row) => row.problems.length > 0) ?? [],
);
</script>

<template>
    <Head title="Import opening stock" />
    <PageFrame>
        <Link
            href="/inventory/readiness"
            class="inline-flex h-11 w-fit items-center rounded-[10px] text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:hidden"
            data-action="back"
        >
            ← Pilot readiness
        </Link>
        <V2WorkLayout
            title="Import opening stock"
            :description="`${props.import.branchName} · Record this branch's opening stock from one CSV. Check it first: every row is recorded, or none.`"
        >
            <template #main>
                <p
                    class="rounded-[14px] border border-border bg-muted/40 px-4 py-3 text-sm"
                    data-testid="opening-import-notice"
                >
                    Synthetic data only. Not a Yezza data migration. Each
                    opening still needs sign-off on Pilot readiness by someone
                    other than you.
                </p>

                <section
                    v-if="props.import.recorded > 0 && !checked"
                    class="flex flex-col gap-3 rounded-[14px] border border-success bg-success/10 p-4"
                    role="status"
                    data-testid="opening-import-success"
                >
                    <h2 class="text-base font-semibold">
                        {{ props.import.recorded }} opening
                        {{ props.import.recorded === 1 ? 'line' : 'lines' }}
                        recorded
                    </h2>
                    <p class="text-sm">
                        Each one now needs sign-off by someone other than you.
                    </p>
                    <Link
                        href="/inventory/readiness"
                        class="inline-flex h-11 w-full items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:w-fit lg:h-9"
                        >Go to Pilot readiness for sign-off</Link
                    >
                </section>

                <section
                    class="flex min-w-0 flex-col gap-3"
                    aria-labelledby="v2-opening-file"
                >
                    <h2
                        id="v2-opening-file"
                        class="v2-section-title text-lg font-semibold"
                    >
                        1. The file
                    </h2>
                    <p class="text-sm">
                        A CSV with these columns, in this order, up to
                        {{ props.import.maxRows }} rows:
                    </p>
                    <ol
                        class="grid list-decimal gap-1 pl-5 text-sm sm:grid-cols-2"
                    >
                        <li>
                            <code>location_code</code>: a location at this
                            branch
                        </li>
                        <li><code>sku_code</code>: an active SKU</li>
                        <li><code>batch_number</code>: new or existing</li>
                        <li>
                            <code>expiry_date</code>: YYYY-MM-DD, after today
                        </li>
                        <li><code>quantity</code>: in the SKU's stock unit</li>
                        <li data-testid="opening-import-cost-column">
                            <code>unit_cost</code> (optional): RM per stock
                            unit. Cost policy to be confirmed by the accountant.
                        </li>
                    </ol>
                    <a
                        href="/inventory/opening-import/template"
                        class="inline-flex h-11 w-fit items-center text-sm font-medium text-primary underline underline-offset-4 lg:h-9"
                        >Download the template</a
                    >
                </section>

                <section
                    class="flex min-w-0 flex-col gap-3"
                    aria-labelledby="v2-opening-check"
                >
                    <h2
                        id="v2-opening-check"
                        class="v2-section-title text-lg font-semibold"
                    >
                        2. Check the file
                    </h2>
                    <form
                        class="flex min-w-0 flex-col items-start gap-3"
                        @submit.prevent="check"
                    >
                        <div
                            class="flex w-full min-w-0 flex-col gap-1.5 text-sm font-medium"
                        >
                            <span id="opening-import-file-label">CSV file</span>
                            <FileChooseButton
                                id="opening-import-file"
                                accept=".csv,text/csv"
                                :label="
                                    file
                                        ? 'Choose another file'
                                        : 'Choose CSV file'
                                "
                                :status="file ? file.name : 'No file chosen'"
                                status-id="opening-import-file-state"
                                aria-describedby="opening-import-error"
                                @change="choose"
                            />
                        </div>
                        <button
                            type="submit"
                            class="inline-flex h-11 w-full items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto lg:h-9"
                            :disabled="checking || !file"
                            :aria-busy="checking"
                        >
                            {{ checking ? 'Checking…' : 'Check file' }}
                        </button>
                    </form>
                    <p
                        v-if="!file && !checked"
                        class="text-sm text-muted-foreground"
                    >
                        Choose a file to check it. Nothing is recorded by a
                        check.
                    </p>
                    <InputError
                        id="opening-import-error"
                        :message="fileError"
                    />
                </section>

                <section
                    v-if="checked"
                    class="flex min-w-0 flex-col gap-3"
                    aria-labelledby="opening-import-result"
                >
                    <h2
                        id="opening-import-result"
                        class="v2-section-title text-lg font-semibold"
                    >
                        3. {{ checked.rows.length }}
                        {{ checked.rows.length === 1 ? 'row' : 'rows' }} checked
                    </h2>
                    <p
                        v-if="checked.errors > 0"
                        class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm font-medium text-destructive"
                        role="alert"
                    >
                        {{ checked.errors }}
                        {{ checked.errors === 1 ? 'row has' : 'rows have' }} a
                        problem. Fix the file and check it again. Nothing is
                        recorded until every row is OK.
                    </p>
                    <div
                        v-else
                        class="flex min-w-0 flex-wrap items-center gap-3 rounded-[14px] border border-success bg-success/10 p-4"
                    >
                        <p class="min-w-0 flex-1 basis-64 text-sm">
                            Every row is OK. Recording adds
                            {{ checked.rows.length }} opening
                            {{ checked.rows.length === 1 ? 'line' : 'lines' }}
                            and {{ checked.newBatches }} new
                            {{
                                checked.newBatches === 1 ? 'batch' : 'batches'
                            }}.
                        </p>
                        <button
                            type="button"
                            class="inline-flex h-11 w-full items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto lg:h-9"
                            :disabled="recording || !ready"
                            data-testid="opening-import-record"
                            @click="askToRecord"
                        >
                            Record {{ checked.rows.length }}
                            {{
                                checked.rows.length === 1
                                    ? 'opening'
                                    : 'openings'
                            }}
                        </button>
                    </div>
                    <p
                        v-if="problemRows.length"
                        class="text-sm text-muted-foreground"
                    >
                        Rows with a problem are marked "Problem" and list what
                        to fix.
                    </p>
                    <V2Table
                        label="Checked rows"
                        :columns="6"
                        cards="xl"
                        min-width="900px"
                    >
                        <template #head>
                            <tr>
                                <th scope="col">Row</th>
                                <th scope="col">SKU and location</th>
                                <th scope="col">Batch</th>
                                <th scope="col">Expiry</th>
                                <th scope="col" class="text-right">Quantity</th>
                                <th scope="col">Result</th>
                            </tr>
                        </template>
                        <template #body>
                            <tr
                                v-for="row in checked.rows"
                                :key="row.line"
                                :data-problem="
                                    row.problems.length ? '' : undefined
                                "
                            >
                                <td
                                    data-label="Row"
                                    class="text-sm text-muted-foreground tabular-nums"
                                >
                                    {{ row.line }}
                                </td>
                                <td
                                    data-stack
                                    data-label="SKU and location"
                                    data-primary
                                >
                                    <div class="min-w-0">
                                        <span
                                            class="block font-mono text-sm [overflow-wrap:anywhere]"
                                            >{{ unbroken(row.sku) }}</span
                                        >
                                        <span
                                            class="block text-xs [overflow-wrap:anywhere] text-muted-foreground"
                                            >{{ unbroken(row.location) }}</span
                                        >
                                    </div>
                                </td>
                                <td data-stack data-label="Batch">
                                    <div class="min-w-0">
                                        <span
                                            class="block font-mono text-sm [overflow-wrap:anywhere]"
                                            >{{
                                                unbroken(row.batch_number)
                                            }}</span
                                        >
                                        <span
                                            v-if="
                                                row.new_batch &&
                                                !row.problems.length
                                            "
                                            class="block text-xs text-muted-foreground"
                                            >New batch</span
                                        >
                                    </div>
                                </td>
                                <td
                                    data-label="Expiry"
                                    class="text-sm whitespace-nowrap tabular-nums"
                                >
                                    {{ expiryText(row.expiry_date) }}
                                </td>
                                <td
                                    data-stack
                                    data-label="Quantity"
                                    class="text-right"
                                >
                                    <div class="min-w-0">
                                        <span
                                            class="block text-sm whitespace-nowrap tabular-nums"
                                            >{{ trimQuantity(row.quantity) }}
                                            {{ row.stock_unit ?? '' }}</span
                                        >
                                        <span
                                            v-if="row.unit_cost_sen !== null"
                                            class="block text-xs whitespace-nowrap text-muted-foreground tabular-nums"
                                            >RM
                                            {{
                                                formatRinggit(row.unit_cost_sen)
                                            }}
                                            a unit</span
                                        >
                                    </div>
                                </td>
                                <td data-stack data-label="Result">
                                    <div class="flex min-w-0 flex-col gap-1">
                                        <V2Chip
                                            class="w-fit whitespace-nowrap"
                                            :tone="
                                                row.problems.length
                                                    ? 'danger'
                                                    : 'success'
                                            "
                                            :label="
                                                row.problems.length
                                                    ? 'Problem'
                                                    : 'OK'
                                            "
                                        />
                                        <ul
                                            v-if="row.problems.length"
                                            class="list-disc pl-5 text-sm text-destructive"
                                        >
                                            <li
                                                v-for="problem in row.problems"
                                                :key="problem"
                                            >
                                                {{ problem }}
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </V2Table>
                </section>
            </template>
        </V2WorkLayout>

        <V2Dialog
            :open="confirming"
            :title="`Record ${recordCount} opening ${recordCount === 1 ? 'line' : 'lines'} at ${props.import.branchName}?`"
            :confirm-label="`Record ${recordCount === 1 ? 'opening' : 'openings'}`"
            :busy="recording"
            @update:open="(value) => (confirming = value)"
            @confirm="record"
        >
            <p class="text-sm">
                They cannot be edited afterwards. Each still needs sign-off by
                someone other than you.
            </p>
            <template #footer-note>
                <p class="text-sm" role="status">
                    Adds {{ recordCount }} opening
                    {{ recordCount === 1 ? 'line' : 'lines' }} to stock at
                    {{ props.import.branchName }}.
                </p>
            </template>
        </V2Dialog>
    </PageFrame>
</template>

<style scoped>
/* Phone and tablet cards: a cell with several lines puts its label on its own line and uses the full width. */
@media (max-width: 1279.98px) {
    :deep(.v2-cards-xl td[data-stack]) {
        flex-direction: column;
        align-items: stretch;
        gap: 0.25rem;
        text-align: left;
    }
}
</style>
