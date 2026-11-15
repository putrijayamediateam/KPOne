<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import InventoryPickerField from '@/components/inventory-v2/InventoryPickerField.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2KeyValueList from '@/components/v2/V2KeyValueList.vue';
import type { KeyValueItem } from '@/components/v2/V2KeyValueList.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { quantityWithUnit } from '@/lib/inventory-v2-words';
import { requestJson } from '@/lib/json-client';

/**
 * Version 2 Recalls (inventory V2 page 7b). Everything from `type Where` to `tabs` is a verbatim copy of Classic (a test
 * compares them). Only the layout and the plain sentences are new: recall cards with a "Where it is" list, a Start sheet
 * on the same server SKU search as Classic, and a Lift sheet, each with what the press does beside the button.
 */
// STK-25: a recall blocks named batches of one SKU at every branch at once. It moves no stock.
type Where = {
    branch: string;
    location: string;
    returns: boolean;
    batchNumber: string;
    skuCode: string;
    stockUnit: string;
    quantity: string;
};
type Recall = {
    publicId: string;
    medicine: string;
    skuCode: string;
    stockUnit: string;
    reason: string;
    reference: string | null;
    status: 'active' | 'lifted';
    lockVersion: number;
    note: string | null;
    startedBy: string;
    startedOn: string;
    startedByYou: boolean;
    liftedBy: string | null;
    liftedOn: string | null;
    liftReason: string | null;
    liftNote: string | null;
    batches: { key: string; batchNumber: string; expiry: string | null }[];
    whereIs: Where[];
};
type BatchOption = {
    publicId: string;
    batchNumber: string;
    expiry: string | null;
    recalled: boolean;
    onHand: { branch: string; quantity: string }[];
};

const props = defineProps<{
    recalls: {
        expectedBranchId: number;
        branchName: string;
        tab: 'active' | 'lifted';
        can: {
            start: boolean;
            lift: boolean;
            writeOff: boolean;
            returnToSupplier: boolean;
        };
        allBranches: boolean;
        counts: { active: number; lifted: number };
        recalls: {
            page: number;
            lastPage: number;
            total: number;
            rows: Recall[];
        };
    };
}>();

const reasons: Record<string, string> = {
    supplier_recall: 'Supplier recall',
    regulator_notice: 'Regulator notice',
    quality_issue: 'Quality issue',
    other: 'Other',
};
const liftReasons: Record<string, string> = {
    supplier_cleared: 'Supplier cleared the batch',
    recall_withdrawn: 'Recall withdrawn',
    recalled_in_error: 'Recalled in error',
    other: 'Other',
};

const visit = (query: Record<string, string | number>) =>
    router.get('/inventory/recalls', query, {
        preserveScroll: true,
        preserveState: true,
    });

// Errors with no field of their own show at the top of the open dialog.
const generalError = (bag: Record<string, string>, fields: string[]) =>
    Object.entries(bag).find(([key]) => !fields.includes(key))?.[1] ?? null;

// Start a recall.
const startOpen = ref(false);
const start = reactive({
    sku: '' as string | number,
    batches: [] as string[],
    reason: '',
    reference: '',
    note: '',
    key: crypto.randomUUID(),
});
const options = ref<BatchOption[]>([]);
// Daniel: search the SKU's batches instead of a long list; at most 10 shown, ticked ones stay as chips.
const batchSearch = ref('');
const BATCH_CAP = 10;
const matchingOptions = computed(() => {
    const term = batchSearch.value.trim().toLowerCase();

    return term
        ? options.value.filter((o) =>
              o.batchNumber.toLowerCase().includes(term),
          )
        : options.value;
});
const visibleOptions = computed(() =>
    matchingOptions.value.slice(0, BATCH_CAP),
);
const selectedOptions = computed(() =>
    options.value.filter((o) => start.batches.includes(o.publicId)),
);
const unselect = (publicId: string) =>
    (start.batches = start.batches.filter((id) => id !== publicId));
