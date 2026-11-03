<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
type Target = {
    id: number;
    quantity: string;
    lock_version: number;
    sku_code: string;
    stock_unit: string;
    batch_number: string;
    expiry_date: string;
    location: string;
};
type Action = {
    id: number;
    action: string;
    state: string;
    actor_user_id: number;
    quantity: string | null;
    lock_version: number;
    movement_id: number | null;
    created_at: string;
};
type Case = {
    id: number;
    public_id: string;
    balance_id: number;
    expected_quantity: string;
    expected_balance_version: number;
    current_balance_version: number;
    proposer_user_id: number;
    kind: string;
    reason: string;
    quantity: string | null;
    disposal_reference: string | null;
    state: string;
    lock_version: number;
    sku_code: string;
    stock_unit: string;
    batch_number: string;
    location: string;
    history: Action[];
    canCount: boolean;
    canApprove: boolean;
    canReverse: boolean;
};
type Page<T> = { rows: T[]; total: number; page: number; lastPage: number };
const props = defineProps<{
    stock: {
        branchId: number;
        branchName: string;
        mode: 'count' | 'writeoff';
        q: string;
        state: string;
        legacyLimit: string;
        canPropose: boolean;
        cases: Page<Case>;
        targets: Page<Target>;
    };
}>();
const title =
    props.stock.mode === 'count'
        ? 'Stock count (governed)'
        : 'Write-off (governed)';
const path =
    props.stock.mode === 'count' ? '/inventory/counts' : '/inventory/writeoffs';
