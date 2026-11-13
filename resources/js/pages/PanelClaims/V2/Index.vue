<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import PanelClaimFilterFields from '@/components/panel-v2/PanelClaimFilterFields.vue';
import PanelSelectionSummary from '@/components/panel-v2/PanelSelectionSummary.vue';
import PanelSummaryBlock from '@/components/panel-v2/PanelSummaryBlock.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { DateField } from '@/components/ui/date-field';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2DisclosureBar from '@/components/v2/V2DisclosureBar.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2RowMenu from '@/components/v2/V2RowMenu.vue';
import V2SelectionBar from '@/components/v2/V2SelectionBar.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2Toolbar from '@/components/v2/V2Toolbar.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePanelClaimsList } from '@/composables/panel-claims/usePanelClaimsList';
import type { Props } from '@/composables/panel-claims/usePanelClaimsList';
import {
    claimChipTone,
    claimMenuGroups,
    invoiceChip,
    invoiceMenuGroups,
} from '@/lib/panel-claims-v2';
import { myr } from '@/types/billing';

/**
 * Version 2 Panel Claims list (shown only in V2; Classic is `pages/PanelClaims/Index.vue`, untouched). Same props, same
 * `GET /panel-claims` query, same bulk-claim and claim-pack routes, rules and limits (the logic is a copy of Classic's:
 * `usePanelClaimsList`). Look only. No backend change.
 */
const props = defineProps<Props>();
const list = usePanelClaimsList(props);
const {
    atBulkMax,
    atPackMax,
    bulk,
    bulkErrorMessages,
    bulkMax,
    canMarkSubmitted,
    canPack,
    canSelect,
    clearPack,
    clearSelection,
    createClaim,
    createSelectedClaims,
    draft,
    error,
    filtersActive,
    go,
    headerState,
    invoiceMode,
    lockedReason,
    minSubmittedDate,
    packAllPanel,
    packAllSelected,
    packError,
    packHeaderState,
    packLockedReason,
    packMax,
    packPanel,
    packRows,
    packSelected,
    packTotalSen,
    panelOptions,
    printPack,
    rangeOptions,
    resetFilters,
    selectAllPanel,
    selectAllRows,
    selected,
    selectedPanel,
    selectedRows,
    selectedTotalSen,
    tabs,
    toggleAll,
    togglePackAll,
    togglePackRow,
    toggleRow,
    turnPage,
    allSelected,
    packAllRows,
    visitTypeLabel,
    visitTypeOptions,
} = list;

const apply = () => go({ claims_page: null, allocations_page: null });
const setRange = (value: string) => go({ range: value, claims_page: null });

const filtersPanelOpen = ref(false);
const sheetOpen = ref(false);

// Extra filters: every filter that is not the search box, the Panel or the date range.
const extraCount = computed(
    () =>
        [
            draft.claimNo,
            draft.amountMin,
            draft.amountMax,
            draft.invoiceNo,
            draft.doctor,
            draft.visitType,
            draft.visitFrom,
            draft.visitTo,
            draft.dueFrom,
            draft.dueTo,
            draft.diagnosis,
            draft.treatment,
        ].filter((value) => value !== '').length,
);
const filterBadge = computed(
    () =>
        extraCount.value +
        (draft.panel !== '' ? 1 : 0) +
        (props.work.filters.range !== 'all' ? 1 : 0),
);

const current = computed(() =>
    invoiceMode.value ? props.work.eligible : props.work.claims,
);

const bulkDialogOpen = ref(false);
watch(
    () => selectedRows.value.length,
    (count) => {
        if (count === 0) {
            bulkDialogOpen.value = false;
        }
    },
);
// Ticked claims that are not all on the page being shown (the pack selection is kept across pages).
const packAcrossPages = computed(
    () =>
        packRows.value.length >
        props.work.claims.data.filter((row) => packSelected.value[row.publicId])
            .length,
);

