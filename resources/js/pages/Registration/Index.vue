<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ChevronDown,
    LoaderCircle,
    Plus,
    Search,
    SlidersHorizontal,
    X,
} from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PatientBoard from '@/components/patient-board/PatientBoard.vue';
import VisitCancellationDialog from '@/components/patient-board/VisitCancellationDialog.vue';
import QrIntakeTable from '@/components/registration/QrIntakeTable.vue';
import { Button } from '@/components/ui/button';
import { OperationalSelect } from '@/components/ui/select';
import OperationalTabs from '@/components/ui/tabs/OperationalTabs.vue';
import { formatDate } from '@/lib/presentation';
import {
    queuePresentationLabel,
    waitingDurationLabel,
    servingDurationLabel,
} from '@/lib/r1c2-presentation';
import type { PatientBoardRow, VisitOptions, VisitRow } from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Registration', href: '/registration' }] },
});

const props = defineProps<{
    visits: {
        data: VisitRow[];
        total: number;
        currentPage: number;
        lastPage: number;
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

type BoardTab =
    | 'all'
    | 'waiting'
    | 'serving'
    | 'dispensary'
    | 'billing'
    | 'completed'
    | 'cancelled'
    | 'qr-intake';
const tabs = computed<
    Array<{ value: BoardTab; label: string; count?: number }>
>(() => [
    { value: 'all', label: 'All' },
    { value: 'waiting', label: 'Waiting' },
    { value: 'serving', label: 'Serving Now' },
    { value: 'dispensary', label: 'Dispensary' },
    { value: 'billing', label: 'Awaiting Billing' },
    { value: 'completed', label: 'Completed' },
    { value: 'cancelled', label: 'Cancelled' },
    ...(props.qrIntakes
        ? [
              {
                  value: 'qr-intake' as const,
                  label: 'QR Intake',
                  count: props.qrIntakes.pendingCount,
              },
          ]
        : []),
]);
const activeTab = ref<BoardTab>(
    new URLSearchParams(page.url.split('?', 2)[1] ?? '').get('tab') ===
        'qr-intake' && props.qrIntakes
        ? 'qr-intake'
        : 'all',
);
const rows = ref(props.visits.data);
const total = ref(props.visits.total);
const currentPage = ref(props.visits.currentPage);
const lastPage = ref(props.visits.lastPage);
const loading = ref(false);
const error = ref('');
const busyKey = ref<string | null>(null);
const showMoreFilters = ref(false);
const cancellationRow = ref<PatientBoardRow | null>(null);
const cancelOpen = ref(false);
const today = new Intl.DateTimeFormat('en-CA', {
    timeZone: props.options.branch.timezone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
}).format(new Date());
const form = reactive({
    patient_query: '',
    doctor_id: '',
    visit_type: '',
    priority: '',
    coverage_type: '',
    board_status: 'all' as BoardTab,
    date_from: today,
    date_to: today,
});
const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';
let searchGeneration = 0;
const plannedTab = computed(() => false);
const isQrIntake = computed(() => activeTab.value === 'qr-intake');
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
const registrationDateLabel = computed(() =>
    form.date_from === form.date_to
        ? formatDate(form.date_from)
        : `${formatDate(form.date_from)}–${formatDate(form.date_to)}`,
);
const boardRows = computed<PatientBoardRow[]>(() =>
    rows.value.map((visit) => {
        const status =
            visit.status === 'completed'
                ? { label: 'Completed', tone: 'neutral' as const }
                : visit.awaitingBilling
                  ? { label: 'Awaiting Billing', tone: 'neutral' as const }
                  : visit.dispensaryStatus
                    ? {
                          label:
                              visit.dispensaryStatus === 'pending'
                                  ? 'Pending'
                                  : 'Dispensing',
                          tone:
                              visit.dispensaryStatus === 'pending'
                                  ? ('waiting' as const)
                                  : ('serving' as const),
                      }
                    : visit.status === 'cancelled'
                      ? { label: 'Cancelled', tone: 'cancelled' as const }
                      : visit.queueStatus === 'serving'
                        ? { label: 'Serving Now', tone: 'serving' as const }
                        : visit.queueStatus === 'waiting'
                          ? { label: 'Waiting', tone: 'waiting' as const }
                          : visit.queueStatus === 'removed'
                            ? {
                                  label: queuePresentationLabel({
                                      status: 'removed',
                                      removalReason: visit.queueRemovalReason,
                                      visitStatus: visit.status,
                                  }),
                                  tone: 'removed' as const,
                              }
                            : { label: 'Registered', tone: 'neutral' as const };

        return {
            key: visit.visitNumber,
            patientName: visit.patientName,
            patientNumber: visit.patientNumber,
            visitNumber: visit.visitNumber,
            queueNumber: visit.queueNumber,
            arrivedDate: registrationDateLabel.value,
            arrivedTime: compactTime(visit.registeredAt),
            visitNotes: visit.visitReasonExcerpt,
            doctorName: visit.doctorName,
            coverageLabel: visit.coverageLabel,
            durationLabel: visit.isHeld
                ? 'On Hold'
                : visit.queueStatus === 'serving'
                  ? servingDurationLabel(visit.durationMinutes)
                  : visit.queueStatus === 'waiting'
                    ? waitingDurationLabel(visit.durationMinutes)
                    : '—',
            priority: visit.priority,
            returnedFromDispensary: visit.returnedFromDispensary,
            dispensaryUrl: visit.dispensaryUrl,
            billingUrl: visit.billingUrl,
            completedAt: visit.completedAt,
            statusLabel: visit.isHeld ? 'On Hold' : status.label,
            statusTone: visit.isHeld ? ('held' as const) : status.tone,
            can: visit.can,
            source: visit,
        };
    }),
);

const search = async (page = 1) => {
    const generation = ++searchGeneration;

    if (plannedTab.value) {
        rows.value = [];
        total.value = 0;
        currentPage.value = 1;
        lastPage.value = 1;
        loading.value = false;
        error.value = '';

        return;
    }

    loading.value = true;
    error.value = '';

    try {
        const response = await fetch('/registration/search', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({ ...form, page }),
        });
        const payload = await response.json();

        if (generation !== searchGeneration) {
            return;
        }

        if (!response.ok) {
            const validationErrors = payload.errors as
                Record<string, string[]> | undefined;
            error.value =
                payload.message ??
                (validationErrors
                    ? Object.values(validationErrors)[0]?.[0]
                    : undefined) ??
                'Registration search could not be completed.';

            return;
        }

        rows.value = payload.data;
        total.value = payload.total;
        currentPage.value = payload.currentPage;
        lastPage.value = payload.lastPage;
    } catch {
        if (generation === searchGeneration) {
            error.value = 'Registration search could not be completed.';
        }
    } finally {
        if (generation === searchGeneration) {
            loading.value = false;
        }
    }
};
const selectTab = (tab: BoardTab) => {
    activeTab.value = tab;

    if (tab === 'qr-intake') {
        window.history.replaceState({}, '', '/registration?tab=qr-intake');

        return;
    }

    if (window.location.search !== '') {
        window.history.replaceState({}, '', '/registration');
    }

    form.board_status = tab;
    void search(1);
};
const selectOperationalTab = (value: string) => selectTab(value as BoardTab);
const advancedFilterCount = computed(
    () =>
        [form.priority, form.visit_type].filter(Boolean).length +
        (form.date_from !== today || form.date_to !== today ? 1 : 0),
);
const resetFilters = () => {
    Object.assign(form, {
        patient_query: '',
        doctor_id: '',
        visit_type: '',
        priority: '',
        coverage_type: '',
        board_status: activeTab.value,
        date_from: today,
        date_to: today,
    });
    showMoreFilters.value = false;
    void search(1);
};
const source = (row: PatientBoardRow) => row.source as VisitRow;
const runMutation = (
    row: PatientBoardRow,
    method: 'post' | 'patch',
    url: string,
    data: Record<string, number>,
) => {
    busyKey.value = row.key;
    router[method](url, data, {
        preserveScroll: true,
        onError: (errors) => {
            error.value =
                Object.values(errors)[0] ??
                'The action could not be completed.';
        },
        onFinish: () => {
            busyKey.value = null;
        },
    });
};
const sendToWaiting = (row: PatientBoardRow) =>
    runMutation(
        row,
        'post',
        `/visits/${encodeURIComponent(row.visitNumber)}/queue`,
        {
            expected_branch_id: props.options.branch.id,
            visit_lock_version: source(row).visitLockVersion,
        },
    );
const callIn = (row: PatientBoardRow) =>
    runMutation(
        row,
        'patch',
        `/visits/${encodeURIComponent(row.visitNumber)}/queue/call`,
        {
            expected_branch_id: props.options.branch.id,
            visit_lock_version: source(row).visitLockVersion,
            queue_lock_version: source(row).queueLockVersion!,
        },
    );
const openConsultation = (row: PatientBoardRow) =>
    runMutation(
        row,
        'post',
        `/visits/${encodeURIComponent(row.visitNumber)}/encounter`,
        {
            expected_branch_id: props.options.branch.id,
            visit_lock_version: source(row).visitLockVersion,
            queue_lock_version: source(row).queueLockVersion!,
        },
    );
const requestCancellation = (row: PatientBoardRow) => {
    cancellationRow.value = row;
    cancelOpen.value = true;
};
const changeQrHistoryPage = (qrHistoryPage: number) => {
    // router.reload() always preserves scroll and component state; that is the
    // point of "reload" versus a full visit.
    router.reload({
        only: ['qrIntakes'],
        data: { qr_history_page: qrHistoryPage },
    });
};
</script>

<template>
    <Head title="Registration" />
    <main
        class="mx-auto flex w-full max-w-[1600px] flex-1 flex-col gap-2 px-3 py-2 md:px-5"
    >
        <div class="flex items-center gap-2 border-b">
            <OperationalTabs
                class="min-w-0 flex-1"
                :model-value="activeTab"
                label="Patient board status"
                :tabs="tabs"
                @update:model-value="selectOperationalTab"
            />
            <Button v-if="canCreate" as-child size="sm" class="shrink-0"
                ><Link href="/registration/create"
                    ><Plus class="size-4" /> Register Visit</Link
                ></Button
            >
        </div>

        <QrIntakeTable
            v-if="isQrIntake && qrIntakes"
            :branch="qrIntakes.branch"
            :items="qrIntakes.items"
            :history="qrIntakes.history"
            @history-page-change="changeQrHistoryPage"
        />

        <div v-else-if="plannedTab" class="border-y px-4 py-12 text-center">
            <h2 class="font-semibold">
                {{
                    activeTab === 'dispensary'
                        ? 'Dispensary'
                        : 'Completed Visits'
                }}
            </h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Planned workflow — not available yet. No Patient records are
                represented in this state.
            </p>
        </div>

        <template v-else>
            <form class="border-b pb-3" @submit.prevent="search(1)">
                <div
                    class="grid gap-2 md:grid-cols-2 xl:grid-cols-[minmax(260px,1.6fr)_minmax(150px,0.8fr)_140px_140px_auto_auto]"
                >
                    <label class="relative md:col-span-2 xl:col-span-1">
                        <Search
                            class="absolute top-2.5 left-3 size-4 text-muted-foreground"
                        />
                        <input
                            v-model="form.patient_query"
                            autocomplete="off"
                            class="h-9 w-full rounded-lg border border-transparent bg-muted/45 pr-3 pl-9 text-[13px] transition-colors outline-none hover:bg-muted/65 focus:border-ring/40 focus:bg-background focus:ring-2 focus:ring-ring/25"
                            placeholder="Patient name or exact Patient No."
                        />
                    </label>
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
                    <Button size="sm" type="submit" :disabled="loading"
                        ><LoaderCircle
                            v-if="loading"
                            class="size-4 animate-spin"
                        />{{ loading ? 'Applying…' : 'Apply' }}</Button
                    >
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="showMoreFilters = !showMoreFilters"
                    >
                        <SlidersHorizontal class="size-4" /> More
                        <span v-if="advancedFilterCount" class="text-xs">{{
                            advancedFilterCount
                        }}</span
                        ><ChevronDown
                            class="size-3.5"
                            :class="showMoreFilters ? 'rotate-180' : ''"
                        />
                    </Button>
                </div>
                <div
                    v-if="showMoreFilters"
                    class="mt-3 flex flex-wrap items-end gap-2 border-t pt-3"
                >
                    <label
                        class="grid min-w-40 gap-1 text-xs"
                        for="registration-visit-type"
                        >Visit type
                        <OperationalSelect
                            id="registration-visit-type"
                            v-model="form.visit_type"
                            label="Visit type"
                            :options="visitTypeOptions"
                        />
                    </label>
                    <label class="grid gap-1 text-xs"
                        >From<input
                            v-model="form.date_from"
                            type="date"
                            class="h-9 rounded-lg border border-transparent bg-muted/45 px-2 text-[13px] transition-colors outline-none hover:bg-muted/65 focus:border-ring/40 focus:bg-background focus:ring-2 focus:ring-ring/25"
                    /></label>
                    <label class="grid gap-1 text-xs"
                        >To<input
                            v-model="form.date_to"
                            type="date"
                            class="h-9 rounded-lg border border-transparent bg-muted/45 px-2 text-[13px] transition-colors outline-none hover:bg-muted/65 focus:border-ring/40 focus:bg-background focus:ring-2 focus:ring-ring/25"
                    /></label>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        @click="resetFilters"
                        ><X class="size-4" /> Reset</Button
                    >
                </div>
                <InputError class="mt-2" :message="error" />
            </form>

            <section class="overflow-hidden border-y bg-background">
                <div
                    class="flex items-center justify-between px-3 py-2 text-xs text-muted-foreground"
                >
                    <span>{{ total }} Visit{{ total === 1 ? '' : 's' }}</span
                    ><span>25 per page</span>
                </div>
                <PatientBoard
                    :rows="boardRows"
                    :busy-key="busyKey"
                    @send-to-waiting="sendToWaiting"
                    @call="callIn"
                    @open-consultation="openConsultation"
                    @cancel="requestCancellation"
                />
                <div
                    v-if="lastPage > 1"
                    class="flex justify-end gap-2 border-t p-3"
                >
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="currentPage === 1"
                        @click="search(currentPage - 1)"
                        >Previous</Button
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="currentPage === lastPage"
                        @click="search(currentPage + 1)"
                        >Next</Button
                    >
                </div>
            </section>
        </template>

        <VisitCancellationDialog
            v-model:open="cancelOpen"
            :row="cancellationRow"
            :branch-id="options.branch.id"
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
        />
    </main>
</template>
