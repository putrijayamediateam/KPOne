<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Eye, Info, Printer, Search } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { ActionLink } from '@/components/ui/action-link';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { EmptyState } from '@/components/ui/state';
import { OperationalTable } from '@/components/ui/table';
import OperationalTabs from '@/components/ui/tabs/OperationalTabs.vue';
import { waitingTone, waitingToneDescription } from '@/lib/waiting-time';
import type { WaitingTone } from '@/lib/waiting-time';

/**
 * DSP-27 (owner, 25 Nov 2026, Clean Look gap list B1 and B2): the branch's Dispensary list in three status tabs with
 * counts. Read only: each row opens its case, where the work happens. Done today offers the label reprint.
 */
type Tab = 'prepare' | 'check' | 'done';
type Row = {
    publicId: string;
    queueNumber: string | null;
    patientName: string;
    patientNumber: string;
    visitNumber: string;
    caseType: 'consultation' | 'otc';
    status: string;
    doctorName: string | null;
    medicineCount: number;
    receivedAt: string;
    waitingMinutes: number | null;
    completedAt: string | null;
    caseUrl: string;
    labelUrl: string | null;
    labelAction: 'reprint' | 'view' | null;
};

const props = defineProps<{
    canViewReturns: boolean;
    list: {
        branchName: string;
        tab: Tab;
        search: string;
        caseType?: 'all' | 'consultation' | 'otc';
        doctorContext?: string;
        doctorFilter?: string;
        doctorLabel?: string;
        doctorFilterReset?: boolean;
        counts: Record<Tab, number>;
        canPrintLabels: boolean;
        rows: Row[];
        total: number;
        page: number;
        lastPage: number;
    };
}>();

const tabs = computed(() => [
    { value: 'prepare', label: 'To prepare', count: props.list.counts.prepare },
    {
        value: 'check',
        label: "Waiting for doctor's check",
        count: props.list.counts.check,
    },
    { value: 'done', label: 'Done today', count: props.list.counts.done },
]);
const search = ref(props.list.search);
const selectedType = computed(() =>
    props.list.tab === 'prepare' ? (props.list.caseType ?? 'all') : 'all',
);
const typeOptions = [
    { value: 'all', label: 'All cases' },
    { value: 'consultation', label: 'Consultation' },
    { value: 'otc', label: 'Medicine only' },
] as const;
const showHelpfulInfo = ref(true);
const currentTab = computed(() =>
    tabs.value.find((tab) => tab.value === props.list.tab),
);
const tabHelp = computed(
    () =>
        ({
            prepare:
                'Open a case to prepare the medicines and services. Labels print from saved lines only.',
            check: 'The attending doctor checks the prepared medicines on the consultation page. Verification completes the case.',
            done: 'Completed cases are read-only. Open a case to record or check medicine returns, or view the saved labels.',
        })[props.list.tab],
);

const visit = (params: {
    tab?: Tab;
    page?: number;
    search?: string;
    caseType?: string;
    doctor?: string;
}) =>
    router.get(
        '/dispensary',
        {
            tab: params.tab ?? props.list.tab,
            page: params.page ?? 1,
            search: (params.search ?? props.list.search) || undefined,
            doctor:
                (params.tab ?? props.list.tab) === 'prepare' &&
                (params.doctor ?? props.list.doctorFilter ?? 'all') !== 'all'
                    ? (params.doctor ?? props.list.doctorFilter)
                    : undefined,
            case_type:
                (params.tab ?? props.list.tab) === 'prepare' &&
                (params.caseType ?? selectedType.value) !== 'all'
                    ? (params.caseType ?? selectedType.value)
                    : undefined,
        },
        { preserveScroll: params.page === undefined, preserveState: true },
    );
const selectTab = (value: string) => visit({ tab: value as Tab });
const submitSearch = () => visit({ search: search.value.trim() });

