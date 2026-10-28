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
type Review = {
    id: number;
    version: number;
    reviewer: string;
    when: string;
    outcome: string;
    reviewBy: string | null;
    overdue: boolean;
};
type Row = {
    item_id: number;
    sku_code: string;
    item: string;
    location: string;
    stock_unit: string;
    batch: string | null;
    expiry: string | null;
    quantity: string;
    held: string;
    reorder_level: string | null;
    lock_version: number;
    latest: Review | null;
    history: Review[];
};
type Kind = 'low_stock' | 'expiring' | 'expired';
type List = { rows: Row[]; total: number; page: number; lastPage: number };
const props = defineProps<{
    review: {
        branchId: number;
        branchName: string;
        today: string;
        windowDays: number;
        canManage: boolean;
        q: string;
        lists: Record<Kind, List>;
    };
}>();
const headings: Record<Kind, string> = {
    low_stock: 'Low stock',
    expiring: 'Expiring soon',
    expired: 'Expired stock still on hand',
};
const kinds: Kind[] = ['low_stock', 'expiring', 'expired'];
const outcomes: Record<string, string> = {
    ordered_externally: 'Ordered externally',
    no_action_needed: 'No action needed',
    monitoring: 'Monitoring',
    disposal_requested_external: 'Disposal requested externally',
};
const q = ref(props.review.q);
const selected = ref<Row | null>(null);
const kind = ref<Kind>('low_stock');
const dialog = ref(false);
const history = ref<Row | null>(null);
const form = useForm({
    expected_branch_id: props.review.branchId,
    lock_version: 0,
    retry_key: '',
    outcome: 'monitoring',
    review_by: '',
});
function search(target?: Kind, page = 1) {
    const parameters: Record<string, string | number> = { q: q.value };

    for (const k of kinds) {
        parameters[`${k}_page`] = target
            ? target === k
                ? page
                : props.review.lists[k].page
            : 1;
    }

    router.get('/inventory/stock-review', parameters, { preserveScroll: true });
}
function show(k: Kind, row: Row) {
    kind.value = k;
    selected.value = row;
    form.reset();
    form.clearErrors();
    form.expected_branch_id = props.review.branchId;
    form.lock_version = row.lock_version;
    form.retry_key = crypto.randomUUID();
    dialog.value = true;
}
function submit() {
    if (!selected.value) {
        return;
    }

    form.post(
        `/inventory/stock-review/${kind.value}/${selected.value.item_id}`,
        {
            preserveScroll: true,
            onSuccess: () => {
                dialog.value = false;
                selected.value = null;
            },
        },
    );
}
const page = usePage();
watch(
    () => page.props.branchContext?.active?.id,
    () => {
        dialog.value = false;
        selected.value = null;
        history.value = null;
        q.value = '';
        form.reset();
        form.clearErrors();
    },
);
</script>

