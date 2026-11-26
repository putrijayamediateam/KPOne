<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Info, TriangleAlert } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { ActionLink } from '@/components/ui/action-link';
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
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { StatusBadge } from '@/components/ui/status';

import { handCostPerUnit } from '@/lib/hand-cost';
import { ringgitToSen } from '@/lib/supplier-terms';

type Cost = {
    publicId: string;
    status: string;
    totalSen: number;
    total: string;
    perUnit: string;
    reason: string;
    note: string | null;
    reference: string | null;
    enteredBy: string;
    enteredAt: string;
    decidedBy: string | null;
    decidedAt: string | null;
    decisionReason: string | null;
    selfEntered: boolean;
    version: number;
    canCheck: boolean;
};
type Row = {
    publicId: string;
    skuCode: string;
    item: string;
    batch: string;
    branch: string;
    date: string;
    quantity: string;
    quantityScaled: number;
    unit: string;
    originalBasis: string;
    reversed: boolean;
    canEnter: boolean;
    acceptedId: string | null;
    history: Cost[];
};
const props = defineProps<{
    costs: {
        expectedBranchId: number;
        timezone: string;
        banner: string;
        filters: { q: string; state: string };
        rows: Row[];
        page: number;
        lastPage: number;
        total: number;
    };
}>();
const reasons = [
    ['supplier_invoice_found', 'Supplier invoice found'],
    ['opening_price_list', 'Opening price list'],
    ['estimate_was_wrong', 'Estimate was wrong'],
    ['other', 'Other'],
];
const reasonLabel = (code: string) =>
    reasons.find(([value]) => value === code)?.[1] ?? code;
const q = ref(props.costs.filters.q);
const state = ref(props.costs.filters.state);
const visit = (page = 1) =>
    router.get(
        '/inventory/hand-costs',
        { q: q.value, state: state.value, page },
        { preserveScroll: true },
    );
const selected = ref<Row | null>(null);
const amount = ref('');
const entry = useForm({
    expected_branch_id: props.costs.expectedBranchId,
    movement_public_id: '',
    lock_version: 0,
    idempotency_key: '',
    cost_total_sen: null as number | null,
    reason_code: '',
    note: '',
    reference: '',
});
const parsedSen = computed(() => {
    const sen = ringgitToSen(amount.value);

    return typeof sen === 'number' && sen <= 999999999999 ? sen : null;
});
const perUnit = computed(() =>
    parsedSen.value === null || !selected.value
        ? null
        : handCostPerUnit(parsedSen.value, selected.value.quantityScaled),
);
watch(
    [amount, () => entry.reason_code, () => entry.note, () => entry.reference],
    () => {
        entry.idempotency_key = crypto.randomUUID();
    },
);
let entryTrigger: HTMLElement | null = null;
let checkTrigger: HTMLElement | null = null;
const start = (row: Row) => {
    entryTrigger =
        document.activeElement instanceof HTMLElement
            ? document.activeElement
            : null;
    selected.value = row;
    amount.value = '';
    entry.reset();
    entry.clearErrors();
    entry.expected_branch_id = props.costs.expectedBranchId;
    entry.movement_public_id = row.publicId;
    entry.idempotency_key = crypto.randomUUID();
};
const focusError = () =>
    nextTick(() => {
        const invalid = document.querySelector<HTMLElement>(
            '[role="dialog"] [aria-invalid="true"]',
        );
        const alert = document.querySelector<HTMLElement>(
            '[role="dialog"] [role="alert"]',
        );
        (invalid ?? alert)?.focus();
    });
