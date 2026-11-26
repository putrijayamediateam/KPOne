<script setup lang="ts">
import { Siren } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import type { EmergencyAlert } from '@/composables/useEmergencyAlerts';
import { formatDateTime } from '@/lib/presentation';
defineProps<{
    alerts: EmergencyAlert[];
    paused: boolean;
    busy: boolean;
    uncertain: boolean;
    error: string;
    canRespond: boolean;
    soundReady: boolean;
    muted: boolean;
    canHold: boolean;
    held: boolean;
    presence: { available: boolean; doctorCount: number | null } | null;
}>();
defineEmits<{
    take: [alert: EmergencyAlert];
    seen: [alert: EmergencyAlert];
    holdTake: [alert: EmergencyAlert];
    sound: [];
    mute: [];
    retry: [];
    reload: [];
}>();
</script>
<template>
    <section
        v-if="alerts.length || paused || busy || uncertain || error"
        data-testid="red-zone-alerts"
        aria-label="Emergency alerts"
        class="max-h-[40dvh] overflow-y-auto border-2 border-destructive bg-card px-4 py-3 text-sm text-foreground"
    >
        <div class="flex flex-wrap items-center gap-2">
            <Siren class="size-5 text-destructive" aria-hidden="true" />
            <h2 class="font-semibold text-destructive">Emergency</h2>
            <span
                v-if="alerts.some((row) => row.takenBy === null)"
                role="alert"
                class="sr-only"
                >{{
                    alerts
                        .filter((row) => row.takenBy === null)
                        .map((row) => row.queueNumber)
                        .join(', ')
                }}
                waiting for a doctor.</span
            >
            <template
                v-if="canRespond && alerts.some((row) => row.takenBy === null)"
            >
                <Button
                    v-if="!soundReady"
                    variant="outline"
                    class="h-auto min-h-9 whitespace-normal max-lg:min-h-11"
                    @click="$emit('sound')"
                    >Sound is off on this device. Tap to turn on</Button
                >
                <Button
                    v-else
                    variant="outline"
                    class="h-auto min-h-9 whitespace-normal max-lg:min-h-11"
                    :disabled="muted"
                    @click="$emit('mute')"
                    >{{
                        muted
                            ? 'Sound muted for up to 2 minutes'
                            : 'Mute sound for 2 minutes'
                    }}</Button
                >
            </template>
        </div>
        <p class="mt-1 text-muted-foreground">
            Also tell a doctor in person. This alert can be missed.
        </p>
        <p
            v-if="paused"
            role="status"
            class="mt-2 font-medium text-warning-strong"
        >
            Live alerts paused. Reload this page. Last known alerts remain
            below.
            <Button variant="outline" @click="$emit('reload')">Reload</Button>
        </p>
        <p
            v-if="presence?.available && presence.doctorCount === 0"
            class="mt-2 font-medium text-warning-strong"
        >
            No doctor has checked for alerts at this branch in the last 30
            seconds.
        </p>
        <p
            v-else-if="presence && !presence.available"
            class="mt-2 text-muted-foreground"
        >
            Doctor presence is unavailable. Tell a doctor in person.
        </p>
        <p v-if="error" role="status" class="mt-2 text-destructive">
            {{ error }}
            <span v-if="held"
                >Your current patient remains on hold. Use the existing Resume
                action when appropriate.</span
            >
        </p>
        <div v-if="uncertain" class="mt-2 flex flex-wrap gap-2">
            <Button :disabled="busy" @click="$emit('retry')"
                >Retry unchanged</Button
            ><Button variant="outline" :disabled="busy" @click="$emit('reload')"
                >Reload and check</Button
            >
        </div>
        <ul class="mt-3 space-y-3">
            <li
                v-for="alert in alerts"
                :key="alert.episodeId ?? alert.visitNumber"
                class="flex flex-wrap items-center gap-2 border-t pt-3"
                data-testid="red-zone-alert"
            >
                <strong class="tabular-nums">{{ alert.queueNumber }}</strong>
                <span
                    >Waiting
                    {{ alert.waitingMinutes < 1 ? '<1' : alert.waitingMinutes }}
                    min</span
                >
                <span v-if="alert.alertStartedAt" class="text-muted-foreground"
                    >{{
                        alert.alertOrigin === 'activation'
                            ? 'Alert observed at'
                            : 'Alert recorded at'
                    }}
                    {{
                        formatDateTime(
                            alert.alertStartedAt,
                            'Asia/Kuala_Lumpur',
                        )
                    }}</span
                >
                <span v-if="alert.takenBy"
                    >Taken by {{ alert.mine ? 'you' : alert.takenBy }}</span
                >
                <template v-if="canRespond && alert.canTake">
                    <Button
                        variant="outline"
                        class="h-auto min-h-9 whitespace-normal max-lg:min-h-11"
                        :disabled="busy || uncertain"
                        :aria-label="`I'll see ${alert.queueNumber}`"
                        @click="$emit('take', alert)"
                        >I'll see this patient</Button
                    >
                    <Button
                        v-if="!alert.legacy"
                        variant="outline"
                        class="h-auto min-h-9 whitespace-normal max-lg:min-h-11"
                        :disabled="busy || uncertain || !!alert.seenByMe"
                        @click="$emit('seen', alert)"
                        >{{
                            alert.seenByMe
                                ? 'Seen — alert continues'
                                : 'Seen, not taking'
                        }}</Button
                    >
                    <Button
                        v-if="canHold"
                        variant="outline"
                        class="h-auto min-h-9 whitespace-normal max-lg:min-h-11"
                        :disabled="busy || uncertain"
                        @click="$emit('holdTake', alert)"
                        >Hold my current patient, then take</Button
                    >
                </template>
            </li>
        </ul>
    </section>
</template>
