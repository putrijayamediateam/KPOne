<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { AlertTriangle, LoaderCircle, PhoneCall, Pin } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import type { QueueRow } from '@/types';

defineProps<{
    rows: QueueRow[];
    callingVisit: string | null;
    waitLabel: (row: QueueRow) => string;
}>();
defineEmits<{ call: [row: QueueRow] }>();
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[980px] text-left text-sm">
            <thead
                class="bg-muted/35 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
            >
                <tr>
                    <th class="px-4 py-2.5">Queue</th>
                    <th class="px-4 py-2.5">Patient</th>
                    <th class="px-4 py-2.5">Doctor</th>
                    <th class="px-4 py-2.5">Reason / coverage</th>
                    <th class="px-4 py-2.5">Waiting</th>
                    <th class="px-4 py-2.5">Priority</th>
                    <th class="px-4 py-2.5">Status</th>
                    <th class="px-4 py-2.5" />
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="row in rows"
                    :key="`${row.operationalDate}-${row.queueNumber}`"
                    class="border-b border-border/40 last:border-0 hover:bg-emerald-50/25"
                >
                    <td class="px-4 py-3 align-top">
                        <div class="flex items-center gap-1.5">
                            <Pin
                                v-if="row.priority === 'urgent'"
                                class="size-4 text-red-600"
                            />
                            <span
                                class="font-mono text-xl font-bold tabular-nums"
                                >{{ row.queueNumber }}</span
                            >
                        </div>
                        <span class="text-[11px] text-muted-foreground">{{
                            row.operationalDate
                        }}</span>
                    </td>
                    <td class="px-4 py-3 align-top">
                        <div class="font-semibold">{{ row.patientName }}</div>
                        <div class="font-mono text-xs text-muted-foreground">
                            {{ row.patientNumber }}
                        </div>
                    </td>
                    <td class="px-4 py-3 align-top">
                        <div>{{ row.doctorName ?? 'Unassigned' }}</div>
                        <div
                            v-if="
                                !row.doctorEligible && row.status === 'waiting'
                            "
                            class="mt-1 flex items-center gap-1 text-xs text-amber-700"
                        >
                            <AlertTriangle class="size-3.5" /> Doctor
                            unavailable
                        </div>
                    </td>
                    <td class="max-w-72 px-4 py-3 align-top">
                        <div class="truncate text-xs">
                            {{ row.visitReasonExcerpt ?? 'No reason recorded' }}
                        </div>
                        <div class="mt-1 text-xs text-muted-foreground">
                            {{ row.coverageLabel }}
                        </div>
                    </td>
                    <td class="px-4 py-3 align-top font-medium tabular-nums">
                        {{ row.status === 'waiting' ? waitLabel(row) : '—' }}
                    </td>
                    <td class="px-4 py-3 align-top">
                        <span
                            :class="
                                row.priority === 'urgent'
                                    ? 'bg-red-50 font-semibold text-red-700'
                                    : 'bg-muted text-muted-foreground'
                            "
                            class="rounded-full px-2 py-0.5 text-xs"
                            >{{
                                row.priority === 'urgent' ? 'Urgent' : 'Normal'
                            }}</span
                        >
                    </td>
                    <td class="px-4 py-3 align-top capitalize">
                        {{ row.status }}
                    </td>
                    <td class="px-4 py-3 text-right align-top">
                        <Button
                            v-if="row.canCall && row.status === 'waiting'"
                            size="sm"
                            :disabled="
                                callingVisit !== null || !row.doctorEligible
                            "
                            @click="$emit('call', row)"
                        >
                            <LoaderCircle
                                v-if="callingVisit === row.visitNumber"
                                class="size-4 animate-spin"
                            />
                            <PhoneCall v-else class="size-4" /> Call In
                        </Button>
                        <Link
                            v-else
                            :href="`/visits/${encodeURIComponent(row.visitNumber)}`"
                            class="text-xs font-medium text-emerald-800 hover:underline"
                            >View Visit</Link
                        >
                    </td>
                </tr>
                <tr v-if="!rows.length">
                    <td
                        colspan="8"
                        class="px-4 py-10 text-center text-sm text-muted-foreground"
                    >
                        No Queue entries in this section.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
