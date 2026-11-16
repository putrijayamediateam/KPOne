<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import InventoryPickerField from '@/components/inventory-v2/InventoryPickerField.vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2Picker from '@/components/v2/V2Picker.vue';
import type { PickerOption } from '@/components/v2/V2Picker.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { quantityWithUnit } from '@/lib/inventory-v2-words';

/**
 * Version 2 Purchase requests (inventory V2 page 10b). Everything from the STK-18 comment to `lineWord` is a verbatim copy
 * of Classic (a test compares it). Only the layout is new: status tabs with counts, one table (cards below 1280px) with
 * each request's lines in its row, and V2 sheets for New request, Convert, Reject and Withdraw. The sheets call
 * Classic's `submitSend`, `submitConvert`, `submitReject` and `withdraw` unchanged.
 */
// STK-18: a CA or CA Supervisor asks for stock; another person converts it into a draft purchase order or rejects it.
type Line = {
    id: number;
    sku: string;
    item: string;
    stockUnit: string;
    asked: string;
    converted: string | null;
    note: string | null;
};
type PurchaseRequest = {
    publicId: string;
    number: string;
    status: 'waiting' | 'converted' | 'rejected' | 'withdrawn';
    lockVersion: number;
    location: string;
    preferredSupplier: { publicId: string; name: string } | null;
    requestedBy: string;
    requestedOn: string;
    requestedByYou: boolean;
    decidedBy: string | null;
    decidedOn: string | null;
    rejectReason: string | null;
    order: { number: string; status: string } | null;
    lines: Line[];
};
type PrefillLine = {
    skuPublicId: string;
    label: string;
    stockUnit: string;
    quantity: string;
};
type Tab = PurchaseRequest['status'];

const props = defineProps<{
    requests: {
        expectedBranchId: number;
        branchName: string;
        tab: Tab;
        can: { request: boolean; review: boolean };
        counts: Record<Tab, number>;
        requests: {
            page: number;
            lastPage: number;
            total: number;
            rows: PurchaseRequest[];
        };
        prefill: {
            location: { publicId: string; name: string };
            lines: PrefillLine[];
        } | null;
        locations: { publicId: string; name: string }[];
    };
}>();

const rejectReasons: Record<string, string> = {
    not_needed: 'Not needed',
    stock_elsewhere: 'Stock available elsewhere',
    budget: 'Budget',
    other: 'Other',
};
const statusLabel: Record<Tab, string> = {
    waiting: 'Waiting for review',
    converted: 'Converted',
    rejected: 'Rejected',
    withdrawn: 'Withdrawn',
};
const statusTone: Record<Tab, 'warning' | 'success' | 'danger' | 'neutral'> = {
    waiting: 'warning',
    converted: 'success',
    rejected: 'danger',
    withdrawn: 'neutral',
};

const visit = (query: Record<string, string | number>) =>
    router.get('/inventory/purchase-requests', query, {
        preserveScroll: true,
        preserveState: true,
    });
const busy = ref(false);
// Errors with no field of their own show at the top of the open dialog.
const generalError = (bag: Record<string, string>, fields: RegExp) =>
    Object.entries(bag).find(([key]) => !fields.test(key))?.[1] ?? null;

