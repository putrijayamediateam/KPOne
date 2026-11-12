<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import WhatsAppLink from '@/components/patient/WhatsAppLink.vue';
import VisitCancellationDialog from '@/components/patient-board/VisitCancellationDialog.vue';
import CallReadinessActions from '@/components/queue/CallReadinessActions.vue';
import QrIntakeTable from '@/components/registration/QrIntakeTable.vue';
import { DateField } from '@/components/ui/date-field';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2RowMenu from '@/components/v2/V2RowMenu.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2Toolbar from '@/components/v2/V2Toolbar.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import WaitRing from '@/components/v2/WaitRing.vue';
import { useRegistrationBoard } from '@/composables/registration/useRegistrationBoard';
import { SEARCH_INPUT_ID } from '@/composables/registration/useRegistrationBoard';
import type { BoardTab } from '@/composables/registration/useRegistrationBoard';
import { useRegistrationRowActions } from '@/composables/registration/useRegistrationRowActions';
import { formatDate } from '@/lib/presentation';
import { servingDurationLabel } from '@/lib/r1c2-presentation';
import {
    legacyBoardRow,
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
defineOptions({
    layout: { breadcrumbs: [{ title: 'Registration', href: '/registration' }] },
});

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

const cancelOpen = ref(false);
const cancellationVisit = ref<VisitRow | null>(null);
const board = useRegistrationBoard({
    visits: props.visits,
    timezone: props.options.branch.timezone,
    hasQrIntakes: !!props.qrIntakes,
    initialTab:
        new URLSearchParams(page.url.split('?', 2)[1] ?? '').get('tab') ===
            'qr-intake' && props.qrIntakes
            ? 'qr-intake'
            : 'all',
    pageBusy: () => cancelOpen.value || actions.busyKey.value !== null,
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
        cancellationVisit.value = visit;
        cancelOpen.value = true;
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
const resultLine = computed(
    () =>
        `${total.value} ${total.value === 1 ? 'visit' : 'visits'}` +
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
        legacy: legacyBoardRow(visit),
    })),
);
const attention = computed(() => needsAttention(rows.value));
const stats = computed(() => waitStats(rows.value));

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
// The live refresh treats the board search box as safe to refresh around while its text is unchanged (as Classic).
// V2Toolbar owns the input and has no id prop yet, so the id is set here until that prop exists.
onMounted(() => {
    document
        .querySelector<HTMLInputElement>(
            '[data-slot="v2-toolbar"] input[type="search"]',
        )
        ?.setAttribute('id', SEARCH_INPUT_ID);
});
</script>

