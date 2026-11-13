import { router, usePage } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { computed, ref, watch } from 'vue';
import {
    formatInventoryHoldLabel,
    formatInventoryReason,
} from '@/lib/inventory-picker';
import { inventoryFilterError } from '@/types/inventory-operations';

/**
 * The Inventory home's filters, tabs and navigation, copied from the Classic page (`pages/Inventory/Index.vue`, which
 * keeps its own copy and does not use this). Used by the Version 2 page only. Same route (`GET /inventory`), same query
 * keys and the same rules for which filter goes with which tab. A test compares the two copies.
 */
export type InventoryRow = Record<
    string,
    string | boolean | null | undefined
> & {
    held?: boolean;
    holdReason?: string | null;
};

export type InventoryDirectory = {
    tab: 'stock' | 'batches' | 'movements';
    branch: string;
    branchTimezone: string;
    filters: {
        search: string;
        location: string;
        status: string;
        movementType: string;
        dateFrom: string;
        dateTo: string;
        batch: string;
    };
    locations: Array<{ publicId: string; name: string; type: string }>;
    summary: {
        skusWithStock: number;
        totalBatches: number;
        expiredBatches: number;
    };
    data: InventoryRow[];
    total: number;
    currentPage: number;
    lastPage: number;
};

export type InventoryTaskGroup = {
    id: string;
    title: string;
    links: Array<{ href: string; label: string }>;
};

/** The same 16 task links as the Classic page, in the same order (DD-75 groups). */
export const inventoryTaskGroups = (
    canViewControlledRegister: boolean,
): InventoryTaskGroup[] => [
    {
        id: 'count-and-adjust',
        title: 'Count and adjust',
        links: [
            { href: '/inventory/counts', label: 'Stock count (governed)' },
            { href: '/inventory/writeoffs', label: 'Write-off (governed)' },
            {
                href: '/inventory/treatment-usage',
                label: 'Treatment room usage',
            },
            { href: '/inventory/batch-holds', label: 'Batch safety holds' },
            { href: '/inventory/recalls', label: 'Recalls' },
            ...(canViewControlledRegister
                ? [
                      {
                          href: '/inventory/controlled-register',
                          label: 'Controlled register',
                      },
                  ]
                : []),
        ],
    },
    {
        id: 'orders-and-suppliers',
        title: 'Orders and suppliers',
        links: [
            {
                href: '/inventory/purchase-requests',
                label: 'Purchase requests',
            },
            {
                href: '/inventory/reorder-suggestions',
                label: 'Reorder suggestions',
            },
            {
                href: '/inventory/branch-replenishment',
                label: 'Stock other branches need',
            },
            {
                href: '/inventory/supplier-invoices',
                label: 'Supplier invoices',
            },
            { href: '/inventory/supplier-returns', label: 'Supplier returns' },
            {
                href: '/inventory/supplier-terms',
                label: 'Supplier price list',
            },
            { href: '/inventory/skus', label: 'SKUs' },
        ],
    },
    {
        id: 'reports-and-checks',
        title: 'Reports and checks',
        links: [
            { href: '/inventory/stock-review', label: 'Stock review' },
            { href: '/inventory/stock-health', label: 'Stock health' },
            { href: '/inventory/readiness', label: 'Pilot readiness' },
        ],
    },
];

export const inventoryTabs = [
    { value: 'stock', label: 'Stock overview' },
    { value: 'batches', label: 'Batch and expiry' },
    { value: 'movements', label: 'Movements' },
];

export const inventoryStatusOptions = [
    { value: '', label: 'All statuses' },
    { value: 'active', label: 'Available' },
    { value: 'inactive', label: 'Unavailable' },
    { value: 'returns', label: 'Patient returns only' },
];