const optionsError = ref('');
const startErrors = ref<Record<string, string>>({});
const busy = ref(false);
let batchRequest = 0;
watch(
    () => start.sku,
    async (sku) => {
        const request = ++batchRequest;
        start.batches = [];
        batchSearch.value = '';
        options.value = [];
        optionsError.value = '';

        if (!sku) {
            return;
        }

        try {
            const response = await requestJson<{ data: BatchOption[] }>(
                `/inventory/recalls/batches?sku=${encodeURIComponent(String(sku))}`,
            );

            // A slower answer for an SKU chosen earlier is ignored.
            if (request === batchRequest) {
                options.value = response.data;
            }
        } catch {
            if (request !== batchRequest) {
                return;
            }

            optionsError.value =
                'The batches could not be loaded. Choose the SKU again.';
        }
    },
);
const openStart = () => {
    start.sku = '';
    start.batches = [];
    start.reason = '';
    start.reference = '';
    start.note = '';
    start.key = crypto.randomUUID();
    startErrors.value = {};
    startOpen.value = true;
};
// An error goes away as soon as its field is changed.
watch(
    () => start.batches.length,
    () => delete startErrors.value.batch_public_ids,
);
watch(
    () => start.reason,
    () => {
        delete startErrors.value.reason;
        delete startErrors.value.note;
    },
);
watch(
    () => start.note,
    () => delete startErrors.value.note,
);
watch(
    () => start.sku,
    () => delete startErrors.value.sku_public_id,
);
const startFields = [
    'sku_public_id',
    'batch_public_ids',
    'reason',
    'reference',
    'note',
];
const submitStart = () => {
    startErrors.value = {};

    if (!start.sku) {
        startErrors.value.sku_public_id = 'Choose the SKU.';
    }

    if (!start.batches.length) {
        startErrors.value.batch_public_ids = 'Tick at least one batch.';
    }

    if (!start.reason) {
        startErrors.value.reason = 'Choose a reason.';
    } else if (start.reason === 'other' && !start.note.trim()) {
        startErrors.value.note = 'Add a note to explain the recall.';
    }

    if (Object.keys(startErrors.value).length) {
        return;
    }

    router.post(
        '/inventory/recalls',
        {
            expected_branch_id: props.recalls.expectedBranchId,
            idempotency_key: start.key,
            sku_public_id: start.sku,
            batch_public_ids: start.batches,
            reason: start.reason,
            reference: start.reference.trim() || null,
            note: start.note.trim() || null,
        },
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
            onSuccess: () => (startOpen.value = false),
            onError: (bag) => {
                startErrors.value = bag as Record<string, string>;
                start.key = crypto.randomUUID();
            },
        },
    );
};
const startGeneral = computed(() =>
    generalError(startErrors.value, startFields),
);

