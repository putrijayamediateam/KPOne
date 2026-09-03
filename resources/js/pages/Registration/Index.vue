<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
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
import { Button } from '@/components/ui/button';
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
}>();

type BoardTab =
    'all' | 'waiting' | 'serving' | 'dispensary' | 'completed' | 'cancelled';
const tabs: Array<{ value: BoardTab; label: string; planned?: boolean }> = [
    { value: 'all', label: 'All' },
    { value: 'waiting', label: 'Waiting' },
    { value: 'serving', label: 'Serving Now' },
    { value: 'dispensary', label: 'Dispensary' },
    { value: 'completed', label: 'Completed', planned: true },
    { value: 'cancelled', label: 'Cancelled' },
];
const activeTab = ref<BoardTab>('all');
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
const plannedTab = computed(() => activeTab.value === 'completed');
const duration = (minutes: number | null) => {
    if (minutes === null) {
        return '—';
    }

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
const registrationDateLabel = computed(() =>
    form.date_from === form.date_to
        ? compactDate(form.date_from)
        : `${compactDate(form.date_from)}–${compactDate(form.date_to)}`,
);
const boardRows = computed<PatientBoardRow[]>(() =>
    rows.value.map((visit) => {
        const status = visit.dispensaryStatus
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
                          label: 'Removed from Queue',
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
            durationLabel: duration(visit.durationMinutes),
            priority: visit.priority,
            returnedFromDispensary: visit.returnedFromDispensary,
            dispensaryUrl: visit.dispensaryUrl,
            statusLabel: status.label,
            statusTone: status.tone,
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
    form.board_status = tab;
    void search(1);
};
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
</script>

<template>
    <Head title="Registration" />
    <main
        class="mx-auto flex w-full max-w-[1600px] flex-1 flex-col gap-2 px-3 py-2 md:px-5"
    >
        <div class="flex items-center gap-2 border-b">
            <nav
                class="flex min-w-0 flex-1 gap-1 overflow-x-auto"
                aria-label="Patient board status"
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
                    <span
                        v-if="tab.planned"
                        class="ml-1 text-[9px] font-normal tracking-normal text-muted-foreground/70"
                        >Planned</span
                    >
                </button>
            </nav>
            <Button v-if="canCreate" as-child size="sm" class="shrink-0"
                ><Link href="/registration/create"
                    ><Plus class="size-4" /> Register Visit</Link
                ></Button
            >
        </div>

        <div v-if="plannedTab" class="border-y px-4 py-12 text-center">
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
                    <select
                        v-model="form.doctor_id"
                        aria-label="Doctor"
                        class="h-9 rounded-lg border border-transparent bg-muted/45 px-2 text-[13px] transition-colors outline-none hover:bg-muted/65 focus:border-ring/40 focus:bg-background focus:ring-2 focus:ring-ring/25"
                    >
                        <option value="">All doctors</option>
                        <option
                            v-for="doctor in options.doctors"
                            :key="doctor.id"
                            :value="doctor.id"
                        >
                            {{ doctor.name }}
                        </option>
                    </select>
                    <select
                        v-model="form.coverage_type"
                        aria-label="Coverage"
                        class="h-9 rounded-lg border border-transparent bg-muted/45 px-2 text-[13px] transition-colors outline-none hover:bg-muted/65 focus:border-ring/40 focus:bg-background focus:ring-2 focus:ring-ring/25"
                    >
                        <option value="">All coverage</option>
                        <option value="self_pay">Self-pay</option>
                        <option value="panel">Panel</option>
                    </select>
                    <select
                        v-model="form.priority"
                        aria-label="Urgency"
                        class="h-9 rounded-lg border border-transparent bg-muted/45 px-2 text-[13px] transition-colors outline-none hover:bg-muted/65 focus:border-ring/40 focus:bg-background focus:ring-2 focus:ring-ring/25"
                    >
                        <option value="">All urgency</option>
                        <option value="urgent">Urgent</option>
                        <option value="normal">Normal</option>
                    </select>
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
                    <label class="grid gap-1 text-xs"
                        >Visit type<select
                            v-model="form.visit_type"
                            class="h-9 rounded-lg border border-transparent bg-muted/45 px-2 text-[13px] transition-colors outline-none hover:bg-muted/65 focus:border-ring/40 focus:bg-background focus:ring-2 focus:ring-ring/25"
                        >
                            <option value="">All</option>
                            <option value="consultation">Consultation</option>
                            <option value="otc">OTC</option>
                        </select></label
                    >
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