type DoctorOption = { value: string; label: string };
type DoctorOptions = {
    data: DoctorOption[];
    page: number;
    lastPage: number;
    total: number;
    search: string;
    reset: boolean;
};
const selectedDoctor = computed(() => props.list.doctorFilter ?? 'all');
const doctorSearch = ref('');
const loadedDoctorSearch = ref('');
const requestedDoctorSearch = ref('');
const loadedDoctorPage = ref(1);
const doctorOptions = ref<DoctorOptions | null>(null);
const doctorLoading = ref(false);
const doctorError = ref('');
const doctorRegion = ref<HTMLElement | null>(null);
let doctorRequest: AbortController | undefined;
let doctorSequence = 0;
const visibleDoctors = computed(() =>
    (doctorOptions.value?.data ?? []).filter(
        (option) => option.value !== selectedDoctor.value,
    ),
);
const loadDoctors = async (
    page = 1,
    searchTerm = loadedDoctorSearch.value,
    focus = false,
) => {
    if (focus) {
        doctorRegion.value?.focus();
    }

    requestedDoctorSearch.value = searchTerm;
    doctorRequest?.abort();
    const request = new AbortController();
    doctorRequest = request;
    const sequence = ++doctorSequence;
    doctorLoading.value = true;
    doctorError.value = '';
    // Old identities must not remain actionable after a failed read or branch change.
    doctorOptions.value = null;

    try {
        const query = new URLSearchParams({
            page: String(page),
            search: searchTerm,
            selected: selectedDoctor.value,
        });
        const response = await fetch(`/dispensary/doctor-options?${query}`, {
            signal: request.signal,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            cache: 'no-store',
        });

        if (!response.ok) {
            throw new Error('Doctor choices could not be loaded.');
        }

        const data: DoctorOptions = await response.json();

        if (sequence !== doctorSequence || props.list.tab !== 'prepare') {
            return;
        }

        doctorOptions.value = data;
        loadedDoctorSearch.value = data.search;
        loadedDoctorPage.value = data.page;
    } catch {
        if (sequence === doctorSequence && !request.signal.aborted) {
            doctorError.value =
                'Doctor choices could not be loaded. Retry the search.';
        }
    } finally {
        if (sequence === doctorSequence) {
            doctorLoading.value = false;
        }
    }
};
watch(
    [
        () => props.list.doctorContext,
        () => props.list.tab,
        () => selectedDoctor.value,
    ],
    ([context, tab], [previousContext, previousTab]) => {
        doctorRequest?.abort();
        doctorSequence++;
        doctorOptions.value = null;
        doctorLoading.value = false;

        if (context !== previousContext || tab !== previousTab) {
            doctorSearch.value = '';
            requestedDoctorSearch.value = '';
            loadedDoctorSearch.value = '';
            loadedDoctorPage.value = 1;
        }

        if (props.list.tab === 'prepare') {
            void loadDoctors(
                loadedDoctorPage.value,
                requestedDoctorSearch.value,
            );
        }
    },
);
onMounted(() => {
    if (props.list.tab === 'prepare') {
        void loadDoctors(loadedDoctorPage.value);
    }
});
onBeforeUnmount(() => {
    doctorSequence++;
    doctorRequest?.abort();
});

const medicines = (n: number) => (n === 1 ? '1 medicine' : `${n} medicines`);
const from = (row: Row) =>
    row.caseType === 'otc' ? 'Medicine only' : (row.doctorName ?? 'Doctor');
const waiting = (row: Row) => {
    const minutes = row.waitingMinutes;

    if (minutes === null) {
        return '';
    }

    if (minutes < 1) {
        return 'Just now';
    }

    if (minutes < 60) {
        return `Waiting ${minutes} min`;
    }

    if (minutes < 24 * 60) {
        return `Waiting ${Math.floor(minutes / 60)} h ${minutes % 60} min`;
    }

    const days = Math.floor(minutes / (24 * 60));

    return `Waiting ${days} ${days === 1 ? 'day' : 'days'}`;
};
// Q-1 (Daniel): the same limits and tones as the queue boards, with a dot and words, never colour alone.
const toneClass = (tone: WaitingTone) =>
    ({
        normal: 'text-muted-foreground',
        amber: 'font-medium text-warning-strong',
        red: 'font-semibold text-destructive',
    })[tone];
