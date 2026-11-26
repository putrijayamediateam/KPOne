<script setup lang="ts">
import type { QueueCallStats } from '@/lib/queue-call-stats';

defineProps<{ branchName: string; stats: QueueCallStats }>();

const serviceLabels: Record<string, string> = {
    consultation: 'Consultation',
    dispensary: 'Dispensary',
    treatment: 'Treatment',
};
const serviceLabel = (service: string) =>
    serviceLabels[service] ?? 'Other service';
</script>

<template>
    <details class="rounded-xl border bg-card p-4">
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
        <p v-if="stats.total === 0" class="mt-4 text-sm text-muted-foreground">
            No calls recorded today at {{ branchName }}.
        </p>
        <div v-else class="mt-4 grid gap-6 md:grid-cols-2">
            <div class="min-w-0">
                <h2 class="text-sm font-semibold">By room</h2>
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