const selection = computed(() => {
    if (invoiceMode.value && selectedRows.value.length > 0) {
        const count = selectedRows.value.length;

        return {
            title: `${count} invoice${count === 1 ? '' : 's'} selected`,
            detail: `${selectedPanel.value} · ${myr(selectedTotalSen.value)}`,
            primaryLabel: `Create ${count} claim${count === 1 ? '' : 's'}`,
            clearLabel: 'Clear selection',
            busy: bulk.busy,
            ruleLine: `Only invoices of ${selectedPanel.value} can be added to this batch.`,
            notices: atBulkMax.value
                ? [
                      `${bulkMax} invoices selected, the most for one batch. Create these claims first, then select more.`,
                  ]
                : [],
            errors: bulkDialogOpen.value ? [] : bulkErrorMessages.value,
            ruleId: undefined as string | undefined,
            testId: 'bulk-claim-bar',
            primaryTestId: undefined as string | undefined,
            ariaLabel: 'Create claims for the selected invoices',
            primary: () => {
                bulkDialogOpen.value = true;
            },
            clear: clearSelection,
        };
    }

    if (!invoiceMode.value && packRows.value.length > 0) {
        const count = packRows.value.length;

        return {
            title: `${count} claim${count === 1 ? '' : 's'} selected${packAcrossPages.value ? ' (across pages)' : ''}`,
            detail: `${packPanel.value} · ${myr(packTotalSen.value)}`,
            primaryLabel: 'Print pack',
            clearLabel: 'Clear',
            busy: false,
            ruleLine: `Only claims of ${packPanel.value} can be added to this pack.`,
            notices: atPackMax.value
                ? [
                      `${packMax} claims selected, the most for one pack. Print these first, then select more.`,
                  ]
                : [],
            errors: [] as string[],
            ruleId: 'pack-panel-rule',
            testId: 'claim-pack-bar',
            primaryTestId: 'claim-pack-print',
            ariaLabel: 'Print the selected claims',
            primary: printPack,
            clear: clearPack,
        };
    }

    return null;
});
</script>