const send = () => {
    entry.cost_total_sen = parsedSen.value;
    entry.post('/inventory/hand-costs', {
        preserveScroll: true,
        onSuccess: () => {
            selected.value = null;
        },
        onFinish: () => {
            if (entry.hasErrors) {
                void focusError();
            }
        },
    });
};
const checking = ref<{
    row: Row;
    cost: Cost;
    status: 'accepted' | 'rejected';
} | null>(null);
const decision = useForm({
    expected_branch_id: props.costs.expectedBranchId,
    lock_version: 1,
    idempotency_key: '',
    status: '',
    reason: '',
});
watch(
    () => decision.reason,
    () => {
        decision.idempotency_key = crypto.randomUUID();
    },
);
const check = (row: Row, cost: Cost, status: 'accepted' | 'rejected') => {
    checkTrigger =
        document.activeElement instanceof HTMLElement
            ? document.activeElement
            : null;
    checking.value = { row, cost, status };
    decision.reset();
    decision.clearErrors();
    decision.expected_branch_id = props.costs.expectedBranchId;
    decision.lock_version = cost.version;
    decision.status = status;
    decision.idempotency_key = crypto.randomUUID();
};
const decide = () => {
    if (!checking.value) {
        return;
    }

    decision.post(
        `/inventory/hand-costs/${checking.value.cost.publicId}/decision`,
        {
            preserveScroll: true,
            onSuccess: () => {
                checking.value = null;
            },
            onFinish: () => {
                if (decision.hasErrors) {
                    void focusError();
                }
            },
        },
    );
};
watch(selected, (value, old) => {
    if (!value && old) {
        nextTick(() =>
            (entryTrigger?.isConnected
                ? entryTrigger
                : document.getElementById(`hand-entry-${old.publicId}`)
            )?.focus(),
        );
    }
});
watch(checking, (value, old) => {
    if (!value && old) {
        nextTick(() =>
            (checkTrigger?.isConnected
                ? checkTrigger
                : document.getElementById(`hand-entry-${old.row.publicId}`)
            )?.focus(),
        );
    }
});
watch(
    () => props.costs.expectedBranchId,
    () => {
        selected.value = null;
        checking.value = null;
    },
);
</script>

