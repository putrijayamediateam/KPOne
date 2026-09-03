<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { LoaderCircle, Search } from '@lucide/vue';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from 'vue';
import InputError from '@/components/InputError.vue';
import PatientBoard from '@/components/patient-board/PatientBoard.vue';
import VisitCancellationDialog from '@/components/patient-board/VisitCancellationDialog.vue';
import { Button } from '@/components/ui/button';
import type { PatientBoardRow, QueueRow, QueueSnapshot } from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Consultation', href: '/queue' }] },
});

const props = defineProps<{ snapshot: QueueSnapshot }>();
type QueueTab = 'all' | 'waiting' | 'serving' | 'removed';
const tabs: Array<{ value: QueueTab; label: string }> = [
    { value: 'all', label: 'All active' },
    { value: 'waiting', label: 'Waiting' },
    { value: 'serving', label: 'Serving Now' },
    { value: 'removed', label: 'Removed' },
];
const live = ref(props.snapshot);
const activeTab = ref<QueueTab>('all');
const isRefreshing = ref(false);
const isApplyingFilters = ref(false);
const error = ref('');
const busyKey = ref<string | null>(null);
const cancellationRow = ref<PatientBoardRow | null>(null);
const cancelOpen = ref(false);
const filters = reactive({
    query: '',
    doctor_id: '',
    priority: '',
    status: '',
});
const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';
let pollTimer: ReturnType<typeof setTimeout> | undefined;
let clockTimer: ReturnType<typeof setInterval> | undefined;
let controller: AbortController | undefined;
let activeRefresh: Promise<void> | undefined;
let failures = 0;
const receivedAt = ref(Date.now());
const clockNow = ref(Date.now());

watch(
    () => props.snapshot,
    (snapshot) => {
        live.value = snapshot;
        receivedAt.value = Date.now();
    },
);
const waitLabel = (row: QueueRow) => {
    void clockNow.value;
    const effectiveNow =
        Date.parse(live.value.serverNow) + (Date.now() - receivedAt.value);
    const start =
        row.status === 'serving' && row.calledAt ? row.calledAt : row.queuedAt;
    const minutes = Math.max(
        0,
        Math.floor((effectiveNow - Date.parse(start)) / 60_000),
    );

    if (minutes < 1) {
        return '<1 min';
    }

    if (minutes < 60) {
        return `${minutes} ${minutes === 1 ? 'min' : 'mins'}`;
    }

    const hours = Math.floor(minutes / 60);
    const remainder = minutes % 60;
    const hourLabel = `${hours} ${hours === 1 ? 'hour' : 'hours'}`;

    return remainder
        ? `${hourLabel} ${remainder} ${remainder === 1 ? 'min' : 'mins'}`
        : hourLabel;
};
const sourceRows = computed<QueueRow[]>(() => {
    if (activeTab.value === 'removed') {
        return live.value.removed;
    }

    if (activeTab.value === 'serving') {
        return live.value.serving;
    }

    if (activeTab.value === 'waiting') {
        return [...live.value.carryOver.data, ...live.value.waiting.data];
    }

    return [
        ...live.value.carryOver.data,
        ...live.value.waiting.data,
        ...live.value.serving,
    ];
});
const compactDate = (value: string) => {
    const [year, month, day] = value.split('-').map(Number);

    if (!year || !month || !day) {
        return value;
    }

    return new Intl.DateTimeFormat('en-MY', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(year, month - 1, day)));
};
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
    }).format(new Date(Date.UTC(2000, 0, 1, hour, minute)));
};
const boardRows = computed<PatientBoardRow[]>(() =>
    sourceRows.value.map((row) => ({
        key: `${row.operationalDate}-${row.queueNumber}`,
        patientName: row.patientName,
        patientNumber: row.patientNumber,
        visitNumber: row.visitNumber,
        queueNumber: row.queueNumber,
        arrivedDate: compactDate(row.operationalDate),
        arrivedTime: compactTime(row.queuedTime),
        visitNotes: row.visitReasonExcerpt,
        doctorName: row.doctorName,
        coverageLabel: row.coverageLabel,
        durationLabel: row.status === 'removed' ? '—' : waitLabel(row),
        priority: row.priority,
        returnedFromDispensary: row.returnedFromDispensary,
        statusLabel:
            row.status === 'serving'
                ? 'Serving Now'
                : row.status === 'waiting' &&
                    row.operationalDate !== live.value.operationalDate
                  ? 'Waiting · carry-over'
                  : row.status === 'waiting'
                    ? 'Waiting'
                    : 'Removed from Queue',
        statusTone:
            row.status === 'serving'
                ? 'serving'
                : row.status === 'waiting'
                  ? 'waiting'
                  : 'removed',
        can: {
            viewPatient: row.can.viewPatient,
            update: row.can.update,
            cancel: row.can.cancel,
            sendToWaiting: false,
            call: row.canCall && row.status === 'waiting',
            openConsultation: row.canOpenEncounter,
        },
        source: row,
    })),
);