<template>
    <Head title="Panel Claims" />
    <PageFrame width="wide">
        <V2WorkLayout
            title="Panel Claims"
            rail-from="2xl"
            rail-label="Submissions and payouts"
            :description="`${work.branch.name} · Create claims for manual submission and track Panel decisions and remittances.`"
        >
            <template #actions>
                <Link
                    href="/panel-claims/follow-up"
                    class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                    >Follow-up list</Link
                >
                <Link
                    v-if="work.can.recordRemittances"
                    href="/panel-remittances"
                    class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                    >Record Panel payment</Link
                >
            </template>

            <template #rail-collapsed>
                <V2DisclosureBar
                    title="Submissions and payouts"
                    :tone="
                        work.summary.overdue.count > 0 ? 'warning' : 'neutral'
                    "
                >
                    <PanelSummaryBlock :summary="work.summary" />
                </V2DisclosureBar>
            </template>

            <template #main>
                <h2 class="sr-only">Panel Claims list</h2>
                <p
                    v-if="error"
                    role="alert"
                    class="rounded-[10px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
                >
                    {{ error }}
                </p>

                <V2Tabs
                    :model-value="work.filters.tab"
                    :tabs="tabs"
                    ariaLabel="Claim status"
                    @update:model-value="
                        (value) =>
                            go({
                                tab: value,
                                claims_page: null,
                                allocations_page: null,
                            })
                    "
                />

                <p
                    v-if="invoiceMode"
                    class="max-w-prose text-[13px] text-muted-foreground"
                >
                    Approved Panel invoices without a claim yet. Create claim
                    opens the creation page for a submitted claim with invoice
                    lines, patient name, member reference, visit date, branch
                    and invoice number. The claim summary excludes clinical
                    notes, diagnoses, identity documents or attachments. Photos
                    can be added as claim documents. A claim is overdue once the
                    Panel’s due days after the visit have passed.
                </p>

                <form @submit.prevent="apply">
                    <V2Toolbar
                        v-model="draft.name"
                        search-label="Name"
                        search-placeholder="Name"
                        @search="apply"
                    >
                        <template #filters>
                            <div class="hidden w-44 sm:block">
                                <OperationalSelect
                                    :model-value="work.filters.range"
                                    :options="rangeOptions"
                                    label="Date range"
                                    @update:model-value="
                                        (value) => setRange(String(value))
                                    "
                                />
                            </div>
                            <div class="hidden w-44 sm:block">
                                <OperationalSelect
                                    :model-value="draft.panel"
                                    :options="panelOptions"
                                    label="Panel"
                                    @update:model-value="
                                        (value) => {
                                            draft.panel = String(value);
                                            apply();
                                        }
                                    "
                                />
                            </div>
                            <template v-if="work.filters.range === 'custom'">
                                <label
                                    class="hidden min-w-0 gap-1 text-sm font-medium sm:grid sm:w-40"
                                    >From
                                    <DateField
                                        v-model="draft.from"
                                        :echo="false"
                                        @change="go({ claims_page: null })"
                                    />
                                </label>
                                <label
                                    class="hidden min-w-0 gap-1 text-sm font-medium sm:grid sm:w-40"
                                    >To
                                    <DateField
                                        v-model="draft.to"
                                        :echo="false"
                                        @change="go({ claims_page: null })"
                                    />
                                </label>
                            </template>
                        </template>
                        <template #primary>
                            <div class="flex gap-2 max-sm:w-full">
                                <button
                                    type="button"
                                    class="inline-flex h-11 flex-1 items-center justify-center gap-1.5 rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:hidden"
                                    @click="sheetOpen = true"
                                >
                                    Filters{{
                                        filterBadge ? ` (${filterBadge})` : ''
                                    }}
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex h-11 items-center justify-center gap-1.5 rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none max-sm:hidden lg:h-9"
                                    :aria-expanded="filtersPanelOpen"
                                    aria-controls="v2-panel-claims-filters"
                                    @click="
                                        filtersPanelOpen = !filtersPanelOpen
                                    "
                                >
                                    Filters{{
                                        filterBadge ? ` (${filterBadge})` : ''
                                    }}
                                </button>
                                <button
                                    type="submit"
                                    class="inline-flex h-11 flex-1 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:flex-none lg:h-9"
                                >
                                    Apply
                                </button>
                            </div>
                        </template>
                    </V2Toolbar>
                    <div
                        v-if="filtersPanelOpen"
                        id="v2-panel-claims-filters"
                        class="mt-3 flex flex-wrap items-end gap-2 rounded-[14px] border bg-card p-3 max-sm:hidden [&>*]:w-44"
                    >
                        <PanelClaimFilterFields
                            :draft="draft"
                            :invoice-mode="invoiceMode"
                            :clinical-filters="work.can.clinicalFilters"
                            :range="work.filters.range"
                            :range-options="rangeOptions"
                            :panel-options="panelOptions"
                            :visit-type-options="visitTypeOptions"
                            @go="go"
                        />
                        <button
                            type="button"
                            class="inline-flex h-11 items-center rounded-[10px] px-4 text-sm font-medium text-primary hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                            @click="resetFilters"
                        >
                            Reset
                        </button>
                    </div>
                </form>
                <div
                    v-if="filtersActive && !filtersPanelOpen"
                    class="flex flex-wrap items-center gap-x-3 text-[13px] text-muted-foreground"
                >
                    <span
                        >{{ filterBadge }} filter{{
                            filterBadge === 1 ? '' : 's'
                        }}
                        applied</span
                    >
                    <button
                        type="button"
                        class="min-h-11 text-sm font-medium text-primary underline-offset-4 hover:underline lg:min-h-9"
                        @click="resetFilters"
                    >
                        Reset
                    </button>
                </div>

                <p
                    v-if="!invoiceMode && packError"
                    role="alert"
                    class="rounded-[10px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm wrap-anywhere text-destructive"
                    data-testid="claim-pack-error"
                >
                    {{ packError }}
                </p>

                <!-- Select all, for cards (below 1280 px); on the table it is the tick in the header. -->
                <label
                    v-if="invoiceMode && work.eligible.data.length > 0"
                    class="flex min-h-11 items-center gap-3 rounded-[10px] border bg-card px-3 text-sm xl:hidden"
                    :class="
                        selectAllRows.length === 0
                            ? 'text-muted-foreground'
                            : ''
                    "
                >
                    <Checkbox
                        :model-value="headerState"
                        :disabled="selectAllRows.length === 0"
                        :aria-label="
                            selectAllPanel
                                ? `Select all ${selectAllPanel} invoices on this page`
                                : 'Filter by one Panel to select all invoices on this page'
                        "
                        @update:model-value="() => toggleAll(!allSelected)"
                    />
                    <span class="min-w-0 wrap-anywhere">
                        {{
                            selectAllPanel
                                ? `Select all ${selectAllPanel} invoices on this page`
                                : 'Filter by one Panel to select all invoices on this page'
                        }}
                    </span>
                </label>
                <label
                    v-if="!invoiceMode && work.claims.data.length > 0"
                    class="flex min-h-11 items-center gap-3 rounded-[10px] border bg-card px-3 text-sm xl:hidden"
                    :class="
                        packAllRows.length === 0 ? 'text-muted-foreground' : ''
                    "
                >
                    <Checkbox
                        id="pack-select-all-phone"
                        :model-value="packHeaderState"
                        :disabled="packAllRows.length === 0"
                        :aria-label="
                            packAllPanel
                                ? `Select all ${packAllPanel} claims on this page`
                                : 'Filter by one Panel to select all claims on this page'
                        "
                        @update:model-value="
                            () => togglePackAll(!packAllSelected)
                        "
                    />
                    <span class="min-w-0 wrap-anywhere">
                        {{
                            packAllPanel
                                ? `Select all ${packAllPanel} claims on this page`
                                : 'Filter by one Panel to select all claims on this page'
                        }}
                    </span>
                </label>

                <!-- Invoices without a claim (Pending and Overdue). -->
                <V2Table
                    v-if="invoiceMode"
                    label="Approved Panel invoices without a claim"
                    :columns="8"
                    cards="xl"
                    min-width="860px"
                    data-testid="invoice-cards"
                    :empty="work.eligible.data.length === 0"
                    :empty-message="`${
                        work.filters.tab === 'overdue'
                            ? 'No overdue invoices'
                            : 'No pending invoices'
                    }. Approved Panel invoices without a claim appear here. Change the date range or filters to see more.`"
                >
                    <template #head>
                        <tr>
                            <th scope="col" class="w-14">
                                <label
                                    class="inline-flex min-h-11 min-w-11 items-center justify-center"
                                >
                                    <Checkbox
                                        :model-value="headerState"
                                        :disabled="selectAllRows.length === 0"
                                        :aria-label="
                                            selectAllPanel
                                                ? `Select all ${selectAllPanel} invoices on this page`
                                                : 'Filter by one Panel to select all invoices on this page'
                                        "
                                        @update:model-value="
                                            () => toggleAll(!allSelected)
                                        "
                                    />
                                </label>
                            </th>
                            <th scope="col">Patient name</th>
                            <th scope="col">Invoice no</th>
                            <th scope="col" class="text-right">Amount</th>
                            <th scope="col">Status</th>
                            <th scope="col">Dates</th>
                            <th scope="col">Remark note</th>
                            <th scope="col" class="text-right">Actions</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr
                            v-for="row in work.eligible.data"
                            :key="row.allocationId"
                            :class="
                                selected[row.allocationId] ? 'bg-primary/5' : ''
                            "
                        >
                            <td data-select>
                                <label
                                    class="inline-flex min-h-11 min-w-11 items-center justify-center gap-2"
                                >
                                    <Checkbox
                                        :model-value="
                                            Boolean(selected[row.allocationId])
                                        "
                                        :disabled="
                                            !canSelect(row) ||
                                            (atBulkMax &&
                                                !selected[row.allocationId])
                                        "
                                        :title="lockedReason(row)"
                                        :aria-label="`Select invoice ${row.invoiceNumber} for a bulk claim`"
                                        @update:model-value="
                                            (value) =>
                                                toggleRow(row, value === true)
                                        "
                                    />
                                    <span
                                        v-if="lockedReason(row)"
                                        class="max-w-52 text-xs font-normal text-muted-foreground xl:hidden"
                                        >{{ lockedReason(row) }}</span
                                    >
                                </label>
                            </td>
                            <td data-label="Patient name" data-primary>
                                <span class="block text-sm font-medium">{{
                                    row.patientName
                                }}</span>
                                <span
                                    class="block text-xs font-normal text-muted-foreground"
                                    >{{ row.visitNumber }} ·
                                    {{ visitTypeLabel(row.visitType) }}</span
                                >
                            </td>
                            <td data-label="Invoice no" class="text-sm">
                                <span
                                    class="block font-mono text-xs max-xl:text-right"
                                    >{{ row.invoiceNumber }}</span
                                >
                                <span
                                    class="block text-xs text-muted-foreground max-xl:text-right"
                                    >{{ row.panelName }}</span
                                >
                            </td>
                            <td
                                data-label="Amount"
                                class="text-right text-sm whitespace-nowrap"
                            >
                                {{ myr(row.amountSen) }}
                            </td>
                            <td data-label="Status">
                                <V2Chip
                                    :tone="invoiceChip(row.overdue).tone"
                                    :label="invoiceChip(row.overdue).label"
                                />
                            </td>
                            <td
                                class="text-sm whitespace-nowrap max-xl:!hidden"
                            >
                                <span class="block"
                                    >Visit {{ row.visitDate }}</span
                                >
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >Due {{ row.dueDate }}</span
                                >
                            </td>
                            <td
                                data-label="Visit date"
                                class="text-sm whitespace-nowrap xl:hidden"
                            >
                                {{ row.visitDate }}
                            </td>
                            <td
                                data-label="Due date"
                                class="text-sm whitespace-nowrap xl:hidden"
                            >
                                {{ row.dueDate }}
                            </td>
                            <td
                                v-if="row.doctorName"
                                data-label="Doctor"
                                class="text-sm xl:hidden"
                            >
                                {{ row.doctorName }}
                            </td>
                            <td
                                data-label="Remark note"
                                class="max-w-44 text-sm max-xl:max-w-none max-xl:!text-right xl:truncate"
                                :class="row.remarkNote ? '' : 'max-xl:!hidden'"
                            >
                                {{ row.remarkNote }}
                            </td>
                            <td data-actions class="text-right">
                                <div
                                    class="flex items-center justify-end gap-2 max-xl:justify-between"
                                >
                                    <button
                                        type="button"
                                        class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-3 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none max-xl:flex-1 lg:h-9"
                                        :aria-label="`Create claim for invoice ${row.invoiceNumber}`"
                                        @click="createClaim(row.allocationId)"
                                    >
                                        Create claim
                                    </button>
                                    <V2RowMenu
                                        :label="`Actions for invoice ${row.invoiceNumber}`"
                                        :groups="
                                            invoiceMenuGroups(row.invoiceUrl)
                                        "
                                    />
                                </div>
                            </td>
                        </tr>
                    </template>
                </V2Table>

                <!-- Claims (every other tab). -->
                <V2Table
                    v-else
                    label="Panel claims"
                    :columns="8"
                    cards="xl"
                    min-width="860px"
                    data-testid="claim-cards"
                    :empty="work.claims.data.length === 0"
                    empty-message="No claims match. Create a claim from the Pending or Overdue tab, or change the tab, date range or filters."
                >
                    <template #head>
                        <tr>
                            <th scope="col" class="w-14">
                                <label
                                    class="inline-flex min-h-11 min-w-11 items-center justify-center"
                                >
                                    <Checkbox
                                        id="pack-select-all"
                                        :model-value="packHeaderState"
                                        :disabled="packAllRows.length === 0"
                                        :aria-label="
                                            packAllPanel
                                                ? `Select all ${packAllPanel} claims on this page`
                                                : 'Filter by one Panel to select all claims on this page'
                                        "
                                        @update:model-value="
                                            () =>
                                                togglePackAll(!packAllSelected)
                                        "
                                    />
                                </label>
                            </th>
                            <th scope="col">Patient name</th>
                            <th scope="col">Claim no</th>
                            <th scope="col" class="text-right">Amount</th>
                            <th scope="col">Status</th>
                            <th scope="col">Date</th>
                            <th scope="col">Remarks</th>
                            <th scope="col" class="text-right">Actions</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr
                            v-for="row in work.claims.data"
                            :key="row.publicId"
                            :class="
                                packSelected[row.publicId] ? 'bg-primary/5' : ''
                            "
                        >
                            <td data-select>
                                <label
                                    class="inline-flex min-h-11 min-w-11 items-center justify-center gap-2"
                                >
                                    <Checkbox
                                        :model-value="
                                            Boolean(packSelected[row.publicId])
                                        "
                                        :disabled="
                                            !canPack(row) ||
                                            (atPackMax &&
                                                !packSelected[row.publicId])
                                        "
                                        :title="packLockedReason(row)"
                                        :aria-describedby="
                                            canPack(row)
                                                ? undefined
                                                : 'pack-panel-rule'
                                        "
                                        :aria-label="`Select claim ${row.claimNo} for a claim pack`"
                                        @update:model-value="
                                            (value) =>
                                                togglePackRow(
                                                    row,
                                                    value === true,
                                                )
                                        "
                                    />
                                    <span
                                        v-if="packLockedReason(row)"
                                        class="max-w-52 text-xs font-normal text-muted-foreground xl:hidden"
                                        >{{ packLockedReason(row) }}</span
                                    >
                                </label>
                            </td>
                            <td data-label="Patient name" data-primary>
                                <span class="block text-sm font-medium">{{
                                    row.patientName
                                }}</span>
                                <span
                                    class="block text-xs font-normal text-muted-foreground"
                                    >{{ row.visitNumber }} ·
                                    {{ row.invoiceNumber }}</span
                                >
                            </td>
                            <td data-label="Claim no" class="text-sm">
                                <span
                                    class="block font-mono text-xs max-xl:text-right"
                                    >{{ row.claimNo }}</span
                                >
                                <span
                                    class="block text-xs text-muted-foreground max-xl:text-right"
                                    >{{ row.panelName }}</span
                                >
                            </td>
                            <td
                                data-label="Amount"
                                class="text-right text-sm whitespace-nowrap"
                            >
                                {{ myr(row.claimAmountSen) }}
                            </td>
                            <td data-label="Status">
                                <V2Chip
                                    :tone="claimChipTone(row.status)"
                                    :label="row.status"
                                />
                            </td>
                            <td
                                class="text-sm whitespace-nowrap max-xl:!hidden"
                            >
                                <span class="block">{{ row.createdAt }}</span>
                                <span
                                    v-if="row.updatedBy"
                                    class="block text-xs text-muted-foreground"
                                    >Updated by {{ row.updatedBy }}</span
                                >
                            </td>
                            <td
                                data-label="Date"
                                class="text-sm whitespace-nowrap xl:hidden"
                            >
                                {{ row.createdAt }}
                            </td>
                            <td
                                v-if="row.updatedBy"
                                data-label="Updated by"
                                class="text-sm xl:hidden"
                            >
                                {{ row.updatedBy }}
                            </td>
                            <td
                                data-label="Remarks"
                                class="max-w-44 text-sm max-xl:max-w-none max-xl:!text-right xl:truncate"
                                :class="row.remarks ? '' : 'max-xl:!hidden'"
                            >
                                {{ row.remarks }}
                            </td>
                            <td data-actions class="text-right">
                                <V2RowMenu
                                    :label="`Actions for claim ${row.claimNo}`"
                                    :groups="
                                        claimMenuGroups(
                                            row.showUrl,
                                            canMarkSubmitted(row.status),
                                        )
                                    "
                                />
                            </td>
                        </tr>
                    </template>
                </V2Table>

                <V2Pager
                    v-if="current.lastPage > 1"
                    :current-page="current.currentPage"
                    :last-page="current.lastPage"
                    :total="current.total"
                    :per-page="20"
                    @change="
                        (value) =>
                            turnPage(
                                invoiceMode
                                    ? 'allocations_page'
                                    : 'claims_page',
                                value,
                            )
                    "
                />

                <p
                    v-if="!invoiceMode && packRows.length > 0"
                    class="text-[13px] text-muted-foreground"
                >
                    Prints a cover page and one A4 page per claim, with IC or
                    passport numbers. Opening the pack is recorded. The
                    selection is kept when you change page.
                </p>

                <V2SelectionBar
                    :visible="!!selection"
                    :summary="
                        selection
                            ? `${selection.title} · ${selection.detail}`
                            : ''
                    "
                    :action-label="selection?.primaryLabel ?? ''"
                    :secondary-label="selection?.clearLabel"
                    :busy="selection?.busy"
                    :hint-tone="
                        selection?.notices.length ? 'warning' : 'neutral'
                    "
                    :data-testid="selection?.testId"
                    @action="selection?.primary()"
                    @secondary="selection?.clear()"
                >
                    <template #hint>
                        <span :id="selection?.ruleId">{{
                            selection?.notices[0] ?? selection?.ruleLine
                        }}</span>
                    </template>
                </V2SelectionBar>
            </template>

            <template #rail>
                <PanelSummaryBlock :summary="work.summary" />
                <PanelSelectionSummary
                    v-if="selection"
                    :title="selection.title"
                    :detail="selection.detail"
                    :primary-label="selection.primaryLabel"
                    :clear-label="selection.clearLabel"
                    :busy="selection.busy"
                    :rule-line="selection.ruleLine"
                    :notices="selection.notices"
                    :errors="selection.errors"
                    :label="selection.ariaLabel"
                    @primary="selection.primary"
                    @clear="selection.clear"
                />
            </template>
        </V2WorkLayout>

        <V2Dialog
            v-model:open="bulkDialogOpen"
            :title="`Create ${selectedRows.length} claim${selectedRows.length === 1 ? '' : 's'}`"
            :confirm-label="`Create ${selectedRows.length} claim${selectedRows.length === 1 ? '' : 's'}`"
            :busy="bulk.busy"
            @confirm="createSelectedClaims()"
        >
            <div class="grid gap-3">
                <p class="text-[13px] text-muted-foreground">
                    Each invoice gets its own Submitted claim at its approved
                    amount, all with the same submitted date. Submit them on the
                    Panel's portal yourself; KPOne sends nothing. Add photos on
                    each claim page afterwards. If any invoice cannot be
                    claimed, no claim is created. At most {{ bulkMax }} invoices
                    at a time.
                </p>
                <label class="grid min-w-0 gap-1 text-sm font-medium"
                    >Submitted date
                    <DateField
                        v-model="bulk.submittedDate"
                        :min="minSubmittedDate || undefined"
                        :max="work.branch.today"
                        :echo="false"
                    />
                </label>
                <label class="grid min-w-0 gap-1 text-sm font-medium"
                    >Remarks (optional)
                    <input
                        v-model="bulk.remarks"
                        maxlength="500"
                        placeholder="The same remark goes on every claim"
                        class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9"
                    />
                </label>
                <p
                    v-for="message in bulkErrorMessages"
                    :key="message"
                    role="alert"
                    class="text-sm wrap-anywhere text-destructive"
                >
                    {{ message }}
                </p>
            </div>
        </V2Dialog>

        <V2Dialog
            v-model:open="sheetOpen"
            title="Filters"
            confirm-label="Apply"
            cancel-label="Close"
            @confirm="
                sheetOpen = false;
                apply();
            "
        >
            <div class="grid gap-3">
                <PanelClaimFilterFields
                    :draft="draft"
                    :invoice-mode="invoiceMode"
                    :clinical-filters="work.can.clinicalFilters"
                    :range="work.filters.range"
                    :range-options="rangeOptions"
                    :panel-options="panelOptions"
                    :visit-type-options="visitTypeOptions"
                    include-main
                    @go="go"
                    @update:range="setRange"
                />
                <button
                    type="button"
                    class="inline-flex h-11 items-center justify-center rounded-[10px] px-4 text-sm font-medium text-primary hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    @click="resetFilters"
                >
                    Reset
                </button>
            </div>
        </V2Dialog>
    </PageFrame>
</template>
