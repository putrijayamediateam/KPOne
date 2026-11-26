<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import TreatmentRoomRecordList from '@/components/treatment-room/TreatmentRoomRecordList.vue';
import { Button } from '@/components/ui/button';
import { requestJson } from '@/lib/json-client';
import type { TreatmentRoomRecords } from '@/types/treatment-room';

const props = defineProps<{ url: string }>();
const timezone = ref('UTC');
const headingId = useId();
const panel = ref<HTMLElement | null>(null);
const records = ref<TreatmentRoomRecords | null>(null);
const loading = ref(true);
const error = ref('');
const currentPage = ref(1);
const historyPage = ref(1);
let request: AbortController | null = null;
const load = async () => {
    request?.abort();
    const current = new AbortController();
    request = current;
    error.value = '';
    loading.value = true;

    try {
        const response = await requestJson<{
            data: TreatmentRoomRecords;
            timezone: string;
        }>(
            `${props.url}${props.url.includes('?') ? '&' : '?'}current_page=${currentPage.value}&history_page=${historyPage.value}`,
            { signal: current.signal },
        );

        if (!current.signal.aborted) {
            timezone.value = response.timezone;
            records.value = response.data;
            currentPage.value = response.data.currentPagination.currentPage;
            historyPage.value = response.data.historyPagination.currentPage;
        }
    } catch {
        if (!current.signal.aborted) {
            error.value =
                'Treatment-room records could not be loaded. Reload to check your current access.';
        }
    } finally {
        if (!current.signal.aborted) {
            loading.value = false;
        }
    }
};
onMounted(load);
watch(
    () => props.url,
    () => {
        records.value = null;
        currentPage.value = 1;
        historyPage.value = 1;
        void load();
    },
);
const changePage = (kind: 'current' | 'history', page: number) => {
    if (loading.value) {
        return;
    }

    panel.value?.focus({ preventScroll: true });

    if (kind === 'current') {
        currentPage.value = page;
    } else {
        historyPage.value = page;
    }

    void load();
};
onBeforeUnmount(() => request?.abort());
</script>

<template>
    <section
        ref="panel"
        tabindex="-1"
        class="treatment-room-read space-y-3 rounded-lg border border-border bg-card p-4 shadow-card"
        :aria-labelledby="headingId"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 :id="headingId" class="font-semibold">Treatment room</h2>
            <span class="text-[12.5px] text-muted-foreground">Read only</span>
        </div>
        <p v-if="loading" class="text-sm text-muted-foreground" role="status">
            Loading treatment-room records…
        </p>
        <div v-else-if="error" class="space-y-2">
            <p class="text-sm text-warning-strong" role="alert">{{ error }}</p>
            <Button variant="outline" class="h-9 max-lg:h-11" @click="load"
                >Reload treatment records</Button
            >
        </div>
        <TreatmentRoomRecordList
            v-if="records && !error"
            :records="records"
            :timezone="timezone"
            :busy="loading"
            @current-page="changePage('current', $event)"
            @history-page="changePage('history', $event)"
        />
    </section>
</template>

<style scoped>
.treatment-room-read :deep(button[class]) {
    min-height: 44px;
    height: 44px;
    font-size: 14px;
}
@media (min-width: 1024px) {
    .treatment-room-read :deep(button[class]) {
        min-height: 36px;
        height: 36px;
    }
}
</style>
