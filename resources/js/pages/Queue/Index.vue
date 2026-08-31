<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Clock3,
    ListOrdered,
    LoaderCircle,
    Search,
    Stethoscope,
} from '@lucide/vue';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from 'vue';
import InputError from '@/components/InputError.vue';
import QueueRows from '@/components/queue/QueueRows.vue';
import { Button } from '@/components/ui/button';
import type { QueueRow, QueueSnapshot } from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Queue', href: '/queue' }] },
});

const props = defineProps<{ snapshot: QueueSnapshot }>();
const live = ref(props.snapshot);
// Network coordination is deliberately separate from user-facing filter state:
// quiet background polls must never make the filter form look submitted.
const isRefreshing = ref(false);
const isApplyingFilters = ref(false);
const error = ref('');
const callingVisit = ref<string | null>(null);
const openingVisit = ref<string | null>(null);
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

const visibleRows = computed(() => {
    if (filters.status === 'removed') {
        return live.value.removed;
    }

    return [
        ...live.value.carryOver.data,
        ...live.value.waiting.data,
        ...live.value.serving,
    ];
});

const waitLabel = (row: QueueRow) => {
    void clockNow.value;
    const serverAtReceipt = Date.parse(live.value.serverNow);
    const effectiveNow = serverAtReceipt + (Date.now() - receivedAt.value);
    const minutes = Math.max(
        0,
        Math.floor((effectiveNow - Date.parse(row.queuedAt)) / 60_000),
    );

    if (minutes < 60) {
        return `${minutes}m`;
    }

    return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
};

