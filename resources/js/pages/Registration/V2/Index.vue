<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import WhatsAppLink from '@/components/patient/WhatsAppLink.vue';
import AttentionList from '@/components/registration-v2/AttentionList.vue';
import QrIntakeList from '@/components/registration-v2/QrIntakeList.vue';
import RowDialogs from '@/components/registration-v2/RowDialogs.vue';
import { DateField } from '@/components/ui/date-field';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2DisclosureBar from '@/components/v2/V2DisclosureBar.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2RowMenu from '@/components/v2/V2RowMenu.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2Toolbar from '@/components/v2/V2Toolbar.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import WaitRing from '@/components/v2/WaitRing.vue';
import {
    SEARCH_INPUT_ID,
    useRegistrationBoard,
} from '@/composables/registration/useRegistrationBoard';
import type { BoardTab } from '@/composables/registration/useRegistrationBoard';
import { useRegistrationRowActions } from '@/composables/registration/useRegistrationRowActions';
import { formatDate } from '@/lib/presentation';
import { servingDurationLabel } from '@/lib/r1c2-presentation';
import {
    needsAttention,
    rowMenu,
    rowStatus,
    waitStats,
    waitingMinutes,
} from '@/lib/registration-v2';
import type { VisitOptions, VisitRow } from '@/types';

/**
 * Version 2 Registration board (shown only in V2; Classic is `pages/Registration/Index.vue`, untouched). Same props,
 * same `POST /registration/search`, same routes, permissions, lock versions and payloads. No backend change.
 */
const props = defineProps<{
    visits: {
        data: VisitRow[];
        total: number;
        currentPage: number;
        lastPage: number;
        tabCounts?: Record<string, number>;
    };
    options: Pick<VisitOptions, 'branch' | 'doctors'>;
    canCreate: boolean;
    qrIntakes: {
        branch: { id: number; code: string; name: string };
        pendingCount: number;
        items: Array<{
            publicId: string;
            status: string;
            displayStatus:
                'pending' | 'reviewed' | 'rejected' | 'expired' | 'data_purged';
            submissionType: 'patient' | 'guardian';
            submittedAt: string;
            expiresAt: string;
            lockVersion: number;
            canVerify: boolean;
            visitUrl: string | null;
            summary: {
                name: string;
                age: number | null;
                purpose: string | null;
                complaint: string | null;
                duration: string | null;
            } | null;
        }>;
        history: { total: number; currentPage: number; lastPage: number };
    } | null;
}>();
const page = usePage();

const dialogKind = ref<'notReady' | 'ready' | 'cancel' | null>(null);
const dialogVisit = ref<VisitRow | null>(null);
// Declared after `board` because the row actions refresh it; `pageBusy` only reads `actions` later, never during setup.
const board = useRegistrationBoard({
    visits: props.visits,
    timezone: props.options.branch.timezone,
    hasQrIntakes: !!props.qrIntakes,
    initialTab:
        new URLSearchParams(page.url.split('?', 2)[1] ?? '').get('tab') ===
            'qr-intake' && props.qrIntakes
            ? 'qr-intake'
            : 'all',
    pageBusy: () => dialogKind.value !== null || actions.busyKey.value !== null,
});
const {
    tabCounts,
    rows,
    total,
    currentPage,
    lastPage,
    loading,
    error,
    liveRefreshNotice,
    searchComposing,
    activeTab,
    form,
    isQrIntake,
    advancedFilterCount,
} = board;
const actions = useRegistrationRowActions({
    branchId: props.options.branch.id,
    onError: (message) => {
        error.value = message;
    },
    onSucceeded: () => board.refreshAppliedBoard((run) => void nextTick(run)),
    openCancel: (visit) => {
        dialogVisit.value = visit;
        dialogKind.value = 'cancel';
    },
    openReadiness: (visit) => {
        dialogVisit.value = visit;
        dialogKind.value = visit.callHold?.active ? 'ready' : 'notReady';
    },
});