const schedule = () => {
    if (pollTimer) {
        clearTimeout(pollTimer);
    }

    if (document.hidden) {
        return;
    }

    pollTimer = setTimeout(
        () =>
            refresh(
                live.value.waiting.currentPage,
                live.value.carryOver.currentPage,
            ),
        failures ? Math.min(30_000, 3_000 * 2 ** Math.min(failures, 3)) : 3_000,
    );
};
const refresh = (page = 1, carryPage = 1, reschedule = true) => {
    if (activeRefresh || document.hidden) {
        if (reschedule) {
            schedule();
        }

        return activeRefresh ?? Promise.resolve();
    }

    const request = new AbortController();
    const operation = (async () => {
        isRefreshing.value = true;
        error.value = '';
        controller = request;

        try {
            const response = await fetch('/queue/search', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                signal: request.signal,
                body: JSON.stringify({
                    ...filters,
                    page,
                    carry_page: carryPage,
                }),
            });
            const payload = await response.json();

            if (!response.ok) {
                const validationErrors = payload.errors as
                    Record<string, string[]> | undefined;
                error.value = validationErrors
                    ? Object.values(validationErrors)[0]?.[0]
                    : payload.message;

                throw new Error('Queue snapshot rejected.');
            }

            live.value = payload as QueueSnapshot;
            receivedAt.value = Date.now();
            failures = 0;
        } catch (caught) {
            if (!(
                caught instanceof DOMException && caught.name === 'AbortError'
            )) {
                failures += 1;
                error.value ||= 'Live Queue could not be refreshed. Retrying…';
            }
        } finally {
            if (controller === request) {
                controller = undefined;
                isRefreshing.value = false;
            }

            if (reschedule) {
                schedule();
            }
        }
    })();
    activeRefresh = operation;
    void operation.finally(() => {
        if (activeRefresh === operation) {
            activeRefresh = undefined;
        }
    });

    return operation;
};
const applyFilters = async () => {
    if (isApplyingFilters.value) {
        return;
    }

    isApplyingFilters.value = true;

    try {
        if (activeRefresh) {
            controller?.abort();
            await activeRefresh;
        }

        await refresh(1, 1, false);
    } finally {
        isApplyingFilters.value = false;
        schedule();
    }
};
const selectTab = (tab: QueueTab) => {
    activeTab.value = tab;
    filters.status = tab === 'all' ? '' : tab;
    void applyFilters();
};
const clearFilters = () => {
    Object.assign(filters, {
        query: '',
        doctor_id: '',
        priority: '',
        status: activeTab.value === 'all' ? '' : activeTab.value,
    });
    void applyFilters();
};
const source = (row: PatientBoardRow) => row.source as QueueRow;
const callIn = (row: PatientBoardRow) => {
    const entry = source(row);
    busyKey.value = row.key;
    router.patch(
        `/visits/${encodeURIComponent(row.visitNumber)}/queue/call`,
        {
            expected_branch_id: live.value.branch.id,
            visit_lock_version: entry.visitLockVersion,
            queue_lock_version: entry.queueLockVersion,
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                error.value = Object.values(errors)[0] ?? 'Call In failed.';
            },
            onFinish: () => {
                busyKey.value = null;
                void refresh(1, 1, false).finally(schedule);
            },
        },
    );
};
const openConsultation = (row: PatientBoardRow) => {
    const entry = source(row);

    if (entry.status === 'removed') {
        // Existing authorized read-only entry; never restart a removed Queue.
        router.get(`/visits/${encodeURIComponent(row.visitNumber)}/encounter`);

        return;
    }

    busyKey.value = row.key;
    router.post(
        `/visits/${encodeURIComponent(row.visitNumber)}/encounter`,
        {
            expected_branch_id: live.value.branch.id,
            visit_lock_version: entry.visitLockVersion,
            queue_lock_version: entry.queueLockVersion,
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                error.value =
                    Object.values(errors)[0] ??
                    'The consultation could not be opened.';
            },
            onFinish: () => {
                busyKey.value = null;
            },
        },
    );
};
const requestCancellation = (row: PatientBoardRow) => {
    cancellationRow.value = row;
    cancelOpen.value = true;
};
const handleVisibility = () => {
    if (document.hidden) {
        if (pollTimer) {
            clearTimeout(pollTimer);
        }

        controller?.abort();
    } else {
        failures = 0;
        void refresh(
            live.value.waiting.currentPage,
            live.value.carryOver.currentPage,
        );
    }
};
onMounted(() => {
    document.addEventListener('visibilitychange', handleVisibility);
    clockTimer = setInterval(() => (clockNow.value = Date.now()), 60_000);
    schedule();
});
onBeforeUnmount(() => {
    document.removeEventListener('visibilitychange', handleVisibility);

    if (pollTimer) {
        clearTimeout(pollTimer);
    }

    if (clockTimer) {
        clearInterval(clockTimer);
    }

    controller?.abort();
});
</script>