const schedule = () => {
    if (pollTimer) {
        clearTimeout(pollTimer);
    }

    if (document.hidden) {
        return;
    }

    const delay = failures
        ? Math.min(30_000, 3_000 * 2 ** Math.min(failures, 3))
        : 3_000;
    pollTimer = setTimeout(
        () =>
            refresh(
                live.value.waiting.currentPage,
                live.value.carryOver.currentPage,
            ),
        delay,
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
const clearFilters = () => {
    Object.assign(filters, {
        query: '',
        doctor_id: '',
        priority: '',
        status: '',
    });
    applyFilters();
};
const callIn = (row: QueueRow) => {
    if (callingVisit.value) {
        return;
    }

    callingVisit.value = row.visitNumber;
    router.patch(
        `/visits/${encodeURIComponent(row.visitNumber)}/queue/call`,
        {
            expected_branch_id: live.value.branch.id,
            visit_lock_version: row.visitLockVersion,
            queue_lock_version: row.queueLockVersion,
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                error.value = Object.values(errors)[0] ?? 'Call In failed.';
            },
            onFinish: () => {
                callingVisit.value = null;
                void refresh(1, 1, false).finally(schedule);
            },
        },
    );
};
const openConsultation = (row: QueueRow) => {
    if (openingVisit.value) {
        return;
    }

    openingVisit.value = row.visitNumber;
    router.post(
        `/visits/${encodeURIComponent(row.visitNumber)}/encounter`,
        {
            expected_branch_id: live.value.branch.id,
            visit_lock_version: row.visitLockVersion,
            queue_lock_version: row.queueLockVersion,
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                error.value =
                    Object.values(errors)[0] ??
                    'The consultation could not be opened.';
            },
            onFinish: () => {
                openingVisit.value = null;
            },
        },
    );
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
    <Head title="Queue" />
    <main class="flex flex-1 flex-col gap-4 p-4 md:p-6">
        <div
            class="flex flex-col justify-between gap-3 md:flex-row md:items-center"
        >
            <div class="flex items-center gap-3">
                <div class="rounded-lg bg-emerald-100 p-2 text-emerald-800">
                    <ListOrdered class="size-5" />
                </div>
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Queue</h1>
                    <p class="text-sm text-muted-foreground">
                        {{ live.branch.name }} · {{ live.operationalDate }} ·
                        {{
                            live.scope === 'own'
                                ? 'My patients'
                                : 'Branch Queue'
                        }}
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2 text-xs text-muted-foreground">
                <span>Live · every 3 seconds</span>
                <Button v-if="live.scope === 'branch'" as-child size="sm">
                    <Link href="/registration">Registration</Link>
                </Button>
            </div>
        </div>

        <form
            class="rounded-lg border bg-card p-3"
            @submit.prevent="applyFilters"
        >
            <div
                class="grid gap-2 md:grid-cols-2 lg:grid-cols-[minmax(280px,1.6fr)_minmax(160px,0.8fr)_140px_140px_auto_auto]"
            >
                <label class="relative md:col-span-2 lg:col-span-1">
                    <Search
                        class="absolute top-2.5 left-3 size-4 text-muted-foreground"
                    />
                    <input
                        v-model="filters.query"
                        autocomplete="off"
                        class="h-9 w-full rounded-md border bg-background pr-3 pl-9 text-sm"
                        placeholder="Patient or exact Queue number"
                    />
                </label>
                <select
                    v-if="live.scope === 'branch'"
                    v-model="filters.doctor_id"
                    aria-label="Doctor"
                    class="h-9 rounded-md border bg-background px-2 text-sm"
                >
                    <option value="">All doctors</option>
                    <option
                        v-for="doctor in live.doctors"
                        :key="doctor.id"
                        :value="doctor.id"
                    >
                        {{ doctor.name }}
                    </option>
                </select>
                <span v-else class="hidden lg:block" />
                <select
                    v-model="filters.priority"
                    aria-label="Priority"
                    class="h-9 rounded-md border bg-background px-2 text-sm"
                >
                    <option value="">All priorities</option>
                    <option value="urgent">Urgent</option>
                    <option value="normal">Normal</option>
                </select>
                <select
                    v-model="filters.status"
                    aria-label="Status"
                    class="h-9 rounded-md border bg-background px-2 text-sm"
                >
                    <option value="">Active Queue</option>
                    <option value="waiting">Waiting</option>
                    <option value="serving">Serving</option>
                    <option value="removed">Removed</option>
                </select>
                <Button type="submit" size="sm" :disabled="isApplyingFilters">
                    <LoaderCircle
                        v-if="isApplyingFilters"
                        class="size-3.5 animate-spin"
                    />
                    {{ isApplyingFilters ? 'Applying…' : 'Apply' }}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    @click="clearFilters"
                    >Reset</Button
                >
            </div>
            <InputError class="mt-2" :message="error" />
        </form>

        <section
            v-if="filters.status !== 'waiting' && filters.status !== 'removed'"
            class="space-y-2"
        >
            <div class="flex items-center gap-2">
                <Stethoscope class="size-4 text-emerald-700" />
                <h2 class="text-sm font-semibold tracking-wide uppercase">
                    Serving now
                </h2>
                <span class="text-xs text-muted-foreground">{{
                    live.serving.length
                }}</span>
            </div>
            <div
                v-if="live.serving.length"
                class="grid gap-2 md:grid-cols-2 xl:grid-cols-3"
            >
                <div
                    v-for="row in live.serving"
                    :key="`${row.operationalDate}-${row.queueNumber}`"
                    class="flex items-center gap-3 rounded-lg bg-emerald-50/60 px-4 py-3 ring-1 ring-emerald-200/70"
                >
                    <span
                        class="font-mono text-2xl font-bold text-emerald-800"
                        >{{ row.queueNumber }}</span
                    >
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-semibold">{{
                            row.patientName
                        }}</span>
                        <span
                            class="block truncate text-xs text-muted-foreground"
                            >{{ row.doctorName }}</span
                        >
                    </span>
                    <Button
                        v-if="row.canOpenEncounter"
                        size="sm"
                        :disabled="openingVisit !== null || !row.doctorEligible"
                        @click="openConsultation(row)"
                    >
                        <LoaderCircle
                            v-if="openingVisit === row.visitNumber"
                            class="size-4 animate-spin"
                        />
                        Open consultation
                    </Button>
                </div>
            </div>
            <p
                v-else
                class="rounded-lg bg-muted/25 px-4 py-3 text-sm text-muted-foreground"
            >
                No Patient is currently Serving.
            </p>
        </section>

        <section
            v-if="
                filters.status !== 'serving' &&
                filters.status !== 'removed' &&
                live.carryOver.total
            "
            class="overflow-hidden rounded-lg border border-amber-300 bg-amber-50/30"
        >
            <div
                class="flex items-center gap-2 border-b border-amber-200 px-4 py-2.5"
            >
                <AlertTriangle class="size-4 text-amber-700" />
                <h2 class="text-sm font-semibold text-amber-900">
                    Previous day / carry-over
                </h2>
                <span class="text-xs text-amber-800">
                    {{ live.carryOver.total }} unresolved · Review before
                    today’s Queue
                </span>
            </div>
            <QueueRows
                :rows="live.carryOver.data"
                :calling-visit="callingVisit"
                :wait-label="waitLabel"
                @call="callIn"
            />
            <div
                v-if="live.carryOver.lastPage > 1"
                class="flex justify-end gap-2 border-t border-amber-200 p-3"
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

        <section
            class="overflow-hidden rounded-lg bg-card ring-1 ring-border/60"
        >
            <div class="flex items-center justify-between px-4 py-2.5">
                <div class="flex items-center gap-2">
                    <Clock3 class="size-4 text-muted-foreground" />
                    <h2 class="text-sm font-semibold tracking-wide uppercase">
                        {{
                            filters.status === 'removed'
                                ? 'Removed'
                                : 'Waiting today'
                        }}
                    </h2>
                </div>
                <span class="text-xs text-muted-foreground">
                    {{
                        filters.status === 'removed'
                            ? live.removed.length
                            : live.waiting.total
                    }}
                    entries
                </span>
            </div>
            <QueueRows
                :rows="
                    filters.status === 'removed'
                        ? live.removed
                        : live.waiting.data
                "
                :calling-visit="callingVisit"
                :wait-label="waitLabel"
                @call="callIn"
            />
            <div
                v-if="live.waiting.lastPage > 1 && filters.status !== 'removed'"
                class="flex justify-end gap-2 border-t p-3"
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
                    >Previous</Button
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
                    >Next</Button
                >
            </div>
        </section>

        <p
            v-if="!visibleRows.length"
            class="py-8 text-center text-sm text-muted-foreground"
        >
            No Queue entries match this operational view.
        </p>
    </main>
</template>
