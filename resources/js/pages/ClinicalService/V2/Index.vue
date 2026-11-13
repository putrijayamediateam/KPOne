<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ConsultationTariffV2 from '@/components/clinical-service-v2/ConsultationTariffV2.vue';
import ServiceForm from '@/components/clinical-service-v2/ServiceForm.vue';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2LeaveGuard from '@/components/v2/V2LeaveGuard.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2RowMenu from '@/components/v2/V2RowMenu.vue';
import type { RowMenuGroup } from '@/components/v2/V2RowMenu.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2Toolbar from '@/components/v2/V2Toolbar.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { useServiceForm } from '@/composables/clinical-service/useServiceForm';
import type {
    ConsultationTariff,
    ServiceRow,
    SetupOptions,
} from '@/composables/clinical-service/useServiceForm';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 Clinical Service Catalogue (shown only in V2; Classic is `pages/ClinicalService/Index.vue`, untouched). Same
 * props, same routes and payloads. Add and Edit are one full page inside this page (same address, `?add=1` or
 * `?edit=<id>`): "← Clinical services" returns to the list.
 */
const props = defineProps<{
    services: {
        data: ServiceRow[];
        total: number;
        currentPage: number;
        lastPage: number;
    };
    filters: { search: string; status: string };
    setup: SetupOptions;
    consultationTariff: ConsultationTariff;
}>();

const c = useServiceForm(props.setup);

usePageCrumbs(() =>
    c.formOpen.value
        ? [
              { title: 'Clinic management', href: '/clinic-management' },
              {
                  title: c.editing.value
                      ? `Edit ${c.editing.value.displayName}`
                      : 'Add clinical service',
              },
          ]
        : [
              { title: 'Clinic management', href: '/clinic-management' },
              { title: 'Clinical services' },
          ],
);

