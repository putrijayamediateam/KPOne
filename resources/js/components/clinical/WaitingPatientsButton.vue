<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown, UsersRound } from '@lucide/vue';
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { OperationalTabs } from '@/components/ui/tabs';
import { useCallReadinessAlerts } from '@/composables/useCallReadinessAlerts';
import { requestJson } from '@/lib/json-client';
import { createQueuePollingLifecycle } from '@/lib/queue-polling';

const props = defineProps<{ branchId: number; canViewBranch: boolean }>();
type Counts = {
    branchId: number;
    own: number;
    branch: number | null;
    notReady: number;
};
const readyAlerts = useCallReadinessAlerts(false);
const open = ref(false);
const selected = ref('own');
const counts = ref<Counts | null>(null);
const error = ref('');
const loading = ref(false);
const lifecycle = createQueuePollingLifecycle();
let controller: AbortController | null = null;
let timer: ReturnType<typeof setTimeout> | undefined;
const total = computed(() =>
    selected.value === 'branch' && props.canViewBranch
        ? counts.value?.branch
        : counts.value?.own,
);
const tabs = computed(() => [
    { value: 'own', label: 'Your patient' },
    { value: 'branch', label: 'All in clinic', disabled: !props.canViewBranch },
]);
const cancel = () => {
    lifecycle.invalidate();
    controller?.abort();
    clearTimeout(timer);
    loading.value = false;
};
const refresh = async () => {
    cancel();

    if (!lifecycle.canRun() || document.hidden) {
        return;
    }

    const generation = lifecycle.currentGeneration();
    const branchId = props.branchId;
    controller = new AbortController();
    loading.value = true;
    error.value = '';

    try {
        const params = new URLSearchParams({
            expected_branch_id: String(branchId),
            include_readiness: '1',
            scope: selected.value,
        });
        // Sample clear events first: a later clear must not explain an earlier cancellation drop.
        const readiness = await readyAlerts
            .read(branchId, controller.signal)
            .catch(() => null);
        const result = await requestJson<Counts>(
            `/queue/waiting-counts?${params}`,
            { signal: controller.signal },
        );

        if (!lifecycle.accepts(generation)) {
            return;
        }

        if (result.branchId !== branchId || props.branchId !== branchId) {
            throw new Error('Branch changed');
        }

        const previous = counts.value?.notReady;
        counts.value = result;
        // A clear-event signal is required: cancellation and Call In never trigger readiness.
        readyAlerts.announce(
            readiness,
            previous !== undefined && result.notReady < previous,
        );
    } catch {
        if (lifecycle.accepts(generation)) {
            counts.value = null;
            error.value = 'Waiting counts unavailable. Try again.';
        }
    } finally {
        if (lifecycle.accepts(generation)) {
            loading.value = false;

            if (!document.hidden) {
                timer = setTimeout(() => void refresh(), 15_000);
            }
        }
    }
};
const visibilityChanged = () => {
    if (document.hidden) {
        cancel();
    } else {
        void refresh();
    }
};
watch(selected, () => {
    counts.value = null;
    readyAlerts.reset();
    void refresh();
});
watch(open, (value) => {
    if (value) {
        void refresh();
    }
});
watch(
    () => [props.branchId, props.canViewBranch],
    () => {
        counts.value = null;
        readyAlerts.reset();
        selected.value = 'own';
        open.value = false;
        void refresh();
    },
);
onMounted(() => {
    lifecycle.mount();
    document.addEventListener('visibilitychange', visibilityChanged);
    void refresh();
});
onBeforeUnmount(() => {
    cancel();
    lifecycle.dispose();
    document.removeEventListener('visibilitychange', visibilityChanged);
});
</script>

<template>
    <PopoverRoot v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                type="button"
                size="sm"
                variant="outline"
                data-testid="patients-waiting"
                class="min-h-11"
            >
                <UsersRound class="size-3.5" />
                {{
                    total == null
                        ? 'Patients waiting'
                        : `${total} patients waiting`
                }}
                <ChevronDown class="size-3.5" />
            </Button>
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                align="end"
                :side-offset="6"
                class="consultation-waiting-counts z-50 w-72 max-w-[calc(100vw-24px)] rounded-lg border bg-popover p-3 text-xs text-popover-foreground shadow-sm"
                aria-label="Patients waiting"
                data-testid="waiting-counts-dropdown"
            >
                <OperationalTabs
                    v-model="selected"
                    :tabs="tabs"
                    label="Waiting patient scope"
                />
                <div
                    role="tabpanel"
                    class="py-4"
                    :aria-label="
                        selected === 'own' ? 'Your patient' : 'All in clinic'
                    "
                    :aria-busy="loading"
                >
                    <p v-if="error" role="status" class="text-muted-foreground">
                        {{ error }}
                    </p>
                    <p v-else role="status">
                        {{
                            total == null
                                ? 'Loading waiting count…'
                                : `${total} waiting, ${counts?.notReady ?? 0} not ready to be called`
                        }}
                    </p>
                    <p
                        v-if="!canViewBranch"
                        class="mt-2 text-[11px] text-muted-foreground"
                    >
                        Your assigned consultation queue only.
                    </p>
                </div>
                <Button
                    v-if="error"
                    type="button"
                    variant="outline"
                    class="h-[30px] text-xs"
                    @click="refresh"
                    >Retry</Button
                >
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11 w-full"
                    :aria-pressed="readyAlerts.chimeEnabled.value"
                    @click="readyAlerts.toggleChime"
                >
                    Notification sound:
                    {{ readyAlerts.chimeEnabled.value ? 'On' : 'Off' }}
                </Button>
                <Link
                    href="/queue"
                    class="mt-1 block border-t pt-3 text-xs font-medium hover:underline"
                    @click="open = false"
                    >View all waiting patients</Link
                >
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>

<style>
.consultation-waiting-counts [role='tab'] {
    height: 30px;
    padding-inline: 8px;
    font-size: 12px;
}
</style>