<template>
    <Head title="Stock review" />
    <main class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-7">
        <a href="/inventory" class="inline-flex min-h-11 items-center underline"
            >Inventory</a
        >
        <h1 class="text-2xl font-semibold">Stock review</h1>
        <p>{{ review.branchName }} · Branch-local date: {{ review.today }}</p>
        <p class="text-sm text-muted-foreground">
            Review evidence only. Acknowledgements do not change stock, send
            messages, place orders or hide unresolved conditions. Thresholds are
            per SKU and location. Held stock is shown separately and is never
            eligible stock.
        </p>
        <form class="flex flex-wrap items-end gap-2" @submit.prevent="search()">
            <label class="min-w-0 flex-1"
                >Search SKU, item, batch or location<input
                    v-model="q"
                    maxlength="100"
                    class="mt-1 min-h-11 w-full rounded border bg-background px-3"
                    type="search"
            /></label>
            <button class="min-h-11 rounded border px-4" type="submit">
                Search
            </button>
        </form>
        <section
            v-for="k in kinds"
            :key="k"
            class="min-w-0 space-y-3 rounded-lg border p-3 md:p-5"
        >
            <h2 class="text-xl font-semibold">{{ headings[k] }}</h2>
            <p v-if="k === 'expiring'" class="text-sm">
                Eligible positive stock expiring after today through day
                {{ review.windowDays }}, inclusive.
            </p>
            <p v-if="k === 'expired'" class="text-sm font-medium">
                Always listed while stock remains, including held or unavailable
                stock. Disposal is deferred to STK-5; an acknowledgement does
                not prove disposal.
            </p>
            <p class="text-sm text-muted-foreground">
                {{ review.lists[k].total }} matching items · Page
                {{ review.lists[k].page }} of {{ review.lists[k].lastPage }} ·
                Up to 25 items per page. Use all pages to review unresolved
                items.
            </p>
            <p v-if="!review.lists[k].rows.length">
                No matching items on this page.
            </p>
            <article
                v-for="row in review.lists[k].rows"
                :key="row.item_id"
                class="min-w-0 space-y-3 rounded border p-3"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1 break-words">
                        <h3 class="font-semibold">
                            {{ row.sku_code }} · {{ row.item }}
                        </h3>
                        <p>
                            {{ row.location
                            }}<span v-if="row.batch">
                                · {{ row.batch }} · Expiry
                                {{ row.expiry }}</span
                            >
                        </p>
                    </div>
                    <button
                        v-if="review.canManage"
                        type="button"
                        class="min-h-11 rounded border px-3"
                        @click="show(k, row)"
                    >
                        Acknowledge
                    </button>
                </div>
                <p>
                    {{ k === 'low_stock' ? 'Eligible quantity' : 'On hand' }}:
                    {{ row.quantity }} {{ row.stock_unit
                    }}<span v-if="k === 'low_stock'">
                        · Reorder level: {{ row.reorder_level }}</span
                    >
                    · Held: {{ row.held }}
                </p>
                <div v-if="row.latest" class="space-y-1 text-sm">
                    <p
                        v-if="row.latest.overdue"
                        class="font-bold text-destructive"
                    >
                        Review overdue
                    </p>
                    <p>
                        {{ outcomes[row.latest.outcome] }} ·
                        {{ row.latest.reviewer }} · {{ row.latest.when }}
                    </p>
                    <p v-if="row.latest.reviewBy">
                        Review by {{ row.latest.reviewBy }}
                    </p>
                </div>
                <p v-else class="text-sm text-muted-foreground">
                    Not yet acknowledged
                </p>
                <button
                    type="button"
                    class="min-h-11 underline"
                    @click="history = row"
                >
                    Review history (last 10)
                </button>
            </article>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <button
                    class="min-h-11 rounded border px-4 disabled:opacity-40"
                    type="button"
                    :disabled="review.lists[k].page <= 1"
                    @click="search(k, review.lists[k].page - 1)"
                >
                    Previous</button
                ><button
                    class="min-h-11 rounded border px-4 disabled:opacity-40"
                    type="button"
                    :disabled="review.lists[k].page >= review.lists[k].lastPage"
                    @click="search(k, review.lists[k].page + 1)"
                >
                    Next
                </button>
            </div>
        </section>
        <Dialog v-model:open="dialog"
            ><DialogContent
                :show-close-button="false"
                class="max-h-[90dvh] overflow-y-auto"
                ><DialogHeader
                    ><DialogTitle>Acknowledge stock review</DialogTitle
                    ><DialogDescription
                        >Records who reviewed this item and when. It changes no
                        stock and sends nothing.</DialogDescription
                    ></DialogHeader
                >
                <form class="space-y-4" @submit.prevent="submit">
                    <p class="break-words">
                        {{ selected?.sku_code }} · {{ selected?.location }}
                    </p>
                    <label class="block"
                        >Outcome<select
                            v-model="form.outcome"
                            class="mt-1 min-h-11 w-full rounded border bg-background px-2"
                        >
                            <option
                                v-for="(label, value) in outcomes"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option>
                        </select></label
                    ><InputError :message="form.errors.outcome" /><label
                        class="block"
                        >Review by<input
                            v-model="form.review_by"
                            type="date"
                            :min="review.today"
                            class="mt-1 min-h-11 w-full rounded border bg-background px-2"
                    /></label>
                    <p class="text-sm">
                        Required for Monitoring and Ordered externally. Choose
                        today through 14 days ahead.
                    </p>
                    <InputError
                        v-for="(message, field) in form.errors"
                        :key="field"
                        :message="message"
                    />
                    <div class="flex flex-wrap gap-2">
                        <button
                            class="min-h-11 rounded border px-4"
                            type="button"
                            @click="dialog = false"
                        >
                            Cancel</button
                        ><button
                            class="min-h-11 rounded bg-primary px-4 text-primary-foreground disabled:opacity-40"
                            type="submit"
                            :disabled="form.processing"
                        >
                            Save acknowledgement
                        </button>
                    </div>
                </form>
            </DialogContent></Dialog
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
                    ><DialogTitle>Review history</DialogTitle
                    ><DialogDescription
                        >Last 10 acknowledgements. Earlier evidence is
                        retained.</DialogDescription
                    ></DialogHeader
                >
                <p class="break-words">
                    {{ history?.sku_code }} · {{ history?.location }}
                </p>
                <p v-if="!history?.history.length">No acknowledgements yet.</p>
                <article
                    v-for="entry in history?.history"
                    :key="entry.id"
                    class="space-y-1 border-t py-3 text-sm"
                >
                    <p>{{ outcomes[entry.outcome] }}</p>
                    <p>{{ entry.reviewer }} · {{ entry.when }}</p>
                    <p v-if="entry.reviewBy">Review by {{ entry.reviewBy }}</p>
                    <p v-if="entry.overdue" class="font-bold text-destructive">
                        Review overdue
                    </p>
                </article>
                <button
                    type="button"
                    class="min-h-11 rounded border px-4"
                    @click="history = null"
                >
                    Close history
                </button></DialogContent
            ></Dialog
        >
    </main>
</template>
