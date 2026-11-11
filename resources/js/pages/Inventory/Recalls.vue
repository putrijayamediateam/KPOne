<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import InventorySearchSelect from '@/components/inventory/InventorySearchSelect.vue';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTabs } from '@/components/ui/tabs';
import { requestJson } from '@/lib/json-client';

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
        can: { start: boolean; lift: boolean };
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
</script>

<template>
    <Head title="Recalls" />
    <main class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-7">
        <ActionLink
            href="/inventory"
            variant="ghost"
            class="-ml-3 min-h-11 self-start"
            ><ArrowLeft class="size-4" aria-hidden="true" />
            Inventory</ActionLink
        >
        <PageHeader
            title="Recalls"
            :description="`A recall blocks named batches at every branch. It moves no stock. ${recalls.allBranches ? 'Stock is shown for every branch.' : `Stock is shown for ${recalls.branchName}.`}`"
        />
        <div class="flex flex-wrap items-center justify-between gap-3">
            <OperationalTabs
                :model-value="recalls.tab"
                :tabs="tabs"
                label="Recall status"
                class="min-w-0 max-lg:[&>button]:h-11 max-sm:[&>button]:px-2.5"
                @update:model-value="(tab) => visit({ tab })"
            />
            <Button
                v-if="recalls.can.start"
                type="button"
                class="min-h-11 w-full sm:w-auto"
                data-testid="recall-start"
                @click="openStart"
                >Start a recall</Button
            >
        </div>

        <p
            v-if="!recalls.recalls.rows.length"
            class="rounded-md border p-4 text-sm text-muted-foreground"
        >
            {{
                recalls.tab === 'active'
                    ? 'No batch is under a recall.'
                    : 'No recall has been lifted yet.'
            }}
        </p>
        <ul v-else class="space-y-3" aria-label="Recalls">
            <li
                v-for="recall in recalls.recalls.rows"
                :key="recall.publicId"
                class="space-y-3 rounded-md border p-4"
                data-testid="recall-card"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h2 class="font-semibold break-words">
                            {{ recall.medicine }}
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            {{ recall.skuCode }} ·
                            {{ batchWord(recall.batches.length) }}
                        </p>
                    </div>
                    <StatusBadge
                        :status="recall.status"
                        :label="
                            recall.status === 'active' ? 'Recalled' : 'Lifted'
                        "
                        :tone="
                            recall.status === 'active' ? 'danger' : 'neutral'
                        "
                    />
                </div>
                <ul class="flex flex-wrap gap-2" aria-label="Recalled batches">
                    <li
                        v-for="batch in recall.batches"
                        :key="batch.key"
                        class="rounded-md border px-2 py-1 text-sm"
                    >
                        {{ batch.batchNumber
                        }}<span
                            v-if="batch.expiry"
                            class="text-muted-foreground"
                        >
                            · exp {{ batch.expiry }}</span
                        >
                    </li>
                </ul>
                <dl class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="me-1 inline font-medium">Reason:</dt>
                        <dd class="inline">
                            {{ reasons[recall.reason] ?? recall.reason
                            }}<template v-if="recall.reference">
                                · {{ recall.reference }}</template
                            >
                        </dd>
                    </div>
                    <div>
                        <dt class="me-1 inline font-medium">Started:</dt>
                        <dd class="inline">
                            {{ recall.startedOn }} by {{ recall.startedBy }}
                        </dd>
                    </div>
                    <div v-if="recall.note" class="sm:col-span-2">
                        <dt class="me-1 inline font-medium">Note:</dt>
                        <dd class="inline break-words">{{ recall.note }}</dd>
                    </div>
                    <div
                        v-if="recall.status === 'lifted'"
                        class="sm:col-span-2"
                    >
                        <dt class="me-1 inline font-medium">Lifted:</dt>
                        <dd class="inline">
                            {{ recall.liftedOn }} by {{ recall.liftedBy }} ·
                            {{
                                liftReasons[recall.liftReason ?? ''] ??
                                recall.liftReason
                            }}<template v-if="recall.liftNote">
                                · {{ recall.liftNote }}</template
                            >
                        </dd>
                    </div>
                </dl>

                <section
                    v-if="recall.status === 'active'"
                    class="space-y-2"
                    :aria-label="`Where ${recall.medicine} is`"
                >
                    <h3 class="text-sm font-semibold">Where it is now</h3>
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
                    <ul v-else class="space-y-1 text-sm">
                        <li
                            v-for="(where, index) in recall.whereIs"
                            :key="index"
                            class="flex flex-wrap justify-between gap-2 border-b py-1 last:border-0"
                        >
                            <span class="min-w-0 break-words"
                                ><span v-if="recalls.allBranches"
                                    >{{ where.branch }} · </span
                                >{{ where.location }} · {{ where.skuCode }} ·
                                batch {{ where.batchNumber
                                }}<span
                                    v-if="where.returns"
                                    class="text-muted-foreground"
                                >
                                    (patient returns)</span
                                ></span
                            >
                            <span class="font-medium tabular-nums"
                                >{{ where.quantity }}
                                {{ where.stockUnit }}</span
                            >
                        </li>
                    </ul>
                    <div class="flex flex-wrap gap-2">
                        <ActionLink
                            href="/inventory/writeoffs"
                            variant="outline"
                            class="min-h-11 lg:min-h-9"
                            >Write off stock</ActionLink
                        >
                        <ActionLink
                            href="/inventory/supplier-returns"
                            variant="outline"
                            class="min-h-11 lg:min-h-9"
                            >Return to supplier</ActionLink
                        >
                        <template v-if="recalls.can.lift">
                            <p
                                v-if="recall.startedByYou"
                                class="self-center text-sm text-muted-foreground"
                            >
                                You started this recall, so another person must
                                lift it.
                            </p>
                            <Button
                                v-else
                                type="button"
                                variant="outline"
                                class="min-h-11 lg:min-h-9"
                                :aria-label="`Lift the recall of ${recall.medicine}`"
                                @click="openLift(recall)"
                                >Lift recall</Button
                            >
                        </template>
                    </div>
                </section>
            </li>
        </ul>
        <CompactPagination
            v-if="recalls.recalls.lastPage > 1"
            :current-page="recalls.recalls.page"
            :last-page="recalls.recalls.lastPage"
            :total="recalls.recalls.total"
            @change="(page) => visit({ tab: recalls.tab, page })"
        />

        <Dialog v-model:open="startOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Start a recall</DialogTitle>
                    <DialogDescription
                        >The ticked batches are blocked at every branch until
                        another person lifts the recall. No stock
                        moves.</DialogDescription
                    >
                </DialogHeader>
                <form
                    class="space-y-3"
                    novalidate
                    @submit.prevent="submitStart"
                >
                    <p
                        v-if="startGeneral"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ startGeneral }}
                    </p>
                    <p class="text-sm font-medium">SKU</p>
                    <InventorySearchSelect
                        id="recall-sku"
                        v-model="start.sku"
                        label="SKU"
                        :options="[]"
                        kind="skus"
                        context="operations"
                        :expected-branch-id="recalls.expectedBranchId"
                    />
                    <InputError
                        id="recall-sku-error"
                        :message="startErrors.sku_public_id"
                    />
                    <fieldset
                        v-if="start.sku"
                        class="space-y-2"
                        :aria-invalid="
                            startErrors.batch_public_ids ? 'true' : undefined
                        "
                        :aria-describedby="
                            startErrors.batch_public_ids
                                ? 'recall-batches-error'
                                : undefined
                        "
                    >
                        <legend class="text-sm font-medium">Batches</legend>
                        <p class="text-sm text-muted-foreground">
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
                                class="flex items-center gap-1 rounded-md border bg-muted pl-2 text-sm"
                            >
                                {{ option.batchNumber }}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    class="size-11 lg:size-9"
                                    :aria-label="`Remove batch ${option.batchNumber}`"
                                    @click="unselect(option.publicId)"
                                    >×</Button
                                >
                            </li>
                        </ul>
                        <Input
                            v-model="batchSearch"
                            type="search"
                            class="min-h-11"
                            aria-label="Search batches by number"
                            placeholder="Search batches by number"
                        />
                        <p
                            v-if="options.length"
                            class="text-sm text-muted-foreground"
                            data-testid="recall-batches-showing"
                        >
                            Showing {{ visibleOptions.length }} of
                            {{ matchingOptions.length }}
                            {{
                                matchingOptions.length === 1
                                    ? 'batch'
                                    : 'batches'
                            }}
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
                        <label
                            v-for="option in visibleOptions"
                            :key="option.publicId"
                            class="flex min-h-11 items-start gap-3 rounded-md border p-2 text-sm"
                            :class="option.recalled ? 'opacity-60' : ''"
                        >
                            <input
                                v-model="start.batches"
                                type="checkbox"
                                class="mt-1 size-4"
                                :value="option.publicId"
                                :disabled="option.recalled"
                            />
                            <span class="min-w-0">
                                <span class="font-medium">{{
                                    option.batchNumber
                                }}</span
                                ><span
                                    v-if="option.expiry"
                                    class="text-muted-foreground"
                                >
                                    · exp {{ option.expiry }}</span
                                >
                                <span
                                    v-if="option.recalled"
                                    class="block text-muted-foreground"
                                    >Already recalled</span
                                >
                                <span
                                    v-else
                                    class="block text-muted-foreground"
                                    >{{
                                        option.onHand.length
                                            ? option.onHand
                                                  .map(
                                                      (o) =>
                                                          `${o.branch}: ${o.quantity}`,
                                                  )
                                                  .join(' · ')
                                            : 'None on hand'
                                    }}</span
                                >
                            </span>
                        </label>
                        <InputError
                            id="recall-batches-error"
                            :message="startErrors.batch_public_ids"
                        />
                    </fieldset>
                    <label class="block text-sm font-medium" for="recall-reason"
                        >Reason</label
                    >
                    <select
                        id="recall-reason"
                        v-model="start.reason"
                        class="min-h-11 w-full rounded-md border bg-background px-3 text-sm text-foreground"
                        :aria-invalid="startErrors.reason ? 'true' : undefined"
                        :aria-describedby="
                            startErrors.reason
                                ? 'recall-reason-error'
                                : undefined
                        "
                    >
                        <option value="" disabled>Choose a reason</option>
                        <option
                            v-for="(label, value) in reasons"
                            :key="value"
                            :value="value"
                        >
                            {{ label }}
                        </option>
                    </select>
                    <InputError
                        id="recall-reason-error"
                        :message="startErrors.reason"
                    />
                    <label
                        class="block text-sm font-medium"
                        for="recall-reference"
                        >Notice reference (optional)</label
                    >
                    <p
                        id="recall-reference-hint"
                        class="text-sm text-muted-foreground"
                    >
                        The supplier's or regulator's notice number. No patient
                        names or IC numbers. Only those who start or lift
                        recalls see it.
                    </p>
                    <Input
                        id="recall-reference"
                        v-model="start.reference"
                        maxlength="60"
                        class="min-h-11"
                        :aria-describedby="
                            startErrors.reference
                                ? 'recall-reference-hint recall-reference-error'
                                : 'recall-reference-hint'
                        "
                    />
                    <InputError
                        id="recall-reference-error"
                        :message="startErrors.reference"
                    />
                    <label class="block text-sm font-medium" for="recall-note"
                        >Note{{
                            start.reason === 'other' ? '' : ' (optional)'
                        }}</label
                    >
                    <p
                        id="recall-note-hint"
                        class="text-sm text-muted-foreground"
                    >
                        No patient names or IC numbers.
                    </p>
                    <textarea
                        id="recall-note"
                        v-model="start.note"
                        maxlength="300"
                        rows="3"
                        class="w-full rounded-md border bg-background p-3 text-sm text-foreground"
                        :aria-describedby="
                            startErrors.note
                                ? 'recall-note-hint recall-note-error'
                                : 'recall-note-hint'
                        "
                        :aria-invalid="startErrors.note ? 'true' : undefined"
                    />
                    <InputError
                        id="recall-note-error"
                        :message="startErrors.note"
                    />
                    <p
                        v-if="start.batches.length"
                        class="rounded-md border border-warning bg-warning/10 p-3 text-sm font-medium text-warning-strong"
                        data-testid="recall-confirm"
                    >
                        {{ startSummary }}
                    </p>
                    <div
                        class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11 lg:min-h-9"
                            @click="startOpen = false"
                            >Cancel</Button
                        >
                        <Button
                            type="submit"
                            variant="destructive"
                            class="min-h-11 lg:min-h-9"
                            :disabled="busy"
                            >Start recall</Button
                        >
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="lifting !== null"
            @update:open="(open) => !open && (lifting = null)"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Lift recall</DialogTitle>
                    <DialogDescription
                        >{{ lifting?.medicine }}. {{ liftBatches }} can be used
                        again at every branch, unless something else blocks
                        it.</DialogDescription
                    >
                </DialogHeader>
                <form class="space-y-3" novalidate @submit.prevent="submitLift">
                    <p
                        v-if="liftGeneral"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ liftGeneral }}
                    </p>
                    <label class="block text-sm font-medium" for="lift-reason"
                        >Reason</label
                    >
                    <select
                        id="lift-reason"
                        v-model="lift.reason"
                        class="min-h-11 w-full rounded-md border bg-background px-3 text-sm text-foreground"
                        :aria-invalid="liftErrors.reason ? 'true' : undefined"
                        :aria-describedby="
                            liftErrors.reason ? 'lift-reason-error' : undefined
                        "
                    >
                        <option value="" disabled>Choose a reason</option>
                        <option
                            v-for="(label, value) in liftReasons"
                            :key="value"
                            :value="value"
                        >
                            {{ label }}
                        </option>
                    </select>
                    <InputError
                        id="lift-reason-error"
                        :message="liftErrors.reason"
                    />
                    <label class="block text-sm font-medium" for="lift-note"
                        >Note{{
                            lift.reason === 'other' ? '' : ' (optional)'
                        }}</label
                    >
                    <p
                        id="lift-note-hint"
                        class="text-sm text-muted-foreground"
                    >
                        No patient names or IC numbers.
                    </p>
                    <textarea
                        id="lift-note"
                        v-model="lift.note"
                        maxlength="300"
                        rows="3"
                        class="w-full rounded-md border bg-background p-3 text-sm text-foreground"
                        :aria-describedby="
                            liftErrors.note
                                ? 'lift-note-hint lift-note-error'
                                : 'lift-note-hint'
                        "
                        :aria-invalid="liftErrors.note ? 'true' : undefined"
                    />
                    <InputError
                        id="lift-note-error"
                        :message="liftErrors.note"
                    />
                    <div
                        class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11 lg:min-h-9"
                            @click="lifting = null"
                            >Cancel</Button
                        >
                        <Button
                            type="submit"
                            class="min-h-11 lg:min-h-9"
                            :disabled="busy"
                            >Lift recall</Button
                        >
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </main>
</template>