const search = ref(props.filters.search);
const status = ref(props.filters.status);
const statusOptions = [
    { value: '', label: 'All statuses' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];
const visit = (overrides: Record<string, string | number> = {}) => {
    router.get(
        '/clinical-services',
        {
            search: search.value || undefined,
            status: status.value || undefined,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};
const filterCount = computed(() => (status.value ? 1 : 0));
const sheetOpen = ref(false);

// Add and Edit live in the address (?add=1 or ?edit=<id>), so Back, Forward, refresh and a shared link all behave.
// Nothing here needs a server route: it is the same /clinical-services page with a query.
const page = usePage();
const urlQuery = computed(
    () => new URLSearchParams((page.url.split('?')[1] ?? '').split('#')[0]),
);
const filterQuery = () => ({
    search: search.value || undefined,
    status: status.value || undefined,
});
const openedByPush = ref(false);
const guard = ref<InstanceType<typeof V2LeaveGuard> | null>(null);
const goForm = (extra: Record<string, string>) => {
    openedByPush.value = true;
    router.get(
        '/clinical-services',
        { ...filterQuery(), ...extra },
        { preserveState: true, preserveScroll: true },
    );
};
const openAdd = () => goForm({ add: '1' });
const openEditPage = (row: ServiceRow) => goForm({ edit: row.publicId });
const closeToList = () => {
    c.formOpen.value = false;
    openedByPush.value = false;

    router.get('/clinical-services', filterQuery(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};
const leaveForm = () => {
    // Unsaved work: ask first (the shared question); Discard closes the form straight to the list.
    if (c.isDirty.value) {
        guard.value?.ask(closeToList);

        return;
    }

    if (openedByPush.value) {
        openedByPush.value = false;
        window.history.back();
    } else {
        closeToList();
    }
};
const syncFromUrl = () => {
    const query = urlQuery.value;
    const editId = query.get('edit');

    if (query.get('add') === '1') {
        if (!c.formOpen.value || c.editing.value) {
            c.openCreate();
        }
    } else if (editId) {
        if (!c.formOpen.value || c.editing.value?.publicId !== editId) {
            c.openEdit(
                props.services.data.find((row) => row.publicId === editId) ??
                    ({ publicId: editId, displayName: '' } as ServiceRow),
            );
        }
    } else if (c.formOpen.value) {
        c.formOpen.value = false;
        openedByPush.value = false;
    }
};
watch(() => page.url, syncFromUrl, { immediate: true });
const outlineButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9';
const filledButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none max-sm:w-full lg:h-9';

// Activate or deactivate (same route and wording as Classic).
const pendingToggle = ref<ServiceRow | null>(null);
const toggleBusy = ref(false);
const confirmToggle = () => {
    const row = pendingToggle.value;

    if (!row) {
        return;
    }

    toggleBusy.value = true;
    router.post(
        `/clinical-services/${row.publicId}/${row.isActive ? 'deactivate' : 'activate'}`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                toggleBusy.value = false;
                pendingToggle.value = null;
            },
        },
    );
};
const toggleDescription = computed(() => {
    const row = pendingToggle.value;

    if (!row) {
        return '';
    }

    return row.isActive
        ? `${row.displayName} will no longer be available to order on a Treatment Plan.`
        : `${row.displayName} will become available to order on a Treatment Plan again.`;
});
const menuFor = (row: ServiceRow): RowMenuGroup[] => [
    {
        label: 'Status',
        items: [
            row.isActive
                ? {
                      id: 'toggle',
                      label: 'Deactivate service',
                      allowed: true,
                      tone: 'danger',
                  }
                : { id: 'toggle', label: 'Activate service', allowed: true },
        ],
    },
];
const onMenu = (row: ServiceRow, id: string) => {
    if (id === 'toggle') {
        pendingToggle.value = row;
    }
};
const money = (value: string | null) => (value ? `RM ${value}` : 'no price');
</script>

<template>
    <Head title="Clinical service catalogue" />
    <PageFrame>
        <template v-if="c.formOpen.value">
            <button
                type="button"
                class="inline-flex h-11 w-fit items-center rounded-[10px] text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                data-action="back-to-services"
                @click="leaveForm()"
            >
                ← Clinical services
            </button>
            <V2WorkLayout
                :title="
                    c.editing.value
                        ? `Edit ${c.editing.value.displayName}`
                        : 'Add clinical service'
                "
                :description="
                    c.editing.value
                        ? 'Update this clinical service.'
                        : 'Complete the sections below. Prices are linked to this service when you save.'
                "
            >
                <template #main>
                    <ServiceForm :c="c" :setup="setup" @cancel="leaveForm()" />
                </template>
            </V2WorkLayout>
        </template>

        <V2WorkLayout
            v-else
            title="Clinical service catalogue"
            description="Create and manage clinical services available for ordering on a Treatment Plan."
        >
            <template #actions>
                <button
                    type="button"
                    :class="filledButton"
                    data-action="add-service"
                    @click="openAdd()"
                >
                    Add clinical service
                </button>
            </template>

            <template #main>
                <ConsultationTariffV2
                    :tariff="consultationTariff"
                    :can-manage-prices="setup.canManagePrices"
                    :can-publish-prices="setup.canPublishPrices"
                    :active-branch-id="setup.activeBranchId"
                    :branches="setup.branches"
                    :panels="setup.panels"
                />

                <V2Toolbar
                    v-model="search"
                    search-label="Search clinical services"
                    search-placeholder="Code or name"
                    @search="visit()"
                >
                    <template #filters>
                        <div class="hidden w-44 sm:block">
                            <OperationalSelect
                                v-model="status"
                                label="Status"
                                :options="statusOptions"
                            />
                        </div>
                    </template>
                    <template #primary>
                        <div class="flex gap-2 max-sm:w-full">
                            <button
                                type="button"
                                :class="[outlineButton, 'flex-1 sm:hidden']"
                                data-action="open-filters"
                                @click="sheetOpen = true"
                            >
                                Filters{{
                                    filterCount ? ` (${filterCount})` : ''
                                }}
                            </button>
                            <button
                                type="button"
                                :class="[outlineButton, 'flex-1 sm:flex-none']"
                                data-action="apply-filters"
                                @click="visit()"
                            >
                                Apply
                            </button>
                        </div>
                    </template>
                </V2Toolbar>

                <p class="text-[13px] text-muted-foreground">
                    {{ services.total }}
                    {{
                        services.total === 1
                            ? 'clinical service'
                            : 'clinical services'
                    }}
                </p>

                <V2Table
                    label="Clinical service catalogue"
                    :columns="setup.canManagePrices ? 5 : 4"
                    cards="xl"
                    min-width="800px"
                    :empty="services.data.length === 0"
                    empty-message="No clinical services match this filter. Change the search or status, or add a clinical service."
                >
                    <template #head>
                        <tr>
                            <th scope="col">Service</th>
                            <th scope="col">Order unit</th>
                            <th
                                v-if="setup.canManagePrices"
                                scope="col"
                                class="text-right whitespace-nowrap"
                            >
                                Prices
                            </th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-right">Actions</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr
                            v-for="row in services.data"
                            :key="row.publicId"
                            :data-service="row.code"
                            data-testid="service-row"
                        >
                            <td data-label="Service" data-primary>
                                <span
                                    class="flex flex-wrap items-center gap-x-2 gap-y-1"
                                >
                                    <span class="text-sm font-medium">{{
                                        row.displayName
                                    }}</span>
                                    <V2Chip
                                        class="xl:hidden"
                                        :tone="
                                            row.isActive ? 'success' : 'neutral'
                                        "
                                        :label="
                                            row.isActive ? 'Active' : 'Inactive'
                                        "
                                    />
                                </span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >Code: {{ row.code }}</span
                                >
                                <span
                                    v-if="row.category"
                                    class="block text-xs text-muted-foreground"
                                    >Category: {{ row.category }}</span
                                >
                            </td>
                            <td data-label="Order unit">
                                {{ row.orderUnit || '—' }}
                            </td>
                            <td
                                v-if="setup.canManagePrices"
                                data-label="Prices"
                                class="text-right"
                            >
                                <div class="text-right">
                                    <span class="block xl:whitespace-nowrap"
                                        >Self-pay:
                                        {{ money(row.selfPayAmountRm) }}</span
                                    >
                                    <span
                                        class="block text-xs text-muted-foreground xl:whitespace-nowrap"
                                        >Default Panel:
                                        {{
                                            money(row.panelDefaultAmountRm)
                                        }}</span
                                    >
                                </div>
                            </td>
                            <td
                                data-label="Status"
                                class="whitespace-nowrap max-xl:hidden!"
                            >
                                <V2Chip
                                    :tone="row.isActive ? 'success' : 'neutral'"
                                    :label="
                                        row.isActive ? 'Active' : 'Inactive'
                                    "
                                />
                            </td>
                            <td data-actions class="text-right">
                                <div
                                    class="flex items-center justify-end gap-2"
                                >
                                    <button
                                        type="button"
                                        :class="[
                                            outlineButton,
                                            'max-xl:flex-1',
                                        ]"
                                        data-action="edit-service"
                                        :aria-label="`Edit ${row.displayName}`"
                                        @click="openEditPage(row)"
                                    >
                                        Edit
                                    </button>
                                    <V2RowMenu
                                        :label="`More actions for ${row.displayName}`"
                                        :groups="menuFor(row)"
                                        show-label
                                        @select="onMenu(row, $event)"
                                    />
                                </div>
                            </td>
                        </tr>
                    </template>
                </V2Table>

                <V2Pager
                    :current-page="services.currentPage"
                    :last-page="services.lastPage"
                    :total="services.total"
                    :per-page="25"
                    @change="(page) => visit({ page })"
                />
            </template>
        </V2WorkLayout>

        <V2Dialog
            v-model:open="sheetOpen"
            title="Filters"
            confirm-label="Apply"
            cancel-label="Close"
            @confirm="
                sheetOpen = false;
                visit();
            "
        >
            <div class="grid gap-3">
                <label class="grid gap-1.5 text-sm font-medium"
                    >Status
                    <OperationalSelect
                        v-model="status"
                        label="Status"
                        :options="statusOptions"
                    />
                </label>
                <button
                    type="button"
                    :class="outlineButton"
                    @click="status = ''"
                >
                    Reset
                </button>
            </div>
        </V2Dialog>

        <V2Dialog
            :open="pendingToggle !== null"
            :title="
                pendingToggle?.isActive
                    ? 'Deactivate clinical service'
                    : 'Activate clinical service'
            "
            :description="toggleDescription"
            :confirm-label="pendingToggle?.isActive ? 'Deactivate' : 'Activate'"
            :tone="pendingToggle?.isActive ? 'danger' : 'default'"
            :busy="toggleBusy"
            @update:open="$event || (pendingToggle = null)"
            @confirm="confirmToggle()"
        />

        <V2LeaveGuard ref="guard" :dirty="c.isDirty.value" />
    </PageFrame>
</template>