// Dispensary and Awaiting Billing have no count for a role that may not use them (the server returns 404 for them),
// so such a tab is not shown. Decided once from the first page, so a failed search cannot make a tab vanish.
const countedTabs = new Set(Object.keys(props.visits.tabCounts ?? {}));
const tabs = computed(() => {
    const list: Array<{ value: string; label: string; count?: number }> = [
        { value: 'all', label: 'All' },
        { value: 'waiting', label: 'Waiting' },
        { value: 'serving', label: 'Serving now' },
        { value: 'dispensary', label: 'Dispensary' },
        { value: 'billing', label: 'Awaiting billing' },
        { value: 'completed', label: 'Completed' },
        { value: 'cancelled', label: 'Cancelled' },
    ]
        .filter(
            (tab) =>
                !['dispensary', 'billing'].includes(tab.value) ||
                countedTabs.has(tab.value),
        )
        .map((tab) => ({ ...tab, count: tabCounts.value?.[tab.value] }));

    if (props.qrIntakes) {
        list.push({
            value: 'qr-intake',
            label: 'QR intake',
            count: props.qrIntakes.pendingCount,
        });
    }

    return list;
});
const doctorOptions = computed(() => [
    { value: '', label: 'All doctors' },
    ...props.options.doctors.map((doctor) => ({
        value: doctor.id,
        label: doctor.name,
    })),
]);
const coverageOptions = [
    { value: '', label: 'All coverage' },
    { value: 'self_pay', label: 'Self-pay' },
    { value: 'panel', label: 'Panel' },
];
const urgencyOptions = [
    { value: '', label: 'All urgency' },
    { value: 'urgent', label: 'Urgent' },
    { value: 'normal', label: 'Normal' },
];
const visitTypeOptions = [
    { value: '', label: 'All' },
    { value: 'consultation', label: 'Consultation' },
    { value: 'otc', label: 'OTC' },
];
const compactTime = (value: string) => {
    const [hour, minute] = value.split(':').map(Number);

    if (hour === undefined || minute === undefined) {
        return value;
    }

    return new Intl.DateTimeFormat('en-MY', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
        timeZone: 'UTC',
    })
        .format(new Date(Date.UTC(2000, 0, 1, hour, minute)))
        .replace(/\b(am|pm)\b/gi, (period) => period.toUpperCase());
};
const durationText = (visit: VisitRow) =>
    visit.isHeld
        ? 'On hold'
        : visit.queueStatus === 'serving'
          ? servingDurationLabel(visit.durationMinutes)
          : '—';
const stats = computed(() => waitStats(rows.value));
const resultLine = computed(
    () =>
        `${total.value} ${total.value === 1 ? 'visit' : 'visits'}` +
        (stats.value.waitingNow
            ? ` · ${stats.value.waitingNow} waiting · longest ${stats.value.longestWait} min`
            : '') +
        (form.date_from === board.today && form.date_to === board.today
            ? ''
            : ` · ${
                  form.date_from === form.date_to
                      ? formatDate(form.date_from)
                      : `${formatDate(form.date_from)}–${formatDate(form.date_to)}`
              }`),
);
const rowsWithMenu = computed(() =>
    rows.value.map((visit) => ({
        visit,
        status: rowStatus(visit),
        minutes: waitingMinutes(visit),
        menu: rowMenu(visit),
    })),
);
const attention = computed(() => needsAttention(rows.value));
// The Payment column is only there when a row on screen is a Panel visit or carries a Panel note.
const hasPanelRow = computed(() =>
    rows.value.some(
        (visit) => /panel/i.test(visit.coverageLabel) || !!visit.panelNote,
    ),
);
const sheetOpen = ref(false);
const viewRow = (visitNumber: string) => {
    const row = document.querySelector<HTMLElement>(
        `[data-visit="${visitNumber}"]`,
    );

    row?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    row?.focus({ preventScroll: true });
};
const isToday = (visit: VisitRow) => visit.registeredAtDate === board.today;

