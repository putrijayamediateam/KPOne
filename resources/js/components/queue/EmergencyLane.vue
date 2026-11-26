<script setup lang="ts">
import { Siren } from '@lucide/vue';
import { useId } from 'vue';
import { Button } from '@/components/ui/button';
defineProps<{
    total: number;
    currentPage: number;
    lastPage: number;
    busy: boolean;
}>();
defineEmits<{ page: [page: number] }>();
const headingId = useId();
</script>
<template>
    <section
        :aria-labelledby="headingId"
        class="overflow-hidden rounded-lg border-2 border-destructive bg-card shadow-card"
        data-testid="emergency-lane"
    >
        <header class="space-y-1 border-b px-4 py-3">
            <h2
                :id="headingId"
                class="flex items-center gap-2 text-base font-semibold text-destructive"
            >
                <Siren class="size-5" aria-hidden="true" />Emergency
                <span class="text-sm tabular-nums">{{ total }}</span>
            </h2>
            <p class="text-[12.5px] text-muted-foreground">
                Waiting emergencies, including previous days. Separate from the
                ordinary board filters. Use the usual Call In action to call the
                patient.
            </p>
        </header>
        <slot />
        <nav
            v-if="lastPage > 1"
            aria-label="Emergency lane pages"
            class="flex flex-wrap items-center justify-end gap-2 border-t px-4 py-3"
        >
            <span class="mr-auto text-[12.5px] text-muted-foreground"
                >Emergency page {{ currentPage }} of {{ lastPage }} ·
                {{ total }} total</span
            >
            <Button
                variant="outline"
                class="min-h-9 max-lg:min-h-11"
                :disabled="busy || currentPage <= 1"
                @click="$emit('page', currentPage - 1)"
                >Previous emergencies</Button
            >
            <Button
                variant="outline"
                class="min-h-9 max-lg:min-h-11"
                :disabled="busy || currentPage >= lastPage"
                @click="$emit('page', currentPage + 1)"
                >Next emergencies</Button
            >
        </nav>
    </section>
</template>

<style scoped>
@media (width >= 768px) {
    /* The legacy nine-column board needs extra duration room; focused Queue columns size themselves. */
    section :deep(.patient-board:not([data-queue-layout]) th:nth-child(6)) {
        width: 7%;
    }
    section :deep(.patient-board:not([data-queue-layout]) th:nth-child(7)) {
        width: 19%;
    }
}
</style>
