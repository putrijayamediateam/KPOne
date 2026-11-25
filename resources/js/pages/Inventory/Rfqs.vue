<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import InventorySearchSelect from '@/components/inventory/InventorySearchSelect.vue';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
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
import { EmptyState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTabs } from '@/components/ui/tabs';
import { roundStateLabel, roundStateTone } from '@/lib/rfq';
import type { RoundState } from '@/lib/rfq';

// STK-48: quote rounds. Pick the items once and one or more suppliers; KPOne makes one RFQ per supplier.
type Round = {
    publicId: string;
    status: 'open' | 'converted' | 'cancelled';
    state: RoundState;
    items: string[];
    location: string;
    quotesIn: number;
    suppliers: number;
    quoteBy: string;
    startedOn: string;
    startedBy: string;
};
type Tab = Round['status'];

const props = defineProps<{
    rfqs: {
        expectedBranchId: number;
        branchName: string;
        tab: Tab;
        can: { start: boolean; seePrices: boolean };
        counts: Record<Tab, number>;
        rounds: {
            page: number;
            lastPage: number;
            total: number;
            rows: Round[];
        };
        locations: { publicId: string; name: string }[];
    };
}>();

const tabLabel: Record<Tab, string> = {
    open: 'Open',
    converted: 'Order made',
    cancelled: 'Cancelled',
};
const tabs = computed(() =>
    (['open', 'converted', 'cancelled'] as Tab[]).map((t) => ({
        value: t,
        label: tabLabel[t],
        count: props.rfqs.counts[t],
    })),
);
const visit = (query: Record<string, string | number>) =>
    router.get('/inventory/rfqs', query, {
        preserveScroll: true,
        preserveState: true,
    });
const itemsText = (items: string[]) =>
    items.length <= 2
        ? items.join(', ')
        : `${items.slice(0, 2).join(', ')} and ${items.length - 2} more`;
const quotesText = (round: Round) =>
    `${round.quotesIn} of ${round.suppliers} ${round.suppliers === 1 ? 'quote' : 'quotes'} in`;

// Start a quote round.
type DraftLine = { key: string; sku: string | number; quantity: string };
type DraftSupplier = { key: string; supplier: string | number };
const newLine = (): DraftLine => ({
    key: crypto.randomUUID(),
    sku: '',
    quantity: '',
});
const newSupplier = (): DraftSupplier => ({
    key: crypto.randomUUID(),
    supplier: '',
});
const startOpen = ref(false);
const busy = ref(false);
const start = reactive({
    location: '' as string | number,
    quoteBy: '',
    lines: [newLine()] as DraftLine[],
    suppliers: [newSupplier()] as DraftSupplier[],
    key: crypto.randomUUID(),
});
const errors = ref<Record<string, string>>({});
const locationOptions = computed(() =>
    props.rfqs.locations.map((l) => ({ value: l.publicId, label: l.name })),
);
const openStart = () => {
    start.location =
        props.rfqs.locations.length === 1
            ? props.rfqs.locations[0].publicId
            : '';
    start.quoteBy = '';
    start.lines = [newLine()];
    start.suppliers = [newSupplier()];
    start.key = crypto.randomUUID();
    errors.value = {};
    startOpen.value = true;
};
const general = computed(
    () =>
        Object.entries(errors.value).find(
            ([key]) =>
                !/^(lines|supplier_public_ids|destination_location_public_id$|quote_by_date$)/.test(
                    key,
                ),
        )?.[1] ?? null,
);
const summary = computed(() => {
    const suppliers = start.suppliers.filter((s) => s.supplier).length;

    return suppliers === 1
        ? 'KPOne will make 1 RFQ.'
        : `KPOne will make ${suppliers} RFQs, one per supplier, with the same items.`;
});
const submitStart = () => {
    errors.value = {};

    if (!start.location) {
        errors.value.destination_location_public_id =
            'Choose where the stock should go.';
    }

    if (!start.quoteBy) {
        errors.value.quote_by_date = 'Choose the date to quote by.';
    }

    start.lines.forEach((line, index) => {
        if (!line.sku) {
            errors.value[`lines.${index}.sku_public_id`] = 'Choose the item.';
        }

        if (
            !/^\d+(\.\d{1,3})?$/.test(line.quantity.trim()) ||
            Number(line.quantity) <= 0
        ) {
            errors.value[`lines.${index}.quantity`] =
                'Enter a quantity above zero.';
        }
    });
    start.suppliers.forEach((row, index) => {
        if (!row.supplier) {
            errors.value[`supplier_public_ids.${index}`] =
                'Choose the supplier.';
        }
    });

    if (Object.keys(errors.value).length) {
        return;
    }

    router.post(
        '/inventory/rfqs',
        {
            expected_branch_id: props.rfqs.expectedBranchId,
            idempotency_key: start.key,
            destination_location_public_id: start.location,
            quote_by_date: start.quoteBy,
            supplier_public_ids: start.suppliers.map((s) => s.supplier),
            lines: start.lines.map((l) => ({
                sku_public_id: l.sku,
                quantity: l.quantity.trim(),
            })),
        },
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
            onSuccess: () => (startOpen.value = false),
            onError: (bag) => {
                errors.value = bag as Record<string, string>;
                start.key = crypto.randomUUID();
            },
        },
    );
};
</script>