// New request.
type DraftLine = {
    key: string;
    sku: string | number;
    pinned: { value: string; label: string } | null;
    quantity: string;
    note: string;
};
const newLine = (): DraftLine => ({
    key: crypto.randomUUID(),
    sku: '',
    pinned: null,
    quantity: '',
    note: '',
});
const sendOpen = ref(false);
const send = reactive({
    location: '' as string | number,
    supplier: '' as string | number,
    lines: [newLine()] as DraftLine[],
    key: crypto.randomUUID(),
});
const sendErrors = ref<Record<string, string>>({});
const locationOptions = computed(() =>
    props.requests.locations.map((l) => ({ value: l.publicId, label: l.name })),
);
const openSend = () => {
    const prefill = props.requests.prefill;
    send.location =
        prefill?.location.publicId ??
        (props.requests.locations.length === 1
            ? props.requests.locations[0].publicId
            : '');
    send.supplier = '';
    send.lines = prefill?.lines.length
        ? prefill.lines.map((l) => ({
              key: crypto.randomUUID(),
              sku: l.skuPublicId,
              pinned: { value: l.skuPublicId, label: l.label },
              quantity: l.quantity,
              note: '',
          }))
        : [newLine()];
    send.key = crypto.randomUUID();
    sendErrors.value = {};
    sendOpen.value = true;
};
// Opened after the visit from Reorder suggestions has finished, so the navigation itself does not close it.
onMounted(() => {
    if (props.requests.prefill && props.requests.can.request) {
        window.setTimeout(openSend, 0);
    }
});
const removeLine = (index: number) => {
    send.lines.splice(index, 1);
    sendErrors.value = {};
};
watch(
    () => send.location,
    () => delete sendErrors.value.destination_location_public_id,
);
watch(
    () => send.lines.map((l) => `${l.sku}|${l.quantity}|${l.note}`).join(','),
    () => {
        for (const key of Object.keys(sendErrors.value)) {
            if (key.startsWith('lines')) {
                delete sendErrors.value[key];
            }
        }
    },
);
const filledLines = computed(() => send.lines.filter((l) => l.sku).length);
// Daniel: a neutral line, not amber; amber is kept for warnings.
const sendSummary = computed(() =>
    filledLines.value === 1
        ? '1 line will be sent for review.'
        : `${filledLines.value} lines will be sent for review.`,
);
const submitSend = () => {
    sendErrors.value = {};

    if (!send.location) {
        sendErrors.value.destination_location_public_id =
            'Choose where the stock should go.';
    }

    send.lines.forEach((line, index) => {
        if (!line.sku) {
            sendErrors.value[`lines.${index}.sku_public_id`] =
                'Choose the SKU.';
        }

        if (
            !/^\d+(\.\d{1,3})?$/.test(line.quantity.trim()) ||
            Number(line.quantity) <= 0
        ) {
            sendErrors.value[`lines.${index}.quantity`] =
                'Enter a quantity above zero.';
        }
    });

    if (!send.lines.length) {
        sendErrors.value.lines = 'Add at least one line.';
    }

    if (Object.keys(sendErrors.value).length) {
        return;
    }

    router.post(
        '/inventory/purchase-requests',
        {
            expected_branch_id: props.requests.expectedBranchId,
            idempotency_key: send.key,
            destination_location_public_id: send.location,
            preferred_supplier_public_id: send.supplier || null,
            lines: send.lines.map((l) => ({
                sku_public_id: l.sku,
                quantity: l.quantity.trim(),
                note: l.note.trim() || null,
            })),
        },
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
            onSuccess: () => (sendOpen.value = false),
            onError: (bag) => {
                sendErrors.value = bag as Record<string, string>;
                send.key = crypto.randomUUID();
            },
        },
    );
};
const sendGeneral = computed(() =>
    generalError(
        sendErrors.value,
        /^(lines\.\d+\.|destination_location_public_id$|preferred_supplier_public_id$)/,
    ),
);