// Lift a recall.
const lifting = ref<Recall | null>(null);
const lift = reactive({ reason: '', note: '', key: crypto.randomUUID() });
const liftErrors = ref<Record<string, string>>({});
watch(
    () => lift.reason,
    () => {
        delete liftErrors.value.reason;
        delete liftErrors.value.note;
    },
);
watch(
    () => lift.note,
    () => delete liftErrors.value.note,
);
const openLift = (recall: Recall) => {
    lift.reason = '';
    lift.note = '';
    lift.key = crypto.randomUUID();
    liftErrors.value = {};
    lifting.value = recall;
};
const submitLift = () => {
    const recall = lifting.value;

    if (!recall) {
        return;
    }

    liftErrors.value = {};

    if (!lift.reason) {
        liftErrors.value.reason = 'Choose a reason.';
    } else if (lift.reason === 'other' && !lift.note.trim()) {
        liftErrors.value.note =
            'Add a note to explain why the recall is lifted.';
    }

    if (Object.keys(liftErrors.value).length) {
        return;
    }

    router.post(
        `/inventory/recalls/${recall.publicId}/lift`,
        {
            expected_branch_id: props.recalls.expectedBranchId,
            lock_version: recall.lockVersion,
            retry_key: lift.key,
            reason: lift.reason,
            note: lift.note.trim() || null,
        },
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
            onSuccess: () => (lifting.value = null),
            onError: (bag) =>
                (liftErrors.value = bag as Record<string, string>),
        },
    );
};
const liftGeneral = computed(
    () =>
        generalError(liftErrors.value, ['reason', 'note', 'retry_key']) ??
        liftErrors.value.retry_key ??
        null,
);
const batchWord = (n: number) => (n === 1 ? '1 batch' : `${n} batches`);
// "N batches at M branches will be blocked": M counts the places holding the ticked batches now.
const startSummary = computed(() => {
    const places = new Set(
        selectedOptions.value.flatMap((o) => o.onHand.map((h) => h.branch)),
    ).size;

    return places
        ? `${batchWord(start.batches.length)} at ${places === 1 ? '1 branch' : `${places} branches`} will be blocked.`
        : `${batchWord(start.batches.length)} will be blocked. None is on hand now.`;
});
const liftBatches = computed(() => {
    const names = lifting.value?.batches.map((b) => b.batchNumber) ?? [];

    return names.length > 3
        ? batchWord(names.length)
        : `${names.length === 1 ? 'Batch' : 'Batches'} ${names.join(', ')}`;
});
const tabs = computed(() => [
    { value: 'active', label: 'Active', count: props.recalls.counts.active },
    { value: 'lifted', label: 'Lifted', count: props.recalls.counts.lifted },
]);

// ---- V2 only below this line ----
usePageCrumbs(() => [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Recalls' },
]);
/** A code never breaks across lines ("SYN-B-123" stays whole). */
const unbroken = (code: string) => code.replaceAll('-', '‑');
const PLACES_SHOWN = 4;
const expanded = reactive<Record<string, boolean>>({});
const placesFor = (recall: Recall) =>
    expanded[recall.publicId]
        ? recall.whereIs
        : recall.whereIs.slice(0, PLACES_SHOWN);
const placesWord = (n: number) => (n === 1 ? '1 place' : `${n} places`);
const reasonOptions = computed(() =>
    Object.entries(reasons).map(([value, label]) => ({ value, label })),
);
const liftReasonOptions = computed(() =>
    Object.entries(liftReasons).map(([value, label]) => ({ value, label })),
);
/** "Other" needs a note: bring the box into view and put the cursor in it (Daniel, page 6). */
const showNote = (reason: string, id: string) => {
    if (reason !== 'other') {
        return;
    }

    void nextTick(() => {
        const note = document.getElementById(id);
        note?.scrollIntoView({ block: 'nearest' });
        note?.focus();
    });
};
const skuWords = ref('');
const skuUnit = ref('');
/** Why Start recall is off, in words; null when it can be pressed. */
const startBlocked = computed<string | null>(() => {
    if (!start.sku) {
        return 'Choose the SKU to continue.';
    }

    if (!start.batches.length) {
        return 'Tick at least one batch to continue.';
    }

    if (!start.reason) {
        return 'Pick a reason to continue.';
    }

    if (start.reason === 'other' && !start.note.trim()) {
        return 'Add a note to explain the recall.';
    }

    return null;
});
const startSentence = computed(() =>
    start.batches.length
        ? `${startSummary.value} No stock moves.`
        : 'The ticked batches are blocked at every branch until another person lifts the recall. No stock moves.',
);
const startDirty = computed(
    () =>
        Boolean(start.sku) ||
        start.batches.length > 0 ||
        Boolean(start.reason || start.reference || start.note),
);
const toggleBatch = (publicId: string, on: boolean) => {
    start.batches = on
        ? [...new Set([...start.batches, publicId])]
        : start.batches.filter((id) => id !== publicId);
};
const liftBlocked = computed<string | null>(() => {
    if (!lift.reason) {
        return 'Pick a reason to continue.';
    }

    if (lift.reason === 'other' && !lift.note.trim()) {
        return 'Add a note to explain why the recall is lifted.';
    }

    return null;
});
const liftRows = computed<KeyValueItem[]>(() => {
    const recall = lifting.value;

    return recall
        ? [
              { label: 'Medicine', value: recall.medicine },
              { label: 'SKU', value: unbroken(recall.skuCode) },
              { label: 'Batches', value: liftBatches.value },
              {
                  label: 'Started',
                  value: `${recall.startedOn} by ${recall.startedBy}`,
              },
              {
                  label: 'Reason',
                  value: `${reasons[recall.reason] ?? recall.reason}${recall.reference ? ` · ${recall.reference}` : ''}`,
              },
          ]
        : [];
});
</script>

