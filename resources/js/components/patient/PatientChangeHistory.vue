<script setup lang="ts">
import { FileClock } from '@lucide/vue';
import { nextTick, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/state';

export type PatientChangeHistoryRow = {
    id: number;
    event: string;
    label: string;
    keptPatientNumber: string | null;
    mergedPatientNumber: string | null;
    fields: string[];
    recordNumber: string | null;
    isThisRecord: boolean;
    actorName: string;
    occurredAt: string;
    occurredAtLocal: string;
};

export type PatientChangeHistoryData = {
    patientNumber: string;
    timezone: string;
    rows: PatientChangeHistoryRow[];
    nextCursor: string | null;
};

// REG-4: who changed which parts of the record and when. Field names only, never values.
const props = defineProps<{ history: PatientChangeHistoryData }>();

const rows = ref<PatientChangeHistoryRow[]>([...props.history.rows]);
const nextCursor = ref<string | null>(props.history.nextCursor);
const loading = ref(false);
const failed = ref(false);
const rowRefs = ref<Record<number, HTMLElement | null>>({});
const olderButton = ref<{ $el?: HTMLElement } | null>(null);
// One live region that is always on the page, so screen readers announce each status change.
const announcement = ref('');
const REQUEST_TIMEOUT_MS = 15000;
// A fresh page visit sends a new first page; start again from it.
watch(
    () => props.history,
    (history) => {
        rows.value = [...history.rows];
        nextCursor.value = history.nextCursor;
        failed.value = false;
        announcement.value = '';
    },
);

const spoken = (row: PatientChangeHistoryRow) =>
    `${row.label} by ${row.actorName} on ${row.occurredAtLocal}.` +
    (!row.isThisRecord && row.recordNumber && !row.keptPatientNumber
        ? ` From ${row.recordNumber}.`
        : '') +
    (row.fields.length ? ` Fields: ${row.fields.join(', ')}.` : '') +
    (row.keptPatientNumber && row.mergedPatientNumber
        ? ` Kept ${row.keptPatientNumber}, merged ${row.mergedPatientNumber}.`
        : '');

const loadOlder = async () => {
    if (!nextCursor.value || loading.value) {
        return;
    }

    loading.value = true;
    failed.value = false;
    announcement.value = 'Loading older changes…';
    const controller = new AbortController();
    const timeout = window.setTimeout(
        () => controller.abort(),
        REQUEST_TIMEOUT_MS,
    );

    try {
        const response = await fetch(
            '/patients/' +
                encodeURIComponent(props.history.patientNumber) +
                '/history?cursor=' +
                encodeURIComponent(nextCursor.value),
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: controller.signal,
            },
        );

        if (!response.ok) {
            throw new Error('history request failed');
        }

        const page = (await response.json()) as PatientChangeHistoryData;
        const firstNewId = page.rows[0]?.id;
        rows.value = [...rows.value, ...page.rows];
        nextCursor.value = page.nextCursor;
        announcement.value = nextCursor.value
            ? `${page.rows.length} older changes shown.`
            : 'All changes shown.';
        await nextTick();

        if (firstNewId !== undefined) {
            rowRefs.value[firstNewId]?.focus();
        }
    } catch {
        failed.value = true;
        announcement.value = 'Could not load older changes. Try again.';
        await nextTick();
        // Keep keyboard users on the button they pressed, now reading "Try again".
        olderButton.value?.$el?.focus();
    } finally {
        window.clearTimeout(timeout);
        loading.value = false;
    }
};
</script>

<template>
    <section
        class="rounded-lg border bg-card"
        aria-labelledby="patient-history-heading"
        data-test="patient-change-history"
    >
        <div class="border-b px-5 py-4">
            <h2
                id="patient-history-heading"
                class="flex items-center gap-2 font-semibold"
            >
                <FileClock class="size-4" aria-hidden="true" /> Change history
            </h2>
            <p class="mt-1 text-xs text-muted-foreground">
                Shows which details changed, not the old or new values.
            </p>
        </div>

        <div v-if="!rows.length" class="p-5">
            <EmptyState
                title="No changes recorded yet."
                description="Changes made after this record was created will appear here."
            />
        </div>

        <ol v-else class="divide-y text-sm" aria-label="Patient change history">
            <li
                v-for="row in rows"
                :key="row.id"
                :ref="(el) => (rowRefs[row.id] = el as HTMLElement | null)"
                tabindex="-1"
                class="grid min-w-0 gap-1 px-5 py-3 outline-none focus-visible:ring-2 focus-visible:ring-ring md:grid-cols-[minmax(0,1fr)_auto] md:gap-4"
            >
                <span class="sr-only">{{ spoken(row) }}</span>
                <div class="min-w-0 space-y-1" aria-hidden="true">
                    <p class="font-medium break-words">
                        {{ row.label }}
                        <span
                            v-if="
                                !row.isThisRecord &&
                                row.recordNumber &&
                                !row.keptPatientNumber
                            "
                            class="font-normal text-muted-foreground tabular-nums"
                        >
                            From {{ row.recordNumber }}</span
                        >
                    </p>
                    <div v-if="row.fields.length" class="flex flex-wrap gap-1">
                        <Badge
                            v-for="field in row.fields"
                            :key="field"
                            variant="outline"
                            class="text-xs break-words whitespace-normal"
                            >{{ field }}</Badge
                        >
                    </div>
                    <p
                        v-if="row.keptPatientNumber && row.mergedPatientNumber"
                        class="text-xs break-all text-foreground tabular-nums"
                    >
                        Kept {{ row.keptPatientNumber }} · merged
                        {{ row.mergedPatientNumber }}
                    </p>
                </div>
                <p
                    class="text-xs text-muted-foreground md:text-right"
                    aria-hidden="true"
                >
                    <span class="break-words">{{ row.actorName }}</span>
                    <span class="md:hidden"> · </span
                    ><br class="hidden md:inline" />
                    <time :datetime="row.occurredAt">{{
                        row.occurredAtLocal
                    }}</time>
                </p>
            </li>
        </ol>

        <div
            v-if="rows.length"
            class="flex flex-wrap items-center justify-between gap-2 border-t px-5 py-3"
        >
            <p class="sr-only" aria-live="polite" role="status">
                {{ announcement }}
            </p>
            <!-- The live region above makes the only announcement; these show the same state on screen. -->
            <div v-if="loading" aria-hidden="true">
                <LoadingState label="Loading older changes…" />
            </div>
            <div v-else-if="failed" aria-hidden="true" class="min-w-0">
                <ErrorState
                    title="Could not load older changes"
                    message="Check the connection, then press Try again."
                />
            </div>
            <p
                v-else-if="!nextCursor"
                aria-hidden="true"
                class="text-xs text-muted-foreground"
            >
                All changes shown.
            </p>
            <span v-else />
            <Button
                v-if="nextCursor"
                type="button"
                variant="outline"
                size="sm"
                class="min-h-11 aria-disabled:opacity-50 md:min-h-0"
                ref="olderButton"
                :aria-disabled="loading"
                data-test="patient-history-older"
                @click="loadOlder"
            >
                {{ failed ? 'Try again' : 'Show older changes' }}
            </Button>
        </div>
    </section>
</template>