<template>
    <Head title="Registration" />
    <V2WorkLayout
        title="Registration"
        :description="`${options.branch.name} · Register, send to waiting, call and follow every visit.`"
        rail-label="Attention and waiting"
    >
        <template #actions>
            <Link
                v-if="qrIntakes && !isQrIntake"
                href="/registration?tab=qr-intake"
                class="inline-flex h-11 items-center gap-2 rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                @click.prevent="selectTab('qr-intake')"
                >QR intake
                <V2Chip
                    :tone="qrIntakes.pendingCount ? 'warning' : 'neutral'"
                    :label="`${qrIntakes.pendingCount} to review`"
            /></Link>
            <Link
                v-if="canCreate"
                href="/registration/create"
                class="inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none lg:h-9"
                >Register Visit</Link
            >
        </template>

        <template #main>
            <h2 class="sr-only">Registration board</h2>
            <V2Tabs
                :model-value="activeTab"
                :tabs="tabs"
                ariaLabel="Patient board status"
                @update:model-value="selectTab"
            >
                <template #trailing>
                    <span v-if="!isQrIntake" role="status">{{
                        liveRefreshNotice || 'Live · refreshes every 10 seconds'
                    }}</span>
                </template>
            </V2Tabs>

            <QrIntakeTable
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
                        search-label="Search patients"
                        search-placeholder="Patient name or exact Patient No."
                        more-filters
                        @search="board.search(1)"
                        @reset="board.resetFilters"
                    >
                        <template #filters>
                            <OperationalSelect
                                v-model="form.doctor_id"
                                label="Doctor"
                                :options="doctorOptions"
                            />
                            <OperationalSelect
                                v-model="form.coverage_type"
                                label="Coverage"
                                :options="coverageOptions"
                            />
                            <OperationalSelect
                                v-model="form.priority"
                                label="Urgency"
                                :options="urgencyOptions"
                            />
                        </template>
                        <template #primary>
                            <button
                                type="submit"
                                class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9"
                                :disabled="loading"
                            >
                                {{ loading ? 'Applying…' : 'Apply' }}
                            </button>
                        </template>
                        <template #more>
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
                        </template>
                    </V2Toolbar>
                    <p
                        v-if="advancedFilterCount"
                        class="text-xs text-muted-foreground"
                    >
                        {{ advancedFilterCount }} extra
                        {{ advancedFilterCount === 1 ? 'filter' : 'filters' }}
                        applied
                    </p>
                    <InputError :message="error" />
                </form>

                <p class="text-[13px] text-muted-foreground">
                    {{ resultLine }} · 25 per page
                </p>

                <V2Table
                    label="Visits"
                    :columns="8"
                    cards="lg"
                    min-width="960px"
                    :empty="!rows.length"
                    :empty-message="
                        error || 'No Patients match this operational view.'
                    "
                    :loading="loading && !rows.length"
                >
                    <template #head>
                        <tr>
                            <th scope="col">Patient</th>
                            <th scope="col">Queue</th>
                            <th scope="col">Arrived</th>
                            <th scope="col">Doctor and notes</th>
                            <th scope="col">Payment</th>
                            <th scope="col">Duration</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-right">Actions</th>
                        </tr>
                    </template>
                    <tr
                        v-for="item in rowsWithMenu"
                        :key="item.visit.visitNumber"
                        :class="item.visit.historyUrl ? 'cursor-pointer' : ''"
                        @click="rowClick($event, item.visit)"
                    >
                        <td data-label="Patient" data-primary>
                            <span class="block font-medium">{{
                                item.visit.patientName
                            }}</span>
                            <span class="block text-xs text-muted-foreground">{{
                                item.visit.patientNumber
                            }}</span>
                        </td>
                        <td data-label="Queue">
                            {{ item.visit.queueNumber ?? '—' }}
                        </td>
                        <td data-label="Arrived">
                            {{
                                formatDate(
                                    item.visit.registeredAtDate,
                                    options.branch.timezone,
                                )
                            }}
                            <span class="text-muted-foreground">{{
                                compactTime(item.visit.registeredAt)
                            }}</span>
                        </td>
                        <td data-label="Doctor and notes">
                            <span class="block">{{
                                item.visit.doctorName ?? 'Unassigned'
                            }}</span>
                            <span class="block text-xs text-muted-foreground">{{
                                item.visit.visitReasonExcerpt ??
                                'No reason recorded'
                            }}</span>
                        </td>
                        <td data-label="Payment">
                            <span class="block">{{
                                item.visit.coverageLabel
                            }}</span>
                            <span
                                v-if="item.visit.panelNote"
                                class="block max-w-56 truncate text-xs text-muted-foreground"
                                :title="item.visit.panelNote"
                                >{{ item.visit.panelNote }}</span
                            >
                        </td>
                        <td data-label="Duration">
                            <WaitRing
                                v-if="item.minutes !== null"
                                :minutes="item.minutes"
                            />
                            <template v-else>{{
                                durationText(item.visit)
                            }}</template>
                        </td>
                        <td data-label="Status">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <V2Chip
                                    :tone="item.status.tone"
                                    :label="item.status.label"
                                />
                                <V2Chip
                                    v-if="item.visit.priority === 'urgent'"
                                    tone="danger"
                                    label="Urgent"
                                />
                                <V2Chip
                                    v-if="item.visit.returnedFromDispensary"
                                    tone="neutral"
                                    label="Returned"
                                />
                                <span
                                    v-if="item.visit.completedAt"
                                    class="text-xs text-muted-foreground"
                                    >{{ item.visit.completedAt }}</span
                                >
                                <V2Chip
                                    v-if="
                                        item.visit.callHold?.active ||
                                        item.visit.callHold?.overridden
                                    "
                                    tone="warning"
                                    :label="`${
                                        item.visit.callHold.active
                                            ? 'Not ready to call'
                                            : 'Overridden by doctor'
                                    }: ${item.visit.callHold.reason}`"
                                    data-testid="call-readiness-badge"
                                />
                                <CallReadinessActions
                                    :row="item.legacy"
                                    :branch-id="options.branch.id"
                                    from="registration"
                                    @changed="
                                        board.refreshAppliedBoard(
                                            (run) => void nextTick(run),
                                        )
                                    "
                                />
                            </div>
                        </td>
                        <td
                            data-label="Actions"
                            data-actions
                            class="text-right"
                        >
                            <div class="flex items-center justify-end gap-1">
                                <WhatsAppLink
                                    :can-whatsapp="item.visit.canWhatsapp"
                                    :route-key="item.visit.visitNumber"
                                    context="visit"
                                    compact
                                />
                                <V2RowMenu
                                    :label="`Actions for ${item.visit.patientName}`"
                                    :groups="item.menu.groups"
                                    show-label
                                    @select="selectItem(item.visit, $event)"
                                />
                            </div>
                        </td>
                    </tr>
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
                class="rounded-[14px] border bg-card"
                aria-labelledby="v2-attention"
            >
                <h2
                    id="v2-attention"
                    class="px-4 pt-3 text-[15px] font-semibold"
                >
                    Needs attention
                </h2>
                <p class="px-4 pb-2 text-xs text-muted-foreground">
                    From the visits on this screen only.
                </p>
                <ul v-if="attention.length" class="divide-y border-t">
                    <li
                        v-for="item in attention"
                        :key="item.id"
                        class="px-4 py-3"
                    >
                        <p class="text-sm font-medium">{{ item.title }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ item.why }}
                        </p>
                    </li>
                </ul>
                <p
                    v-else
                    class="border-t px-4 py-4 text-sm text-muted-foreground"
                >
                    Nothing needs attention on this screen.
                </p>
            </section>
            <section class="rounded-[14px] border bg-card p-4">
                <h2 class="text-[15px] font-semibold">At a glance</h2>
                <dl class="mt-2 grid gap-1.5 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">Waiting now</dt>
                        <dd class="font-medium tabular-nums">
                            {{ stats.waitingNow }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">Longest wait</dt>
                        <dd class="font-medium tabular-nums">
                            {{
                                stats.longestWait === null
                                    ? '—'
                                    : `${stats.longestWait} min`
                            }}
                        </dd>
                    </div>
                </dl>
            </section>
        </template>
    </V2WorkLayout>

    <VisitCancellationDialog
        v-model:open="cancelOpen"
        :row="cancellationVisit ? legacyBoardRow(cancellationVisit) : null"
        :branch-id="options.branch.id"
        :visit-lock-version="cancellationVisit?.visitLockVersion ?? null"
        :queue-lock-version="cancellationVisit?.queueLockVersion ?? null"
    />
</template>
