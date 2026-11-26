<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import RedZoneAlertBanner from '@/components/queue/RedZoneAlertBanner.vue';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { useEmergencyAlerts } from '@/composables/useEmergencyAlerts';
import type { EmergencyAlert } from '@/composables/useEmergencyAlerts';
import { emergencyHoldBridge } from '@/composables/useEmergencyHoldBridge';
const page = usePage();
const context = computed(() => {
    const user = page.props.auth?.user;
    const branch = page.props.branchContext?.active;
    const permissions = page.props.auth?.permissions ?? [];
    const canRespond = permissions.includes('queue.emergency.respond.branch');

    return user &&
        branch &&
        (canRespond || permissions.includes('visits.triage.record.branch'))
        ? { userId: user.id, branchId: branch.id, canRespond }
        : null;
});
const bridge = computed(() =>
    emergencyHoldBridge.value?.branchId === context.value?.branchId
        ? emergencyHoldBridge.value
        : null,
);
const state = useEmergencyAlerts(context, () => bridge.value?.visitNumber);
const { alerts, presence, paused, busy, uncertain, error, soundReady, muted } =
    state;
const holdTarget = ref<EmergencyAlert | null>(null);
const holding = ref(false);
let continuation = 0;
watch(
    () =>
        `${context.value?.userId}:${context.value?.branchId}:${context.value?.canRespond}`,
    () => {
        continuation++;
        bridge.value?.cancel();
        holdTarget.value = null;
        holding.value = false;
    },
);
watch(
    () => [bridge.value, bridge.value?.visitNumber] as const,
    ([next, nextVisit], [previous, previousVisit]) => {
        if (next !== previous || nextVisit !== previousVisit) {
            continuation++;
            previous?.cancel();
            holding.value = false;
            holdTarget.value = null;
        }
    },
);
const reload = () => window.location.reload();
const confirmHold = () => {
    const target = holdTarget.value;
    const current = bridge.value;

    if (!target || !current || holding.value) {
        return;
    }

    const token = ++continuation;
    holding.value = true;
    holdTarget.value = null;
    current.request(
        () => {
            if (
                token !== continuation ||
                current.branchId !== context.value?.branchId
            ) {
                return;
            }

            holding.value = false;
            state.command('take', target);
        },
        (message) => {
            if (token !== continuation) {
                return;
            }

            holding.value = false;
            error.value = message;
        },
    );
};
</script>
<template>
    <div
        v-if="context"
        class="sticky top-0 z-30 w-full shrink-0"
        data-testid="emergency-alert-host"
    >
        <RedZoneAlertBanner
            :alerts="alerts"
            :presence="presence"
            :paused="paused"
            :busy="busy || holding"
            :uncertain="uncertain"
            :error="error"
            :can-respond="context.canRespond"
            :sound-ready="soundReady"
            :muted="muted"
            :can-hold="!!bridge?.canHold()"
            :held="!!bridge?.isHeld()"
            @take="state.command('take', $event)"
            @seen="state.command('seen', $event)"
            @hold-take="holdTarget = $event"
            @sound="state.enableSound"
            @mute="state.mute"
            @retry="state.retry"
            @reload="reload"
        />
        <OperationalConfirmDialog
            :open="holdTarget !== null"
            title="Hold your current patient, then take this emergency?"
            description="Your current patient stays on hold if taking the emergency fails. Any unsaved work is handled by the existing consultation choices. This does not call either patient."
            confirm-label="Continue to Hold"
            :processing="holding"
            @update:open="!$event && (holdTarget = null)"
            @confirm="confirmHold"
        />
    </div>
</template>
