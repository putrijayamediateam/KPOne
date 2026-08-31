<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ChevronDown,
    ClipboardPlus,
    Plus,
    Search,
    SlidersHorizontal,
    X,
} from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import type { VisitOptions, VisitRow } from '@/types';

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
const rows = ref(props.visits.data);
const total = ref(props.visits.total);
const currentPage = ref(props.visits.currentPage);
const lastPage = ref(props.visits.lastPage);
const loading = ref(false);
const error = ref('');
const showMoreFilters = ref(false);
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
    status: '',
    date_from: today,
    date_to: today,
});
const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';
const search = async (page = 1) => {
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
        error.value = 'Registration search could not be completed.';
    } finally {
        loading.value = false;
    }
};
const advancedFilterCount = computed(
    () =>
        [form.priority, form.status].filter(Boolean).length +
        (form.date_from !== today || form.date_to !== today ? 1 : 0),
);
const resetFilters = () => {
    Object.assign(form, {
        patient_query: '',
        doctor_id: '',
        visit_type: '',
        priority: '',
        coverage_type: '',
        status: '',
        date_from: today,
        date_to: today,
    });
    showMoreFilters.value = false;
    search(1);
};
const visitHref = (number: string) => '/visits/' + encodeURIComponent(number);
</script>

<template>
    <Head title="Registration" />
    <main class="flex flex-1 flex-col gap-4 p-4 md:p-6">
        <div
            class="flex flex-col justify-between gap-3 md:flex-row md:items-center"
        >
            <div class="flex items-center gap-3">
                <div class="rounded-lg bg-emerald-100 p-2 text-emerald-800">
                    <ClipboardPlus class="size-5" />
                </div>
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Registration
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        {{ options.branch.name }} · current operational day
                    </p>
                </div>
            </div>
            <Button v-if="canCreate" as-child>
                <Link href="/registration/create"
                    ><Plus class="size-4" /> Register Visit</Link
                >
            </Button>
        </div>

        <form class="rounded-lg border bg-card p-3" @submit.prevent="search(1)">
            <div
                class="grid gap-2 md:grid-cols-2 lg:grid-cols-[minmax(260px,1.7fr)_minmax(160px,0.8fr)_minmax(140px,0.7fr)_minmax(140px,0.7fr)_auto_auto]"
            >
                <label class="relative md:col-span-2 lg:col-span-1">
                    <Search
                        class="absolute top-2.5 left-3 size-4 text-muted-foreground"
                    />
                    <input
                        v-model="form.patient_query"
                        autofocus
                        autocomplete="off"
                        class="h-9 w-full rounded-md border bg-background pr-3 pl-9 text-sm focus-visible:ring-2 focus-visible:ring-emerald-600/30 focus-visible:outline-none"
                        placeholder="Patient name or exact Patient No."
                    />
                </label>
                <select
                    v-model="form.doctor_id"
                    aria-label="Doctor"
                    class="h-9 rounded-md border bg-background px-2 text-sm"
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
                    v-model="form.visit_type"
                    aria-label="Visit type"
                    class="h-9 rounded-md border bg-background px-2 text-sm"
                >
                    <option value="">All visit types</option>
                    <option value="consultation">Consultation</option>
                    <option value="otc">OTC</option>
                </select>
                <select
                    v-model="form.coverage_type"
                    aria-label="Coverage"
                    class="h-9 rounded-md border bg-background px-2 text-sm"
                >
                    <option value="">All coverage</option>
                    <option value="self_pay">Self-pay</option>
                    <option value="panel">Panel</option>
                </select>
                <Button size="sm" type="submit" :disabled="loading">
                    {{ loading ? 'Loading…' : 'Apply' }}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    @click="showMoreFilters = !showMoreFilters"
                >
                    <SlidersHorizontal class="size-4" /> More
                    <span
                        v-if="advancedFilterCount"
                        class="rounded-full bg-emerald-100 px-1.5 text-[11px] text-emerald-800"
                        >{{ advancedFilterCount }}</span
                    >
                    <ChevronDown
                        class="size-3.5 transition-transform"
                        :class="showMoreFilters ? 'rotate-180' : ''"
                    />
                </Button>
            </div>
            <div
                v-if="showMoreFilters"
                class="mt-3 flex flex-wrap items-end gap-2 border-t border-border/60 pt-3"
            >
                <label class="grid gap-1">
                    <span class="text-xs font-medium text-muted-foreground"
                        >Priority</span
                    >
                    <select
                        v-model="form.priority"
                        class="h-9 rounded-md border bg-background px-2 text-sm"
                    >
                        <option value="">All priorities</option>
                        <option value="normal">Normal</option>
                        <option value="urgent">Urgent</option>
                    </select>
                </label>
                <label class="grid gap-1">
                    <span class="text-xs font-medium text-muted-foreground"
                        >Status</span
                    >
                    <select
                        v-model="form.status"
                        class="h-9 rounded-md border bg-background px-2 text-sm"
                    >
                        <option value="">All statuses</option>
                        <option value="registered">Registered</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </label>
                <label class="grid gap-1">
                    <span class="text-xs font-medium text-muted-foreground"
                        >From</span
                    >
                    <input
                        v-model="form.date_from"
                        type="date"
                        class="h-9 rounded-md border bg-background px-2 text-sm"
                    />
                </label>
                <label class="grid gap-1">
                    <span class="text-xs font-medium text-muted-foreground"
                        >To</span
                    >
                    <input
                        v-model="form.date_to"
                        type="date"
                        class="h-9 rounded-md border bg-background px-2 text-sm"
                    />
                </label>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    @click="resetFilters"
                >
                    <X class="size-4" /> Reset
                </Button>
            </div>
            <InputError class="mt-2" :message="error" />
        </form>

        <section
            class="overflow-hidden rounded-lg bg-card ring-1 ring-border/60"
        >
            <div
                class="flex items-center justify-between px-4 py-2.5 text-xs text-muted-foreground"
            >
                <span>{{ total }} Visit{{ total === 1 ? '' : 's' }}</span>
                <span>25 per page</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[940px] text-left text-sm">
                    <thead
                        class="bg-muted/35 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="px-4 py-2.5">Registered</th>
                            <th class="px-4 py-2.5">Patient</th>
                            <th class="px-4 py-2.5">Visit</th>
                            <th class="px-4 py-2.5">Doctor</th>
                            <th class="px-4 py-2.5">Coverage</th>
                            <th class="px-4 py-2.5">Priority</th>
                            <th class="px-4 py-2.5">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="visit in rows"
                            :key="visit.visitNumber"
                            class="border-b border-border/40 transition-colors last:border-0 hover:bg-emerald-50/30"
                        >
                            <td class="px-4 py-3.5 align-top">
                                <span class="font-semibold tabular-nums">{{
                                    visit.registeredAt
                                }}</span>
                            </td>
                            <td class="px-4 py-3.5 align-top">
                                <div class="font-medium">
                                    {{ visit.patientName }}
                                </div>
                                <div
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{ visit.patientNumber }}
                                </div>
                            </td>
                            <td class="max-w-72 px-4 py-3.5 align-top">
                                <div class="flex items-center gap-2">
                                    <Link
                                        :href="visitHref(visit.visitNumber)"
                                        class="font-mono text-xs font-medium text-emerald-800 hover:underline"
                                        >{{ visit.visitNumber }}</Link
                                    >
                                    <span
                                        class="text-xs text-muted-foreground capitalize"
                                        >{{ visit.visitType }}</span
                                    >
                                    <span
                                        v-if="visit.queueStatus"
                                        class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-800 capitalize"
                                        >Queue {{ visit.queueNumber }} ·
                                        {{ visit.queueStatus }}</span
                                    >
                                </div>
                                <div
                                    class="mt-1 truncate text-xs text-muted-foreground"
                                >
                                    {{
                                        visit.visitReasonExcerpt ??
                                        'No reason recorded'
                                    }}
                                </div>
                            </td>
                            <td class="px-4 py-3.5 align-top">
                                {{ visit.doctorName ?? '—' }}
                            </td>
                            <td class="px-4 py-3.5 align-top">
                                {{ visit.coverageLabel }}
                            </td>
                            <td class="px-4 py-3.5 align-top">
                                <span
                                    :class="
                                        visit.priority === 'urgent'
                                            ? 'border-red-200 bg-red-50 text-red-700'
                                            : 'border-muted bg-muted/40 text-muted-foreground'
                                    "
                                    class="rounded-full border px-2 py-0.5 text-xs font-medium capitalize"
                                    >{{ visit.priority }}</span
                                >
                            </td>
                            <td class="px-4 py-3.5 align-top">
                                <span
                                    :class="
                                        visit.status === 'registered'
                                            ? 'bg-emerald-50 text-emerald-800'
                                            : 'bg-muted text-muted-foreground'
                                    "
                                    class="rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                                    >{{ visit.status }}</span
                                >
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td
                                colspan="7"
                                class="px-4 py-12 text-center text-sm text-muted-foreground"
                            >
                                No Visits match this operational view.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
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
                ><Button
                    size="sm"
                    variant="outline"
                    :disabled="currentPage === lastPage"
                    @click="search(currentPage + 1)"
                    >Next</Button
                >
            </div>
        </section>
    </main>
</template>
