<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
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
        | 'near_match'
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
    /** PNL-18: only on a near match, the statement's own invoice number and why it counts as near. */
    statementInvoice?: string;
    nearReason?: 'format' | 'lookalike';
};
export type Statement = {
    publicId: string;
    uploadedDate: string;
    deleteDate: string;
    rowCount: number;
    headers: { index: number; label: string }[];
    mapping: Record<'amount' | 'claim' | 'invoice' | 'member', number | null>;
    rows: StatementRow[];
    /** PNL-20: near matches looked at only the oldest open claims of this Panel, because it has more of them. */
    nearCut?: boolean;
    nearLimit?: number;
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
    near_match: { label: 'Near match: check', tone: 'warning' },
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
// PNL-18: a near match can be ticked, but only by the officer; it never starts ticked.
const canTick = (row: StatementRow) =>
    row.result === 'matched' || row.result === 'near_match';
const FORMAT_REASON =
    'Same apart from spaces, dashes, capitals or leading zeros.';
const LOOKALIKE_DIGITS: Record<string, string> = {
    O: '0',
    I: '1',
    L: '1',
    S: '5',
    B: '8',
    Z: '2',
};
// PNL-18: in a look-alike near match, the letters read as digits (inside a run that holds a digit, so the "I" of a
// "KPI" prefix stays plain) are marked, because many fonts draw O and 0 alike.
const lookalikeParts = (text: string) =>
    text
        .split(/([0-9OoIiLlSsBbZz]*[0-9])/)
        .flatMap((part, index) =>
            index % 2 === 1
                ? part
                      .split(/([OoIiLlSsBbZz])/)
                      .map((piece, i) => ({ text: piece, mark: i % 2 === 1 }))
                : [{ text: part, mark: false }],
        )
        .filter((part) => part.text !== '');
const nearParts = (row: StatementRow) =>
    row.nearReason === 'lookalike'
        ? lookalikeParts(row.statementInvoice ?? '')
        : [{ text: row.statementInvoice ?? '', mark: false }];
// Daniel's note: say only the letters this row has, e.g. "The statement has the letter O where KPOne has 0."
const nearReasonText = (row: StatementRow) => {
    if (row.nearReason !== 'lookalike') {
        return FORMAT_REASON;
    }

    const letters = [
        ...new Set(
            nearParts(row)
                .filter((part) => part.mark)
                .map((part) => part.text.toUpperCase()),
        ),
    ];
    const list = (items: string[]) =>
        items.length < 2
            ? items.join('')
            : `${items.slice(0, -1).join(', ')} and ${items[items.length - 1]}`;

    return `The statement has the letter${letters.length === 1 ? '' : 's'} ${list(letters)} where KPOne has ${list(letters.map((letter) => LOOKALIKE_DIGITS[letter]))}.`;
};
const tickLabel = (row: StatementRow) =>
    `Use row ${row.line}${row.claim ? `, claim ${row.claim.claimNo}` : ''}${
        row.result === 'near_match' && row.claim
            ? `, near match: statement ${row.statementInvoice ?? row.reference}, KPOne ${row.claim.invoiceNumber}`
            : ''
    }`;

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
const notFoundCount = computed(
    () =>
        props.statement?.rows.filter((row) => row.result === 'not_found')
            .length ?? 0,
);
const nearCount = computed(
    () =>
        (props.statement?.rows ?? []).filter(
            (row) => row.result === 'near_match',
        ).length,
);
const tickedRows = computed(() =>
    (props.statement?.rows ?? []).filter(
        (row) => canTick(row) && ticked.value.has(row.line),
    ),
);
const tickedNear = computed(
    () => tickedRows.value.filter((row) => row.result === 'near_match').length,
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
        class="space-y-3 border-t pt-4"
        aria-labelledby="statement-heading"
    >
        <button
            id="statement-heading"
            type="button"
            class="flex min-h-11 w-full items-center justify-between gap-2 rounded-lg text-left text-sm font-semibold focus-visible:ring-[3px] focus-visible:ring-ring/40 focus-visible:outline-none"
            :aria-expanded="open"
            aria-controls="statement-body"
            @click="open = !open"
        >
            Fill the split from a Panel statement (optional)
            <ChevronDown
                class="size-4 shrink-0 transition-transform"
                :class="open ? 'rotate-180' : ''"
                aria-hidden="true"
            />
        </button>
        <div v-show="open" id="statement-body" class="space-y-3">
            <p class="text-sm text-muted-foreground">
                Upload the Panel's CSV statement. KPOne suggests matching
                claims; nothing is recorded until you press Record payment.
            </p>
            <p class="text-sm text-muted-foreground">
                The file is stored privately, encrypted, and deleted after
                {{ retentionDays }} days.
            </p>

            <form
                v-if="!statement"
                class="flex flex-col gap-2 sm:flex-row sm:items-end"
                @submit.prevent="read"
            >
                <label
                    class="grid min-w-0 flex-1 gap-1.5 text-[13px] font-medium"
                    for="statement-file"
                >
                    Statement file (CSV, up to 1 MB, {{ maxRows }} rows)
                    <input
                        id="statement-file"
                        ref="fileInput"
                        type="file"
                        accept=".csv,text/csv,text/plain"
                        class="block w-full min-w-0 text-sm font-normal file:mr-3 file:h-9 file:rounded-lg file:border file:border-input file:bg-card file:px-3 file:text-sm file:font-medium file:text-foreground max-lg:file:h-11"
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
                    class="max-lg:min-h-11 max-sm:w-full"
                    :disabled="!upload.statement || upload.processing"
                >
                    {{ upload.processing ? 'Reading…' : 'Read statement' }}
                </Button>
            </form>
            <InputError id="statement-error" :message="error" />
            <LoadingState v-if="upload.processing" label="Reading statement…" />

            <template v-if="statement">
                <p class="text-sm text-muted-foreground">
                    {{ statement.rowCount }}
                    {{ statement.rowCount === 1 ? 'row' : 'rows' }} read on
                    {{ statement.uploadedDate }} · deleted on
                    {{ statement.deleteDate }}
                </p>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <label
                        v-for="column in COLUMNS"
                        :key="column.key"
                        class="grid min-w-0 gap-1.5 text-[13px] font-medium"
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
                            class="h-9 w-full min-w-0 rounded-lg border border-input bg-card px-3 text-base font-normal outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/40 max-lg:h-11 lg:text-sm dark:bg-field-fill/30"
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
                <p class="text-sm text-muted-foreground">
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
                    <p class="text-sm" role="status">
                        {{ matchedCount }} matched,
                        <template v-if="nearCount > 0">
                            {{ nearCount }}
                            {{
                                nearCount === 1
                                    ? 'near match to check'
                                    : 'near matches to check'
                            }},
                        </template>
                        {{ statement.rows.length - matchedCount - nearCount }}
                        cannot be used
                    </p>
                    <p
                        v-if="statement.nearCut && notFoundCount > 0"
                        class="mb-1 text-sm text-muted-foreground"
                        role="note"
                        data-testid="statement-near-cut"
                    >
                        Near matches were checked against this Panel's
                        {{ statement.nearLimit ?? 500 }} oldest open claims at
                        this branch only. A row that says Not found may still
                        belong to a newer claim, so check those by hand.
                    </p>
                    <p
                        v-if="matchedCount === 0 && nearCount === 0"
                        class="rounded-lg bg-muted/50 p-3 text-sm"
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
                                    class="size-5 accent-primary"
                                    :checked="ticked.has(row.line)"
                                    :disabled="!canTick(row)"
                                    :aria-label="tickLabel(row)"
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
                                <div
                                    v-if="
                                        row.result === 'near_match' && row.claim
                                    "
                                    class="space-y-0.5 text-xs wrap-anywhere text-muted-foreground"
                                    data-testid="statement-near-card"
                                >
                                    <p>
                                        Statement:
                                        <span
                                            class="font-mono text-foreground slashed-zero"
                                            ><template
                                                v-for="(part, i) in nearParts(
                                                    row,
                                                )"
                                                :key="i"
                                                ><mark
                                                    v-if="part.mark"
                                                    class="bg-transparent font-semibold text-inherit underline"
                                                    >{{ part.text }}</mark
                                                ><template v-else>{{
                                                    part.text
                                                }}</template></template
                                            ></span
                                        >
                                    </p>
                                    <p>
                                        KPOne:
                                        <span
                                            class="font-mono text-foreground slashed-zero"
                                            >{{ row.claim.invoiceNumber }}</span
                                        >
                                        → Claim {{ row.claim.claimNo }}
                                    </p>
                                    <p v-if="row.nearReason">
                                        {{ nearReasonText(row) }}
                                    </p>
                                </div>
                                <p
                                    v-else
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
                                <th scope="col" class="w-14">
                                    <span class="sr-only">Use in split</span>
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
                                            class="size-4 accent-primary"
                                            :checked="ticked.has(row.line)"
                                            :disabled="!canTick(row)"
                                            :aria-label="tickLabel(row)"
                                            :aria-describedby="`statement-result-${row.line}`"
                                            @change="toggle(row.line)"
                                        />
                                    </label>
                                </td>
                                <td class="tabular-nums">{{ row.line }}</td>
                                <td class="max-w-48 wrap-anywhere">
                                    <span
                                        v-if="row.result === 'near_match'"
                                        class="font-mono slashed-zero"
                                        data-testid="statement-near-value"
                                        ><template
                                            v-for="(part, i) in nearParts(row)"
                                            :key="i"
                                            ><mark
                                                v-if="part.mark"
                                                class="bg-transparent font-semibold text-inherit underline"
                                                >{{ part.text }}</mark
                                            ><template v-else>{{
                                                part.text
                                            }}</template></template
                                        ></span
                                    >
                                    <template v-else>{{
                                        row.reference || '—'
                                    }}</template>
                                    <template
                                        v-if="
                                            row.result === 'near_match' &&
                                            row.claim
                                        "
                                    >
                                        <span
                                            class="block font-mono text-xs text-muted-foreground slashed-zero"
                                            data-testid="statement-near-table"
                                            >KPOne:
                                            {{ row.claim.invoiceNumber }}</span
                                        >
                                        <span
                                            v-if="row.nearReason"
                                            class="block text-xs text-muted-foreground"
                                            >{{ nearReasonText(row) }}</span
                                        >
                                    </template>
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
                        {{ statement.rows.length }} rows ticked<template
                            v-if="tickedNear > 0"
                            >, {{ tickedNear }} of them
                            {{
                                tickedNear === 1
                                    ? 'a near match'
                                    : 'near matches'
                            }}</template
                        >
                        ·
                        {{ myr(tickedSen) }}
                    </p>
                </template>
                <div
                    class="flex flex-wrap gap-2 max-sm:grid max-sm:grid-cols-1"
                >
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        class="max-lg:min-h-11"
                        :disabled="tickedRows.length === 0"
                        @click="useTicked"
                    >
                        Use these in the split
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="max-lg:min-h-11"
                        @click="removeFile"
                    >
                        Remove file
                    </Button>
                </div>
            </template>
        </div>
    </section>
</template>
