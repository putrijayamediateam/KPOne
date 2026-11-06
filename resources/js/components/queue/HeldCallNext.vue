<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import CallNextControl from '@/components/queue/CallNextControl.vue';
import { requestJson } from '@/lib/json-client';
import { createQueuePollingLifecycle } from '@/lib/queue-polling';
import type { CallNextBlock } from '@/types';

// Q-4b (owner decisions 2026-11-06): Call next on a held consultation, for the doctor's own queue only. The block
// comes from the same server check as the queue board; a successful call goes to the queue board, and a refusal
// returns here so the dialog can show the latest patient.
const props = defineProps<{
    branchId: number;
    /** The held consultation's visit, the only page a refusal may return to. */
    visitNumber: string;
    /** Another consultation action is running. */
    busy: boolean;
}>();
const emit = defineEmits<{
    processing: [busy: boolean];
    /** True only when Call next can be pressed now, so the notice never offers it while it is unavailable. */
    available: [available: boolean];
}>();

const POLL_MS = 10_000;
const block = ref<CallNextBlock | null>(null);
const lifecycle = createQueuePollingLifecycle();
let controller: AbortController | null = null;
let timer: ReturnType<typeof setTimeout> | undefined;

const stop = () => {
    lifecycle.invalidate();
    controller?.abort();
    clearTimeout(timer);
};
const schedule = () => {
    clearTimeout(timer);

    if (lifecycle.canRun() && !document.hidden) {
        timer = setTimeout(() => void load().catch(() => undefined), POLL_MS);
    }
};
let latest: Promise<void> | null = null;
/**
 * Reloads the block; rejects when it could not be read, so the dialog can say so. A reload replaced by a newer
 * one (the 10 s poll, the tab coming back) waits for that newer one instead of failing.
 */
const load = (): Promise<void> => {
    const run = fetchBlock();
    latest = run;

    return run;
};
const fetchBlock = async (): Promise<void> => {
    stop();

    if (!lifecycle.canRun()) {
        return;
    }

    const generation = lifecycle.currentGeneration();
    const branchId = props.branchId;
    controller = new AbortController();

    try {
        const params = new URLSearchParams({
            expected_branch_id: String(branchId),
        });
        const result = await requestJson<{ callNext: CallNextBlock | null }>(
            `/queue/call-next?${params}`,
            { signal: controller.signal },
        );

        if (lifecycle.accepts(generation) && props.branchId === branchId) {
            block.value = result.callNext;
            emit(
                'available',
                result.callNext?.state === 'ready' &&
                    result.callNext.candidate !== null,
            );
            // The dialog reads the new block right after this resolves.
            await nextTick();
        }
    } catch (error) {
        if (!lifecycle.accepts(generation)) {
            const newer = latest;

            if (lifecycle.canRun() && newer !== null) {
                return newer;
            }

            return;
        }

        // A failed read never keeps offering Call next on an old answer.
        emit('available', false);

        throw error;
    } finally {
        if (lifecycle.accepts(generation)) {
            schedule();
        }
    }
};
const visibilityChanged = () => {
    if (document.hidden) {
        stop();
    } else {
        void load().catch(() => undefined);
    }
};

onMounted(() => {
    lifecycle.mount();
    document.addEventListener('visibilitychange', visibilityChanged);
    void load().catch(() => undefined);
});
onBeforeUnmount(() => {
    emit('available', false);
    document.removeEventListener('visibilitychange', visibilityChanged);
    stop();
    lifecycle.dispose();
});
</script>

<template>
    <CallNextControl
        v-if="block"
        :block="block"
        :branch-id="branchId"
        :busy="busy"
        :refresh="load"
        :from-visit="visitNumber"
        @processing="emit('processing', $event)"
    />
</template>