<template>
    <Head title="Requests for quotation" />
    <main class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-7">
        <ActionLink
            href="/inventory"
            variant="ghost"
            class="-ml-3 min-h-11 self-start"
            ><ArrowLeft class="size-4" aria-hidden="true" />
            Inventory</ActionLink
        >
        <PageHeader
            title="Requests for quotation"
            :description="`Ask several suppliers for prices at ${rfqs.branchName}, compare them and make the chosen quote a draft purchase order. KPOne does not send or receive anything.`"
        />
        <div class="flex flex-wrap items-center justify-between gap-3">
            <OperationalTabs
                :model-value="rfqs.tab"
                :tabs="tabs"
                label="Quote round status"
                class="min-w-0 max-lg:[&>button]:h-11 max-sm:[&>button]:px-2.5"
                @update:model-value="(tab) => visit({ tab })"
            />
            <Button
                v-if="rfqs.can.start"
                type="button"
                class="min-h-11 w-full sm:w-auto lg:min-h-9"
                data-testid="rfq-start"
                @click="openStart"
                >Start a quote round</Button
            >
        </div>

        <EmptyState
            v-if="!rfqs.rounds.rows.length"
            title="No quote rounds here"
            description="Start a quote round to ask suppliers for prices."
        />
        <ul v-else class="space-y-3" aria-label="Quote rounds">
            <li
                v-for="round in rfqs.rounds.rows"
                :key="round.publicId"
                class="rounded-md border p-4"
                data-testid="rfq-round-row"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0 space-y-1">
                        <ActionLink
                            :href="`/inventory/rfqs/rounds/${round.publicId}`"
                            variant="link"
                            class="h-auto p-0 text-base font-semibold break-words whitespace-normal"
                            >{{ itemsText(round.items) }}</ActionLink
                        >
                        <p class="text-sm text-muted-foreground">
                            To {{ round.location }} · {{ quotesText(round) }} ·
                            quote by {{ round.quoteBy }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Started {{ round.startedOn }} by
                            {{ round.startedBy }}
                        </p>
                    </div>
                    <StatusBadge
                        :status="round.state"
                        :label="roundStateLabel[round.state]"
                        :tone="roundStateTone[round.state]"
                    />
                </div>
            </li>
        </ul>
        <CompactPagination
            v-if="rfqs.rounds.lastPage > 1"
            :current-page="rfqs.rounds.page"
            :last-page="rfqs.rounds.lastPage"
            :total="rfqs.rounds.total"
            @change="(page) => visit({ tab: rfqs.tab, page })"
        />

        <Dialog v-model:open="startOpen">
            <DialogContent
                class="max-h-[90vh] overflow-x-hidden overflow-y-auto sm:max-w-2xl"
            >
                <DialogHeader>
                    <DialogTitle>Start a quote round</DialogTitle>
                    <DialogDescription
                        >Pick the items once, then the suppliers to ask. Each
                        supplier gets its own RFQ to print and
                        send.</DialogDescription
                    >
                </DialogHeader>
                <form
                    class="min-w-0 space-y-4"
                    novalidate
                    @submit.prevent="submitStart"
                >
                    <p
                        v-if="general"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ general }}
                    </p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1">
                            <label
                                for="rfq-location"
                                class="text-sm font-medium"
                                >Deliver to</label
                            >
                            <OperationalSelect
                                id="rfq-location"
                                v-model="start.location"
                                label="Deliver to"
                                placeholder="Choose a location"
                                trigger-class="min-h-11"
                                :options="locationOptions"
                                :invalid="
                                    !!errors.destination_location_public_id
                                "
                                describedby="rfq-location-error"
                            />
                            <InputError
                                id="rfq-location-error"
                                :message="errors.destination_location_public_id"
                            />
                        </div>
                        <div class="space-y-1">
                            <label
                                for="rfq-quote-by"
                                class="text-sm font-medium"
                                >Please quote by</label
                            >
                            <DateField
                                id="rfq-quote-by"
                                v-model="start.quoteBy"
                                class="min-h-11"
                            />
                            <InputError :message="errors.quote_by_date" />
                        </div>
                    </div>
                    <fieldset class="min-w-0 space-y-3">
                        <legend class="text-sm font-medium">Items</legend>
                        <div
                            v-for="(line, index) in start.lines"
                            :key="line.key"
                            class="grid min-w-0 gap-2 rounded-md border p-3 sm:grid-cols-[1fr_10rem_auto] sm:items-start"
                            data-testid="rfq-start-line"
                        >
                            <div class="min-w-0 space-y-1">
                                <InventorySearchSelect
                                    :id="`rfq-sku-${index}`"
                                    v-model="line.sku"
                                    :label="`Item ${index + 1}`"
                                    :options="[]"
                                    kind="skus"
                                    context="operations"
                                    :expected-branch-id="rfqs.expectedBranchId"
                                />
                                <InputError
                                    :message="
                                        errors[`lines.${index}.sku_public_id`]
                                    "
                                />
                            </div>
                            <div class="space-y-1">
                                <label
                                    :for="`rfq-quantity-${index}`"
                                    class="sr-only"
                                    >Quantity for item {{ index + 1 }}</label
                                >
                                <Input
                                    :id="`rfq-quantity-${index}`"
                                    v-model="line.quantity"
                                    inputmode="decimal"
                                    placeholder="Quantity"
                                    class="min-h-11"
                                />
                                <InputError
                                    :message="errors[`lines.${index}.quantity`]"
                                />
                            </div>
                            <Button
                                v-if="start.lines.length > 1"
                                type="button"
                                variant="ghost"
                                class="min-h-11 lg:min-h-9"
                                :aria-label="`Remove item ${index + 1}`"
                                @click="start.lines.splice(index, 1)"
                                >Remove</Button
                            >
                        </div>
                        <Button
                            v-if="start.lines.length < 30"
                            type="button"
                            variant="outline"
                            class="min-h-11 lg:min-h-9"
                            @click="start.lines.push(newLine())"
                            >Add an item</Button
                        >
                        <InputError :message="errors.lines" />
                    </fieldset>
                    <fieldset class="min-w-0 space-y-3">
                        <legend class="text-sm font-medium">Suppliers</legend>
                        <div
                            v-for="(row, index) in start.suppliers"
                            :key="row.key"
                            class="grid min-w-0 gap-2 sm:grid-cols-[1fr_auto] sm:items-start"
                            data-testid="rfq-start-supplier"
                        >
                            <div class="min-w-0 space-y-1">
                                <InventorySearchSelect
                                    :id="`rfq-supplier-${index}`"
                                    v-model="row.supplier"
                                    :label="`Supplier ${index + 1}`"
                                    :options="[]"
                                    kind="suppliers"
                                    context="operations"
                                    :expected-branch-id="rfqs.expectedBranchId"
                                />
                                <InputError
                                    :message="
                                        errors[`supplier_public_ids.${index}`]
                                    "
                                />
                            </div>
                            <Button
                                v-if="start.suppliers.length > 1"
                                type="button"
                                variant="ghost"
                                class="min-h-11 lg:min-h-9"
                                :aria-label="`Remove supplier ${index + 1}`"
                                @click="start.suppliers.splice(index, 1)"
                                >Remove</Button
                            >
                        </div>
                        <Button
                            v-if="start.suppliers.length < 6"
                            type="button"
                            variant="outline"
                            class="min-h-11 lg:min-h-9"
                            @click="start.suppliers.push(newSupplier())"
                            >Add a supplier</Button
                        >
                        <InputError :message="errors.supplier_public_ids" />
                    </fieldset>
                    <p
                        class="rounded-md border bg-muted p-2 text-sm"
                        data-testid="rfq-start-summary"
                    >
                        {{ summary }}
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
                            class="min-h-11 lg:min-h-9"
                            :disabled="busy"
                            >Make the RFQs</Button
                        >
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </main>
</template>
