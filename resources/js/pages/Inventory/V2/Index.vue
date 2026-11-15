<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InventorySearchSelect from '@/components/inventory/InventorySearchSelect.vue';
import OlderRecordsSection from '@/components/inventory-v2/OlderRecordsSection.vue';
import OrdersSection from '@/components/inventory-v2/OrdersSection.vue';
import ReorderSection from '@/components/inventory-v2/ReorderSection.vue';
import RequestsSection from '@/components/inventory-v2/RequestsSection.vue';
import StockSetupSection from '@/components/inventory-v2/StockSetupSection.vue';
import { Input } from '@/components/ui/input';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import { ErrorState } from '@/components/ui/state';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DateRange from '@/components/v2/V2DateRange.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2DisclosureBar from '@/components/v2/V2DisclosureBar.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2Toolbar from '@/components/v2/V2Toolbar.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import {
    inventoryMovementOptions,
    inventoryStatusLabel,
    inventoryStatusOptions,
    inventoryTabs,
    useInventoryHome,
} from '@/composables/useInventoryHome';
import type { InventoryDirectory } from '@/composables/useInventoryHome';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { formatDate } from '@/lib/presentation';
import type {
    InventoryOperations,
    InventoryReceiptMemoryContext,
} from '@/types/inventory-operations';

/**
 * Version 2 Inventory home (shown only in V2; Classic is `pages/Inventory/Index.vue`, untouched). Same props, same
 * route and query keys (`GET /inventory`), same task links and permission checks. The receiving, transfer and stock
 * setup panels are the Classic components, unchanged, inside two closed bars; converting them is a later PR.
 */
const props = defineProps<{
    inventory: InventoryDirectory;
    operations: InventoryOperations;
    receiptMemoryContext: InventoryReceiptMemoryContext;
    referenceData: {
        canManage: boolean;
        expectedBranchId: number;
        items: Array<{ publicId: string; code: string; genericName: string }>;
        skus: Array<{
            publicId: string;
            skuCode: string;
            inventoryItemId: number;
        }>;
        locations: Array<{ publicId: string; name: string; type: string }>;
        medicines: Array<{
            publicId: string;
            code: string;
            displayName: string;
        }>;
        branches: Array<{ id: number; name: string }>;
    };
    errors?: Record<string, unknown>;
}>();
usePageCrumbs(() => [{ title: 'Inventory' }]);

const {
    taskGroups,
    search,
    location,
    status,
    movementType,
    dateFrom,
    dateTo,
    batch,
    loading,
    filterError,
    locationOptions,
    visit,
    changeTab,
    applyFilters,
    clearFilters,
} = useInventoryHome(
    () => props.inventory,
    () => props.errors,
);

// Filters kept behind "More filters": say how many are applied, so a hidden filter is never a surprise.
const extraFilters = computed(
    () =>
        [
            props.inventory.filters.location,
            ...(props.inventory.tab === 'stock'
                ? []
                : [props.inventory.filters.batch]),
            ...(props.inventory.tab === 'movements'
                ? [
                      props.inventory.filters.dateFrom,
                      props.inventory.filters.dateTo,
                  ]
                : []),
        ].filter(Boolean).length,
);
const filtersOpen = ref(false);
const sheetOpen = ref(false);
// Every filter except the search: "Filters (n)" on a phone and "n filters applied" beside Clear.
const appliedFilters = computed(
    () =>
        extraFilters.value +
        ((
            props.inventory.tab === 'movements'
                ? props.inventory.filters.movementType
                : props.inventory.filters.status
        )
            ? 1
            : 0),
);
// Staff read "Kuala Lumpur time", not the system zone name.
const timezoneLabel = computed(
    () =>
        `${(props.inventory.branchTimezone.split('/').pop() ?? '').replaceAll('_', ' ')} time`,
);
// "40 units", "1 unit": the plural of the stock unit when the quantity is not 1 (V2 only; Classic keeps its words).
const units = (quantity: unknown, unit: unknown) => {
    const name = String(unit ?? '');
    const amount = Number(String(quantity ?? '').replace(/[^0-9.-]/g, ''));

    if (name === '' || Math.abs(amount) === 1) {
        return name;
    }

    return /(s|x|z|ch|sh)$/i.test(name) ? `${name}es` : `${name}s`;
};
// The date boxes follow the browser's own format (often month first), so the chosen range is echoed day first:
// "1 Nov 2026 to 13 Nov 2026".
const dateEcho = computed(() => {
    const from = dateFrom.value ? formatDate(dateFrom.value) : '';
    const to = dateTo.value ? formatDate(dateTo.value) : '';

    if (from && to) {
        return `${from} to ${to}`;
    }

    return from ? `From ${from}` : to ? `Up to ${to}` : '';
});
// A real minus sign (−40 unit), not a hyphen.
const minus = (value: unknown) => String(value ?? '').replace(/^-/, '\u2212');
const sentence = (value: unknown) => {
    const text = String(value ?? '');

    return text.charAt(0).toUpperCase() + text.slice(1).toLowerCase();
};
const movementOptions = inventoryMovementOptions.map((option) => ({
    ...option,
    label: sentence(option.label),
}));
const tile = 'min-w-0 rounded-[14px] border bg-card p-3 sm:px-4';
const taskLink =
    'inline-flex min-h-11 items-center rounded-[10px] border border-border bg-card px-3 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9';