// Convert into a draft purchase order.
const converting = ref<PurchaseRequest | null>(null);
const convert = reactive({
    supplier: '' as string | number,
    quantities: {} as Record<number, string>,
});
const convertErrors = ref<Record<string, string>>({});
const openConvert = (request: PurchaseRequest) => {
    convert.supplier = request.preferredSupplier?.publicId ?? '';
    convert.quantities = Object.fromEntries(
        request.lines.map((l) => [l.id, l.asked]),
    );
    convertErrors.value = {};
    converting.value = request;
};
const supplierPin = computed(() =>
    converting.value?.preferredSupplier
        ? [
              {
                  value: converting.value.preferredSupplier.publicId,
                  label: converting.value.preferredSupplier.name,
              },
          ]
        : [],
);
watch(
    () => convert.supplier,
    () => delete convertErrors.value.supplier_public_id,
);
watch(
    () => JSON.stringify(convert.quantities),
    () => {
        for (const key of Object.keys(convertErrors.value)) {
            if (key.startsWith('lines')) {
                delete convertErrors.value[key];
            }
        }
    },
);
const orderedLines = computed(
    () => Object.values(convert.quantities).filter((q) => Number(q) > 0).length,
);
const submitConvert = () => {
    const request = converting.value;

    if (!request) {
        return;
    }

    convertErrors.value = {};

    if (!convert.supplier) {
        convertErrors.value.supplier_public_id = 'Choose the supplier.';
    }

    request.lines.forEach((line, index) => {
        const value = (convert.quantities[line.id] ?? '').trim();

        if (!/^\d+(\.\d{1,3})?$/.test(value)) {
            convertErrors.value[`lines.${index}.quantity`] =
                'Enter a quantity, or 0 to leave the line out.';
        }
    });

    if (!Object.keys(convertErrors.value).length && orderedLines.value === 0) {
        convertErrors.value.lines =
            'At least one line needs a quantity above zero.';
    }

    if (Object.keys(convertErrors.value).length) {
        return;
    }

    router.post(
        `/inventory/purchase-requests/${request.publicId}/convert`,
        {
            expected_branch_id: props.requests.expectedBranchId,
            lock_version: request.lockVersion,
            supplier_public_id: convert.supplier,
            lines: request.lines.map((l) => ({
                line_id: l.id,
                quantity: (convert.quantities[l.id] ?? '').trim(),
            })),
        },
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
            onSuccess: () => (converting.value = null),
            onError: (bag) =>
                (convertErrors.value = bag as Record<string, string>),
        },
    );
};
const convertGeneral = computed(() =>
    generalError(convertErrors.value, /^(lines\.\d+\.|supplier_public_id$)/),
);

// Reject with a reason.
const rejecting = ref<PurchaseRequest | null>(null);
const rejectReason = ref('');
const rejectErrors = ref<Record<string, string>>({});
watch(rejectReason, () => delete rejectErrors.value.reason);
const openReject = (request: PurchaseRequest) => {
    rejectReason.value = '';
    rejectErrors.value = {};
    rejecting.value = request;
};
const submitReject = () => {
    const request = rejecting.value;

    if (!request) {
        return;
    }

    if (!rejectReason.value) {
        rejectErrors.value = { reason: 'Choose a reason.' };

        return;
    }

    router.post(
        `/inventory/purchase-requests/${request.publicId}/reject`,
        {
            expected_branch_id: props.requests.expectedBranchId,
            lock_version: request.lockVersion,
            reason: rejectReason.value,
        },
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
            onSuccess: () => (rejecting.value = null),
            onError: (bag) =>
                (rejectErrors.value = bag as Record<string, string>),
        },
    );
};
const rejectGeneral = computed(() =>
    generalError(rejectErrors.value, /^reason$/),
);

// Withdrawing cannot be undone, so it asks first.
const withdrawing = ref<PurchaseRequest | null>(null);
const withdrawError = ref('');
const withdraw = () => {
    const request = withdrawing.value;

    if (!request) {
        return;
    }

    router.post(
        `/inventory/purchase-requests/${request.publicId}/withdraw`,
        {
            expected_branch_id: props.requests.expectedBranchId,
            lock_version: request.lockVersion,
        },
        {
            preserveScroll: true,
            onStart: () => {
                busy.value = true;
                withdrawError.value = '';
            },
            onFinish: () => (busy.value = false),
            onSuccess: () => (withdrawing.value = null),
            onError: (bag) =>
                (withdrawError.value =
                    Object.values(bag)[0] ?? 'The request was not withdrawn.'),
        },
    );
};

const tabs = computed(() =>
    (['waiting', 'converted', 'rejected', 'withdrawn'] as Tab[]).map((t) => ({
        value: t,
        label: statusLabel[t],
        count: props.requests.counts[t],
    })),
);
const emptyText: Record<Tab, string> = {
    waiting: 'No request is waiting for review.',
    converted: 'No request has been converted yet.',
    rejected: 'No request has been rejected.',
    withdrawn: 'No request has been withdrawn.',
};
const lineWord = (n: number) => (n === 1 ? '1 line' : `${n} lines`);