<template>
    <Head title="Costs to fill in" />
    <div class="space-y-6 p-4 lg:p-6">
        <ActionLink
            href="/inventory"
            variant="ghost"
            class="-ml-3 min-h-11 self-start"
            ><ArrowLeft
                class="size-4"
                aria-hidden="true"
            />Inventory</ActionLink
        >
        <PageHeader
            title="Costs to fill in"
            description="Enter a missing or estimated cost, then have a second person check it. All branches."
        />
        <p
            class="flex gap-2 rounded-lg border border-border bg-muted p-3 text-sm text-foreground"
        >
            <Info class="mt-0.5 size-4 shrink-0" aria-hidden="true" />{{
                costs.banner
            }}
        </p>
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="visit()">
            <label class="grid min-w-48 flex-1 gap-1 text-sm"
                >Item, SKU or batch<Input v-model="q" maxlength="100"
            /></label>
            <label class="grid gap-1 text-sm"
                >State<select
                    v-model="state"
                    class="h-11 rounded-lg border border-input bg-background px-3"
                >
                    <option value="all">All cost entries</option>
                    <option value="needs_cost">Needs a cost</option>
                    <option value="waiting">Waiting for a check</option>
                    <option value="accepted">Has an accepted cost</option>
                </select></label
            >
            <Button type="submit" variant="outline" class="min-h-11"
                >Show</Button
            >
        </form>
        <p
            v-if="costs.rows.length === 0"
            class="rounded-lg border border-border p-6 text-muted-foreground"
        >
            Nothing needs a cost.
        </p>
        <section
            v-for="row in costs.rows"
            :key="row.publicId"
            class="space-y-4 rounded-lg border border-border bg-card p-4"
            :aria-label="`${row.skuCode} · ${row.batch}`"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 space-y-1">
                    <h2
                        :id="`hand-entry-${row.publicId}`"
                        tabindex="-1"
                        class="font-medium break-words"
                    >
                        {{ row.item }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        <span class="font-mono">{{ row.skuCode }}</span> · Batch
                        {{ row.batch }}
                    </p>
                </div>
                <Button
                    v-if="row.canEnter"
                    variant="outline"
                    class="min-h-11"
                    @click="start(row)"
                    >{{
                        row.acceptedId ? 'Replace cost' : 'Enter cost'
                    }}</Button
                >
            </div>
            <dl class="grid gap-3 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-muted-foreground">Branch</dt>
                    <dd>{{ row.branch }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Entry date</dt>
                    <dd>{{ row.date }} ({{ costs.timezone }})</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Quantity</dt>
                    <dd class="tabular-nums">
                        {{ row.quantity }} {{ row.unit }}
                    </dd>
                </div>
            </dl>
            <StatusBadge
                status="cost"
                tone="neutral"
                :label="row.originalBasis"
                class="max-w-full whitespace-normal!"
            />
            <p v-if="row.reversed" class="text-sm text-muted-foreground">
                Receipt reversed. Its cost history is kept. A waiting cost can
                be rejected, but cannot be accepted.
            </p>
            <div
                v-for="cost in row.history.filter(
                    (h) =>
                        h.status === 'waiting' || h.publicId === row.acceptedId,
                )"
                :key="cost.publicId"
                class="space-y-2 rounded-lg bg-muted p-3 text-sm"
            >
                <StatusBadge
                    :status="cost.status"
                    tone="neutral"
                    class="max-w-full whitespace-normal!"
                    :label="
                        cost.status === 'waiting'
                            ? 'Entered by hand · waiting for a check'
                            : cost.totalSen === 0
                              ? 'Free (entered by hand)'
                              : 'Entered by hand · accepted'
                    "
                />
                <p class="tabular-nums">
                    Total paid RM {{ cost.total }} · RM {{ cost.perUnit }} per
                    {{ row.unit }}
                </p>
                <p>
                    {{ cost.enteredBy }} · {{ cost.enteredAt }} ({{
                        costs.timezone
                    }})
                </p>
                <p>
                    {{ reasonLabel(cost.reason)
                    }}<template v-if="cost.reference">
                        · Reference: {{ cost.reference }}</template
                    >
                </p>
                <p v-if="cost.note" class="break-words">{{ cost.note }}</p>
                <p
                    v-if="cost.status === 'accepted'"
                    class="text-muted-foreground"
                >
                    Checked by {{ cost.decidedBy }} · {{ cost.decidedAt }}. This
                    accepted cost continues to count while a replacement waits.
                </p>
                <p v-if="cost.status === 'waiting' && cost.selfEntered">
                    You entered this, so someone else checks it.
                </p>
                <div v-else-if="cost.canCheck" class="flex flex-wrap gap-2">
                    <Button
                        v-if="!row.reversed"
                        variant="outline"
                        class="min-h-11"
                        @click="check(row, cost, 'accepted')"
                        >Accept</Button
                    ><Button
                        variant="outline"
                        class="min-h-11"
                        @click="check(row, cost, 'rejected')"
                        >Reject</Button
                    >
                </div>
            </div>
            <details v-if="row.history.length" class="text-sm">
                <summary class="min-h-11 cursor-pointer py-3 font-medium">
                    Cost history ({{ row.history.length }})
                </summary>
                <ol class="grid gap-3">
                    <li
                        v-for="cost in row.history"
                        :key="cost.publicId"
                        class="space-y-1 border-l-2 border-border pl-3"
                    >
                        <p class="font-medium">
                            {{
                                cost.status === 'accepted'
                                    ? 'Accepted'
                                    : cost.status === 'rejected'
                                      ? 'Rejected'
                                      : 'Waiting for a check'
                            }}
                            · RM {{ cost.total
                            }}<span v-if="cost.publicId === row.acceptedId">
                                · Latest accepted</span
                            >
                        </p>
                        <p>
                            {{ cost.enteredBy }} · {{ cost.enteredAt }} ·
                            {{ reasonLabel(cost.reason) }}
                        </p>
                        <p v-if="cost.reference" class="break-words">
                            Reference: {{ cost.reference }}
                        </p>
                        <p v-if="cost.note" class="break-words">
                            {{ cost.note }}
                        </p>
                        <p v-if="cost.decidedAt">
                            {{ cost.decidedBy }} · {{ cost.decidedAt }}
                        </p>
                        <p v-if="cost.decisionReason" class="break-words">
                            {{ cost.decisionReason }}
                        </p>
                    </li>
                </ol>
            </details>
        </section>
        <CompactPagination
            :current-page="costs.page"
            :last-page="costs.lastPage"
            :total="costs.total"
            @change="visit"
        />
    </div>

    <Sheet
        :open="selected !== null"
        @update:open="
            (open) => {
                if (!open && !entry.processing) selected = null;
            }
        "
    >
        <SheetContent
            class="w-full! overflow-y-auto p-5 sm:max-w-lg! [&>button]:size-11"
            @escape-key-down="
                (event) => {
                    if (entry.processing) event.preventDefault();
                }
            "
            @interact-outside="
                (event) => {
                    if (entry.processing) event.preventDefault();
                }
            "
        >
            <SheetHeader
                ><SheetTitle>{{
                    selected?.acceptedId ? 'Replace cost' : 'Enter cost'
                }}</SheetTitle
                ><SheetDescription
                    >A second person must check this before it counts. Accepted
                    history is never edited.</SheetDescription
                ></SheetHeader
            >
            <form v-if="selected" class="grid gap-4" @submit.prevent="send">
                <p class="text-sm">
                    {{ selected.item }} · Batch {{ selected.batch }}<br />{{
                        selected.quantity
                    }}
                    {{ selected.unit }}
                </p>
                <p
                    v-if="entry.hasErrors"
                    role="alert"
                    tabindex="-1"
                    class="rounded-lg bg-destructive/10 p-3 text-sm text-destructive"
                >
                    {{ Object.values(entry.errors).join(' ') }}
                </p>
                <label class="grid gap-1 text-sm" for="hand-total"
                    >Total paid (RM)<span class="flex items-center gap-2"
                        ><span aria-hidden="true">RM</span
                        ><Input
                            id="hand-total"
                            v-model="amount"
                            inputmode="decimal"
                            autocomplete="off"
                            class="tabular-nums"
                            :disabled="entry.processing"
                            :aria-invalid="!!entry.errors.cost_total_sen"
                            aria-describedby="hand-unit"
                            required /></span
                ></label>
                <p
                    id="hand-unit"
                    class="text-sm tabular-nums"
                    aria-live="polite"
                >
                    {{
                        perUnit === null
                            ? 'Enter the total paid, with up to two decimal places.'
                            : `= RM ${perUnit} per ${selected.unit}`
                    }}<span v-if="parsedSen === 0">
                        · Free (entered by hand)</span
                    >
                </p>
                <fieldset :disabled="entry.processing" class="grid gap-1">
                    <legend class="text-sm font-medium">Reason</legend>
                    <label
                        v-for="[code, label] in reasons"
                        :key="code"
                        class="flex min-h-11 cursor-pointer items-center gap-2 text-sm"
                        ><input
                            v-model="entry.reason_code"
                            type="radio"
                            name="hand-reason"
                            :value="code"
                            required
                            :aria-invalid="!!entry.errors.reason_code"
                        />{{ label }}</label
                    >
                    <p
                        v-if="!entry.reason_code"
                        class="text-sm text-muted-foreground"
                    >
                        Pick a reason to continue.
                    </p>
                </fieldset>
                <label class="grid gap-1 text-sm"
                    >Reference (optional)<Input
                        v-model="entry.reference"
                        maxlength="200"
                        :disabled="entry.processing"
                        :aria-invalid="!!entry.errors.reference"
                /></label>
                <label class="grid gap-1 text-sm" for="hand-note"
                    >Note
                    {{
                        entry.reason_code === 'other'
                            ? '(required)'
                            : '(optional)'
                    }}<textarea
                        id="hand-note"
                        v-model="entry.note"
                        maxlength="200"
                        rows="3"
                        class="rounded-lg border border-input bg-background p-3"
                        :required="entry.reason_code === 'other'"
                        :disabled="entry.processing"
                        :aria-invalid="!!entry.errors.note"
                        aria-describedby="hand-note-hint"
                    />
                </label>
                <p id="hand-note-hint" class="text-sm text-muted-foreground">
                    {{ entry.note.length }} / 200 · Do not include patient
                    details.
                </p>
                <div class="flex flex-wrap justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        class="min-h-11"
                        :disabled="entry.processing"
                        @click="selected = null"
                        >Cancel</Button
                    ><Button
                        type="submit"
                        class="min-h-11"
                        :disabled="
                            entry.processing ||
                            parsedSen === null ||
                            !entry.reason_code
                        "
                        >{{
                            entry.processing ? 'Sending…' : 'Send for checking'
                        }}</Button
                    >
                </div>
            </form>
        </SheetContent>
    </Sheet>

    <Dialog
        :open="checking !== null"
        @update:open="
            (open) => {
                if (!open && !decision.processing) checking = null;
            }
        "
    >
        <DialogContent
            class="max-h-[90dvh] overflow-y-auto [&>button]:size-11"
            @escape-key-down="
                (event) => {
                    if (decision.processing) event.preventDefault();
                }
            "
            @interact-outside="
                (event) => {
                    if (decision.processing) event.preventDefault();
                }
            "
        >
            <DialogHeader
                ><DialogTitle>{{
                    checking?.status === 'accepted'
                        ? 'Accept this cost?'
                        : 'Reject this cost?'
                }}</DialogTitle
                ><DialogDescription>{{
                    checking?.status === 'accepted'
                        ? 'Check the item and the total before accepting.'
                        : 'Give a reason so another entry can be prepared.'
                }}</DialogDescription></DialogHeader
            >
            <form v-if="checking" class="grid gap-4" @submit.prevent="decide">
                <p
                    v-if="checking.status === 'accepted'"
                    class="flex gap-2 rounded-lg bg-warning-wash p-3 text-sm text-foreground"
                >
                    <TriangleAlert
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />This changes earlier months: the average is worked out
                    again from the start.
                </p>
                <p class="text-sm">
                    {{ checking.row.item }} · Batch {{ checking.row.batch
                    }}<br />{{ checking.row.quantity }} {{ checking.row.unit
                    }}<br /><span class="tabular-nums"
                        >Total paid RM {{ checking.cost.total }} · RM
                        {{ checking.cost.perUnit }} per
                        {{ checking.row.unit }}</span
                    >
                </p>
                <p
                    v-if="decision.hasErrors"
                    role="alert"
                    tabindex="-1"
                    class="rounded-lg bg-destructive/10 p-3 text-sm text-destructive"
                >
                    {{ Object.values(decision.errors).join(' ') }}
                </p>
                <label
                    v-if="checking.status === 'rejected'"
                    class="grid gap-1 text-sm"
                    >Reason for rejection<textarea
                        v-model="decision.reason"
                        required
                        maxlength="200"
                        rows="3"
                        class="rounded-lg border border-input bg-background p-3"
                        :disabled="decision.processing"
                        :aria-invalid="!!decision.errors.reason"
                    /><span class="text-muted-foreground"
                        >{{ decision.reason.length }} / 200 · Do not include
                        patient details.</span
                    ></label
                >
                <DialogFooter
                    ><Button
                        type="button"
                        variant="outline"
                        class="min-h-11"
                        :disabled="decision.processing"
                        @click="checking = null"
                        >Cancel</Button
                    ><Button
                        type="submit"
                        class="min-h-11"
                        :disabled="
                            decision.processing ||
                            (checking.status === 'rejected' &&
                                !decision.reason.trim())
                        "
                        >{{
                            decision.processing
                                ? 'Saving…'
                                : checking.status === 'accepted'
                                  ? 'Accept cost'
                                  : 'Reject cost'
                        }}</Button
                    ></DialogFooter
                >
            </form>
        </DialogContent>
    </Dialog>
</template>
