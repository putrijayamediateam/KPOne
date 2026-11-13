<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Volume2, VolumeX } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import WhatsAppLink from '@/components/patient/WhatsAppLink.vue';
import TreatmentProgressActions from '@/components/queue/TreatmentProgressActions.vue';
import CallNextV2 from '@/components/queue-v2/CallNextV2.vue';
import QueueAttentionList from '@/components/queue-v2/QueueAttentionList.vue';
import QueueRowDialogs from '@/components/queue-v2/QueueRowDialogs.vue';
import TreatmentChipV2 from '@/components/queue-v2/TreatmentChipV2.vue';
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
import { useQueueBoardV2 } from '@/composables/queue/useQueueBoardV2';
import type {
    QueueBoardProps,
    QueueTab,
} from '@/composables/queue/useQueueBoardV2';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { formatDate } from '@/lib/presentation';
import { needsAttention, rowMenu, rowStatus } from '@/lib/queue-v2';
import type { PatientBoardRow, QueueRow } from '@/types';

/**
 * Version 2 Consultation queue board (shown only in V2; Classic is `pages/Queue/Index.vue`, untouched). Same props,
 * same polling, routes, permissions, lock versions and payloads. No backend change. The logic is a copy of Classic's
 * (`composables/queue/useQueueBoardV2.ts`), kept equal by `ui-v2-queue-parity.test.mjs`.
 */
const props = defineProps<QueueBoardProps>();
usePageCrumbs(() => [{ title: 'Consultation' }]);
const dialogKind = ref<
    'call' | 'notReady' | 'ready' | 'cancel' | 'treatmentCall' | null
>(null);
const dialogRow = ref<PatientBoardRow | null>(null);
const treatmentDialogKey = ref<string | null>(null);
const sheetOpen = ref(false);
const callNextOpen = ref(false);
// The 3 s poll waits while a menu, dialog or sheet is open, so nothing moves under a hand.
const overlayOpen = () =>
    dialogKind.value !== null ||
    sheetOpen.value ||
    callNextOpen.value ||
    treatmentDialogKey.value !== null ||
    document.querySelector(
        '[data-slot="v2-row-menu"][aria-expanded="true"]',
    ) !== null;
const board = useQueueBoardV2(props, { paused: overlayOpen });
const {
    roomSaving,
    roomError,
    roomOptions,
    chooseRoom,
    tabs,
    live,
    readyAlerts,
    activeTab,
    completedLine,
    servingLine,
    isRefreshing,
    isApplyingFilters,
    error,
    busyKey,
    draftFilters,
    doctorOptions,
    urgencyOptions,
    boardRows,
    refresh,
    applyFilters,
    selectTab,
    clearFilters,
    recall,
    refreshBoard,
    finishCall,
    refreshFailedAt,
    receivedAt,
    openConsultation,
} = board;