const rowClick = (event: MouseEvent, visit: VisitRow) => {
    if (
        !visit.historyUrl ||
        (event.target as HTMLElement).closest(
            'a, button, [role="menuitem"], [data-slot="v2-row-menu"]',
        )
    ) {
        return;
    }

    router.visit(visit.historyUrl);
};
const selectItem = (visit: VisitRow, id: string) => {
    const target = rowMenu(visit).targets[id];

    if (!target) {
        return;
    }

    if (target.kind === 'link') {
        router.visit(target.href);
    } else {
        actions.run(visit, target.action);
    }
};
const changeQrHistoryPage = (qrHistoryPage: number) => {
    router.reload({
        only: ['qrIntakes'],
        data: { qr_history_page: qrHistoryPage },
    });
};
const selectTab = (value: string) => board.selectTab(value as BoardTab);
const openQrIntake = (event: MouseEvent) => {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.button) {
        return;
    }

    event.preventDefault();
    selectTab('qr-intake');
};
const filtersOpen = ref(false);
const filterBadge = computed(
    () =>
        advancedFilterCount.value +
        [form.doctor_id, form.coverage_type, form.priority].filter(Boolean)
            .length,
);
</script>

<template>
    <Head title="Registration" />
    <PageFrame width="wide">
        <V2WorkLayout
            title="Registration"
            rail-from="2xl"
            rail-label="Needs attention"
            :description="`${options.branch.name} · Register, send to waiting, call and follow every visit.`"
        >
            <template #actions>
                <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                    <Link
                        v-if="qrIntakes && !isQrIntake"
                        href="/registration?tab=qr-intake"
                        class="inline-flex h-11 items-center justify-center gap-2 rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none max-sm:order-2 lg:h-9"
                        @click="openQrIntake"
                        >QR intake
                        <span class="whitespace-nowrap"
                            ><V2Chip
                                :tone="
                                    qrIntakes.pendingCount
                                        ? 'warning'
                                        : 'neutral'
                                "
                                :label="`${qrIntakes.pendingCount} to review`"
                        /></span>
                    </Link>
                    <Link
                        v-if="canCreate"
                        href="/registration/create"
                        class="inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none max-sm:order-1 lg:h-9"
                        >Register Visit</Link
                    >
                </div>
            </template>

            <template #rail-collapsed>
                <V2DisclosureBar
                    v-if="!isQrIntake && attention.length"
                    :title="`${attention.length} needs attention`"
                    tone="warning"
                >
                    <AttentionList :items="attention" @view="viewRow" />
                </V2DisclosureBar>
                <p
                    v-else-if="!isQrIntake"
                    class="text-sm text-muted-foreground"
                >
                    Nothing needs attention on this screen.
                </p>
            </template>

            <template #main>
                <h2 class="sr-only">Registration board</h2>
                <V2Tabs
                    :model-value="activeTab"
                    :tabs="tabs"
                    ariaLabel="Patient board status"
                    @update:model-value="selectTab"
                />

                <QrIntakeList
                    v-if="isQrIntake && qrIntakes"
                    :branch="qrIntakes.branch"
                    :items="qrIntakes.items"
                    :history="qrIntakes.history"
                    @history-page-change="changeQrHistoryPage"
                />

                <template v-else>
                    <form
                        class="flex flex-col gap-3"
                        @submit.prevent="board.search(1)"
                        @compositionstart="searchComposing = true"
                        @compositionend="searchComposing = false"
                    >
                        <V2Toolbar
                            v-model="form.patient_query"
                            :input-id="SEARCH_INPUT_ID"
                            search-label="Search patients"
                            search-placeholder="Patient name or exact Patient No."
                            @search="board.search(1)"
                        >
                            <template #filters>
                                <div class="hidden w-44 sm:block">
                                    <OperationalSelect
                                        v-model="form.doctor_id"
                                        label="Doctor"
                                        :options="doctorOptions"
                                    />
                                </div>
                                <div class="hidden w-44 sm:block">
                                    <OperationalSelect
                                        v-model="form.coverage_type"
                                        label="Coverage"
                                        :options="coverageOptions"
                                    />
                                </div>
                                <div class="hidden w-44 sm:block">
                                    <OperationalSelect
                                        v-model="form.priority"
                                        label="Urgency"
                                        :options="urgencyOptions"
                                    />
                                </div>
                            </template>
                            <template #primary>
                                <div class="flex gap-2 max-sm:w-full">
                                    <button
                                        type="button"
                                        class="inline-flex h-11 flex-1 items-center justify-center gap-1.5 rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:hidden"
                                        @click="sheetOpen = true"
                                    >
                                        Filters{{
                                            filterBadge
                                                ? ` (${filterBadge})`
                                                : ''
                                        }}
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex h-11 items-center justify-center gap-1.5 rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none max-sm:hidden lg:h-9"
                                        :aria-expanded="filtersOpen"
                                        aria-controls="v2-registration-filters"
                                        @click="filtersOpen = !filtersOpen"
                                    >
                                        More filters
                                    </button>
                                    <button
                                        type="submit"
                                        class="inline-flex h-11 flex-1 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 sm:flex-none lg:h-9"
                                        :disabled="loading"
                                    >
                                        {{ loading ? 'Applying…' : 'Apply' }}
                                    </button>
                                </div>
                            </template>
                        </V2Toolbar>
                        <div
                            v-if="filtersOpen"
                            id="v2-registration-filters"
                            class="flex flex-wrap items-end gap-2 rounded-[14px] border bg-card p-3 max-sm:hidden"
                        >
                            <label class="grid min-w-40 gap-1 text-xs"
                                >Visit type
                                <OperationalSelect
                                    v-model="form.visit_type"
                                    label="Visit type"
                                    :options="visitTypeOptions"
                                />
                            </label>
                            <label class="grid gap-1 text-xs"
                                >From<DateField
                                    v-model="form.date_from"
                                    :echo="false"
                            /></label>
                            <label class="grid gap-1 text-xs"
                                >To<DateField
                                    v-model="form.date_to"
                                    :echo="false"
                            /></label>
                            <button
                                type="button"
                                class="inline-flex h-11 items-center justify-center rounded-[10px] px-4 text-sm font-medium text-primary hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                                @click="board.resetFilters"
                            >
                                Reset
                            </button>
                        </div>
                        <p
                            v-if="advancedFilterCount"
                            class="text-xs text-muted-foreground"
                        >
                            {{ advancedFilterCount }} extra
                            {{
                                advancedFilterCount === 1 ? 'filter' : 'filters'
                            }}
                            applied
                        </p>
                        <InputError :message="error" />
                    </form>

                    <div
                        class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 text-[13px] text-muted-foreground"
                    >
                        <p>{{ resultLine }} · 25 per page</p>
                        <p role="status">
                            {{
                                liveRefreshNotice ||
                                'Live · refreshes every 10 seconds'
                            }}
                        </p>
                    </div>

                    <V2Table
                        label="Visits"
                        :columns="6"
                        cards="xl"
                        min-width="880px"
                        :empty="!rows.length"
                        :empty-message="
                            error || 'No Patients match this operational view.'
                        "
                        :loading="loading && !rows.length"
                    >
                        <template #head>
                            <tr>
                                <th scope="col" class="xl:min-w-48">Patient</th>
                                <th scope="col" class="whitespace-nowrap">
                                    Arrived
                                </th>
                                <th scope="col">Doctor and notes</th>
                                <th v-if="hasPanelRow" scope="col">Payment</th>
                                <th scope="col" class="xl:min-w-40">
                                    Status and wait
                                </th>
                                <th
                                    scope="col"
                                    class="w-px text-right whitespace-nowrap"
                                >
                                    Actions
                                </th>
                            </tr>
                        </template>
                        <template #body>
                            <tr
                                v-for="item in rowsWithMenu"
                                :key="item.visit.visitNumber"
                                :data-visit="item.visit.visitNumber"
                                tabindex="-1"
                                :class="
                                    item.visit.historyUrl
                                        ? 'cursor-pointer'
                                        : ''
                                "
                                @click="rowClick($event, item.visit)"
                            >
                                <td
                                    data-label="Patient"
                                    data-primary
                                    class="xl:min-w-48"
                                >
                                    <span class="block text-sm font-medium">{{
                                        item.visit.patientName
                                    }}</span>
                                    <span
                                        class="block text-xs font-normal text-muted-foreground"
                                        ><template v-if="item.visit.queueNumber"
                                            >{{
                                                item.visit.queueNumber
                                            }}
                                            · </template
                                        >{{ item.visit.patientNumber
                                        }}<span class="xl:hidden">
                                            ·
                                            {{
                                                compactTime(
                                                    item.visit.registeredAt,
                                                )
                                            }}<template
                                                v-if="!isToday(item.visit)"
                                                >,
                                                {{
                                                    formatDate(
                                                        item.visit
                                                            .registeredAtDate,
                                                        options.branch.timezone,
                                                    )
                                                }}</template
                                            ></span
                                        ></span
                                    >
                                </td>
                                <td
                                    data-label="Arrived"
                                    class="text-sm max-xl:!hidden"
                                >
                                    <span
                                        class="whitespace-nowrap max-xl:text-right xl:contents"
                                    >
                                        <span class="block">{{
                                            compactTime(item.visit.registeredAt)
                                        }}</span>
                                        <span
                                            v-if="!isToday(item.visit)"
                                            class="block text-xs text-muted-foreground"
                                            >{{
                                                formatDate(
                                                    item.visit.registeredAtDate,
                                                    options.branch.timezone,
                                                )
                                            }}</span
                                        >
                                    </span>
                                </td>
                                <td
                                    class="min-w-0 text-sm max-xl:max-w-none max-xl:justify-start max-xl:!text-left xl:max-w-32"
                                >
                                    <span class="min-w-0 xl:contents">
                                        <span
                                            class="block truncate"
                                            :title="
                                                item.visit.doctorName ??
                                                'Unassigned'
                                            "
                                            >{{
                                                item.visit.doctorName ??
                                                'Unassigned'
                                            }}</span
                                        >
                                        <span
                                            class="block truncate text-xs text-muted-foreground"
                                            :title="
                                                item.visit.visitReasonExcerpt ??
                                                'No reason recorded'
                                            "
                                            >{{
                                                item.visit.visitReasonExcerpt ??
                                                'No reason recorded'
                                            }}</span
                                        >
                                    </span>
                                </td>
                                <td
                                    v-if="hasPanelRow"
                                    data-label="Payment"
                                    class="text-sm xl:whitespace-nowrap"
                                    :class="
                                        !/panel/i.test(
                                            item.visit.coverageLabel,
                                        ) && !item.visit.panelNote
                                            ? 'max-xl:!hidden'
                                            : ''
                                    "
                                >
                                    <span
                                        class="max-xl:min-w-0 max-xl:text-right max-xl:break-words xl:contents"
                                    >
                                        <span
                                            class="block xl:max-w-36 xl:truncate"
                                            :title="item.visit.coverageLabel"
                                            >{{
                                                item.visit.coverageLabel
                                            }}</span
                                        >
                                        <span
                                            v-if="item.visit.panelNote"
                                            class="block max-w-36 truncate text-xs text-muted-foreground max-xl:line-clamp-1 max-xl:max-w-none max-xl:break-words max-xl:whitespace-normal"
                                            :title="item.visit.panelNote"
                                            >{{ item.visit.panelNote }}</span
                                        >
                                    </span>
                                </td>
                                <td
                                    data-status
                                    class="max-xl:order-[-1] max-xl:justify-start"
                                >
                                    <div
                                        class="flex min-w-0 flex-col gap-1.5 max-xl:flex-row max-xl:flex-wrap max-xl:items-center max-xl:gap-x-3"
                                    >
                                        <div
                                            class="flex flex-wrap items-center gap-1.5 max-xl:justify-end"
                                        >
                                            <V2Chip
                                                :tone="item.status.tone"
                                                :label="item.status.label"
                                            />
                                            <V2Chip
                                                v-if="
                                                    item.visit.priority ===
                                                    'urgent'
                                                "
                                                tone="danger"
                                                label="Urgent"
                                            />
                                            <V2Chip
                                                v-if="
                                                    item.visit
                                                        .returnedFromDispensary
                                                "
                                                tone="neutral"
                                                label="Returned"
                                            />
                                            <V2Chip
                                                v-if="
                                                    item.visit.callHold
                                                        ?.active ||
                                                    item.visit.callHold
                                                        ?.overridden
                                                "
                                                tone="warning"
                                                :label="
                                                    item.visit.callHold.active
                                                        ? 'Not ready'
                                                        : 'Overridden by doctor'
                                                "
                                                :title="
                                                    item.visit.callHold
                                                        .reason ?? undefined
                                                "
                                                data-testid="call-readiness-badge"
                                            />
                                            <span
                                                v-if="item.visit.completedAt"
                                                class="text-xs text-muted-foreground"
                                                >{{
                                                    item.visit.completedAt
                                                }}</span
                                            >
                                        </div>
                                        <p
                                            v-if="item.visit.callHold?.active"
                                            class="max-w-36 truncate text-xs text-muted-foreground max-xl:text-right"
                                            :title="
                                                item.visit.callHold.reason ??
                                                undefined
                                            "
                                        >
                                            {{ item.visit.callHold.reason }}
                                        </p>
                                        <WaitRing
                                            v-if="item.minutes !== null"
                                            :minutes="item.minutes"
                                        />
                                        <span
                                            v-else-if="
                                                durationText(item.visit) !== '—'
                                            "
                                            class="text-xs text-muted-foreground"
                                            >{{
                                                durationText(item.visit)
                                            }}</span
                                        >
                                    </div>
                                </td>
                                <td
                                    data-actions
                                    class="text-right xl:w-px xl:whitespace-nowrap"
                                >
                                    <div
                                        class="flex items-center justify-end gap-1 max-xl:gap-2 max-xl:[&_[data-slot=v2-row-menu]]:flex-1"
                                    >
                                        <WhatsAppLink
                                            :can-whatsapp="
                                                item.visit.canWhatsapp
                                            "
                                            :route-key="item.visit.visitNumber"
                                            context="visit"
                                            compact
                                        />
                                        <V2RowMenu
                                            :label="`Actions for ${item.visit.patientName}`"
                                            :groups="item.menu.groups"
                                            :busy="
                                                actions.busyKey.value ===
                                                item.visit.visitNumber
                                            "
                                            show-label
                                            @select="
                                                selectItem(item.visit, $event)
                                            "
                                        />
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </V2Table>

                    <V2Pager
                        v-if="lastPage > 1"
                        :current-page="currentPage"
                        :last-page="lastPage"
                        :total="total"
                        :per-page="25"
                        :disabled="loading"
                        @change="board.search($event)"
                    />
                </template>
            </template>
            <template #rail>
                <section
                    v-if="!isQrIntake"
                    class="rounded-[14px] border bg-card"
                    aria-labelledby="v2-attention"
                >
                    <h2
                        id="v2-attention"
                        class="px-4 pt-3 text-[15px] font-semibold"
                    >
                        Needs attention
                    </h2>
                    <AttentionList
                        v-if="attention.length"
                        :items="attention"
                        @view="viewRow"
                    />
                    <p v-else class="px-4 py-4 text-sm text-muted-foreground">
                        Nothing needs attention on this screen.
                    </p>
                </section>
            </template>
        </V2WorkLayout>
    </PageFrame>

    <RowDialogs
        :kind="dialogKind"
        :visit="dialogVisit"
        :branch-id="options.branch.id"
        @close="dialogKind = null"
        @changed="board.refreshAppliedBoard((run) => void nextTick(run))"
    />
    <V2Dialog
        v-model:open="sheetOpen"
        title="Filters"
        confirm-label="Apply"
        cancel-label="Close"
        @confirm="
            sheetOpen = false;
            board.search(1);
        "
    >
        <div class="grid gap-3">
            <label class="grid gap-1 text-sm font-medium"
                >Doctor
                <OperationalSelect
                    v-model="form.doctor_id"
                    label="Doctor"
                    :options="doctorOptions"
                />
            </label>
            <label class="grid gap-1 text-sm font-medium"
                >Coverage
                <OperationalSelect
                    v-model="form.coverage_type"
                    label="Coverage"
                    :options="coverageOptions"
                />
            </label>
            <label class="grid gap-1 text-sm font-medium"
                >Urgency
                <OperationalSelect
                    v-model="form.priority"
                    label="Urgency"
                    :options="urgencyOptions"
                />
            </label>
            <label class="grid gap-1 text-sm font-medium"
                >Visit type
                <OperationalSelect
                    v-model="form.visit_type"
                    label="Visit type"
                    :options="visitTypeOptions"
                />
            </label>
            <label class="grid gap-1 text-sm font-medium"
                >From<DateField v-model="form.date_from" :echo="false"
            /></label>
            <label class="grid gap-1 text-sm font-medium"
                >To<DateField v-model="form.date_to" :echo="false"
            /></label>
            <button
                type="button"
                class="inline-flex h-11 items-center justify-center rounded-[10px] px-4 text-sm font-medium text-primary hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                @click="board.resetFilters"
            >
                Reset filters
            </button>
        </div>
    </V2Dialog>
</template>
