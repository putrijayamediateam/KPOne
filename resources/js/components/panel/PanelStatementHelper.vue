<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { LoadingState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';
import { myr } from '@/types/billing';

export type StatementRow = {
    line: number;
    reference: string;
    amountSen: number | null;
    result:
        | 'matched'
        | 'not_found'
        | 'ambiguous'
        | 'not_accepted'
        | 'rejected'
        | 'already_paid'
        | 'over_unpaid'
        | 'repeated'
        | 'unreadable_amount';
    claim: {
        publicId: string;
        claimNo: string;
        invoiceNumber: string;
        outstandingSen: number;
    } | null;
};
export type Statement = {
    publicId: string;
    uploadedDate: string;
    deleteDate: string;
    rowCount: number;
    headers: { index: number; label: string }[];
    mapping: Record<'amount' | 'claim' | 'invoice' | 'member', number | null>;
    rows: StatementRow[];
};

const props = defineProps<{
    branchId: number;
    panel: string;
    statement: Statement | null;
    maxRows: number;
    retentionDays: number;
    error?: string;
}>();
const emit = defineEmits<{
    use: [parts: { claim: string; sen: number }[]];
}>();

const RESULTS: Record<
    StatementRow['result'],
    { label: string; tone: 'success' | 'warning' | 'neutral' }
> = {
    matched: { label: 'Matched', tone: 'success' },
    not_found: { label: 'Not found', tone: 'neutral' },
    ambiguous: { label: 'More than one claim', tone: 'warning' },
    not_accepted: { label: 'Not accepted yet', tone: 'neutral' },
    rejected: { label: 'Rejected by the Panel', tone: 'neutral' },
    already_paid: { label: 'Already paid', tone: 'neutral' },
    over_unpaid: { label: 'Over unpaid amount', tone: 'warning' },
    repeated: { label: 'Claim used in an earlier row', tone: 'warning' },
    unreadable_amount: { label: 'Amount not readable', tone: 'warning' },
};
const COLUMNS = [
    { key: 'amount', label: 'Amount column *' },
    { key: 'claim', label: 'Claim number column' },
    { key: 'invoice', label: 'Invoice number column' },
    { key: 'member', label: 'Member reference column' },
] as const;

const open = ref(props.statement !== null);
const upload = useForm<{ statement: File | null }>({ statement: null });
const fileInput = ref<HTMLInputElement | null>(null);
const ticked = ref<Set<number>>(new Set());

// A new statement or a new column choice starts with every matched row ticked.
watch(
    () => props.statement,
    (statement) => {
        ticked.value = new Set(
            (statement?.rows ?? [])
                .filter((row) => row.result === 'matched')
                .map((row) => row.line),
        );

        if (statement) {
            open.value = true;
        }
    },
    { immediate: true },
);

const matchedCount = computed(
    () =>
        (props.statement?.rows ?? []).filter((row) => row.result === 'matched')
            .length,
);
const tickedRows = computed(() =>
    (props.statement?.rows ?? []).filter(
        (row) => row.result === 'matched' && ticked.value.has(row.line),
    ),
);
const tickedSen = computed(() =>
    tickedRows.value.reduce((sum, row) => sum + (row.amountSen ?? 0), 0),
);
const needsColumns = computed(
    () =>
        props.statement !== null &&
        (props.statement.mapping.amount === null ||
            (props.statement.mapping.claim === null &&
                props.statement.mapping.invoice === null &&
                props.statement.mapping.member === null)),
);

const toggle = (line: number) => {
    const next = new Set(ticked.value);

    if (next.has(line)) {
        next.delete(line);
    } else {
        next.add(line);
    }

    ticked.value = next;
};
const read = () => {
    if (!upload.statement) {
        return;
    }

    upload
        .transform((data) => ({
            ...data,
            expected_branch_id: props.branchId,
            panel: props.panel,
        }))
        .post('/panel-remittances/statements', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                upload.reset();

                if (fileInput.value) {
                    fileInput.value.value = '';
                }
            },
        });
};
const chooseColumn = (key: string, value: string) => {
    if (!props.statement) {
        return;
    }

    const query: Record<string, string> = {
        panel: props.panel,
        statement: props.statement.publicId,
    };

    for (const column of COLUMNS) {
        const current = props.statement.mapping[column.key];
        query[`${column.key}_col`] =
            column.key === key
                ? value
                : current === null
                  ? ''
                  : String(current);
    }

    router.get('/panel-remittances', query, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
};
const removeFile = () => {
    if (!props.statement) {
        return;
    }

    router.delete(`/panel-remittances/statements/${props.statement.publicId}`, {
        data: { expected_branch_id: props.branchId, panel: props.panel },
        preserveScroll: true,
    });
};
const useTicked = () =>
    emit(
        'use',
        tickedRows.value
            .filter((row) => row.claim && row.amountSen)
            .map((row) => ({
                claim: row.claim!.publicId,
                sen: row.amountSen as number,
            })),
    );
</script>

<template>
    <section
        class="space-y-3 rounded-lg border p-3"
        aria-labelledby="statement-heading"
    >
        <button
            id="statement-heading"
            type="button"
            class="flex w-full items-center justify-between gap-2 rounded-md text-left text-sm font-semibold focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :aria-expanded="open"
            aria-controls="statement-body"
            @click="open = !open"
        >
            Fill the split from a Panel statement (optional)
            <span aria-hidden="true">{{ open ? '−' : '+' }}</span>
        </button>
        <div v-show="open" id="statement-body" class="space-y-3">
            <p class="text-xs text-muted-foreground">
                Upload the Panel's CSV statement. KPOne suggests matching
                claims; nothing is recorded until you press Record payment.
            </p>
            <p class="text-xs text-muted-foreground">
                The file is stored privately, encrypted, and deleted after
                {{ retentionDays }} days.
            </p>

            <form
                v-if="!statement"
                class="flex flex-col gap-2 sm:flex-row sm:items-end"
                @submit.prevent="read"
            >
                <label
                    class="grid min-w-0 flex-1 gap-1 text-xs"
                    for="statement-file"
                >
                    Statement file (CSV, up to 1 MB, {{ maxRows }} rows)
                    <input
                        id="statement-file"
                        ref="fileInput"
                        type="file"
                        accept=".csv,text/csv,text/plain"
                        class="block w-full min-w-0 text-sm file:mr-3 file:rounded-md file:border file:bg-background file:px-3 file:py-1.5 file:text-sm file:text-foreground"
                        :aria-invalid="error ? 'true' : undefined"
                        :aria-describedby="
                            error ? 'statement-error' : undefined
                        "
                        @change="
                            upload.statement =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] ?? null
                        "
                    />
                </label>
                <Button
                    type="submit"
                    variant="outline"
                    size="sm"
                    class="max-sm:min-h-11 max-sm:w-full"
                    :disabled="!upload.statement || upload.processing"
                >
                    {{ upload.processing ? 'Reading…' : 'Read statement' }}
                </Button>
            </form>
            <InputError id="statement-error" :message="error" />
            <LoadingState v-if="upload.processing" label="Reading statement…" />

            <template v-if="statement">
                <p class="text-xs text-muted-foreground">
                    {{ statement.rowCount }}
                    {{ statement.rowCount === 1 ? 'row' : 'rows' }} read on
                    {{ statement.uploadedDate }} · deleted on
                    {{ statement.deleteDate }}
                </p>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <label
                        v-for="column in COLUMNS"
                        :key="column.key"
                        class="grid min-w-0 gap-1 text-xs"
                        :for="`statement-col-${column.key}`"
                    >
                        {{ column.label }}
                        <select
                            :id="`statement-col-${column.key}`"
                            :value="
                                statement.mapping[column.key] === null
                                    ? ''
                                    : String(statement.mapping[column.key])
                            "
                            class="h-9 w-full min-w-0 rounded-md border bg-background px-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            @change="
                                chooseColumn(
                                    column.key,
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="">Not used</option>
                            <option
                                v-for="header in statement.headers"
                                :key="header.index"
                                :value="String(header.index)"
                            >
                                {{ header.label }}
                            </option>
                        </select>
                    </label>
                </div>
                <p class="text-xs text-muted-foreground">
                    Pre-selected from the header names. Check them.
                </p>

                <p
                    v-if="needsColumns"
                    class="text-sm text-destructive"
                    role="alert"
                >
                    {{
                        statement.mapping.amount === null
                            ? 'Choose the column that holds the amount.'
                            : 'Choose at least one column to match claims by.'
                    }}
                </p>
                <template v-if="!needsColumns && statement.rows.length > 0">
                    <p class="text-xs" role="status">
                        {{ matchedCount }} matched,
                        {{ statement.rows.length - matchedCount }} cannot be
                        used
                    </p>
                    <p
                        v-if="matchedCount === 0"
                        class="rounded-md bg-muted p-2 text-sm"
                        data-testid="statement-no-match"
                    >
                        No rows match an open claim of this Panel at this
                        branch. Check the columns, or enter the split by hand.
                    </p>
                    <!-- Phones and tablets: one card per row (PNL-13: the seven columns do not fit at 768 px). -->
                    <ul
                        class="divide-y rounded-lg border lg:hidden"
                        aria-label="Statement rows"
                    >
                        <li
                            v-for="row in statement.rows"
                            :key="row.line"
                            class="flex items-start gap-3 p-3 text-sm"
                        >
                            <!-- A 44 px tap target around the tick box. -->
                            <label
                                class="-m-3 flex min-h-11 min-w-11 shrink-0 items-center justify-center p-3"
                            >
                                <input
                                    type="checkbox"
                                    class="size-5"
                                    :checked="ticked.has(row.line)"
                                    :disabled="row.result !== 'matched'"
                                    :aria-label="`Use row ${row.line}${row.claim ? `, claim ${row.claim.claimNo}` : ''}`"
                                    :aria-describedby="`statement-result-m-${row.line}`"
                                    @change="toggle(row.line)"
                                />
                            </label>
                            <div class="min-w-0 flex-1 space-y-1">
                                <p class="tabular-nums">
                                    Row {{ row.line }} ·
                                    {{
                                        row.amountSen === null
                                            ? 'No amount'
                                            : myr(row.amountSen)
                                    }}
                                </p>
                                <p
                                    class="text-xs wrap-anywhere text-muted-foreground"
                                >
                                    {{ row.reference || 'No reference' }}
                                    <template v-if="row.claim">
                                        → Claim {{ row.claim.claimNo }} ·
                                        <span class="font-mono">{{
                                            row.claim.invoiceNumber
                                        }}</span>
                                    </template>
                                </p>
                                <StatusBadge
                                    :id="`statement-result-m-${row.line}`"
                                    :status="row.result"
                                    :label="RESULTS[row.result].label"
                                    :tone="RESULTS[row.result].tone"
                                />
                            </div>
                        </li>
                    </ul>
                    <!-- PNL-13 (DD-28): wide screens use the shared table. -->
                    <OperationalTable
                        class="hidden lg:block"
                        label="Statement rows"
                        :columns="7"
                        min-width="640px"
                        data-testid="statement-table"
                    >
                        <template #head>
                            <tr>
                                <th scope="col" class="w-12">
                                    <span class="sr-only">Use</span>
                                </th>
                                <th scope="col">Row</th>
                                <th scope="col">Statement reference</th>
                                <th scope="col" class="text-right">Amount</th>
                                <th scope="col">Suggested claim</th>
                                <th scope="col" class="text-right">Unpaid</th>
                                <th scope="col">Result</th>
                            </tr>
                        </template>
                        <template #body>
                            <tr v-for="row in statement.rows" :key="row.line">
                                <td>
                                    <!-- A 44 px tap target around the tick box. -->
                                    <label
                                        class="-m-2 inline-flex min-h-11 min-w-11 items-center justify-center"
                                    >
                                        <input
                                            type="checkbox"
                                            class="size-4"
                                            :checked="ticked.has(row.line)"
                                            :disabled="row.result !== 'matched'"
                                            :aria-label="`Use row ${row.line}${row.claim ? `, claim ${row.claim.claimNo}` : ''}`"
                                            :aria-describedby="`statement-result-${row.line}`"
                                            @change="toggle(row.line)"
                                        />
                                    </label>
                                </td>
                                <td class="tabular-nums">{{ row.line }}</td>
                                <td class="max-w-48 wrap-anywhere">
                                    {{ row.reference || '—' }}
                                </td>
                                <td
                                    class="text-right whitespace-nowrap tabular-nums"
                                >
                                    {{
                                        row.amountSen === null
                                            ? '—'
                                            : myr(row.amountSen)
                                    }}
                                </td>
                                <td class="text-xs">
                                    <template v-if="row.claim">
                                        Claim {{ row.claim.claimNo }} ·
                                        <span class="font-mono">{{
                                            row.claim.invoiceNumber
                                        }}</span>
                                    </template>
                                    <template v-else>—</template>
                                </td>
                                <td
                                    class="text-right whitespace-nowrap tabular-nums"
                                >
                                    {{
                                        row.claim
                                            ? myr(row.claim.outstandingSen)
                                            : '—'
                                    }}
                                </td>
                                <td>
                                    <StatusBadge
                                        :id="`statement-result-${row.line}`"
                                        :status="row.result"
                                        :label="RESULTS[row.result].label"
                                        :tone="RESULTS[row.result].tone"
                                    />
                                </td>
                            </tr>
                        </template>
                    </OperationalTable>
                    <p
                        class="text-sm tabular-nums"
                        data-testid="statement-summary"
                    >
                        {{ tickedRows.length }} of
                        {{ statement.rows.length }} rows ticked ·
                        {{ myr(tickedSen) }}
                    </p>
                </template>
                <div class="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        size="sm"
                        class="max-sm:min-h-11"
                        :disabled="tickedRows.length === 0"
                        @click="useTicked"
                    >
                        Use these in the split
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="max-sm:min-h-11"
                        @click="removeFile"
                    >
                        Remove file
                    </Button>
                </div>
            </template>
        </div>
    </section>
</template>
