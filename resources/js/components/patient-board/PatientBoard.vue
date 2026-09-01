<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Ellipsis, LoaderCircle } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { PatientBoardRow } from '@/types';

defineProps<{
    rows: PatientBoardRow[];
    busyKey?: string | null;
    emptyMessage?: string;
}>();

defineEmits<{
    sendToWaiting: [row: PatientBoardRow];
    call: [row: PatientBoardRow];
    openConsultation: [row: PatientBoardRow];
    cancel: [row: PatientBoardRow];
}>();

const patientHref = (number: string) =>
    `/patients/${encodeURIComponent(number)}`;
const visitHref = (number: string) => `/visits/${encodeURIComponent(number)}`;
const editHref = (number: string) => `${visitHref(number)}/edit`;
const statusClass = (tone: PatientBoardRow['statusTone']) =>
    ({
        neutral: 'border-border bg-muted/40 text-muted-foreground',
        waiting: 'border-amber-200 bg-amber-50 text-amber-800',
        serving: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        cancelled: 'border-border bg-muted text-muted-foreground',
        removed: 'border-border bg-background text-muted-foreground',
    })[tone];
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[1040px] table-fixed text-left text-sm">
            <thead
                class="border-y bg-muted/25 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
            >
                <tr>
                    <th class="w-[18%] px-3 py-2">Patient</th>
                    <th class="w-[8%] px-3 py-2">Queue</th>
                    <th class="w-[8%] px-3 py-2">Arrived</th>
                    <th class="w-[19%] px-3 py-2">Visit notes</th>
                    <th class="w-[13%] px-3 py-2">Doctor</th>
                    <th class="w-[11%] px-3 py-2">Coverage</th>
                    <th class="w-[8%] px-3 py-2">Duration</th>
                    <th class="w-[10%] px-3 py-2">Status</th>
                    <th class="w-[5%] px-3 py-2 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="row in rows"
                    :key="row.key"
                    class="border-b border-border/60 transition-colors last:border-0 hover:bg-muted/20"
                >
                    <td class="px-3 py-2.5 align-top">
                        <div class="truncate font-medium">
                            {{ row.patientName }}
                        </div>
                        <div
                            class="truncate font-mono text-[11px] text-muted-foreground"
                        >
                            {{ row.patientNumber }}
                        </div>
                    </td>
                    <td
                        class="px-3 py-2.5 align-top font-mono font-semibold tabular-nums"
                    >
                        {{ row.queueNumber ?? '—' }}
                    </td>
                    <td class="px-3 py-2.5 align-top tabular-nums">
                        {{ row.arrivedAt }}
                    </td>
                    <td class="px-3 py-2.5 align-top">
                        <div
                            class="line-clamp-2 text-xs leading-5 text-muted-foreground"
                        >
                            {{ row.visitNotes ?? 'No reason recorded' }}
                        </div>
                    </td>
                    <td class="px-3 py-2.5 align-top">
                        <div class="truncate">
                            {{ row.doctorName ?? 'Unassigned' }}
                        </div>
                    </td>
                    <td class="px-3 py-2.5 align-top">
                        <div class="truncate">{{ row.coverageLabel }}</div>
                    </td>
                    <td class="px-3 py-2.5 align-top font-medium tabular-nums">
                        {{ row.durationLabel }}
                    </td>
                    <td class="px-3 py-2.5 align-top">
                        <span
                            class="inline-flex rounded-full border px-2 py-0.5 text-[11px] font-medium"
                            :class="statusClass(row.statusTone)"
                            >{{ row.statusLabel }}</span
                        >
                        <span
                            v-if="row.priority === 'urgent'"
                            class="mt-1 block text-[11px] font-semibold text-red-700"
                            >Urgent</span
                        >
                    </td>
                    <td class="px-3 py-2 text-right align-top">
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Actions for ${row.patientName}`"
                                >
                                    <LoaderCircle
                                        v-if="busyKey === row.key"
                                        class="size-4 animate-spin"
                                    />
                                    <Ellipsis v-else class="size-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-48">
                                <DropdownMenuItem as-child>
                                    <Link :href="visitHref(row.visitNumber)"
                                        >View Visit</Link
                                    >
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-if="row.can.viewPatient"
                                    as-child
                                >
                                    <Link :href="patientHref(row.patientNumber)"
                                        >View Patient</Link
                                    >
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-if="row.can.update"
                                    as-child
                                >
                                    <Link :href="editHref(row.visitNumber)"
                                        >Edit Visit</Link
                                    >
                                </DropdownMenuItem>
                                <DropdownMenuSeparator
                                    v-if="
                                        row.can.sendToWaiting ||
                                        row.can.call ||
                                        row.can.openConsultation ||
                                        row.can.cancel
                                    "
                                />
                                <DropdownMenuItem
                                    v-if="row.can.sendToWaiting"
                                    @select="$emit('sendToWaiting', row)"
                                    >Send to Waiting</DropdownMenuItem
                                >
                                <DropdownMenuItem
                                    v-if="row.can.call"
                                    @select="$emit('call', row)"
                                    >Call In</DropdownMenuItem
                                >
                                <DropdownMenuItem
                                    v-if="row.can.openConsultation"
                                    @select="$emit('openConsultation', row)"
                                    >Open Consultation</DropdownMenuItem
                                >
                                <DropdownMenuItem
                                    v-if="row.can.cancel"
                                    class="text-red-700 focus:text-red-700"
                                    @select="$emit('cancel', row)"
                                    >Cancel Visit</DropdownMenuItem
                                >
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </td>
                </tr>
                <tr v-if="!rows.length">
                    <td
                        colspan="9"
                        class="px-4 py-12 text-center text-sm text-muted-foreground"
                    >
                        {{
                            emptyMessage ??
                            'No Patients match this operational view.'
                        }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