const tone = (row: Row) => waitingTone(row.waitingMinutes);
const emptyText = computed(
    () =>
        ({
            prepare: 'No case is waiting to be prepared.',
            check: "No case is waiting for the doctor's check.",
            done: 'No case has been completed today.',
        })[props.list.tab],
);

// The list refreshes itself every 15 seconds while the tab is visible, as the Registration board does.
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    // DD-30: a tab chosen from the address (for example Done today) is scrolled into view on a phone.
    void nextTick(() =>
        document
            .querySelector<HTMLElement>('[role="tab"][aria-selected="true"]')
            ?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' }),
    );
    timer = setInterval(() => {
        if (document.visibilityState === 'visible') {
            router.reload({ only: ['list'] });
        }
    }, 15000);
});
onBeforeUnmount(() => timer && clearInterval(timer));
</script>

<template>
    <Head title="Dispensary" />
    <main
        class="mx-auto flex w-full max-w-[1500px] min-w-0 flex-col gap-4 px-4 py-6 md:px-6 lg:[&_a[href]]:min-h-9 lg:[&_button]:min-h-9"
    >
        <PageHeader title="Dispensary" :description="list.branchName" />

        <nav
            aria-label="Dispensary workspace"
            class="flex w-fit items-center gap-1 rounded-full bg-muted p-1"
        >
            <span
                aria-current="page"
                class="inline-flex min-h-11 items-center rounded-full bg-card px-4 text-sm font-semibold shadow-sm lg:min-h-9"
                >Dispensing</span
            >
            <Link
                v-if="canViewReturns"
                href="/dispensary/returns-to-check"
                class="inline-flex min-h-11 items-center rounded-full px-4 text-sm text-muted-foreground outline-none hover:bg-card focus-visible:ring-2 focus-visible:ring-ring lg:min-h-9"
                >Returns</Link
            >
        </nav>

        <OperationalTabs
            :model-value="list.tab"
            label="Dispensary cases by status"
            :tabs="tabs"
            @update:model-value="selectTab"
        />

        <div
            class="grid min-w-0 gap-5"
            :class="showHelpfulInfo ? 'xl:grid-cols-[minmax(0,1fr)_280px]' : ''"
        >
            <section
                aria-label="Dispensary cases"
                class="flex min-w-0 flex-col gap-4"
            >
                <div
                    class="grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-3"
                    aria-label="Case totals"
                >
                    <button
                        v-for="tab in tabs"
                        :key="tab.value"
                        type="button"
                        :aria-pressed="list.tab === tab.value"
                        :aria-label="`${tab.label}: ${tab.count}`"
                        class="min-h-24 rounded-[14px] border bg-card p-4 text-left shadow-card outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                        :class="
                            list.tab === tab.value
                                ? 'border-primary ring-1 ring-primary'
                                : 'border-border'
                        "
                        @click="selectTab(tab.value)"
                    >
                        <span
                            class="block text-2xl font-semibold tabular-nums"
                            >{{ tab.count }}</span
                        >
                        <span
                            class="mt-2 block text-sm text-muted-foreground"
                            >{{ tab.label }}</span
                        >
                    </button>
                </div>

                <Alert
                    v-if="list.tab === 'check'"
                    class="text-sm"
                    data-testid="dispensary-check-note"
                >
                    <Info aria-hidden="true" />
                    <AlertDescription class="text-sm text-foreground">
                        The doctor checks these on the consultation page. Do not
                        hand over the medicine until the doctor has checked it.
                    </AlertDescription>
                </Alert>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <form
                        class="flex max-w-md gap-2"
                        role="search"
                        @submit.prevent="submitSearch"
                    >
                        <label for="dispensary-search" class="sr-only"
                            >Search patient name, KP number or visit
                            number</label
                        >
                        <Input
                            id="dispensary-search"
                            v-model="search"
                            type="search"
                            placeholder="Name, medicine, KP, visit or queue no."
                            class="max-lg:min-h-11"
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            class="max-lg:min-h-11"
                        >
                            <Search class="size-4" aria-hidden="true" />Search
                        </Button>
                    </form>
                    <Button
                        type="button"
                        variant="outline"
                        class="min-h-11 lg:min-h-9"
                        :aria-expanded="showHelpfulInfo"
                        aria-controls="dispensary-help"
                        @click="showHelpfulInfo = !showHelpfulInfo"
                        >{{
                            showHelpfulInfo
                                ? 'Hide helpful info'
                                : 'Show helpful info'
                        }}</Button
                    >
                </div>

                <p class="text-xs text-muted-foreground">
                    Medicine search uses saved names from the latest handoff and
                    excludes medicines marked not dispensed.
                </p>

                <div
                    v-if="list.tab === 'prepare'"
                    class="flex flex-wrap items-center gap-2"
                >
                    <label
                        for="dispensary-case-type"
                        class="text-sm font-medium"
                        >Type</label
                    >
                    <select
                        id="dispensary-case-type"
                        :value="selectedType"
                        class="min-h-11 max-w-full rounded-lg border border-border bg-card px-3 text-sm text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring lg:min-h-9"
                        data-testid="dispensary-type-filter"
                        @change="
                            visit({
                                caseType: ($event.target as HTMLSelectElement)
                                    .value,
                            })
                        "
                    >
                        <option
                            v-for="option in typeOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <p class="text-xs text-muted-foreground">
                        Search narrows the list; the To prepare count includes
                        all cases matching the selected Type and Doctor filters.
                    </p>
                </div>

                <section
                    v-if="list.tab === 'prepare'"
                    ref="doctorRegion"
                    tabindex="-1"
                    aria-label="Doctor filter choices"
                    class="min-w-0 rounded-xl border bg-card p-3 outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    :aria-busy="doctorLoading"
                >
                    <label
                        for="dispensary-doctor"
                        class="block text-sm font-medium"
                        >Doctor</label
                    >
                    <select
                        id="dispensary-doctor"
                        :value="selectedDoctor"
                        class="mt-2 min-h-11 w-full max-w-full rounded-lg border border-border bg-card px-3 text-sm text-foreground focus-visible:outline-2 focus-visible:outline-ring lg:min-h-9"
                        @change="
                            visit({
                                doctor: ($event.target as HTMLSelectElement)
                                    .value,
                            })
                        "
                    >
                        <option value="all">All doctors</option>
                        <option value="otc">OTC counter</option>
                        <option
                            v-if="!['all', 'otc'].includes(selectedDoctor)"
                            :value="selectedDoctor"
                        >
                            {{ list.doctorLabel }}
                        </option>
                        <option
                            v-for="option in visibleDoctors"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <p class="mt-2 text-sm break-words">
                        Selected: {{ list.doctorLabel ?? 'All doctors' }}
                    </p>
                    <p
                        v-if="list.doctorFilterReset"
                        role="status"
                        class="mt-2 text-sm text-warning-strong"
                    >
                        The previous Doctor selection is no longer available in
                        this branch. Showing All doctors.
                    </p>
                    <p
                        v-if="doctorOptions?.reset"
                        role="status"
                        class="mt-2 text-sm text-warning-strong"
                    >
                        The selected doctor no longer has a case to prepare.
                        Choose All doctors to refresh the cases.
                    </p>
                    <form
                        class="mt-3 flex flex-wrap items-end gap-2"
                        @submit.prevent="
                            loadDoctors(1, doctorSearch.trim(), true)
                        "
                    >
                        <div class="min-w-0 flex-1">
                            <label
                                for="dispensary-doctor-search"
                                class="block text-xs text-muted-foreground"
                                >Find a doctor with a case to prepare</label
                            >
                            <Input
                                id="dispensary-doctor-search"
                                v-model="doctorSearch"
                                type="search"
                                class="mt-1 min-h-11 lg:min-h-9"
                            />
                        </div>
                        <Button
                            type="submit"
                            variant="outline"
                            class="min-h-11 lg:min-h-9"
                            >Find doctor</Button
                        >
                    </form>
                    <p
                        v-if="doctorLoading"
                        role="status"
                        class="mt-2 text-sm text-muted-foreground"
                    >
                        Loading doctor choices…
                    </p>
                    <p
                        v-if="doctorError"
                        role="alert"
                        class="mt-2 text-sm text-destructive"
                    >
                        {{ doctorError }}
                    </p>
                    <div
                        v-if="doctorOptions"
                        class="mt-3 flex flex-wrap items-center gap-2"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11 lg:min-h-9"
                            :disabled="doctorOptions.page <= 1"
                            @click="
                                loadDoctors(
                                    doctorOptions.page - 1,
                                    loadedDoctorSearch,
                                    true,
                                )
                            "
                            >Previous doctors</Button
                        >
                        <span
                            class="text-xs text-muted-foreground"
                            aria-live="polite"
                            >Page {{ doctorOptions.page }} of
                            {{ doctorOptions.lastPage }} ·
                            {{ doctorOptions.total }} matching doctors</span
                        >
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11 lg:min-h-9"
                            :disabled="
                                doctorOptions.page >= doctorOptions.lastPage
                            "
                            @click="
                                loadDoctors(
                                    doctorOptions.page + 1,
                                    loadedDoctorSearch,
                                    true,
                                )
                            "
                            >Next doctors</Button
                        >
                        <p
                            v-if="doctorOptions.total === 0"
                            class="w-full text-sm text-muted-foreground"
                        >
                            No doctor choices match this search. Clear the
                            doctor search to broaden the choices.
                        </p>
                    </div>
                    <p class="mt-2 text-xs text-muted-foreground">
                        Choices come from this branch’s cases to prepare,
                        independently of Type and case search. Finding a doctor
                        does not change the selected filter.
                    </p>
                </section>

                <EmptyState
                    v-if="list.rows.length === 0"
                    :title="
                        list.search ||
                        selectedType !== 'all' ||
                        selectedDoctor !== 'all'
                            ? 'No match'
                            : 'Nothing here'
                    "
                    :description="
                        selectedDoctor !== 'all'
                            ? 'No cases match the selected Doctor, Type and search. Choose All doctors or broaden the other filters.'
                            : selectedType !== 'all'
                              ? list.search
                                  ? `No case of the selected type matches “${list.search}” in To prepare. Choose All cases or change the search.`
                                  : 'No cases of the selected type are waiting to prepare. Choose All cases to broaden the list.'
                              : list.search
                                ? `No case matches “${list.search}” in this tab.`
                                : emptyText
                    "
                />

                <template v-else>
                    <div class="hidden xl:block">
                        <OperationalTable
                            label="Dispensary cases"
                            :columns="6"
                            min-width="820px"
                        >
                            <template #head>
                                <tr>
                                    <th scope="col">No.</th>
                                    <th scope="col">Patient</th>
                                    <th scope="col">Items</th>
                                    <th scope="col">From</th>
                                    <th scope="col" class="text-right">
                                        {{
                                            list.tab === 'done'
                                                ? 'Completed'
                                                : 'Received'
                                        }}
                                    </th>
                                    <th scope="col" class="text-right">
                                        <span class="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </template>
                            <template #body>
                                <tr
                                    v-for="row in list.rows"
                                    :key="row.publicId"
                                    data-testid="dispensary-list-row"
                                >
                                    <td class="font-mono whitespace-nowrap">
                                        {{ row.queueNumber ?? '—' }}
                                    </td>
                                    <td>
                                        <span
                                            class="block font-medium text-foreground"
                                            >{{ row.patientName }}</span
                                        >
                                        <span
                                            class="block font-mono text-xs text-muted-foreground"
                                            >{{ row.patientNumber }} ·
                                            {{ row.visitNumber }}</span
                                        >
                                    </td>
                                    <td class="whitespace-nowrap">
                                        {{ medicines(row.medicineCount) }}
                                    </td>
                                    <td>{{ from(row) }}</td>
                                    <td class="text-right whitespace-nowrap">
                                        <span class="block tabular-nums">{{
                                            row.completedAt ?? row.receivedAt
                                        }}</span>
                                        <span
                                            v-if="row.waitingMinutes !== null"
                                            class="inline-flex items-center gap-1.5 text-xs"
                                            :class="toneClass(tone(row))"
                                            data-testid="dispensary-waiting"
                                            ><span
                                                v-if="tone(row) !== 'normal'"
                                                class="size-1.5 rounded-full bg-current"
                                                aria-hidden="true"
                                            ></span
                                            >{{ waiting(row)
                                            }}<span
                                                v-if="
                                                    waitingToneDescription(
                                                        tone(row),
                                                    )
                                                "
                                                class="sr-only"
                                            >
                                                ({{
                                                    waitingToneDescription(
                                                        tone(row),
                                                    )
                                                }})</span
                                            ></span
                                        >
                                    </td>
                                    <td class="text-right">
                                        <div class="flex justify-end gap-2">
                                            <Button
                                                v-if="row.labelUrl"
                                                as-child
                                                variant="outline"
                                                size="sm"
                                                class="max-lg:min-h-11"
                                                ><a
                                                    :href="row.labelUrl"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    data-testid="dispensary-list-labels"
                                                    :aria-label="`${row.labelAction === 'reprint' ? 'Reprint labels' : 'View labels'} for ${row.patientName}, ${row.visitNumber} (opens in a new tab)`"
                                                >
                                                    <Printer
                                                        v-if="
                                                            row.labelAction ===
                                                            'reprint'
                                                        "
                                                        class="size-4"
                                                        aria-hidden="true"
                                                    /><Eye
                                                        v-else
                                                        class="size-4"
                                                        aria-hidden="true"
                                                    />{{
                                                        row.labelAction ===
                                                        'reprint'
                                                            ? 'Reprint labels'
                                                            : 'View labels'
                                                    }}</a
                                                ></Button
                                            >
                                            <ActionLink
                                                :href="row.caseUrl"
                                                variant="outline"
                                                size="sm"
                                                class="max-lg:min-h-11"
                                                :aria-label="`Open case for ${row.patientName}, ${row.visitNumber}`"
                                                >Open case</ActionLink
                                            >
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </OperationalTable>
                    </div>

                    <ul
                        class="flex flex-col gap-3 xl:hidden"
                        aria-label="Dispensary cases"
                    >
                        <li
                            v-for="row in list.rows"
                            :key="row.publicId"
                            class="rounded-xl border bg-card p-4 text-sm"
                            data-testid="dispensary-list-card"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-medium text-foreground">
                                        <span class="mr-2 font-mono">{{
                                            row.queueNumber ?? '—'
                                        }}</span
                                        >{{ row.patientName }}
                                    </p>
                                    <p
                                        class="font-mono text-xs text-muted-foreground"
                                    >
                                        {{ row.patientNumber }} ·
                                        {{ row.visitNumber }}
                                    </p>
                                </div>
                                <span
                                    class="inline-flex shrink-0 items-center gap-1.5 text-xs"
                                    :class="
                                        row.completedAt
                                            ? 'text-muted-foreground'
                                            : toneClass(tone(row))
                                    "
                                    ><span
                                        v-if="
                                            !row.completedAt &&
                                            tone(row) !== 'normal'
                                        "
                                        class="size-1.5 rounded-full bg-current"
                                        aria-hidden="true"
                                    ></span
                                    >{{ row.completedAt ?? waiting(row)
                                    }}<span
                                        v-if="
                                            !row.completedAt &&
                                            waitingToneDescription(tone(row))
                                        "
                                        class="sr-only"
                                    >
                                        ({{
                                            waitingToneDescription(tone(row))
                                        }})</span
                                    ></span
                                >
                            </div>
                            <p class="mt-2 text-muted-foreground">
                                {{ medicines(row.medicineCount) }} ·
                                {{ from(row) }}
                            </p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <ActionLink
                                    :href="row.caseUrl"
                                    variant="outline"
                                    size="sm"
                                    class="min-h-11"
                                    :aria-label="`Open case for ${row.patientName}, ${row.visitNumber}`"
                                    >Open case</ActionLink
                                >
                                <Button
                                    v-if="row.labelUrl"
                                    as-child
                                    variant="outline"
                                    size="sm"
                                    class="min-h-11"
                                    ><a
                                        :href="row.labelUrl"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        ><Printer
                                            v-if="row.labelAction === 'reprint'"
                                            class="size-4"
                                            aria-hidden="true"
                                        /><Eye
                                            v-else
                                            class="size-4"
                                            aria-hidden="true"
                                        />{{
                                            row.labelAction === 'reprint'
                                                ? 'Reprint labels'
                                                : 'View labels'
                                        }}<span class="sr-only">
                                            (opens in a new tab)</span
                                        ></a
                                    ></Button
                                >
                            </div>
                        </li>
                    </ul>

                    <CompactPagination
                        :current-page="list.page"
                        :last-page="list.lastPage"
                        :total="list.total"
                        @change="(page: number) => visit({ page })"
                    />
                </template>
            </section>
            <aside
                v-if="showHelpfulInfo"
                id="dispensary-help"
                aria-label="Helpful information"
                class="flex min-w-0 flex-col gap-4"
            >
                <details
                    open
                    class="rounded-[14px] border bg-card px-4 shadow-card"
                >
                    <summary
                        class="min-h-11 cursor-pointer py-3 text-sm font-semibold outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        About this tab
                    </summary>
                    <div class="pb-4 text-sm">
                        <p class="mb-2 font-medium">{{ currentTab?.label }}</p>
                        <p class="text-muted-foreground">{{ tabHelp }}</p>
                    </div>
                </details>
                <details
                    open
                    class="rounded-[14px] border bg-card px-4 shadow-card"
                >
                    <summary
                        class="min-h-11 cursor-pointer py-3 text-sm font-semibold outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        Today at a glance
                    </summary>
                    <dl class="pb-2 text-sm">
                        <div
                            v-for="tab in tabs"
                            :key="tab.value"
                            class="flex items-start justify-between gap-3 border-t py-3"
                        >
                            <dt>{{ tab.label }}</dt>
                            <dd class="shrink-0 font-medium tabular-nums">
                                {{ tab.count }}
                            </dd>
                        </div>
                    </dl>
                </details>
                <details class="rounded-[14px] border bg-card px-4 shadow-card">
                    <summary
                        class="min-h-11 cursor-pointer py-3 text-sm font-semibold outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        Good to know
                    </summary>
                    <p class="pb-4 text-sm text-muted-foreground">
                        Allergy matches show on the case before medicines are
                        saved. Labels print from saved lines only. Return checks
                        happen on the case page; recording a return does not
                        give a refund.
                    </p>
                </details>
            </aside>
        </div>
    </main>
</template>