// ---- V2 only below this line ----
usePageCrumbs(() => [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Purchase requests' },
]);
/** A code never breaks across lines ("SYN-SKU-1" stays whole). */
const unbroken = (code: string) => code.replaceAll('-', '\u2011');
const rejectOptions = Object.entries(rejectReasons).map(([value, label]) => ({
    value,
    label,
}));
/** Location types in words, as in Stock setup. */
const locationTypes: Record<string, string> = {
    medical_stock: 'Medical stock',
    branch_store: 'Branch store',
    dispensary: 'Dispensary',
};
/**
 * Classic's locations for the V2 picker, each with its code and type so locations that share a name are told apart
 * ("Synthetic Dispensary · SYN-DISP-I9WQX · Dispensary"). The server sends both; Classic reads only the name.
 */
const locationChoices = computed<PickerOption[]>(() =>
    locationOptions.value.map((option) => {
        const location = requestLocations().find(
            (l) => l.publicId === option.value,
        );

        return {
            id: option.value,
            label: option.label,
            detail: [
                location?.code ? unbroken(location.code) : null,
                location?.type
                    ? (locationTypes[location.type] ?? location.type)
                    : null,
            ]
                .filter(Boolean)
                .join(' · '),
        };
    }),
);
function requestLocations() {
    return props.requests.locations as {
        publicId: string;
        name: string;
        code?: string;
        type?: string;
    }[];
}
/** Send stays off until there is somewhere to deliver and at least one line with a SKU and a quantity. */
const lineReady = (line: DraftLine) =>
    !!line.sku &&
    /^\d+(\.\d{1,3})?$/.test(line.quantity.trim()) &&
    Number(line.quantity) > 0;
const sendBlockedReason = computed(() => {
    const where = !!send.location;
    const lines = send.lines.some(lineReady);

    return where && lines
        ? undefined
        : !where && !lines
          ? 'Choose where the stock should go, and add a SKU and a quantity to continue.'
          : !where
            ? 'Choose where the stock should go to continue.'
            : 'Add a SKU and a quantity to continue.';
});
const inputClass =
    'h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base md:text-[15px] lg:h-9';
const outlineButton =
    'inline-flex h-11 w-full items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto lg:h-9';
const filledButton =
    'inline-flex h-11 w-full items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto lg:h-9';
const askWithdraw = (request: PurchaseRequest) => {
    withdrawError.value = '';
    withdrawing.value = request;
};
</script>