<template>
    <Head title="Consultation" />
    <main
        class="mx-auto flex w-full max-w-[1600px] flex-1 flex-col gap-2 px-3 py-2 md:px-5"
    >
        <div class="flex items-center gap-2 border-b">
            <nav
                class="flex min-w-0 flex-1 gap-1 overflow-x-auto"
                aria-label="Consultation Queue status"
            >
                <button
                    v-for="tab in tabs"
                    :key="tab.value"
                    type="button"
                    class="relative shrink-0 px-3 py-2 text-[13px] font-normal text-muted-foreground transition-colors hover:bg-muted/25 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
                    :class="
                        activeTab === tab.value
                            ? 'font-medium text-foreground after:absolute after:inset-x-3 after:bottom-0 after:h-px after:bg-foreground/60'
                            : ''
                    "
                    :aria-current="activeTab === tab.value ? 'page' : undefined"
                    @click="selectTab(tab.value)"
                >
                    {{ tab.label }}
                </button>
            </nav>
            <span class="shrink-0 text-[11px] text-muted-foreground">{{
                isRefreshing ? 'Refreshing…' : 'Live · every 3 seconds'
            }}</span>
        </div>

        <form
            class="grid gap-2 border-b pb-3 md:grid-cols-2 xl:grid-cols-[minmax(280px,1.6fr)_minmax(160px,0.8fr)_140px_auto_auto]"
            @submit.prevent="applyFilters"
        >
            <label class="relative md:col-span-2 xl:col-span-1"
                ><Search
                    class="absolute top-2.5 left-3 size-4 text-muted-foreground" /><input
                    v-model="filters.query"
                    autocomplete="off"
                    class="h-9 w-full rounded-lg border border-transparent bg-muted/45 pr-3 pl-9 text-[13px] transition-colors outline-none hover:bg-muted/65 focus:border-ring/40 focus:bg-background focus:ring-2 focus:ring-ring/25"
                    placeholder="Patient or exact Queue number"
            /></label>
            <select
                v-if="live.scope === 'branch'"
                v-model="filters.doctor_id"
                aria-label="Doctor"
                class="h-9 rounded-lg border border-transparent bg-muted/45 px-2 text-[13px] transition-colors outline-none hover:bg-muted/65 focus:border-ring/40 focus:bg-background focus:ring-2 focus:ring-ring/25"
            >
                <option value="">All doctors</option>
                <option
                    v-for="doctor in live.doctors"
                    :key="doctor.id"
                    :value="doctor.id"
                >
                    {{ doctor.name }}
                </option></select
            ><span v-else class="hidden xl:block" />
            <select
                v-model="filters.priority"
                aria-label="Urgency"
                class="h-9 rounded-lg border border-transparent bg-muted/45 px-2 text-[13px] transition-colors outline-none hover:bg-muted/65 focus:border-ring/40 focus:bg-background focus:ring-2 focus:ring-ring/25"
            >
                <option value="">All urgency</option>
                <option value="urgent">Urgent</option>
                <option value="normal">Normal</option>
            </select>
            <Button type="submit" size="sm" :disabled="isApplyingFilters"
                ><LoaderCircle
                    v-if="isApplyingFilters"
                    class="size-4 animate-spin"
                />{{ isApplyingFilters ? 'Applying…' : 'Apply' }}</Button
            >
            <Button
                type="button"
                size="sm"
                variant="ghost"
                @click="clearFilters"
                >Reset</Button
            >
            <InputError class="md:col-span-2 xl:col-span-5" :message="error" />
        </form>

        <section class="overflow-hidden border-y bg-background">
            <div
                class="flex items-center justify-between px-3 py-2 text-xs text-muted-foreground"
            >
                <span>{{ boardRows.length }} visible entries</span
                ><span>{{
                    live.scope === 'own' ? 'Clinician scope' : 'Branch scope'
                }}</span>
            </div>
            <PatientBoard
                :rows="boardRows"
                :busy-key="busyKey"
                @call="callIn"
                @open-consultation="openConsultation"
                @cancel="requestCancellation"
            />
            <div
                v-if="
                    (activeTab === 'all' || activeTab === 'waiting') &&
                    (live.waiting.lastPage > 1 || live.carryOver.lastPage > 1)
                "
                class="flex flex-wrap justify-end gap-2 border-t p-3"
            >
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="live.waiting.currentPage === 1 || isRefreshing"
                    @click="
                        refresh(
                            live.waiting.currentPage - 1,
                            live.carryOver.currentPage,
                        )
                    "
                    >Previous today</Button
                >
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="
                        live.waiting.currentPage === live.waiting.lastPage ||
                        isRefreshing
                    "
                    @click="
                        refresh(
                            live.waiting.currentPage + 1,
                            live.carryOver.currentPage,
                        )
                    "
                    >Next today</Button
                >
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="live.carryOver.currentPage === 1 || isRefreshing"
                    @click="
                        refresh(
                            live.waiting.currentPage,
                            live.carryOver.currentPage - 1,
                        )
                    "
                    >Previous carry-over</Button
                >
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="
                        live.carryOver.currentPage ===
                            live.carryOver.lastPage || isRefreshing
                    "
                    @click="
                        refresh(
                            live.waiting.currentPage,
                            live.carryOver.currentPage + 1,
                        )
                    "
                    >Next carry-over</Button
                >
            </div>
        </section>

        <VisitCancellationDialog
            v-model:open="cancelOpen"
            :row="cancellationRow"
            :branch-id="live.branch.id"
            :visit-lock-version="
                cancellationRow
                    ? source(cancellationRow).visitLockVersion
                    : null
            "
            :queue-lock-version="
                cancellationRow
                    ? source(cancellationRow).queueLockVersion
                    : null
            "
            @completed="refresh(1, 1, false)"
        />
    </main>
</template>
