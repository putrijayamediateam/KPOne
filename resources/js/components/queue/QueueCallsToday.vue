<script setup lang="ts">
import { ref, useId } from 'vue';
import type { QueueCallStats } from '@/lib/queue-call-stats';

const props = withDefaults(
    defineProps<{
        branchName: string;
        stats: QueueCallStats;
        busy?: boolean;
        error?: string;
    }>(),
    { busy: false, error: '' },
);
const emit = defineEmits<{ page: [page: number] }>();
const results = ref<HTMLElement | null>(null);
const errorId = useId();
const selectPage = (page: number) => {
    if (props.busy || page < 1 || page > props.stats.roomPagination.lastPage) {
        return;
    }

    results.value?.focus({ preventScroll: true });
    emit('page', page);
};

const serviceLabels: Record<string, string> = {
    consultation: 'Consultation',
    dispensary: 'Dispensary',
    treatment: 'Treatment',
};
const serviceLabel = (service: string) =>
    serviceLabels[service] ?? 'Other service';
</script>

<template>
    <details
        ref="results"
        tabindex="-1"
        aria-label="Calls today results"
        :aria-describedby="error ? errorId : undefined"
        class="rounded-xl border bg-card p-4"
    >
        <summary
            class="min-h-11 cursor-pointer rounded-sm py-3 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
        >
            Calls today <span class="ml-2 tabular-nums">{{ stats.total }}</span>
        </summary>
        <p class="mt-3 text-sm">
            {{ branchName }} · {{ stats.operationalDate }}
        </p>
        <p class="mt-1 text-xs text-muted-foreground">
            Recalls included: {{ stats.recalls }}. Counts are updated when this
            page loads.
        </p>
        <p
            v-if="error"
            :id="errorId"
            role="alert"
            class="mt-3 text-sm text-destructive"
        >
            {{ error }}
        </p>
        <p v-if="stats.total === 0" class="mt-4 text-sm text-muted-foreground">
            No calls recorded today at {{ branchName }}.
        </p>
        <div v-else class="mt-4 grid gap-6 md:grid-cols-2">
            <div class="min-w-0">
                <h2 class="text-sm font-semibold">By room</h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ stats.byRoom.length }} of
                    {{ stats.roomPagination.total }} room labels · page
                    {{ stats.roomPagination.currentPage }} of
                    {{ stats.roomPagination.lastPage }}. Names are from call
                    records.
                </p>
                <ul class="mt-2 divide-y text-sm">
                    <li
                        v-for="room in stats.byRoom"
                        :key="room.room"
                        class="flex items-start justify-between gap-4 py-2"
                    >
                        <span class="min-w-0 break-words">{{ room.room }}</span>
                        <span class="shrink-0 tabular-nums"
                            >{{ room.calls }} calls</span
                        >
                    </li>
                </ul>
                <nav
                    v-if="stats.roomPagination.lastPage > 1"
                    aria-label="Calls today room pages"
                    class="mt-3 flex flex-wrap gap-2"
                >
                    <button
                        type="button"
                        class="min-h-9 rounded-full border bg-background px-3 text-sm hover:bg-accent disabled:opacity-50 max-lg:min-h-11"
                        :disabled="
                            busy || stats.roomPagination.currentPage <= 1
                        "
                        @click="
                            selectPage(stats.roomPagination.currentPage - 1)
                        "
                    >
                        Previous rooms
                    </button>
                    <button
                        type="button"
                        class="min-h-9 rounded-full border bg-background px-3 text-sm hover:bg-accent disabled:opacity-50 max-lg:min-h-11"
                        :disabled="
                            busy ||
                            stats.roomPagination.currentPage >=
                                stats.roomPagination.lastPage
                        "
                        @click="
                            selectPage(stats.roomPagination.currentPage + 1)
                        "
                    >
                        Next rooms
                    </button>
                </nav>
            </div>
            <div class="min-w-0">
                <h2 class="text-sm font-semibold">By service</h2>
                <ul class="mt-2 divide-y text-sm">
                    <li
                        v-for="(counts, service) in stats.byService"
                        :key="service"
                        class="flex items-start justify-between gap-4 py-2"
                    >
                        <span>{{ serviceLabel(service) }}</span>
                        <span class="text-right tabular-nums">
                            {{ counts.calls + counts.recalls }} calls
                            <span class="block text-xs text-muted-foreground"
                                >Recalls included: {{ counts.recalls }}</span
                            >
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </details>
</template>