<template>
    <Head title="Purchase requests" />
    <PageFrame>
        <Link
            href="/inventory"
            class="inline-flex h-11 w-fit items-center rounded-[10px] text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:hidden"
            data-action="back"
        >
            ← Inventory
        </Link>
        <V2WorkLayout
            title="Purchase requests"
            :description="`Ask for stock at ${requests.branchName}. Another person turns a request into a draft purchase order, which then goes through the usual approval.`"
        >
            <template v-if="requests.can.request" #actions>
                <button
                    type="button"
                    :class="filledButton"
                    data-testid="purchase-request-new"
                    @click="openSend"
                >
                    New request
                </button>
            </template>
            <template #main>
                <V2Tabs
                    :model-value="requests.tab"
                    :tabs="tabs"
                    ariaLabel="Request status"
                    @update:model-value="(tab) => visit({ tab })"
                />
                <V2Table
                    label="Purchase requests"
                    :columns="5"
                    cards="xl"
                    min-width="980px"
                    :empty="!requests.requests.rows.length"
                    :empty-message="emptyText[requests.tab]"
                >
                    <template #head>
                        <tr>
                            <th scope="col">Request</th>
                            <th scope="col">Status</th>
                            <th scope="col">Asked and decided</th>
                            <th scope="col" class="w-[32%]">Lines</th>
                            <th scope="col">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </template>
                    <template #body>
                        <tr
                            v-for="request in requests.requests.rows"
                            :key="request.publicId"
                            data-testid="purchase-request-card"
                        >
                            <td data-stack data-label="Request" data-primary>
                                <div class="min-w-0">
                                    <span
                                        class="block font-mono text-sm font-medium whitespace-nowrap"
                                        >{{ unbroken(request.number) }}</span
                                    >
                                    <span
                                        class="block text-xs break-words text-muted-foreground"
                                        >{{ lineWord(request.lines.length) }} ·
                                        to {{ request.location }}</span
                                    >
                                    <span
                                        v-if="request.preferredSupplier"
                                        class="block text-xs break-words text-muted-foreground"
                                        >Preferred supplier:
                                        {{
                                            request.preferredSupplier.name
                                        }}</span
                                    >
                                </div>
                            </td>
                            <td data-label="Status">
                                <V2Chip
                                    class="whitespace-nowrap"
                                    :tone="statusTone[request.status]"
                                    :label="statusLabel[request.status]"
                                />
                            </td>
                            <td data-stack data-label="Asked and decided">
                                <div class="min-w-0 text-sm">
                                    <span class="block break-words"
                                        >Asked {{ request.requestedOn }} by
                                        {{ request.requestedBy }}</span
                                    >
                                    <span
                                        v-if="request.decidedOn"
                                        class="block text-xs break-words text-muted-foreground"
                                        >{{ statusLabel[request.status] }}
                                        {{ request.decidedOn }} by
                                        {{ request.decidedBy
                                        }}<template v-if="request.rejectReason">
                                            ·
                                            {{
                                                rejectReasons[
                                                    request.rejectReason
                                                ] ?? request.rejectReason
                                            }}</template
                                        ></span
                                    >
                                    <span
                                        v-if="request.order"
                                        class="block text-xs break-words text-muted-foreground"
                                        >Draft order
                                        {{
                                            unbroken(request.order.number)
                                        }}</span
                                    >
                                </div>
                            </td>
                            <td data-stack data-label="Lines">
                                <ul
                                    class="flex min-w-0 flex-col gap-1.5 text-sm"
                                    :aria-label="`Lines of ${request.number}`"
                                >
                                    <li
                                        v-for="line in request.lines"
                                        :key="line.id"
                                        class="min-w-0"
                                    >
                                        <span class="block break-words"
                                            >{{ line.item }}
                                            <span
                                                class="font-mono text-xs text-muted-foreground"
                                                >{{ unbroken(line.sku) }}</span
                                            ></span
                                        >
                                        <span
                                            class="block text-xs text-muted-foreground tabular-nums"
                                            >Asked
                                            {{
                                                quantityWithUnit(
                                                    line.asked,
                                                    line.stockUnit,
                                                )
                                            }}<template
                                                v-if="
                                                    request.status ===
                                                    'converted'
                                                "
                                            >
                                                ·
                                                {{
                                                    line.converted === '0'
                                                        ? 'Left out'
                                                        : `Ordered ${quantityWithUnit(line.converted, line.stockUnit)}`
                                                }}</template
                                            ></span
                                        >
                                        <span
                                            v-if="line.note"
                                            class="block text-xs break-words text-muted-foreground"
                                            >{{ line.note }}</span
                                        >
                                    </li>
                                </ul>
                            </td>
                            <td data-actions>
                                <div
                                    v-if="request.status === 'waiting'"
                                    class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:flex-wrap sm:justify-end"
                                >
                                    <button
                                        v-if="
                                            request.requestedByYou &&
                                            requests.can.request
                                        "
                                        type="button"
                                        :class="outlineButton"
                                        :disabled="busy"
                                        :aria-label="`Withdraw request ${request.number}`"
                                        @click="askWithdraw(request)"
                                    >
                                        Withdraw
                                    </button>
                                    <template v-if="requests.can.review">
                                        <p
                                            v-if="request.requestedByYou"
                                            class="text-xs text-muted-foreground"
                                            data-testid="purchase-request-own"
                                        >
                                            You sent this request, so another
                                            person must review it.
                                        </p>
                                        <template v-else>
                                            <button
                                                type="button"
                                                :class="outlineButton"
                                                :aria-label="`Convert ${request.number} into a draft purchase order`"
                                                @click="openConvert(request)"
                                            >
                                                Convert to draft order
                                            </button>
                                            <button
                                                type="button"
                                                :class="outlineButton"
                                                :aria-label="`Reject ${request.number}`"
                                                @click="openReject(request)"
                                            >
                                                Reject
                                            </button>
                                        </template>
                                    </template>
                                </div>
                            </td>
                        </tr>
                    </template>
                </V2Table>
                <V2Pager
                    v-if="requests.requests.lastPage > 1"
                    :current-page="requests.requests.page"
                    :last-page="requests.requests.lastPage"
                    :total="requests.requests.total"
                    :per-page="25"
                    @change="(page) => visit({ tab: requests.tab, page })"
                />
            </template>
        </V2WorkLayout>

        <V2Dialog
            :open="sendOpen"
            title="New purchase request"
            description="Another person reviews it and turns it into a draft purchase order."
            confirm-label="Send for review"
            :busy="busy"
            :confirm-disabled="sendBlockedReason !== undefined"
            :confirm-disabled-reason="sendBlockedReason"
            size="tall"
            @update:open="(value) => (sendOpen = value)"
            @confirm="submitSend"
        >
            <div class="flex flex-col gap-4 pb-8">
                <p
                    v-if="sendGeneral"
                    role="alert"
                    class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
                >
                    {{ sendGeneral }}
                </p>
                <V2Picker
                    :model-value="send.location ? String(send.location) : null"
                    label="Deliver to"
                    required
                    placeholder="Choose a location"
                    :selected-label="
                        requests.locations.find(
                            (l) => l.publicId === String(send.location),
                        )?.name
                    "
                    :options="locationChoices"
                    :error="sendErrors.destination_location_public_id"
                    @update:model-value="
                        (value) => (send.location = value ?? '')
                    "
                />
                <InventoryPickerField
                    :model-value="String(send.supplier)"
                    kind="suppliers"
                    label="Preferred supplier (optional)"
                    :expected-branch-id="requests.expectedBranchId"
                    :error="sendErrors.preferred_supplier_public_id"
                    @update:model-value="(value) => (send.supplier = value)"
                />
                <fieldset class="flex min-w-0 flex-col gap-3">
                    <legend class="mb-1 text-sm font-medium">Lines</legend>
                    <div
                        v-for="(line, index) in send.lines"
                        :key="line.key"
                        class="flex min-w-0 flex-col gap-3 rounded-[14px] border p-3"
                        data-testid="purchase-request-line"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-medium">
                                Line {{ index + 1 }}
                            </p>
                            <button
                                v-if="send.lines.length > 1"
                                type="button"
                                class="inline-flex h-11 min-w-11 items-center justify-center rounded-[10px] px-3 text-sm font-medium text-destructive hover:bg-destructive/10 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                                data-testid="purchase-request-remove-line"
                                :aria-label="`Remove line ${index + 1}`"
                                @click="removeLine(index)"
                            >
                                Remove
                            </button>
                        </div>
                        <InventoryPickerField
                            :model-value="String(line.sku)"
                            kind="skus"
                            :label="`SKU for line ${index + 1}`"
                            required
                            :selected-label="line.pinned?.label ?? ''"
                            :expected-branch-id="requests.expectedBranchId"
                            :error="sendErrors[`lines.${index}.sku_public_id`]"
                            @update:model-value="(value) => (line.sku = value)"
                        />
                        <div class="grid gap-3 sm:grid-cols-[10rem_1fr]">
                            <V2Field
                                v-slot="{ id, describedBy }"
                                label="Quantity"
                                required
                                :error="sendErrors[`lines.${index}.quantity`]"
                            >
                                <input
                                    :id="id"
                                    v-model="line.quantity"
                                    inputmode="decimal"
                                    :aria-describedby="describedBy"
                                    :aria-invalid="
                                        sendErrors[`lines.${index}.quantity`]
                                            ? 'true'
                                            : undefined
                                    "
                                    :class="inputClass"
                                />
                            </V2Field>
                            <V2Field
                                v-slot="{ id, describedBy }"
                                label="Note"
                                hint="Optional"
                                :error="sendErrors[`lines.${index}.note`]"
                            >
                                <input
                                    :id="id"
                                    v-model="line.note"
                                    maxlength="200"
                                    :aria-describedby="describedBy"
                                    :class="inputClass"
                                />
                            </V2Field>
                        </div>
                    </div>
                    <button
                        v-if="send.lines.length < 30"
                        type="button"
                        :class="outlineButton"
                        @click="send.lines.push(newLine())"
                    >
                        Add a line
                    </button>
                    <p
                        v-if="sendErrors.lines"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ sendErrors.lines }}
                    </p>
                </fieldset>
            </div>
            <template #footer-note>
                <p
                    class="text-sm"
                    role="status"
                    data-testid="purchase-request-summary"
                >
                    {{ sendSummary }}
                </p>
            </template>
        </V2Dialog>

        <V2Dialog
            :open="converting !== null"
            :title="`Convert ${converting?.number ?? ''} to a draft order?`"
            description="You can change a quantity, or enter 0 to leave a line out. The asked quantity stays on the request."
            confirm-label="Create draft order"
            :busy="busy"
            size="tall"
            @update:open="(open) => !open && (converting = null)"
            @confirm="submitConvert"
        >
            <div v-if="converting" class="flex flex-col gap-4 pb-8">
                <p
                    v-if="convertGeneral"
                    role="alert"
                    class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
                >
                    {{ convertGeneral }}
                </p>
                <InventoryPickerField
                    :model-value="String(convert.supplier)"
                    kind="suppliers"
                    label="Supplier"
                    required
                    :selected-label="supplierPin[0]?.label ?? ''"
                    :expected-branch-id="requests.expectedBranchId"
                    :error="convertErrors.supplier_public_id"
                    @update:model-value="(value) => (convert.supplier = value)"
                />
                <div
                    v-for="(line, index) in converting.lines"
                    :key="line.id"
                    class="grid items-end gap-3 rounded-[14px] border p-3 sm:grid-cols-[1fr_10rem]"
                >
                    <div class="min-w-0 text-sm">
                        <p class="font-medium break-words">
                            {{ line.item }}
                            <span
                                class="font-mono text-xs font-normal text-muted-foreground"
                                >{{ unbroken(line.sku) }}</span
                            >
                        </p>
                        <p
                            class="text-muted-foreground tabular-nums"
                            data-testid="convert-asked"
                        >
                            Asked
                            {{ quantityWithUnit(line.asked, line.stockUnit) }}
                        </p>
                    </div>
                    <V2Field
                        v-slot="{ id, describedBy }"
                        :label="`Order (${line.stockUnit})`"
                        :error="convertErrors[`lines.${index}.quantity`]"
                    >
                        <input
                            :id="id"
                            v-model="convert.quantities[line.id]"
                            inputmode="decimal"
                            :aria-describedby="describedBy"
                            :aria-invalid="
                                convertErrors[`lines.${index}.quantity`]
                                    ? 'true'
                                    : undefined
                            "
                            :class="inputClass"
                        />
                    </V2Field>
                </div>
                <p
                    v-if="convertErrors.lines"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ convertErrors.lines }}
                </p>
            </div>
            <template #footer-note>
                <p class="text-sm" role="status">
                    {{ lineWord(orderedLines) }} will go on the draft order. The
                    order still needs its usual approval.
                </p>
            </template>
        </V2Dialog>

        <V2Dialog
            :open="rejecting !== null"
            :title="`Reject ${rejecting?.number ?? ''}?`"
            description="The person who sent it sees the reason."
            confirm-label="Reject request"
            tone="danger"
            :busy="busy"
            :confirm-disabled="rejectReason === ''"
            :confirm-disabled-reason="
                rejectReason === '' ? 'Pick a reason to continue.' : undefined
            "
            @update:open="(open) => !open && (rejecting = null)"
            @confirm="submitReject"
        >
            <p
                v-if="rejectGeneral"
                role="alert"
                class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
            >
                {{ rejectGeneral }}
            </p>
            <V2OptionGroup
                :model-value="rejectReason || null"
                label="Reason"
                required
                :options="rejectOptions"
                :error="rejectErrors.reason"
                @update:model-value="(value) => (rejectReason = value ?? '')"
            />
            <template #footer-note>
                <p class="text-sm" role="status">
                    Closes the request. No order is made and no stock changes.
                </p>
            </template>
        </V2Dialog>

        <V2Dialog
            :open="withdrawing !== null"
            :title="`Withdraw ${withdrawing?.number ?? ''}?`"
            description="Nobody can convert it after this. You can send a new request later."
            confirm-label="Withdraw request"
            tone="danger"
            :busy="busy"
            @update:open="(open) => !open && (withdrawing = null)"
            @confirm="withdraw"
        >
            <p
                v-if="withdrawError"
                role="alert"
                class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
            >
                {{ withdrawError }}
            </p>
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