const rowsWithMenu = computed(() =>
    boardRows.value.map((row) => ({
        row,
        status: rowStatus(row),
        menu: rowMenu(row, live.value.branch.id),
        entry: row.source as QueueRow,
    })),
);
const attention = computed(() => needsAttention(boardRows.value));
const roomIsMissing = computed(
    () =>
        !!props.roomChoice &&
        props.roomChoice.rooms.length > 0 &&
        props.roomChoice.currentRoomId === null,
);
const roomName = computed(
    () =>
        props.roomChoice?.rooms.find(
            (room) => room.id === props.roomChoice?.currentRoomId,
        )?.name ?? null,
);
const changingRoom = ref(false);
const updatedAt = computed(() =>
    new Date(receivedAt.value)
        .toLocaleTimeString('en-MY', {
            hour: 'numeric',
            minute: '2-digit',
            timeZone: live.value.branch.timezone,
        })
        .replace(/\b(am|pm)\b/gi, (period) => period.toUpperCase()),
);
// The automatic-retry message is the amber notice on the live line; showing it again in red would say it twice.
const visibleError = computed(() =>
    error.value === 'Live Queue could not be refreshed. Retrying…'
        ? ''
        : error.value,
);
const showPayment = computed(() =>
    boardRows.value.some(
        (row) => /panel/i.test(row.coverageLabel) || !!row.panelNote,
    ),
);
const choosingRoom = () => {
    changingRoom.value = true;
    void nextTick(() =>
        document
            .querySelector<HTMLElement>(
                '[data-testid="doctor-room-choice"] [role="combobox"]',
            )
            ?.focus(),
    );
};
const filterBadge = computed(
    () =>
        [draftFilters.doctor_id, draftFilters.priority].filter(Boolean).length,
);
const carryOverLabel = (operationalDate: string) => {
    const [y, m, d] = live.value.operationalDate.split('-').map(Number);
    const yesterday = new Date(Date.UTC(y, m - 1, d - 1))
        .toISOString()
        .slice(0, 10);

    return operationalDate === yesterday
        ? 'From yesterday'
        : `From ${formatDate(operationalDate)}`;
};
const onTabChange = (value: string) => selectTab(value as QueueTab);
const viewRow = (visitNumber: string) => {
    const row = document.querySelector<HTMLElement>(
        `[data-visit="${visitNumber}"]`,
    );

    row?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    row?.focus({ preventScroll: true });
};
const openDialog = (
    kind: 'call' | 'notReady' | 'ready' | 'cancel' | 'treatmentCall',
    row: PatientBoardRow,
) => {
    // Let the menu close and return focus first, so the dialog keeps focus (and returns it to the row's menu button).
    setTimeout(() => {
        dialogRow.value = row;
        dialogKind.value = kind;
    }, 0);
};
const selectItem = (item: { row: PatientBoardRow }, id: string) => {
    const target = rowMenu(item.row, live.value.branch.id).targets[id];

    if (!target) {
        return;
    }

    if (target.kind === 'link') {
        router.visit(target.href);

        return;
    }

    switch (target.action) {
        case 'call':
            openDialog('call', item.row);
            break;
        case 'recall':
            recall(item.row);
            break;
        case 'callTreatment':
            openDialog('treatmentCall', item.row);
            break;
        case 'openConsultation':
            openConsultation(item.row);
            break;
        case 'markNotReady':
            openDialog('notReady', item.row);
            break;
        case 'markReady':
            openDialog('ready', item.row);
            break;
        case 'treatmentProgress':
            // Let the menu close and return focus first, so the dialog keeps focus.
            setTimeout(() => (treatmentDialogKey.value = item.row.key), 0);
            break;
        case 'cancel':
            openDialog('cancel', item.row);
            break;
    }
};
const rowClick = (event: MouseEvent, row: PatientBoardRow) => {
    if (
        !row.historyUrl ||
        (event.target as HTMLElement).closest(
            'a, button, [role="menuitem"], [data-slot="v2-row-menu"]',
        )
    ) {
        return;
    }

    router.visit(row.historyUrl);
};
</script>