<template>
    <Head title="Recalls" />
    <PageFrame>
        <Link
            href="/inventory"
            class="inline-flex h-11 w-fit items-center rounded-[10px] text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:hidden"
            data-action="back"
        >
            ← Inventory
        </Link>
        <V2WorkLayout
            title="Recalls"
            :description="`A recall blocks named batches at every branch. It moves no stock. ${recalls.allBranches ? 'Stock is shown for every branch.' : `Stock is shown for ${recalls.branchName}.`}`"
        >
            <template v-if="recalls.can.start" #actions>
                <button
                    type="button"
                    class="inline-flex h-11 w-full items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:w-auto lg:h-9"
                    data-testid="recall-start"
                    @click="openStart"
                >
                    Start a recall
                </button>
            </template>
            <template #main>
                <V2Tabs
                    :model-value="recalls.tab"
                    :tabs="tabs"
                    ariaLabel="Recall status"
                    @update:model-value="(tab) => visit({ tab })"
                />
                <p
                    v-if="!recalls.recalls.rows.length"
                    class="rounded-[14px] border p-4 text-sm text-muted-foreground"
                >
                    {{
                        recalls.tab === 'active'
                            ? 'No batch is under a recall.'
                            : 'No recall has been lifted yet.'
                    }}
                </p>
                <ul
                    v-else
                    class="flex min-w-0 flex-col gap-3"
                    aria-label="Recalls"
                >
                    <li
                        v-for="recall in recalls.recalls.rows"
                        :key="recall.publicId"
                        class="grid min-w-0 gap-4 rounded-[14px] border bg-card p-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]"
                        data-testid="recall-card"
                    >
                        <div class="flex min-w-0 flex-col gap-3">
                            <div
                                class="flex flex-wrap items-start justify-between gap-2"
                            >
                                <div class="min-w-0">
                                    <h2
                                        class="text-base font-semibold break-words"
                                    >
                                        {{ recall.medicine }}
                                    </h2>
                                    <p class="text-sm text-muted-foreground">
                                        <span
                                            class="font-mono whitespace-nowrap"
                                            >{{
                                                unbroken(recall.skuCode)
                                            }}</span
                                        >
                                        · {{ batchWord(recall.batches.length) }}
                                    </p>
                                </div>
                                <V2Chip
                                    class="whitespace-nowrap"
                                    :tone="
                                        recall.status === 'active'
                                            ? 'danger'
                                            : 'neutral'
                                    "
                                    :label="
                                        recall.status === 'active'
                                            ? 'Recalled'
                                            : 'Lifted'
                                    "
                                />
                            </div>
                            <ul
                                class="flex flex-wrap gap-2"
                                :aria-label="`Recalled batches of ${recall.medicine}`"
                            >
                                <li
                                    v-for="batch in recall.batches"
                                    :key="batch.key"
                                    class="rounded-full border px-2.5 py-0.5 text-xs whitespace-nowrap"
                                >
                                    <span class="font-mono">{{
                                        unbroken(batch.batchNumber)
                                    }}</span
                                    ><span
                                        v-if="batch.expiry"
                                        class="text-muted-foreground"
                                    >
                                        · exp {{ batch.expiry }}</span
                                    >
                                </li>
                            </ul>
                            <dl class="grid gap-1 text-sm">
                                <div class="flex flex-wrap gap-x-2">
                                    <dt class="text-muted-foreground">
                                        Reason
                                    </dt>
                                    <dd class="min-w-0 break-words">
                                        {{
                                            reasons[recall.reason] ??
                                            recall.reason
                                        }}<template v-if="recall.reference">
                                            ·
                                            <span class="whitespace-nowrap">{{
                                                unbroken(recall.reference)
                                            }}</span></template
                                        >
                                    </dd>
                                </div>
                                <div class="flex flex-wrap gap-x-2">
                                    <dt class="text-muted-foreground">
                                        Started
                                    </dt>
                                    <dd>
                                        {{ recall.startedOn }} by
                                        {{ recall.startedBy }}
                                    </dd>
                                </div>
                                <div
                                    v-if="recall.note"
                                    class="flex flex-wrap gap-x-2"
                                >
                                    <dt class="text-muted-foreground">Note</dt>
                                    <dd class="min-w-0 break-words">
                                        {{ recall.note }}
                                    </dd>
                                </div>
                                <div
                                    v-if="recall.status === 'lifted'"
                                    class="flex flex-wrap gap-x-2"
                                >
                                    <dt class="text-muted-foreground">
                                        Lifted
                                    </dt>
                                    <dd class="min-w-0 break-words">
                                        {{ recall.liftedOn }} by
                                        {{ recall.liftedBy }} ·
                                        {{
                                            liftReasons[
                                                recall.liftReason ?? ''
                                            ] ?? recall.liftReason
                                        }}<template v-if="recall.liftNote">
                                            · {{ recall.liftNote }}</template
                                        >
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <section
                            v-if="recall.status === 'active'"
                            class="flex min-w-0 flex-col gap-3"
                            :aria-label="`Where ${recall.medicine} is`"
                        >
                            <h3 class="text-sm font-semibold">
                                Where it is
                                <span class="font-normal text-muted-foreground"
                                    >({{
                                        placesWord(recall.whereIs.length)
                                    }})</span
                                >
                            </h3>
                            <p
                                v-if="!recall.whereIs.length"
                                class="text-sm text-muted-foreground"
                            >
                                No stock of these batches is on hand{{
                                    recalls.allBranches
                                        ? ''
                                        : ` at ${recalls.branchName}`
                                }}.
                            </p>
                            <ul
                                v-else
                                class="flex flex-col divide-y rounded-[10px] border text-sm"
                            >
                                <li
                                    v-for="(where, index) in placesFor(recall)"
                                    :key="index"
                                    class="flex flex-col gap-0.5 px-3 py-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                                >
                                    <span class="min-w-0 break-words"
                                        ><span v-if="recalls.allBranches"
                                            >{{ where.branch }} · </span
                                        >{{ where.location
                                        }}<span
                                            v-if="where.returns"
                                            class="text-muted-foreground"
                                        >
                                            (patient returns)</span
                                        >
                                        <span
                                            class="block text-xs text-muted-foreground"
                                            >Batch
                                            <span
                                                class="font-mono whitespace-nowrap"
                                                >{{
                                                    unbroken(where.batchNumber)
                                                }}</span
                                            ></span
                                        ></span
                                    >
                                    <span
                                        class="font-medium whitespace-nowrap tabular-nums"
                                        >{{
                                            quantityWithUnit(
                                                where.quantity,
                                                where.stockUnit,
                                            )
                                        }}</span
                                    >
                                </li>
                            </ul>
                            <button
                                v-if="recall.whereIs.length > PLACES_SHOWN"
                                type="button"
                                class="inline-flex h-11 w-fit items-center rounded-[10px] text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                                :aria-expanded="
                                    expanded[recall.publicId] ? 'true' : 'false'
                                "
                                @click="
                                    expanded[recall.publicId] =
                                        !expanded[recall.publicId]
                                "
                            >
                                {{
                                    expanded[recall.publicId]
                                        ? `Show the first ${PLACES_SHOWN}`
                                        : `Show all ${recall.whereIs.length} places`
                                }}
                            </button>
                            <div
                                class="flex flex-col gap-2 sm:flex-row sm:flex-wrap"
                            >
                                <p
                                    v-if="
                                        !recalls.can.writeOff &&
                                        !recalls.can.returnToSupplier &&
                                        !recalls.can.lift
                                    "
                                    class="text-sm text-muted-foreground"
                                    data-testid="recall-read-only"
                                >
                                    Read only. A CA Supervisor writes off or
                                    returns this stock.
                                </p>
                                <Link
                                    v-if="recalls.can.writeOff"
                                    href="/inventory/writeoffs"
                                    class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                                    :aria-label="`Write off stock of ${recall.medicine}`"
                                    >Write off stock</Link
                                >
                                <Link
                                    v-if="recalls.can.returnToSupplier"
                                    href="/inventory/supplier-returns"
                                    class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                                    :aria-label="`Return ${recall.medicine} to the supplier`"
                                    >Return to supplier</Link
                                >
                                <template v-if="recalls.can.lift">
                                    <p
                                        v-if="recall.startedByYou"
                                        class="text-sm text-muted-foreground"
                                        data-testid="own-recall"
                                    >
                                        You started this recall, so another
                                        person must lift it.
                                    </p>
                                    <button
                                        v-else
                                        type="button"
                                        class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                                        :aria-label="`Lift the recall of ${recall.medicine}`"
                                        @click="openLift(recall)"
                                    >
                                        Lift recall
                                    </button>
                                </template>
                            </div>
                        </section>
                    </li>
                </ul>
                <V2Pager
                    v-if="recalls.recalls.lastPage > 1"
                    :current-page="recalls.recalls.page"
                    :last-page="recalls.recalls.lastPage"
                    :total="recalls.recalls.total"
                    :per-page="25"
                    @change="(page) => visit({ tab: recalls.tab, page })"
                />
            </template>
        </V2WorkLayout>

        <!-- Start a recall: same server SKU search as Classic; red action, off with its reason until complete. -->
        <V2Dialog
            :open="startOpen"
            title="Start a recall"
            :confirm-label="
                skuWords ? `Start recall of ${skuWords}` : 'Start recall'
            "
            tone="danger"
            size="tall"
            :busy="busy"
            :dirty="startDirty"
            :confirm-disabled="startBlocked !== null"
            :confirm-disabled-reason="startBlocked ?? undefined"
            @update:open="(value) => (startOpen = value)"
            @confirm="submitStart"
        >
            <form
                class="flex flex-col gap-4"
                novalidate
                @submit.prevent="submitStart"
            >
                <p
                    v-if="startGeneral"
                    role="alert"
                    class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
                >
                    {{ startGeneral }}
                </p>
                <InventoryPickerField
                    :model-value="String(start.sku)"
                    kind="skus"
                    label="SKU"
                    required
                    :expected-branch-id="recalls.expectedBranchId"
                    :error="startErrors.sku_public_id"
                    @update:model-value="(value) => (start.sku = value)"
                    @picked="
                        (words, unit) => ((skuWords = words), (skuUnit = unit))
                    "
                />
                <fieldset
                    v-if="start.sku"
                    class="flex min-w-0 flex-col gap-2"
                    :aria-invalid="
                        startErrors.batch_public_ids ? 'true' : undefined
                    "
                    :aria-describedby="
                        startErrors.batch_public_ids
                            ? 'recall-batches-error'
                            : undefined
                    "
                    data-testid="recall-batches"
                >
                    <legend class="mb-1 text-sm font-medium">
                        Batches
                        <span class="text-destructive" aria-hidden="true"
                            >*</span
                        >
                    </legend>
                    <p class="text-xs text-muted-foreground">
                        Every batch of this item with a ticked number is
                        blocked, in any pack size.
                    </p>
                    <ul
                        v-if="selectedOptions.length"
                        class="flex flex-wrap gap-2"
                        aria-label="Ticked batches"
                    >
                        <li
                            v-for="option in selectedOptions"
                            :key="option.publicId"
                            class="inline-flex items-center gap-1 rounded-full border bg-muted/50 py-0.5 pr-1 pl-3 text-sm"
                        >
                            <span class="font-mono whitespace-nowrap">{{
                                unbroken(option.batchNumber)
                            }}</span>
                            <button
                                type="button"
                                class="inline-flex size-11 items-center justify-center rounded-full hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:size-8"
                                :aria-label="`Remove batch ${option.batchNumber}`"
                                @click="unselect(option.publicId)"
                            >
                                ×
                            </button>
                        </li>
                    </ul>
                    <input
                        v-model="batchSearch"
                        type="search"
                        aria-label="Search batches by number"
                        placeholder="Search batches by number"
                        class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]"
                    />
                    <p
                        v-if="options.length"
                        class="text-xs text-muted-foreground"
                        data-testid="recall-batches-showing"
                    >
                        Showing {{ visibleOptions.length }} of
                        {{ matchingOptions.length }}
                        {{ matchingOptions.length === 1 ? 'batch' : 'batches' }}
                    </p>
                    <p
                        v-if="optionsError"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ optionsError }}
                    </p>
                    <p
                        v-else-if="!options.length"
                        class="text-sm text-muted-foreground"
                    >
                        This SKU has no batches.
                    </p>
                    <ul class="flex flex-col divide-y rounded-[10px] border">
                        <li
                            v-for="option in visibleOptions"
                            :key="option.publicId"
                        >
                            <label
                                class="flex min-h-11 items-start gap-1 px-2 py-1 text-sm"
                                :class="
                                    option.recalled
                                        ? 'text-muted-foreground'
                                        : ''
                                "
                                ><span
                                    class="-my-1 inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center"
                                    ><Checkbox
                                        :model-value="
                                            start.batches.includes(
                                                option.publicId,
                                            )
                                        "
                                        :disabled="option.recalled"
                                        :aria-label="`Batch ${option.batchNumber}`"
                                        @update:model-value="
                                            (value) =>
                                                toggleBatch(
                                                    option.publicId,
                                                    value === true,
                                                )
                                        " /></span
                                ><span class="min-w-0 py-2"
                                    ><span
                                        class="font-mono whitespace-nowrap"
                                        >{{
                                            unbroken(option.batchNumber)
                                        }}</span
                                    ><span
                                        v-if="option.expiry"
                                        class="text-muted-foreground"
                                    >
                                        · exp {{ option.expiry }}</span
                                    >
                                    <V2Chip
                                        v-if="option.recalled"
                                        class="ml-1 whitespace-nowrap"
                                        tone="neutral"
                                        label="Already recalled"
                                    />
                                    <span
                                        v-else
                                        class="block text-xs text-muted-foreground"
                                        >{{
                                            option.onHand.length
                                                ? option.onHand
                                                      .map(
                                                          (o) =>
                                                              `${o.branch}: ${quantityWithUnit(o.quantity, skuUnit)}`,
                                                      )
                                                      .join(' · ')
                                                : 'None on hand'
                                        }}</span
                                    ></span
                                ></label
                            >
                        </li>
                    </ul>
                    <p
                        v-if="startErrors.batch_public_ids"
                        id="recall-batches-error"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ startErrors.batch_public_ids }}
                    </p>
                </fieldset>
                <V2OptionGroup
                    :model-value="start.reason || null"
                    label="Reason"
                    required
                    :options="reasonOptions"
                    :error="startErrors.reason"
                    @update:model-value="
                        (value) => {
                            start.reason = value ?? '';
                            showNote(start.reason, 'recall-note');
                        }
                    "
                />
                <label
                    class="flex flex-col gap-1.5 text-sm font-medium"
                    for="recall-reference"
                    >Notice reference (optional)
                    <span
                        id="recall-reference-hint"
                        class="text-xs font-normal text-muted-foreground"
                        >The supplier's or regulator's notice number. No patient
                        names or IC numbers. Only those who start or lift
                        recalls see it.</span
                    >
                    <input
                        id="recall-reference"
                        v-model="start.reference"
                        maxlength="60"
                        aria-describedby="recall-reference-hint"
                        class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px]"
                    />
                    <span
                        v-if="startErrors.reference"
                        role="alert"
                        class="text-sm font-normal text-destructive"
                        >{{ startErrors.reference }}</span
                    >
                </label>
                <label
                    class="flex flex-col gap-1.5 text-sm font-medium"
                    for="recall-note"
                    >Note{{
                        start.reason === 'other' ? ' (required)' : ' (optional)'
                    }}
                    <span
                        id="recall-note-hint"
                        class="text-xs font-normal text-muted-foreground"
                        >No patient names or IC numbers.</span
                    >
                    <textarea
                        id="recall-note"
                        v-model="start.note"
                        maxlength="300"
                        rows="3"
                        aria-describedby="recall-note-hint"
                        :aria-invalid="startErrors.note ? 'true' : undefined"
                        class="w-full min-w-0 rounded-[10px] border border-input bg-card px-3 py-2 text-base md:text-[15px]"
                    />
                    <span
                        v-if="startErrors.note"
                        role="alert"
                        class="text-sm font-normal text-destructive"
                        >{{ startErrors.note }}</span
                    >
                </label>
            </form>
            <template #footer-note>
                <p class="text-sm" role="status" data-testid="recall-confirm">
                    {{ startSentence }}
                </p>
            </template>
        </V2Dialog>

        <!-- Lift a recall: summary first, then the reason; what the press does beside the button. -->
        <V2Dialog
            :open="lifting !== null"
            title="Lift the recall?"
            confirm-label="Lift recall"
            :busy="busy"
            :dirty="lift.note.trim() !== ''"
            :confirm-disabled="liftBlocked !== null"
            :confirm-disabled-reason="liftBlocked ?? undefined"
            @update:open="(value) => !value && (lifting = null)"
            @confirm="submitLift"
        >
            <p
                v-if="liftGeneral"
                role="alert"
                class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
            >
                {{ liftGeneral }}
            </p>
            <V2KeyValueList :items="liftRows" />
            <V2OptionGroup
                :model-value="lift.reason || null"
                label="Reason"
                required
                :options="liftReasonOptions"
                :error="liftErrors.reason"
                @update:model-value="
                    (value) => {
                        lift.reason = value ?? '';
                        showNote(lift.reason, 'lift-note');
                    }
                "
            />
            <label
                class="flex flex-col gap-1.5 text-sm font-medium"
                for="lift-note"
                >Note{{
                    lift.reason === 'other' ? ' (required)' : ' (optional)'
                }}
                <span
                    id="lift-note-hint"
                    class="text-xs font-normal text-muted-foreground"
                    >No patient names or IC numbers.</span
                >
                <textarea
                    id="lift-note"
                    v-model="lift.note"
                    maxlength="300"
                    rows="3"
                    aria-describedby="lift-note-hint"
                    :aria-invalid="liftErrors.note ? 'true' : undefined"
                    class="w-full min-w-0 rounded-[10px] border border-input bg-card px-3 py-2 text-base md:text-[15px]"
                />
                <span
                    v-if="liftErrors.note"
                    role="alert"
                    class="text-sm font-normal text-destructive"
                    >{{ liftErrors.note }}</span
                >
            </label>
            <template #footer-note>
                <p class="text-sm" role="status">
                    {{ liftBatches }} can be used again at every branch, unless
                    a branch hold or expiry stops them. No stock moves.
                </p>
            </template>
        </V2Dialog>
    </PageFrame>
</template>
