<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import MedicineForm from '@/components/medicine-v2/MedicineForm.vue';
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
import { useMedicineForm } from '@/composables/medicine/useMedicineForm';
import type {
    MedicineRow,
    SetupOptions,
} from '@/composables/medicine/useMedicineForm';
import { useMedicineRowActions } from '@/composables/medicine/useMedicineRowActions';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 Medicine Catalogue (shown only in V2; Classic is `pages/Medicine/Index.vue`, untouched). Same props, same
 * routes and payloads. Add and Edit are one full page inside this page (same address): "← Medicines" returns to the list.
 */
const props = defineProps<{
    medicines: {
        data: MedicineRow[];
        total: number;
        currentPage: number;
        lastPage: number;
    };
    filters: { search: string; status: string };
    setup: SetupOptions;
}>();

const c = useMedicineForm(props.setup);
const rows = useMedicineRowActions();

usePageCrumbs(() =>
    c.formOpen.value
        ? [
              { title: 'Clinic management', href: '/clinic-management' },
              {
                  title: c.editing.value
                      ? `Edit ${c.editing.value.displayName}`
                      : 'Add medicine',
              },
          ]
        : [
              { title: 'Clinic management', href: '/clinic-management' },
              { title: 'Medicines' },
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
        '/medicines',
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
// Nothing here needs a server route: it is the same /medicines page with a query.
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
        '/medicines',
        { ...filterQuery(), ...extra },
        { preserveState: true, preserveScroll: true },
    );
};
const openAdd = () => goForm({ add: '1' });
const openEditPage = (row: MedicineRow) => goForm({ edit: row.publicId });
const closeToList = () => {
    c.formOpen.value = false;
    openedByPush.value = false;

    router.get('/medicines', filterQuery(), {
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
                props.medicines.data.find((row) => row.publicId === editId) ??
                    ({ publicId: editId, displayName: '' } as MedicineRow),
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
const textAreaClass =
    'w-full rounded-[10px] border bg-card px-3 py-2 text-base outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px]';

const menuFor = (row: MedicineRow): RowMenuGroup[] => [
    {
        label: 'Allergy matching',
        items: [
            { id: 'groups', label: 'Drug groups', allowed: true },
            { id: 'aliases', label: 'Other names', allowed: true },
        ],
    },
    {
        label: 'Labels',
        items: [
            {
                id: 'controlled',
                label: row.isControlled
                    ? 'Unmark controlled'
                    : 'Mark controlled',
                allowed: true,
            },
        ],
    },
    {
        label: 'Status',
        items: [
            row.isActive
                ? {
                      id: 'toggle',
                      label: 'Deactivate medicine',
                      allowed: true,
                      tone: 'danger',
                  }
                : { id: 'toggle', label: 'Activate medicine', allowed: true },
        ],
    },
];
const onMenu = (row: MedicineRow, id: string) => {
    if (id === 'groups') {
        rows.openGroups(row);
    } else if (id === 'aliases') {
        rows.openAliases(row);
    } else if (id === 'controlled') {
        rows.pendingControlled.row = row;
    } else if (id === 'toggle') {
        rows.requestToggle(row);
    }
};

const money = (value: string | null) => (value ? `RM ${value}` : 'no price');
const strengthLine = (row: MedicineRow) =>
    [row.strengthText, row.dosageForm, row.orderUnit]
        .filter(Boolean)
        .join(' · ');
const groupsLine = (items: string[]) =>
    items.slice(0, 3).join(', ') +
    (items.length > 3 ? ` +${items.length - 3} more` : '');
</script>

<template>
    <Head title="Medicine catalogue" />
    <PageFrame>
        <template v-if="c.formOpen.value">
            <button
                type="button"
                class="inline-flex h-11 w-fit items-center rounded-[10px] text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                data-action="back-to-medicines"
                @click="leaveForm()"
            >
                ← Medicines
            </button>
            <V2WorkLayout
                :title="
                    c.editing.value
                        ? `Edit ${c.editing.value.displayName}`
                        : 'Add medicine'
                "
                :description="
                    c.editing.value
                        ? 'Update this medicine catalogue entry.'
                        : 'Complete the sections below. Inventory, prices and opening stock are linked to this medicine when you save.'
                "
            >
                <template #main>
                    <MedicineForm :c="c" :setup="setup" @cancel="leaveForm()" />
                </template>
            </V2WorkLayout>
        </template>

        <V2WorkLayout
            v-else
            title="Medicine catalogue"
            description="Create and manage the organisation medicine catalogue."
        >
            <template #actions>
                <button
                    type="button"
                    :class="filledButton"
                    data-action="add-medicine"
                    @click="openAdd()"
                >
                    Add medicine
                </button>
            </template>

            <template #main>
                <V2Toolbar
                    v-model="search"
                    search-label="Search medicines"
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
                    {{ medicines.total }}
                    {{ medicines.total === 1 ? 'medicine' : 'medicines' }}
                </p>

                <V2Table
                    label="Medicine catalogue"
                    :columns="setup.canManagePrices ? 5 : 4"
                    cards="xl"
                    min-width="880px"
                    :empty="medicines.data.length === 0"
                    empty-message="No medicines match this filter. Change the search or status, or add a medicine."
                >
                    <template #head>
                        <tr>
                            <th scope="col">Medicine</th>
                            <th scope="col">Strength and form</th>
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
                            v-for="row in medicines.data"
                            :key="row.publicId"
                            :data-medicine="row.code"
                            data-testid="medicine-row"
                        >
                            <td data-label="Medicine" data-primary>
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
                                    <V2Chip
                                        v-if="row.isControlled"
                                        class="xl:hidden"
                                        tone="neutral"
                                        label="Controlled"
                                    />
                                </span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >Code: {{ row.code }}</span
                                >
                                <span
                                    v-if="row.allergyGroups.length"
                                    class="block text-xs text-muted-foreground"
                                    data-testid="allergy-groups"
                                    >Drug groups:
                                    {{ groupsLine(row.allergyGroups) }}</span
                                >
                                <span
                                    v-if="row.allergyAliases.length"
                                    class="block text-xs text-muted-foreground"
                                    data-testid="allergy-aliases"
                                    >Other names:
                                    {{ groupsLine(row.allergyAliases) }}</span
                                >
                            </td>
                            <td data-label="Strength and form">
                                {{ strengthLine(row) || '—' }}
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
                                <span class="flex flex-wrap items-center gap-1">
                                    <V2Chip
                                        :tone="
                                            row.isActive ? 'success' : 'neutral'
                                        "
                                        :label="
                                            row.isActive ? 'Active' : 'Inactive'
                                        "
                                    />
                                    <V2Chip
                                        v-if="row.isControlled"
                                        tone="neutral"
                                        label="Controlled"
                                    />
                                </span>
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
                                        data-action="edit-medicine"
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
                    :current-page="medicines.currentPage"
                    :last-page="medicines.lastPage"
                    :total="medicines.total"
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
            :open="rows.pendingToggle.row !== null"
            :title="
                rows.pendingToggle.row?.isActive
                    ? 'Deactivate medicine'
                    : 'Activate medicine'
            "
            :description="rows.toggleDescription.value"
            :confirm-label="
                rows.pendingToggle.row?.isActive ? 'Deactivate' : 'Activate'
            "
            :tone="rows.pendingToggle.row?.isActive ? 'danger' : 'default'"
            :busy="rows.toggleBusy.value"
            @update:open="$event || (rows.pendingToggle.row = null)"
            @confirm="rows.confirmToggle()"
        />

        <V2Dialog
            :open="rows.pendingControlled.row !== null"
            :title="
                rows.pendingControlled.row?.isControlled
                    ? 'Remove controlled medicine mark'
                    : 'Mark as controlled medicine'
            "
            :description="rows.controlledDescription.value"
            :confirm-label="
                rows.pendingControlled.row?.isControlled
                    ? 'Remove mark'
                    : 'Mark'
            "
            :tone="
                rows.pendingControlled.row?.isControlled ? 'danger' : 'default'
            "
            :busy="rows.controlledBusy.value"
            @update:open="$event || (rows.pendingControlled.row = null)"
            @confirm="rows.confirmControlled()"
        />

        <V2Dialog
            :open="rows.groupsRow.value !== null"
            title="Drug groups"
            :description="`${rows.groupsRow.value?.displayName}: the drug groups this medicine belongs to, one per line (for example Penicillins, NSAIDs). The Dispensary allergy warning checks a patient's recorded allergies against these as well as the medicine's names. KPOne does not decide the groups; check them against a reliable reference. Use drug-class names, not dosage forms or broad words such as Tablets.`"
            confirm-label="Save"
            :busy="rows.groupsBusy.value"
            @update:open="$event || (rows.groupsRow.value = null)"
            @confirm="rows.saveGroups()"
        >
            <div class="grid gap-1.5 text-sm">
                <label for="medicine-allergy-groups" class="font-medium"
                    >Drug groups (up to 10, 3 to 60 characters each)</label
                >
                <textarea
                    id="medicine-allergy-groups"
                    v-model="rows.groupsText.value"
                    rows="5"
                    maxlength="700"
                    :class="[
                        textAreaClass,
                        rows.groupsError.value
                            ? 'border-destructive'
                            : 'border-input',
                    ]"
                    :aria-invalid="rows.groupsError.value ? 'true' : undefined"
                    aria-describedby="medicine-allergy-groups-error"
                    data-field="allergy-groups"
                ></textarea>
                <InputError
                    id="medicine-allergy-groups-error"
                    :message="rows.groupsError.value"
                />
            </div>
        </V2Dialog>

        <V2Dialog
            :open="rows.aliasesRow.value !== null"
            title="Other names"
            :description="`${rows.aliasesRow.value?.displayName}: brand names and common misspellings, one per line (for example Augmentin, amoxycillin). These are used only to match a patient's recorded allergies at Dispensary. They are never printed on drug labels and do not change the medicine name.`"
            confirm-label="Save"
            :busy="rows.aliasesBusy.value"
            @update:open="$event || (rows.aliasesRow.value = null)"
            @confirm="rows.saveAliases()"
        >
            <div class="grid gap-1.5 text-sm">
                <label for="medicine-allergy-aliases" class="font-medium"
                    >Other names (up to 10, 3 to 60 characters each)</label
                >
                <textarea
                    id="medicine-allergy-aliases"
                    v-model="rows.aliasesText.value"
                    rows="5"
                    maxlength="700"
                    :placeholder="'Augmentin\namoxycillin'"
                    :class="[
                        textAreaClass,
                        rows.aliasesError.value
                            ? 'border-destructive'
                            : 'border-input',
                    ]"
                    :aria-invalid="rows.aliasesError.value ? 'true' : undefined"
                    aria-describedby="medicine-allergy-aliases-error"
                    data-field="allergy-aliases"
                ></textarea>
                <InputError
                    id="medicine-allergy-aliases-error"
                    :message="rows.aliasesError.value"
                />
            </div>
        </V2Dialog>

        <V2LeaveGuard ref="guard" :dirty="c.isDirty.value" />
    </PageFrame>
</template>