<template>
    <Head title="Consultation" />
    <PageFrame width="wide">
        <V2WorkLayout
            title="Consultation queue"
            rail-from="2xl"
            rail-label="Needs attention"
            :description="`${live.branch.name} · Call in, follow and hand off every consultation.`"
        >
            <template #actions>
                <CallNextV2
                    v-if="live.callNext"
                    :block="live.callNext"
                    :branch-id="live.branch.id"
                    :busy="busyKey !== null"
                    :refresh="refreshBoard"
                    @choose-room="choosingRoom"
                    @dialog="callNextOpen = $event"
                    @processing="busyKey = $event ? 'call-next' : null"
                />
            </template>

            <template #rail-collapsed>
                <V2DisclosureBar
                    v-if="attention.length"
                    :title="`${attention.length} needs attention`"
                    tone="warning"
                >
                    <QueueAttentionList :items="attention" @view="viewRow" />
                </V2DisclosureBar>
                <p v-else class="text-sm text-muted-foreground">
                    Nothing needs attention on this screen.
                </p>
            </template>

            <template #main>
                <h2 class="sr-only">Consultation queue board</h2>
                <V2Tabs
                    :model-value="activeTab"
                    :tabs="tabs"
                    ariaLabel="Queue status"
                    @update:model-value="onTabChange"
                />

                <div
                    v-if="roomChoice"
                    class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-[14px] border bg-card px-4 py-2 text-sm"
                    data-testid="doctor-room-choice"
                >
                    <span id="doctor-room-label" class="font-medium"
                        >My room today:</span
                    >
                    <template v-if="!changingRoom && !roomIsMissing">
                        <span>{{ roomName ?? 'Not chosen' }}</span>
                        <button
                            type="button"
                            class="inline-flex h-11 items-center text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                            @click="changingRoom = true"
                        >
                            Change
                        </button>
                    </template>
                    <template v-else>
                        <span
                            v-if="roomIsMissing"
                            class="inline-flex items-center gap-1.5 text-warning-strong"
                            ><span
                                class="size-2 rounded-full bg-warning"
                                aria-hidden="true"
                            />Choose your room to call patients</span
                        >
                        <div class="w-48 max-sm:w-full">
                            <OperationalSelect
                                :model-value="roomChoice.currentRoomId ?? ''"
                                label="My room today"
                                labelledby="doctor-room-label"
                                :options="roomOptions"
                                :disabled="
                                    roomSaving || roomChoice.rooms.length === 0
                                "
                                @update:model-value="
                                    (value) => {
                                        chooseRoom(value);
                                        changingRoom = false;
                                    }
                                "
                            />
                        </div>
                    </template>
                    <span
                        v-if="roomChoice.rooms.length === 0"
                        class="text-xs text-muted-foreground"
                        >No consultation rooms are set up for this branch yet.
                        Ask a supervisor to add them in Queue Display
                        (TV).</span
                    >
                    <span
                        v-else-if="roomIsMissing"
                        class="text-xs text-muted-foreground"
                        >Call In stays blocked until you do, so the TV can tell
                        patients where to go.</span
                    >
                    <InputError :message="roomError" />
                </div>

                <form
                    class="flex flex-col gap-3"
                    @submit.prevent="applyFilters"
                >
                    <V2Toolbar
                        v-model="draftFilters.query"
                        search-label="Search patients or queue numbers"
                        search-placeholder="Patient or exact Queue number"
                        @search="applyFilters"
                    >
                        <template #filters>
                            <div
                                v-if="live.scope === 'branch'"
                                class="hidden w-44 sm:block"
                            >
                                <OperationalSelect
                                    v-model="draftFilters.doctor_id"
                                    label="Doctor"
                                    :options="doctorOptions"
                                />
                            </div>
                            <div class="hidden w-44 sm:block">
                                <OperationalSelect
                                    v-model="draftFilters.priority"
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
                                        filterBadge ? ` (${filterBadge})` : ''
                                    }}
                                </button>
                                <button
                                    type="submit"
                                    class="inline-flex h-11 flex-1 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 sm:flex-none lg:h-9"
                                    :disabled="isApplyingFilters"
                                >
                                    {{
                                        isApplyingFilters
                                            ? 'Applying…'
                                            : 'Apply'
                                    }}
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex h-11 items-center justify-center rounded-[10px] px-4 text-sm font-medium text-primary hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none max-sm:hidden lg:h-9"
                                    @click="clearFilters"
                                >
                                    Reset
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex size-11 shrink-0 items-center justify-center rounded-[10px] border border-border bg-card hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:size-9"
                                    :aria-label="
                                        readyAlerts.chimeEnabled.value
                                            ? 'Sound is on. Turn off'
                                            : 'Sound is off. Turn on'
                                    "
                                    :title="
                                        readyAlerts.chimeEnabled.value
                                            ? 'Sound is on. Turn off'
                                            : 'Sound is off. Turn on'
                                    "
                                    data-testid="sound-toggle"
                                    @click="readyAlerts.toggleChime"
                                >
                                    <Volume2
                                        v-if="readyAlerts.chimeEnabled.value"
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    <VolumeX
                                        v-else
                                        class="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                </button>
                            </div>
                        </template>
                    </V2Toolbar>
                    <InputError :message="visibleError" />
                </form>

                <p
                    v-if="readyAlerts.chimeEnabled.value"
                    class="text-[13px] text-muted-foreground"
                    data-testid="sound-help"
                >
                    Sound is on. If you hear nothing, tap anywhere on this page
                    once: browsers block sound until you do.
                </p>
                <div
                    class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 text-[13px] text-muted-foreground"
                >
                    <p>
                        {{ boardRows.length }} visible
                        {{ boardRows.length === 1 ? 'entry' : 'entries' }} ·
                        {{
                            live.scope === 'own'
                                ? 'Clinician scope'
                                : 'Branch scope'
                        }}
                    </p>
                    <p
                        role="status"
                        :class="refreshFailedAt ? 'text-warning-strong' : ''"
                        data-testid="live-line"
                    >
                        <template v-if="refreshFailedAt"
                            >Live refresh paused. Showing the last results from
                            {{ updatedAt }}. Retrying.</template
                        >
                        <template v-else>Live · every 3 seconds</template>
                    </p>
                </div>

                <V2Table
                    label="Consultation queue"
                    :columns="showPayment ? 6 : 5"
                    cards="xl"
                    min-width="880px"
                    :empty="!rowsWithMenu.length"
                    :empty-message="
                        error || 'No Patients match this operational view.'
                    "
                    :loading="isRefreshing && !rowsWithMenu.length"
                >
                    <template #head>
                        <tr>
                            <th scope="col">Patient</th>
                            <th scope="col">Arrived</th>
                            <th scope="col">Doctor and notes</th>
                            <th v-if="showPayment" scope="col">Payment</th>
                            <th scope="col">Status and wait</th>
                            <th scope="col" class="text-right">Actions</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr
                            v-for="item in rowsWithMenu"
                            :key="item.row.key"
                            :data-visit="item.row.visitNumber"
                            tabindex="-1"
                            :class="item.row.historyUrl ? 'cursor-pointer' : ''"
                            @click="rowClick($event, item.row)"
                        >
                            <td data-label="Patient" data-primary>
                                <span class="block text-sm font-medium">{{
                                    item.row.patientName
                                }}</span>
                                <span
                                    class="block text-xs font-normal text-muted-foreground"
                                    ><span
                                        v-if="item.row.queueNumber"
                                        class="whitespace-nowrap"
                                        >{{ item.row.queueNumber }}</span
                                    ><template v-if="item.row.queueNumber">
                                        · </template
                                    >{{ item.row.patientNumber
                                    }}<span class="xl:hidden">
                                        · {{ item.row.arrivedTime }}</span
                                    ></span
                                >
                            </td>
                            <td
                                data-label="Arrived"
                                class="text-sm max-xl:!hidden"
                            >
                                <span class="whitespace-nowrap xl:contents">
                                    <span class="block">{{
                                        item.row.arrivedTime
                                    }}</span>
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >{{ item.row.arrivedDate }}</span
                                    >
                                </span>
                            </td>
                            <td
                                class="max-w-56 min-w-0 text-sm max-xl:w-full max-xl:max-w-none max-xl:justify-start max-xl:!text-left"
                            >
                                <span class="min-w-0 max-xl:flex-1 xl:contents">
                                    <span
                                        class="block truncate max-xl:break-words max-xl:whitespace-normal"
                                        :title="
                                            item.row.doctorName ?? 'Unassigned'
                                        "
                                        >{{
                                            item.row.doctorName ?? 'Unassigned'
                                        }}</span
                                    >
                                    <span
                                        class="block truncate text-xs text-muted-foreground max-xl:line-clamp-1 max-xl:break-all max-xl:whitespace-normal"
                                        :title="
                                            item.row.visitNotes ??
                                            'No reason recorded'
                                        "
                                        >{{
                                            item.row.visitNotes ??
                                            'No reason recorded'
                                        }}</span
                                    >
                                </span>
                            </td>
                            <td
                                v-if="showPayment"
                                data-label="Payment"
                                class="text-sm whitespace-nowrap"
                                :class="
                                    !/panel/i.test(item.row.coverageLabel) &&
                                    !item.row.panelNote
                                        ? 'max-xl:!hidden'
                                        : ''
                                "
                            >
                                <span
                                    v-if="
                                        /panel/i.test(item.row.coverageLabel) ||
                                        item.row.panelNote
                                    "
                                    class="max-xl:text-right xl:contents"
                                >
                                    <span class="block">{{
                                        item.row.coverageLabel
                                    }}</span>
                                    <span
                                        v-if="item.row.panelNote"
                                        class="block max-w-56 truncate text-xs text-muted-foreground"
                                        :title="item.row.panelNote"
                                        >{{ item.row.panelNote }}</span
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
                                        class="flex flex-wrap items-center gap-1.5 max-xl:justify-start"
                                    >
                                        <V2Chip
                                            :tone="item.status.tone"
                                            :label="item.status.label"
                                        />
                                        <V2Chip
                                            v-if="
                                                item.entry.operationalDate !==
                                                live.operationalDate
                                            "
                                            tone="neutral"
                                            :label="
                                                carryOverLabel(
                                                    item.entry.operationalDate,
                                                )
                                            "
                                        />
                                        <V2Chip
                                            v-if="
                                                item.row.priority === 'urgent'
                                            "
                                            tone="danger"
                                            label="Urgent"
                                        />
                                        <V2Chip
                                            v-if="
                                                item.row.returnedFromDispensary
                                            "
                                            tone="neutral"
                                            label="Returned"
                                        />
                                        <V2Chip
                                            v-if="
                                                item.row.callHold?.active ||
                                                item.row.callHold?.overridden
                                            "
                                            tone="warning"
                                            :label="
                                                item.row.callHold.active
                                                    ? 'Not ready'
                                                    : 'Overridden by doctor'
                                            "
                                            :title="
                                                item.row.callHold.reason ??
                                                undefined
                                            "
                                            data-testid="call-readiness-badge"
                                        />
                                        <TreatmentChipV2
                                            :progress="
                                                item.row.treatmentProgress
                                            "
                                            :minutes="item.row.treatmentMinutes"
                                        />
                                        <TreatmentProgressActions
                                            v-if="
                                                live.branch.id &&
                                                item.row.treatmentProgress
                                            "
                                            :open="
                                                treatmentDialogKey ===
                                                item.row.key
                                            "
                                            :progress="
                                                item.row.treatmentProgress
                                            "
                                            :visit-number="item.row.visitNumber"
                                            :branch-id="live.branch.id"
                                            from="queue"
                                            inline="running"
                                            :subject="`${item.row.queueNumber ?? item.row.visitNumber} · ${item.row.patientName}`"
                                            @update:open="
                                                treatmentDialogKey = $event
                                                    ? item.row.key
                                                    : null
                                            "
                                        />
                                    </div>
                                    <p
                                        v-if="item.row.callHold?.active"
                                        :id="`call-hold-${item.row.key}`"
                                        class="max-w-56 truncate text-xs text-muted-foreground max-xl:text-right"
                                        :title="
                                            item.row.callHold.reason ??
                                            undefined
                                        "
                                    >
                                        {{ item.row.callHold.reason }}
                                    </p>
                                    <WaitRing
                                        v-if="
                                            item.row.waitingMinutes !== null &&
                                            item.row.waitingMinutes !==
                                                undefined
                                        "
                                        :minutes="item.row.waitingMinutes"
                                    />
                                    <span
                                        v-else-if="
                                            item.row.durationLabel !== '—'
                                        "
                                        class="text-xs text-muted-foreground"
                                        >{{ item.row.durationLabel }}</span
                                    >
                                </div>
                            </td>
                            <td data-actions class="text-right">
                                <div
                                    class="flex items-center justify-end gap-1 max-xl:gap-2 max-xl:[&_[data-slot=v2-row-menu]]:flex-1"
                                >
                                    <button
                                        v-if="item.row.can.call"
                                        type="button"
                                        class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-3 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                                        :class="
                                            item.row.callHold?.active
                                                ? 'cursor-not-allowed opacity-60'
                                                : ''
                                        "
                                        :aria-disabled="
                                            item.row.callHold?.active
                                                ? 'true'
                                                : undefined
                                        "
                                        :aria-describedby="
                                            item.row.callHold?.active
                                                ? `call-hold-${item.row.key}`
                                                : undefined
                                        "
                                        :title="
                                            item.row.callHold?.active
                                                ? `Not ready to call: ${item.row.callHold.reason}`
                                                : undefined
                                        "
                                        :aria-label="`Call in ${item.row.queueNumber ?? ''} ${item.row.patientName}`"
                                        @click="
                                            !item.row.callHold?.active &&
                                            openDialog('call', item.row)
                                        "
                                    >
                                        Call in
                                    </button>
                                    <WhatsAppLink
                                        :can-whatsapp="item.row.canWhatsapp"
                                        :route-key="item.row.visitNumber"
                                        context="visit"
                                        compact
                                    />
                                    <V2RowMenu
                                        :label="`Actions for ${item.row.patientName}`"
                                        :groups="item.menu.groups"
                                        :busy="busyKey === item.row.key"
                                        show-label
                                        @select="selectItem(item, $event)"
                                    />
                                </div>
                            </td>
                        </tr>
                    </template>
                </V2Table>

                <p
                    v-if="completedLine"
                    class="text-[13px] text-muted-foreground"
                    data-testid="completed-shown-count"
                >
                    {{ completedLine }}
                </p>
                <p
                    v-if="servingLine"
                    class="text-[13px] text-muted-foreground"
                    data-testid="serving-shown-count"
                >
                    {{ servingLine }}
                </p>

                <section
                    v-if="live.waiting.lastPage > 1"
                    aria-label="Today pages"
                >
                    <p class="mb-1 text-[13px] text-muted-foreground">
                        Waiting today
                    </p>
                    <V2Pager
                        :current-page="live.waiting.currentPage"
                        :last-page="live.waiting.lastPage"
                        :total="live.waiting.total"
                        :per-page="25"
                        :disabled="isRefreshing"
                        @change="refresh($event, live.carryOver.currentPage)"
                    />
                </section>
                <section
                    v-if="live.carryOver.lastPage > 1"
                    aria-label="Carry-over pages"
                >
                    <p class="mb-1 text-[13px] text-muted-foreground">
                        Carry-over from earlier days
                    </p>
                    <V2Pager
                        :current-page="live.carryOver.currentPage"
                        :last-page="live.carryOver.lastPage"
                        :total="live.carryOver.total"
                        :per-page="25"
                        :disabled="isRefreshing"
                        @change="refresh(live.waiting.currentPage, $event)"
                    />
                </section>
            </template>

            <template #rail>
                <section
                    class="rounded-[14px] border bg-card"
                    aria-labelledby="v2-queue-attention"
                >
                    <h2
                        id="v2-queue-attention"
                        class="px-4 pt-3 text-[15px] font-semibold"
                    >
                        Needs attention
                    </h2>
                    <QueueAttentionList
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
                v-if="live.scope === 'branch'"
                class="grid gap-1 text-sm font-medium"
                >Doctor
                <OperationalSelect
                    v-model="draftFilters.doctor_id"
                    label="Doctor"
                    :options="doctorOptions"
                />
            </label>
            <label class="grid gap-1 text-sm font-medium"
                >Urgency
                <OperationalSelect
                    v-model="draftFilters.priority"
                    label="Urgency"
                    :options="urgencyOptions"
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
    <QueueRowDialogs
        :kind="dialogKind"
        :row="dialogRow"
        :branch-id="live.branch.id"
        :treatment-rooms="treatmentRooms"
        @busy="busyKey = $event ? (dialogRow?.key ?? null) : null"
        @close="dialogKind = null"
        @changed="finishCall"
    />
</template>