const button =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9';
// A held batch reads as a warning; available as success; anything else neutral.
const isHeld = (row: InventoryDirectory['data'][number], status: unknown) =>
    !!row.holdReason ||
    row.held === true ||
    String(status).toLowerCase() === 'held';
const availabilityTone = (row: InventoryDirectory['data'][number]) =>
    isHeld(row, row.availabilityStatus)
        ? 'warning'
        : row.available
          ? 'success'
          : 'neutral';
const batchTone = (row: InventoryDirectory['data'][number]) =>
    isHeld(row, row.batchStatus)
        ? 'warning'
        : String(row.batchStatus).toLowerCase() === 'available'
          ? 'success'
          : 'neutral';
</script>

<template>
    <Head title="Inventory" />
    <PageFrame width="wide">
        <V2WorkLayout
            title="Inventory"
            :description="`${inventory.branch} · Stock, batches and movement history.`"
        >
            <template #main>
                <section
                    class="grid grid-cols-3 gap-2 sm:gap-3"
                    aria-label="Inventory summary"
                >
                    <div :class="tile">
                        <p class="text-xs break-words text-muted-foreground">
                            SKUs with stock
                        </p>
                        <p class="mt-1 text-xl font-semibold tabular-nums">
                            {{ inventory.summary.skusWithStock }}
                        </p>
                    </div>
                    <div :class="tile">
                        <p class="text-xs break-words text-muted-foreground">
                            Total batches
                        </p>
                        <p class="mt-1 text-xl font-semibold tabular-nums">
                            {{ inventory.summary.totalBatches }}
                        </p>
                    </div>
                    <div :class="tile">
                        <p class="text-xs break-words text-muted-foreground">
                            Expired batches
                        </p>
                        <p class="mt-1 text-xl font-semibold tabular-nums">
                            {{ inventory.summary.expiredBatches }}
                        </p>
                    </div>
                </section>

                <nav aria-label="Inventory tasks">
                    <!-- From 1024 px each group is a card with its links; below that the same groups fold. -->
                    <div class="hidden gap-3 lg:grid lg:grid-cols-3">
                        <section
                            v-for="group in taskGroups"
                            :key="group.id"
                            class="space-y-2 rounded-[14px] border bg-card p-4"
                            :aria-labelledby="`v2-inventory-tasks-${group.id}`"
                        >
                            <h2
                                :id="`v2-inventory-tasks-${group.id}`"
                                class="text-[15px] font-semibold"
                            >
                                {{ group.title }}
                            </h2>
                            <div class="flex flex-wrap gap-2">
                                <Link
                                    v-for="link in group.links"
                                    :key="link.href"
                                    :href="link.href"
                                    :class="taskLink"
                                    >{{ link.label }}</Link
                                >
                            </div>
                        </section>
                    </div>
                    <div class="space-y-2 lg:hidden">
                        <V2DisclosureBar
                            v-for="group in taskGroups"
                            :key="group.id"
                            :title="`${group.title} · ${group.links.length}`"
                        >
                            <div class="flex flex-wrap gap-2">
                                <Link
                                    v-for="link in group.links"
                                    :key="link.href"
                                    :href="link.href"
                                    :class="taskLink"
                                    >{{ link.label }}</Link
                                >
                            </div>
                        </V2DisclosureBar>
                    </div>
                </nav>

                <h2 class="sr-only">Stock records</h2>
                <V2Tabs
                    :model-value="inventory.tab"
                    :tabs="inventoryTabs"
                    ariaLabel="Inventory views"
                    @update:model-value="changeTab"
                />

                <form
                    class="flex flex-col gap-3"
                    aria-label="Inventory filters"
                    @submit.prevent="applyFilters"
                >
                    <V2Toolbar
                        v-model="search"
                        search-label="Search SKU, item or medicine"
                        search-placeholder="SKU, item or medicine"
                        @search="applyFilters"
                    >
                        <template #filters>
                            <div
                                v-if="inventory.tab !== 'movements'"
                                class="hidden w-48 sm:block"
                            >
                                <OperationalSelect
                                    v-model="status"
                                    label="Inventory status"
                                    :options="inventoryStatusOptions"
                                />
                            </div>
                            <div v-else class="hidden w-56 sm:block">
                                <OperationalSelect
                                    v-model="movementType"
                                    label="Movement type"
                                    :options="movementOptions"
                                />
                            </div>
                        </template>
                        <template #primary>
                            <div class="flex gap-2 max-sm:w-full">
                                <button
                                    type="button"
                                    :class="[button, 'flex-1 sm:hidden']"
                                    @click="sheetOpen = true"
                                >
                                    Filters{{
                                        appliedFilters
                                            ? ` (${appliedFilters})`
                                            : ''
                                    }}
                                </button>
                                <button
                                    type="button"
                                    :class="[button, 'max-sm:hidden']"
                                    :aria-expanded="filtersOpen"
                                    aria-controls="v2-inventory-filters"
                                    @click="filtersOpen = !filtersOpen"
                                >
                                    More filters
                                </button>
                                <button
                                    type="submit"
                                    :class="[button, 'flex-1 sm:flex-none']"
                                    :disabled="loading"
                                >
                                    {{ loading ? 'Applying…' : 'Apply' }}
                                </button>
                                <button
                                    type="button"
                                    :class="[button, 'max-sm:hidden']"
                                    :disabled="loading"
                                    @click="clearFilters"
                                >
                                    Clear
                                </button>
                                <span
                                    v-if="appliedFilters"
                                    class="self-center text-xs whitespace-nowrap text-muted-foreground max-sm:hidden"
                                    >{{ appliedFilters }}
                                    {{
                                        appliedFilters === 1
                                            ? 'filter'
                                            : 'filters'
                                    }}
                                    applied</span
                                >
                            </div>
                        </template>
                    </V2Toolbar>
                    <div
                        v-if="filtersOpen"
                        id="v2-inventory-filters"
                        class="flex flex-wrap items-end gap-2 rounded-[14px] border bg-card p-3 max-sm:hidden"
                    >
                        <div class="grid w-64 gap-1 text-xs">
                            <span aria-hidden="true">Location</span>
                            <InventorySearchSelect
                                v-model="location"
                                label="Inventory location"
                                :options="locationOptions"
                                kind="locations"
                                context="directory"
                                :expected-branch-id="
                                    operations.expectedBranchId
                                "
                            />
                        </div>
                        <div
                            v-if="inventory.tab === 'movements'"
                            class="grid gap-1"
                        >
                            <V2DateRange
                                v-model:from="dateFrom"
                                v-model:to="dateTo"
                                label="Movement dates"
                            />
                            <p
                                v-if="dateEcho"
                                class="text-[13px] text-muted-foreground"
                                aria-live="polite"
                            >
                                {{ dateEcho }}
                            </p>
                        </div>
                        <label
                            v-if="inventory.tab !== 'stock'"
                            class="grid min-w-48 gap-1 text-xs"
                            >Batch
                            <Input
                                v-model="batch"
                                maxlength="100"
                                placeholder="Batch number"
                            />
                        </label>
                    </div>
                </form>

                <ErrorState
                    v-if="filterError"
                    title="Inventory filters need attention"
                    :message="filterError"
                />
                <p class="text-[13px] text-muted-foreground" role="status">
                    {{
                        loading
                            ? 'Updating inventory…'
                            : `${inventory.total} ${inventory.total === 1 ? 'record' : 'records'} · 25 per page · ${timezoneLabel}`
                    }}
                </p>

                <div
                    v-if="inventory.data.length === 0 && !loading"
                    class="flex min-h-28 flex-col items-center justify-center gap-1 rounded-[14px] border bg-card px-4 py-6 text-center text-sm text-muted-foreground"
                    role="status"
                    data-test="v2-inventory-empty"
                >
                    <p class="font-medium text-foreground">No records</p>
                    <p>
                        No
                        {{
                            inventory.tab === 'movements' ? 'movement' : 'stock'
                        }}
                        records are available for {{ inventory.branch }} with
                        these filters.
                    </p>
                </div>

                <V2Table
                    v-else-if="inventory.tab === 'stock'"
                    label="Stock overview"
                    :columns="4"
                    cards="xl"
                    min-width="800px"
                    :loading="loading"
                >
                    <template #head>
                        <tr>
                            <th scope="col">Item</th>
                            <th scope="col">Location</th>
                            <th scope="col">Batch and expiry</th>
                            <th scope="col" class="text-right">Stock</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr
                            v-for="(row, index) in inventory.data"
                            :key="`${row.skuCode}-${row.location}-${row.batchNumber}-${index}`"
                        >
                            <td data-label="Item" data-primary>
                                <span class="block font-medium break-words">{{
                                    row.itemName
                                }}</span>
                                <span
                                    class="block text-xs break-words text-muted-foreground"
                                    ><span class="font-mono">{{
                                        row.skuCode
                                    }}</span
                                    ><template v-if="row.medicineName != null">
                                        · {{ row.medicineName }}</template
                                    ></span
                                >
                            </td>
                            <td data-label="Location" class="break-words">
                                <div class="min-w-0">
                                    {{ row.location }}
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >{{ row.locationType }} ·
                                        {{ row.branch }}</span
                                    >
                                </div>
                            </td>
                            <td data-label="Batch and expiry">
                                <div class="min-w-0">
                                    <span
                                        class="block font-mono text-xs break-words"
                                        >{{ row.batchNumber }}</span
                                    >
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >Expires {{ row.expiryDate }}</span
                                    >
                                </div>
                            </td>
                            <td data-label="Stock" class="text-right">
                                <div
                                    class="flex flex-wrap items-center justify-end gap-x-2 gap-y-1"
                                >
                                    <span class="whitespace-nowrap"
                                        ><V2Chip
                                            :tone="availabilityTone(row)"
                                            :label="
                                                inventoryStatusLabel(
                                                    row,
                                                    row.availabilityStatus,
                                                )
                                            "
                                    /></span>
                                    <span
                                        class="font-semibold whitespace-nowrap tabular-nums"
                                        >{{ row.quantity }}
                                        {{
                                            units(row.quantity, row.stockUnit)
                                        }}</span
                                    >
                                </div>
                            </td>
                        </tr>
                    </template>
                </V2Table>

                <V2Table
                    v-else-if="inventory.tab === 'batches'"
                    label="Batch and expiry"
                    :columns="4"
                    cards="xl"
                    min-width="800px"
                    :loading="loading"
                >
                    <template #head>
                        <tr>
                            <th scope="col">Item</th>
                            <th scope="col">Batch and location</th>
                            <th scope="col">Received and expiry</th>
                            <th scope="col" class="text-right">Stock</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr
                            v-for="(row, index) in inventory.data"
                            :key="`${row.skuCode}-${row.location}-${row.batchNumber}-${index}`"
                        >
                            <td data-label="Item" data-primary>
                                <span class="block font-medium break-words">{{
                                    row.itemName
                                }}</span>
                                <span
                                    class="block text-xs break-words text-muted-foreground"
                                    ><span class="font-mono">{{
                                        row.skuCode
                                    }}</span
                                    ><template v-if="row.medicineName != null">
                                        · {{ row.medicineName }}</template
                                    ></span
                                >
                            </td>
                            <td data-label="Batch and location">
                                <div class="min-w-0">
                                    <span
                                        class="block font-mono text-xs break-words"
                                        >{{ row.batchNumber }}</span
                                    >
                                    <span
                                        class="block text-xs break-words text-muted-foreground"
                                        >{{ row.location }}</span
                                    >
                                </div>
                            </td>
                            <td data-label="Received and expiry">
                                <div class="min-w-0">
                                    <span class="block"
                                        >Expires {{ row.expiryDate }}</span
                                    >
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >Received
                                        {{ row.receivedDate ?? '—' }}</span
                                    >
                                </div>
                            </td>
                            <td data-label="Stock" class="text-right">
                                <div
                                    class="flex flex-wrap items-center justify-end gap-x-2 gap-y-1"
                                >
                                    <span class="whitespace-nowrap"
                                        ><V2Chip
                                            :tone="
                                                row.expiryStatus === 'Expired'
                                                    ? 'danger'
                                                    : 'success'
                                            "
                                            :label="String(row.expiryStatus)"
                                    /></span>
                                    <span class="whitespace-nowrap"
                                        ><V2Chip
                                            :tone="batchTone(row)"
                                            :label="
                                                inventoryStatusLabel(
                                                    row,
                                                    row.batchStatus,
                                                )
                                            "
                                    /></span>
                                    <span
                                        class="font-semibold whitespace-nowrap tabular-nums"
                                        >{{ row.quantity }}
                                        {{
                                            units(row.quantity, row.stockUnit)
                                        }}</span
                                    >
                                </div>
                            </td>
                        </tr>
                    </template>
                </V2Table>

                <V2Table
                    v-else
                    label="Stock movements"
                    :columns="4"
                    cards="xl"
                    min-width="800px"
                    :loading="loading"
                >
                    <template #head>
                        <tr>
                            <th scope="col">Date and type</th>
                            <th scope="col">Item and batch</th>
                            <th scope="col" class="text-right">Quantity</th>
                            <th scope="col">Reference</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr
                            v-for="(row, index) in inventory.data"
                            :key="`${row.occurredAt}-${row.skuCode}-${row.batchNumber}-${index}`"
                        >
                            <td data-label="Date and type" data-primary>
                                <span class="block">{{ row.occurredAt }}</span>
                                <span class="whitespace-nowrap"
                                    ><V2Chip
                                        tone="neutral"
                                        :label="sentence(row.typeLabel)"
                                /></span>
                            </td>
                            <td data-label="Item and batch">
                                <div class="min-w-0">
                                    <span
                                        class="block font-medium break-words"
                                        >{{ row.itemName }}</span
                                    >
                                    <span
                                        class="block text-xs break-words text-muted-foreground"
                                        ><span class="font-mono"
                                            >{{ row.skuCode }} ·
                                            {{ row.batchNumber }}</span
                                        ><template
                                            v-if="row.medicineName != null"
                                        >
                                            · {{ row.medicineName }}</template
                                        ></span
                                    >
                                </div>
                            </td>
                            <td data-label="Quantity" class="text-right">
                                <div class="min-w-0">
                                    <span
                                        class="font-semibold whitespace-nowrap"
                                        >{{ minus(row.quantityDisplay) }}
                                        {{
                                            units(
                                                row.quantityDisplay,
                                                row.stockUnit,
                                            )
                                        }}</span
                                    >
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >{{ row.direction }}</span
                                    >
                                </div>
                            </td>
                            <td data-label="Reference" class="break-words">
                                {{ row.reference }}
                            </td>
                        </tr>
                    </template>
                </V2Table>

                <V2Pager
                    v-if="inventory.total > 0"
                    :current-page="inventory.currentPage"
                    :last-page="inventory.lastPage"
                    :total="inventory.total"
                    :per-page="25"
                    :disabled="loading"
                    @change="(page) => visit({ page })"
                />
                <p class="text-xs text-muted-foreground">
                    Movement records are read-only.
                </p>

                <!-- Page 2a: suppliers, purchase orders and receiving on the V2 blocks; the forms stay when the bar closes. -->
                <V2DisclosureBar title="Orders and receiving" keep-mounted>
                    <OrdersSection
                        :operations="operations"
                        :receipt-memory-context="receiptMemoryContext"
                        :timezone="inventory.branchTimezone"
                    />
                </V2DisclosureBar>
                <!-- Page 2b: stock requests and transfers on the V2 blocks. -->
                <V2DisclosureBar
                    title="Stock requests and transfers"
                    keep-mounted
                >
                    <RequestsSection :operations="operations" />
                </V2DisclosureBar>
                <!-- Page 2c: reorder levels, alerts and the read-only older records; the Classic panel is no longer shown here. -->
                <V2DisclosureBar title="Reorder levels and alerts" keep-mounted>
                    <ReorderSection
                        :operations="operations"
                        :timezone="inventory.branchTimezone"
                    />
                </V2DisclosureBar>
                <V2DisclosureBar title="Older records (read only)">
                    <OlderRecordsSection :operations="operations" />
                </V2DisclosureBar>
                <!-- Page 2d: Stock setup on the V2 blocks; no Classic panel is left on the V2 home. -->
                <V2DisclosureBar
                    v-if="referenceData.canManage"
                    title="Stock setup"
                    keep-mounted
                >
                    <StockSetupSection
                        :reference-data="referenceData"
                        :timezone="inventory.branchTimezone"
                    />
                </V2DisclosureBar>
            </template>
        </V2WorkLayout>
    </PageFrame>

    <V2Dialog
        v-model:open="sheetOpen"
        title="Filters"
        confirm-label="Apply"
        cancel-label="Close"
        @confirm="
            sheetOpen = false;
            applyFilters();
        "
    >
        <div class="grid gap-3">
            <label
                v-if="inventory.tab !== 'movements'"
                class="grid gap-1 text-sm font-medium"
                >Status
                <OperationalSelect
                    v-model="status"
                    label="Inventory status"
                    :options="inventoryStatusOptions"
                />
            </label>
            <label v-else class="grid gap-1 text-sm font-medium"
                >Movement type
                <OperationalSelect
                    v-model="movementType"
                    label="Movement type"
                    :options="movementOptions"
                />
            </label>
            <div class="grid gap-1 text-sm font-medium">
                <span aria-hidden="true">Location</span>
                <InventorySearchSelect
                    v-model="location"
                    label="Inventory location"
                    :options="locationOptions"
                    kind="locations"
                    context="directory"
                    :expected-branch-id="operations.expectedBranchId"
                />
            </div>
            <div v-if="inventory.tab === 'movements'" class="grid gap-1">
                <V2DateRange
                    v-model:from="dateFrom"
                    v-model:to="dateTo"
                    label="Movement dates"
                />
                <p
                    v-if="dateEcho"
                    class="text-[13px] text-muted-foreground"
                    aria-live="polite"
                >
                    {{ dateEcho }}
                </p>
            </div>
            <label
                v-if="inventory.tab !== 'stock'"
                class="grid gap-1 text-sm font-medium"
                >Batch
                <Input
                    v-model="batch"
                    maxlength="100"
                    placeholder="Batch number"
                />
            </label>
            <button
                type="button"
                class="inline-flex h-11 items-center justify-center rounded-[10px] px-4 text-sm font-medium text-primary hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                @click="
                    sheetOpen = false;
                    clearFilters();
                "
            >
                Reset filters
            </button>
        </div>
    </V2Dialog>
</template>