export const inventoryMovementOptions = [
    { value: '', label: 'All movement types' },
    { value: 'opening_balance', label: 'Opening Balance' },
    { value: 'transfer', label: 'Inventory Transfer' },
    { value: 'dispense', label: 'Dispense' },
    { value: 'purchase_receipt', label: 'Purchase Receipt' },
    { value: 'transfer_dispatch', label: 'Transfer Dispatch' },
    { value: 'transfer_receipt', label: 'Transfer Receipt' },
    { value: 'stocktake_gain', label: 'Stocktake Gain' },
    { value: 'stocktake_loss', label: 'Stocktake Loss' },
    { value: 'adjustment_in', label: 'Adjustment In' },
    { value: 'adjustment_out', label: 'Adjustment Out' },
    { value: 'supplier_return', label: 'Supplier Return' },
    { value: 'treatment_usage', label: 'Treatment Room Use' },
    { value: 'treatment_waste', label: 'Treatment Room Waste' },
    { value: 'dispensary_return', label: 'Patient Return' },
];

/** The Classic hold rule for a stock or batch status: a held batch shows its hold reason. */
export const inventoryStatusLabel = (row: InventoryRow, status: unknown) =>
    row.holdReason ||
    row.held === true ||
    String(status).toLowerCase() === 'held'
        ? formatInventoryHoldLabel(formatInventoryReason(row.holdReason))
        : String(status);

export function useInventoryHome(
    inventory: () => InventoryDirectory,
    errors: () => Record<string, unknown> | undefined,
) {
    const page = usePage();
    // STK-24: the controlled-drug register link shows only for its viewers (the server checks too).
    const canViewControlledRegister = computed(() =>
        page.props.auth.permissions.includes(
            'inventory.controlled_register.view.branch',
        ),
    );
    const taskGroups = computed(() =>
        inventoryTaskGroups(canViewControlledRegister.value),
    );

    const filters = inventory().filters;
    const search = ref(filters.search);
    const location = ref(filters.location);
    const status = ref(filters.status);
    const movementType = ref(filters.movementType);
    const dateFrom = ref(filters.dateFrom);
    const dateTo = ref(filters.dateTo);
    const batch = ref(filters.batch);
    const loading = ref(false);
    const filterError = computed(() => inventoryFilterError(errors() ?? {}));

    watch(
        () => inventory().filters,
        (next) => {
            search.value = next.search;
            location.value = next.location;
            status.value = next.status;
            movementType.value = next.movementType;
            dateFrom.value = next.dateFrom;
            dateTo.value = next.dateTo;
            batch.value = next.batch;
        },
    );

    const locationOptions = computed(() => [
        { value: '', label: 'All branch locations' },
        ...inventory().locations.map((item) => ({
            value: item.publicId,
            label: `${item.name} · ${item.type}`,
        })),
    ]);

    const visit = (overrides: Record<string, string | number> = {}) => {
        const tab = inventory().tab;

        router.get(
            '/inventory',
            {
                tab,
                search: search.value || undefined,
                location: location.value || undefined,
                status:
                    tab === 'movements' ? undefined : status.value || undefined,
                movement_type:
                    tab === 'movements'
                        ? movementType.value || undefined
                        : undefined,
                date_from:
                    tab === 'movements'
                        ? dateFrom.value || undefined
                        : undefined,
                date_to:
                    tab === 'movements' ? dateTo.value || undefined : undefined,
                batch: tab === 'stock' ? undefined : batch.value || undefined,
                ...overrides,
            },
            {
                preserveState: true,
                replace: true,
                onStart: () => (loading.value = true),
                onFinish: () => (loading.value = false),
            },
        );
    };
    const changeTab = (tab: string) => visit({ tab, page: 1 });
    const applyFilters = () => visit({ page: 1 });
    const clearFilters = () => {
        for (const field of [
            search,
            location,
            status,
            movementType,
            dateFrom,
            dateTo,
            batch,
        ] as Ref<string>[]) {
            field.value = '';
        }

        visit({ page: 1 });
    };

    return {
        canViewControlledRegister,
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
    };
}