const states: Record<string, string> = {
    awaiting_count: 'Counter must enter the first count',
    awaiting_recount: 'A different counter must recount',
    awaiting_approval:
        'Independent CA Supervisor, Finance Officer or Director approval required',
    closed_zero: 'Closed: zero variance, no stock movement',
    posted: 'Posted: immutable movement recorded',
};
const q = ref(props.stock.q);
const state = ref(props.stock.state);
const open = ref(false);
const history = ref<Case | null>(null);
const label = ref('');
const endpoint = ref('');
const operation = ref('');
const form = useForm({
    expected_branch_id: props.stock.branchId,
    lock_version: 0,
    retry_key: '',
    balance_id: 0,
    balance_version: 0,
    unit: '',
    quantity: '',
    reason: 'expired',
    disposal_reference: '',
    reverses_case_id: 0,
    return_to: props.stock.mode,
});
function search(page = 1, targets = 1) {
    router.get(
        path,
        { q: q.value, state: state.value, page, targets_page: targets },
        { preserveScroll: true },
    );
}
function showTarget(row: Target) {
    form.reset();
    form.clearErrors();
    form.expected_branch_id = props.stock.branchId;
    form.retry_key = crypto.randomUUID();
    form.balance_id = row.id;
    form.balance_version = row.lock_version;
    form.unit = row.stock_unit;
    label.value = `${row.sku_code} · ${row.batch_number} · ${row.location} · On hand ${row.quantity} ${row.stock_unit}`;
    operation.value = props.stock.mode;
    endpoint.value = `/inventory/governed/${props.stock.mode}`;
    open.value = true;
}
function showCase(row: Case, action: string) {
    form.reset();
    form.clearErrors();
    form.expected_branch_id = props.stock.branchId;
    form.retry_key = crypto.randomUUID();
    form.lock_version = row.lock_version;
    form.unit = row.stock_unit;
    label.value = `${row.sku_code} · ${row.batch_number} · Snapshot ${row.expected_quantity} ${row.stock_unit}`;
    operation.value = action;

    if (action === 'reversal') {
        form.lock_version = 0;
        form.balance_id = row.balance_id;
        form.balance_version = row.current_balance_version;
        form.reverses_case_id = row.id;
        endpoint.value = '/inventory/governed/reversal';
    } else {
        endpoint.value = `/inventory/governed/${row.id}/${action}`;
    }

    open.value = true;
}
function submit() {
    form.transform((data) => {
        const common = {
            expected_branch_id: data.expected_branch_id,
            lock_version: data.lock_version,
            retry_key: data.retry_key,
        };

        if (operation.value === 'count') {
            return {
                ...common,
                balance_id: data.balance_id,
                balance_version: data.balance_version,
                unit: data.unit,
            };
        }

        if (operation.value === 'writeoff') {
            return {
                ...common,
                balance_id: data.balance_id,
                balance_version: data.balance_version,
                unit: data.unit,
                quantity: data.quantity,
                reason: data.reason,
                disposal_reference: data.disposal_reference,
            };
        }

        if (operation.value === 'reversal') {
            return {
                ...common,
                balance_id: data.balance_id,
                balance_version: data.balance_version,
                unit: data.unit,
                reverses_case_id: data.reverses_case_id,
            };
        }

        if (operation.value === 'approved') {
            return { ...common, return_to: props.stock.mode };
        }

        return {
            ...common,
            quantity: data.quantity,
            unit: data.unit,
            return_to: props.stock.mode,
        };
    }).post(endpoint.value, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
const page = usePage();
watch(
    () => page.props.branchContext?.active?.id,
    () => {
        open.value = false;
        history.value = null;
        label.value = '';
        endpoint.value = '';
        q.value = '';
        state.value = '';
        form.reset();
        form.clearErrors();
    },
);
</script>

<template>
    <Head :title="title" />
    <main class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-7">
        <nav class="flex flex-wrap gap-4">
            <a
                class="inline-flex min-h-11 items-center underline"
                href="/inventory"
                >Inventory</a
            ><a
                class="inline-flex min-h-11 items-center underline"
                href="/inventory/counts"
                >Stock count</a
            ><a
                class="inline-flex min-h-11 items-center underline"
                href="/inventory/writeoffs"
                >Write-off</a
            >
        </nav>
        <h1 class="text-2xl font-semibold">{{ title }}</h1>
        <p>{{ stock.branchName }}</p>
        <p class="rounded border border-amber-500 p-3 text-sm">
            Stock controls: {{ stock.legacyLimit }}
        </p>
        <p class="text-sm text-muted-foreground">
            One saved snapshot per SKU, batch and location. Non-zero variance
            always needs a different recounter and an independent approver. No
            variance threshold or valuation. Disposal references do not prove
            disposal. Held batches may be counted and written off without
            releasing their hold.
        </p>
        <form class="flex flex-wrap items-end gap-2" @submit.prevent="search()">
            <label class="min-w-[min(100%,18rem)] flex-1 basis-72"
                >Search SKU, batch or location<input
                    v-model="q"
                    maxlength="100"
                    type="search"
                    class="mt-1 min-h-11 w-full rounded border bg-background px-3" /></label
            ><label class="min-w-0"
                >Status<select
                    v-model="state"
                    class="mt-1 min-h-11 max-w-full rounded border bg-background px-2"
                >
                    <option value="">All states</option>
                    <option
                        v-for="(value, key) in states"
                        :key="key"
                        :value="key"
                    >
                        {{ key.replaceAll('_', ' ') }}
                    </option>
                </select></label
            ><button class="min-h-11 rounded border px-4" type="submit">
                Search
            </button>
        </form>
        <section class="space-y-3">
            <h2 class="text-xl font-semibold">
                {{
                    stock.mode === 'count'
                        ? 'Snapshot targets'
                        : 'Write-off targets'
                }}
            </h2>
            <p class="text-sm">
                {{ stock.targets.total }} targets · Page
                {{ stock.targets.page }} of {{ stock.targets.lastPage }} · 25
                per page. Search finds targets beyond this page.
            </p>
            <article
                v-for="row in stock.targets.rows"
                :key="row.id"
                class="flex min-w-0 flex-wrap justify-between gap-3 rounded border p-3"
            >
                <div class="min-w-0 flex-1 break-words">
                    <h3 class="font-semibold">{{ row.sku_code }}</h3>
                    <p>{{ row.batch_number }} · {{ row.location }}</p>
                    <p>
                        On hand {{ row.quantity }} {{ row.stock_unit }} · Expiry
                        {{ row.expiry_date }}
                    </p>
                </div>
                <button
                    v-if="stock.canPropose"
                    class="min-h-11 rounded border px-3"
                    type="button"
                    @click="showTarget(row)"
                >
                    {{
                        stock.mode === 'count'
                            ? 'Take snapshot'
                            : 'Propose write-off'
                    }}
                </button>
            </article>
            <p v-if="!stock.targets.rows.length">No matching targets.</p>
            <div class="flex justify-between gap-2">
                <button
                    class="min-h-11 rounded border px-4 disabled:opacity-40"
                    :disabled="stock.targets.page <= 1"
                    @click="search(stock.cases.page, stock.targets.page - 1)"
                >
                    Previous targets</button
                ><button
                    class="min-h-11 rounded border px-4 disabled:opacity-40"
                    :disabled="stock.targets.page >= stock.targets.lastPage"
                    @click="search(stock.cases.page, stock.targets.page + 1)"
                >
                    Next targets
                </button>
            </div>
        </section>
        <section class="space-y-3">
            <h2 class="text-xl font-semibold">Cases and next action</h2>
            <p class="text-sm">
                {{ stock.cases.total }} cases · Page {{ stock.cases.page }} of
                {{ stock.cases.lastPage }} · 25 per page.
            </p>
            <article
                v-for="row in stock.cases.rows"
                :key="row.id"
                class="min-w-0 space-y-2 rounded border p-3"
            >
                <h3 class="font-semibold break-words">
                    Case {{ row.id }} · {{ row.sku_code }} ·
                    {{ row.batch_number }}
                </h3>
                <p>{{ row.location }} · {{ row.kind }} · {{ row.reason }}</p>
                <p>Snapshot {{ row.expected_quantity }} {{ row.stock_unit }}</p>
                <p v-if="row.quantity">Requested quantity {{ row.quantity }}</p>
                <p v-if="row.disposal_reference" class="break-words">
                    Disposal reference:
                    {{ row.disposal_reference }} (referenced, not proven)
                </p>
                <p class="font-semibold">{{ states[row.state] }}</p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-if="row.canCount"
                        class="min-h-11 rounded border px-3"
                        @click="
                            showCase(
                                row,
                                row.state === 'awaiting_count'
                                    ? 'counted'
                                    : 'recounted',
                            )
                        "
                    >
                        {{
                            row.state === 'awaiting_count'
                                ? 'Enter count'
                                : 'Independent recount'
                        }}</button
                    ><button
                        v-if="row.canApprove"
                        class="min-h-11 rounded border px-3"
                        @click="showCase(row, 'approved')"
                    >
                        Approve and post</button
                    ><button
                        v-if="row.canReverse"
                        class="min-h-11 rounded border px-3"
                        @click="showCase(row, 'reversal')"
                    >
                        Propose reversal</button
                    ><button
                        class="min-h-11 px-2 underline"
                        @click="history = row"
                    >
                        Evidence history
                    </button>
                </div>
            </article>
            <p v-if="!stock.cases.rows.length">No matching cases.</p>
            <div class="flex justify-between gap-2">
                <button
                    class="min-h-11 rounded border px-4 disabled:opacity-40"
                    :disabled="stock.cases.page <= 1"
                    @click="search(stock.cases.page - 1, stock.targets.page)"
                >
                    Previous cases</button
                ><button
                    class="min-h-11 rounded border px-4 disabled:opacity-40"
                    :disabled="stock.cases.page >= stock.cases.lastPage"
                    @click="search(stock.cases.page + 1, stock.targets.page)"
                >
                    Next cases
                </button>
            </div>
        </section>
        <Dialog v-model:open="open"
            ><DialogContent
                :show-close-button="false"
                class="max-h-[90dvh] overflow-y-auto"
                ><DialogHeader
                    ><DialogTitle>{{
                        operation === 'approved'
                            ? 'Independent approval and posting'
                            : operation === 'count'
                              ? 'Freeze stock snapshot'
                              : operation === 'writeoff'
                                ? 'Propose write-off'
                                : operation === 'reversal'
                                  ? 'Propose reversal'
                                  : 'Record physical count'
                    }}</DialogTitle
                    ><DialogDescription
                        >Saved evidence is append-only. A changed snapshot is
                        refused. Approval posts atomically; the original
                        evidence is never edited.</DialogDescription
                    ></DialogHeader
                >
                <form class="space-y-4" @submit.prevent="submit">
                    <p class="break-words">{{ label }}</p>
                    <label
                        v-if="
                            ['writeoff', 'counted', 'recounted'].includes(
                                operation,
                            )
                        "
                        class="block"
                        >{{
                            operation === 'writeoff'
                                ? 'Write-off quantity'
                                : 'Physical quantity counted'
                        }}<input
                            v-model="form.quantity"
                            inputmode="decimal"
                            maxlength="16"
                            class="mt-1 min-h-11 w-full rounded border bg-background px-3"
                    /></label>
                    <template v-if="operation === 'writeoff'"
                        ><label class="block"
                            >Controlled reason<select
                                v-model="form.reason"
                                class="mt-1 min-h-11 w-full rounded border bg-background px-3"
                            >
                                <option
                                    v-for="reason in [
                                        'expired',
                                        'damaged',
                                        'contaminated',
                                        'other_approved',
                                    ]"
                                    :key="reason"
                                    :value="reason"
                                >
                                    {{ reason.replaceAll('_', ' ') }}
                                </option>
                            </select></label
                        ><label class="block"
                            >Disposal document or log reference<input
                                v-model="form.disposal_reference"
                                maxlength="200"
                                class="mt-1 min-h-11 w-full rounded border bg-background px-3"
                        /></label>
                        <p class="text-sm">
                            This records the reference only. KPOne does not
                            prove physical disposal.
                        </p></template
                    >
                    <InputError
                        v-for="(message, field) in form.errors"
                        :key="field"
                        :message="message"
                    />
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="min-h-11 rounded border px-4"
                            @click="open = false"
                        >
                            Cancel</button
                        ><button
                            type="submit"
                            class="min-h-11 rounded bg-primary px-4 text-primary-foreground disabled:opacity-40"
                            :disabled="form.processing"
                        >
                            {{
                                operation === 'approved'
                                    ? 'Approve and post movement'
                                    : 'Save evidence'
                            }}
                        </button>
                    </div>
                </form></DialogContent
            ></Dialog
        >
        <Dialog
            :open="history !== null"
            @update:open="
                (value) => {
                    if (!value) history = null;
                }
            "
            ><DialogContent
                :show-close-button="false"
                class="max-h-[90dvh] overflow-y-auto"
                ><DialogHeader
                    ><DialogTitle>Immutable evidence history</DialogTitle
                    ><DialogDescription
                        >Original counts and recounts are retained. No edit or
                        delete.</DialogDescription
                    ></DialogHeader
                >
                <article
                    v-for="entry in history?.history"
                    :key="entry.id"
                    class="space-y-1 border-t py-2"
                >
                    <p>
                        {{ entry.action }} · Staff {{ entry.actor_user_id }} ·
                        Version {{ entry.lock_version }}
                    </p>
                    <p v-if="entry.quantity !== null">
                        Quantity {{ entry.quantity }}
                    </p>
                    <p>{{ states[entry.state] }}</p>
                    <p v-if="entry.movement_id">
                        Movement {{ entry.movement_id }}
                    </p>
                </article>
                <button
                    class="min-h-11 rounded border px-4"
                    @click="history = null"
                >
                    Close history
                </button></DialogContent
            ></Dialog
        >
    </main>
</template>
