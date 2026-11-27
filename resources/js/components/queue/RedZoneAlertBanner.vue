<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Siren } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { requestJson } from '@/lib/json-client';

// Q-16b (owner decisions 25 Nov 2026): the red-zone alert for resident doctors and triage recorders. It shows only the queue
// number, the minutes waiting and who took it. "I'll take it" assigns the patient to you; it never calls them. Polled from its
// own endpoint (not the board payload), so it also reaches a doctor who is inside a consultation. Minimise is browser state only.
type Alert = {
    visitNumber: string;
    queueNumber: string;
    waitingMinutes: number;
    takenBy: string | null;
    mine: boolean;
    canTake: boolean;
};

const props = defineProps<{ branchId: number; fromVisit?: string }>();
const alerts = ref<Alert[]>([]);
const minimised = ref(false);
const taking = ref<string | null>(null);
let timer: ReturnType<typeof setInterval> | undefined;
let controller: AbortController | undefined;

const open = computed(() => alerts.value.filter((a) => a.takenBy === null));
// A minimised alert stays as one compact line until every red case is taken, and opens itself again for a new red case.
const known = new Set<string>();
watch(alerts, (list) => {
    const fresh = list.filter(
        (a) => a.takenBy === null && !known.has(a.visitNumber),
    );
    list.forEach((a) => known.add(a.visitNumber));

    if (fresh.length > 0) {
        minimised.value = false;
    }
});
const visible = computed(
    () =>
        alerts.value.length > 0 &&
        !(minimised.value && open.value.length === 0),
);
const read = async () => {
    if (document.visibilityState === 'hidden') {
        return;
    }

    controller?.abort();
    controller = new AbortController();

    try {
        const body = await requestJson<{ alerts: Alert[] }>(
            `/queue/red-zone-alerts?expected_branch_id=${props.branchId}`,
            { signal: controller.signal },
        );
        alerts.value = body.alerts;
    } catch {
        // A failed poll keeps the last list; the next poll tries again.
    }
};
const take = (alert: Alert) => {
    if (taking.value) {
        return;
    }

    taking.value = alert.visitNumber;
    router.post(
        `/visits/${alert.visitNumber}/queue/take-red-zone`,
        {
            expected_branch_id: props.branchId,
            ...(props.fromVisit
                ? { from: 'consultation', from_visit: props.fromVisit }
                : {}),
        },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => {
                toast.error(
                    Object.values(errors)[0] ?? 'Could not take this patient.',
                );
            },
            onFinish: () => {
                taking.value = null;
                void read();
            },
        },
    );
};

onMounted(() => {
    void read();
    timer = setInterval(() => void read(), 10_000);
});
onBeforeUnmount(() => {
    clearInterval(timer);
    controller?.abort();
});
</script>

<template>
    <section
        v-if="visible"
        data-testid="red-zone-alerts"
        role="status"
        aria-live="polite"
        aria-label="Red zone alerts"
        class="rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2 text-[13px] text-foreground"
    >
        <div class="flex flex-wrap items-center gap-2">
            <Siren class="size-4 text-destructive" aria-hidden="true" />
            <span class="font-semibold text-destructive">
                Red zone waiting: {{ open.length }} not taken
            </span>
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="ml-auto min-h-11"
                :aria-expanded="!minimised"
                @click="minimised = !minimised"
            >
                {{ minimised ? 'Show alert' : 'Minimise' }}
            </Button>
        </div>
        <ul v-if="!minimised" class="mt-2 flex flex-col gap-2" role="list">
            <li
                v-for="alert in alerts"
                :key="alert.visitNumber"
                class="flex flex-wrap items-center gap-2 rounded-md bg-background/70 px-3 py-2"
                data-testid="red-zone-alert"
            >
                <span class="font-semibold tabular-nums">{{
                    alert.queueNumber
                }}</span>
                <span class="text-muted-foreground">
                    Red zone, waiting
                    {{ alert.waitingMinutes < 1 ? '<1' : alert.waitingMinutes }}
                    min
                </span>
                <span
                    v-if="alert.takenBy"
                    class="ml-auto font-medium text-success"
                    >{{
                        alert.mine
                            ? 'Taken by you'
                            : `Taken by ${alert.takenBy}`
                    }}</span
                >
                <Button
                    v-else-if="alert.canTake"
                    type="button"
                    class="ml-auto min-h-11"
                    :disabled="taking !== null"
                    data-testid="red-zone-take"
                    :aria-label="`I'll take ${alert.queueNumber}`"
                    @click="take(alert)"
                >
                    I'll take it
                </Button>
            </li>
        </ul>
    </section>
</template>
