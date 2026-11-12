<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, onMounted, reactive, ref, watch } from 'vue';
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
import { OperationalSelect } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTabs } from '@/components/ui/tabs';

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
const pageError = ref<string | null>(null);

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
onMounted(() => {
    if (props.requests.prefill && props.requests.can.request) {
        openSend();
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

const withdraw = (request: PurchaseRequest) =>
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
                pageError.value = null;
            },
            onFinish: () => (busy.value = false),
            onError: (bag) => (pageError.value = Object.values(bag)[0] ?? null),
        },
    );

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
</script>

<template>
    <Head title="Purchase requests" />
    <main class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-7">
        <ActionLink
            href="/inventory"
            variant="ghost"
            class="-ml-3 min-h-11 self-start"
            ><ArrowLeft class="size-4" aria-hidden="true" />
            Inventory</ActionLink
        >
        <PageHeader
            title="Purchase requests"
            :description="`Ask for stock at ${requests.branchName}. Another person turns a request into a draft purchase order, which then goes through the usual approval.`"
        />
        <div class="flex flex-wrap items-center justify-between gap-3">
            <OperationalTabs
                :model-value="requests.tab"
                :tabs="tabs"
                label="Request status"
                class="min-w-0 max-lg:[&>button]:h-11 max-sm:[&>button]:px-2.5"
                @update:model-value="(tab) => visit({ tab })"
            />
            <Button
                v-if="requests.can.request"
                type="button"
                class="min-h-11 w-full sm:w-auto"
                data-testid="purchase-request-new"
                @click="openSend"
                >New request</Button
            >
        </div>
        <p v-if="pageError" role="alert" class="text-sm text-destructive">
            {{ pageError }}
        </p>

        <p
            v-if="!requests.requests.rows.length"
            class="rounded-md border p-4 text-sm text-muted-foreground"
        >
            {{ emptyText[requests.tab] }}
        </p>
        <ul v-else class="space-y-3" aria-label="Purchase requests">
            <li
                v-for="request in requests.requests.rows"
                :key="request.publicId"
                class="space-y-3 rounded-md border p-4"
                data-testid="purchase-request-card"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h2 class="font-semibold break-words">
                            {{ request.number }}
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            {{ lineWord(request.lines.length) }} · to
                            {{ request.location }}
                        </p>
                    </div>
                    <StatusBadge
                        :status="request.status"
                        :label="statusLabel[request.status]"
                        :tone="statusTone[request.status]"
                    />
                </div>
                <dl class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="me-1 inline font-medium">Asked:</dt>
                        <dd class="inline">
                            {{ request.requestedOn }} by
                            {{ request.requestedBy }}
                        </dd>
                    </div>
                    <div v-if="request.preferredSupplier">
                        <dt class="me-1 inline font-medium">
                            Preferred supplier:
                        </dt>
                        <dd class="inline break-words">
                            {{ request.preferredSupplier.name }}
                        </dd>
                    </div>
                    <div v-if="request.decidedOn" class="sm:col-span-2">
                        <dt class="me-1 inline font-medium">
                            {{ statusLabel[request.status] }}:
                        </dt>
                        <dd class="inline">
                            {{ request.decidedOn }} by {{ request.decidedBy
                            }}<template v-if="request.rejectReason">
                                ·
                                {{
                                    rejectReasons[request.rejectReason] ??
                                    request.rejectReason
                                }}</template
                            >
                        </dd>
                    </div>
                    <div v-if="request.order" class="sm:col-span-2">
                        <dt class="me-1 inline font-medium">Draft order:</dt>
                        <dd class="inline">
                            {{ request.order.number }}
                        </dd>
                    </div>
                </dl>
                <table class="w-full text-sm">
                    <caption class="sr-only">
                        Lines of
                        {{
                            request.number
                        }}
                    </caption>
                    <thead>
                        <tr class="border-b text-left text-muted-foreground">
                            <th scope="col" class="py-1 pr-2 font-medium">
                                Item
                            </th>
                            <th
                                scope="col"
                                class="py-1 pr-2 text-right font-medium"
                            >
                                Asked
                            </th>
                            <th
                                v-if="request.status === 'converted'"
                                scope="col"
                                class="py-1 text-right font-medium"
                            >
                                Ordered
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="line in request.lines"
                            :key="line.id"
                            class="border-b align-top last:border-0"
                        >
                            <td class="py-1 pr-2 break-words">
                                {{ line.sku }} · {{ line.item }}
                                <span
                                    v-if="line.note"
                                    class="block text-muted-foreground"
                                    >{{ line.note }}</span
                                >
                            </td>
                            <td class="py-1 pr-2 text-right tabular-nums">
                                {{ line.asked }} {{ line.stockUnit }}
                            </td>
                            <td
                                v-if="request.status === 'converted'"
                                class="py-1 text-right tabular-nums"
                            >
                                {{
                                    line.converted === '0'
                                        ? 'Left out'
                                        : `${line.converted} ${line.stockUnit}`
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div
                    v-if="request.status === 'waiting'"
                    class="flex flex-wrap items-center gap-2"
                >
                    <Button
                        v-if="request.requestedByYou && requests.can.request"
                        type="button"
                        variant="outline"
                        class="min-h-11 lg:min-h-9"
                        :disabled="busy"
                        :aria-label="`Withdraw ${request.number}`"
                        @click="withdraw(request)"
                        >Withdraw</Button
                    >
                    <template v-if="requests.can.review">
                        <p
                            v-if="request.requestedByYou"
                            class="text-sm text-muted-foreground"
                            data-testid="purchase-request-own"
                        >
                            You sent this request, so another person must review
                            it.
                        </p>
                        <template v-else>
                            <Button
                                type="button"
                                class="min-h-11 lg:min-h-9"
                                :aria-label="`Convert ${request.number} into a draft purchase order`"
                                @click="openConvert(request)"
                                >Convert to draft order</Button
                            >
                            <Button
                                type="button"
                                variant="outline"
                                class="min-h-11 lg:min-h-9"
                                :aria-label="`Reject ${request.number}`"
                                @click="openReject(request)"
                                >Reject</Button
                            >
                        </template>
                    </template>
                </div>
            </li>
        </ul>
        <CompactPagination
            v-if="requests.requests.lastPage > 1"
            :current-page="requests.requests.page"
            :last-page="requests.requests.lastPage"
            :total="requests.requests.total"
            @change="(page) => visit({ tab: requests.tab, page })"
        />

        <Dialog v-model:open="sendOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>New purchase request</DialogTitle>
                    <DialogDescription
                        >Another person reviews it and turns it into a draft
                        purchase order.</DialogDescription
                    >
                </DialogHeader>
                <form class="space-y-3" novalidate @submit.prevent="submitSend">
                    <p
                        v-if="sendGeneral"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ sendGeneral }}
                    </p>
                    <div class="space-y-1">
                        <label
                            for="request-location"
                            class="text-sm font-medium"
                            >Deliver to</label
                        >
                        <OperationalSelect
                            id="request-location"
                            v-model="send.location"
                            label="Deliver to"
                            placeholder="Choose a location"
                            trigger-class="min-h-11"
                            :options="locationOptions"
                            :invalid="
                                !!sendErrors.destination_location_public_id
                            "
                            describedby="request-location-error"
                        />
                        <InputError
                            id="request-location-error"
                            :message="sendErrors.destination_location_public_id"
                        />
                    </div>
                    <div class="space-y-1">
                        <p class="text-sm font-medium">
                            Preferred supplier (optional)
                        </p>
                        <InventorySearchSelect
                            id="request-supplier"
                            v-model="send.supplier"
                            label="Preferred supplier"
                            :options="[]"
                            kind="suppliers"
                            context="operations"
                            :expected-branch-id="requests.expectedBranchId"
                        />
                        <InputError
                            :message="sendErrors.preferred_supplier_public_id"
                        />
                    </div>
                    <fieldset class="space-y-3">
                        <legend class="text-sm font-medium">Lines</legend>
                        <div
                            v-for="(line, index) in send.lines"
                            :key="line.key"
                            class="space-y-2 rounded-md border p-3"
                            data-testid="purchase-request-line"
                        >
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-medium">
                                    Line {{ index + 1 }}
                                </p>
                                <Button
                                    v-if="send.lines.length > 1"
                                    type="button"
                                    variant="ghost"
                                    class="min-h-11 lg:min-h-9"
                                    :aria-label="`Remove line ${index + 1}`"
                                    @click="removeLine(index)"
                                    >Remove</Button
                                >
                            </div>
                            <InventorySearchSelect
                                :id="`request-sku-${index}`"
                                v-model="line.sku"
                                :label="`SKU for line ${index + 1}`"
                                :options="line.pinned ? [line.pinned] : []"
                                kind="skus"
                                context="operations"
                                :expected-branch-id="requests.expectedBranchId"
                            />
                            <InputError
                                :message="
                                    sendErrors[`lines.${index}.sku_public_id`]
                                "
                            />
                            <div class="grid gap-2 sm:grid-cols-[10rem_1fr]">
                                <div class="space-y-1">
                                    <label
                                        :for="`request-quantity-${index}`"
                                        class="text-sm"
                                        >Quantity</label
                                    >
                                    <Input
                                        :id="`request-quantity-${index}`"
                                        v-model="line.quantity"
                                        inputmode="decimal"
                                        class="min-h-11"
                                        :aria-invalid="
                                            sendErrors[
                                                `lines.${index}.quantity`
                                            ]
                                                ? 'true'
                                                : undefined
                                        "
                                        :aria-describedby="`request-quantity-${index}-error`"
                                    />
                                    <InputError
                                        :id="`request-quantity-${index}-error`"
                                        :message="
                                            sendErrors[
                                                `lines.${index}.quantity`
                                            ]
                                        "
                                    />
                                </div>
                                <div class="space-y-1">
                                    <label
                                        :for="`request-note-${index}`"
                                        class="text-sm"
                                        >Note (optional)</label
                                    >
                                    <Input
                                        :id="`request-note-${index}`"
                                        v-model="line.note"
                                        maxlength="200"
                                        class="min-h-11"
                                    />
                                    <InputError
                                        :message="
                                            sendErrors[`lines.${index}.note`]
                                        "
                                    />
                                </div>
                            </div>
                        </div>
                        <Button
                            v-if="send.lines.length < 30"
                            type="button"
                            variant="outline"
                            class="min-h-11 lg:min-h-9"
                            @click="send.lines.push(newLine())"
                            >Add a line</Button
                        >
                        <InputError :message="sendErrors.lines" />
                    </fieldset>
                    <p
                        class="rounded-md border bg-muted p-2 text-sm"
                        data-testid="purchase-request-summary"
                    >
                        {{ sendSummary }}
                    </p>
                    <div class="flex flex-wrap justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11 lg:min-h-9"
                            @click="sendOpen = false"
                            >Cancel</Button
                        >
                        <Button
                            type="submit"
                            class="min-h-11 lg:min-h-9"
                            :disabled="busy"
                            >Send for review</Button
                        >
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="converting !== null"
            @update:open="(open) => !open && (converting = null)"
        >
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle
                        >Convert {{ converting?.number }} to a draft
                        order</DialogTitle
                    >
                    <DialogDescription
                        >You can change a quantity, or enter 0 to leave a line
                        out. The asked quantity stays on the
                        request.</DialogDescription
                    >
                </DialogHeader>
                <form
                    v-if="converting"
                    class="space-y-3"
                    novalidate
                    @submit.prevent="submitConvert"
                >
                    <p
                        v-if="convertGeneral"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ convertGeneral }}
                    </p>
                    <div class="space-y-1">
                        <p class="text-sm font-medium">Supplier</p>
                        <InventorySearchSelect
                            id="convert-supplier"
                            v-model="convert.supplier"
                            label="Supplier"
                            :options="supplierPin"
                            kind="suppliers"
                            context="operations"
                            :expected-branch-id="requests.expectedBranchId"
                        />
                        <InputError
                            :message="convertErrors.supplier_public_id"
                        />
                    </div>
                    <div
                        v-for="(line, index) in converting.lines"
                        :key="line.id"
                        class="grid items-end gap-2 rounded-md border p-3 sm:grid-cols-[1fr_10rem]"
                    >
                        <div class="min-w-0 text-sm">
                            <p class="font-medium break-words">
                                {{ line.sku }} · {{ line.item }}
                            </p>
                            <p
                                class="text-muted-foreground"
                                data-testid="convert-asked"
                            >
                                Asked: {{ line.asked }} {{ line.stockUnit }}
                            </p>
                        </div>
                        <div class="space-y-1">
                            <label
                                :for="`convert-quantity-${line.id}`"
                                class="text-sm"
                                >Order ({{ line.stockUnit }})</label
                            >
                            <Input
                                :id="`convert-quantity-${line.id}`"
                                v-model="convert.quantities[line.id]"
                                inputmode="decimal"
                                class="min-h-11"
                                :aria-invalid="
                                    convertErrors[`lines.${index}.quantity`]
                                        ? 'true'
                                        : undefined
                                "
                                :aria-describedby="`convert-quantity-${line.id}-error`"
                            />
                            <InputError
                                :id="`convert-quantity-${line.id}-error`"
                                :message="
                                    convertErrors[`lines.${index}.quantity`]
                                "
                            />
                        </div>
                    </div>
                    <InputError :message="convertErrors.lines" />
                    <p class="rounded-md border bg-muted p-2 text-sm">
                        {{ lineWord(orderedLines) }} will go on the draft order.
                    </p>
                    <div class="flex flex-wrap justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11 lg:min-h-9"
                            @click="converting = null"
                            >Cancel</Button
                        >
                        <Button
                            type="submit"
                            class="min-h-11 lg:min-h-9"
                            :disabled="busy"
                            >Create draft order</Button
                        >
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="rejecting !== null"
            @update:open="(open) => !open && (rejecting = null)"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Reject {{ rejecting?.number }}</DialogTitle>
                    <DialogDescription
                        >The person who sent it sees the
                        reason.</DialogDescription
                    >
                </DialogHeader>
                <form
                    class="space-y-3"
                    novalidate
                    @submit.prevent="submitReject"
                >
                    <p
                        v-if="rejectGeneral"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ rejectGeneral }}
                    </p>
                    <fieldset
                        class="space-y-2"
                        :aria-invalid="rejectErrors.reason ? 'true' : undefined"
                        aria-describedby="reject-reason-error"
                    >
                        <legend class="text-sm font-medium">Reason</legend>
                        <label
                            v-for="(label, value) in rejectReasons"
                            :key="value"
                            class="flex min-h-11 items-center gap-3 rounded-md border p-2 text-sm"
                        >
                            <input
                                v-model="rejectReason"
                                type="radio"
                                name="reject-reason"
                                class="size-4"
                                :value="value"
                            />
                            {{ label }}
                        </label>
                    </fieldset>
                    <InputError
                        id="reject-reason-error"
                        :message="rejectErrors.reason"
                    />
                    <div class="flex flex-wrap justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11 lg:min-h-9"
                            @click="rejecting = null"
                            >Cancel</Button
                        >
                        <Button
                            type="submit"
                            variant="destructive"
                            class="min-h-11 lg:min-h-9"
                            :disabled="busy"
                            >Reject request</Button
                        >
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </main>
</template>
