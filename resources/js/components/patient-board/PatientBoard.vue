<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Clock3, Ellipsis, LoaderCircle, UserRound } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
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
        neutral: 'bg-muted/55 text-muted-foreground',
        waiting: 'bg-amber-100/70 text-amber-800',
        serving: 'bg-emerald-100/65 text-emerald-800',
        cancelled: 'bg-muted/70 text-muted-foreground',
        removed: 'bg-muted/40 text-muted-foreground',
    })[tone];
</script>

<template>
    <div class="overflow-x-auto">
        <table
            class="w-full min-w-[1040px] table-fixed text-left text-[13px] leading-5"
        >
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
                    <td class="px-3 py-2.5 align-middle">
                        <div class="flex min-w-0 items-center gap-2.5">
                            <span
                                class="grid size-8 shrink-0 place-items-center rounded-full bg-muted/75 text-muted-foreground"
                                aria-hidden="true"
                            >
                                <UserRound class="size-3.5" />
                            </span>
                            <div class="min-w-0">
                                <div
                                    class="truncate font-medium text-foreground"
                                >
                                    {{ row.patientName }}
                                </div>
                                <div
                                    class="truncate text-[11px] font-normal text-muted-foreground"
                                >
                                    {{ row.patientNumber }}
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="px-3 py-2.5 align-middle">
                        <span
                            class="inline-flex min-w-9 justify-center rounded-md bg-muted/65 px-2 py-0.5 font-normal text-foreground tabular-nums"
                            >{{ row.queueNumber ?? '—' }}</span
                        >
                    </td>
                    <td
                        class="px-3 py-2.5 align-middle font-normal tabular-nums"
                    >
                        <span class="block whitespace-nowrap">{{
                            row.arrivedDate
                        }}</span>
                        <span
                            class="block text-xs whitespace-nowrap text-muted-foreground"
                            >{{ row.arrivedTime }}</span
                        >
                    </td>
                    <td class="px-3 py-2.5 align-middle">
                        <div
                            class="line-clamp-2 text-xs leading-5 text-muted-foreground"
                        >
                            {{ row.visitNotes ?? 'No reason recorded' }}
                        </div>
                    </td>
                    <td class="px-3 py-2.5 align-middle">
                        <TooltipProvider :delay-duration="350">
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <span
                                        class="flex max-w-full items-center gap-1.5 rounded-md bg-muted/70 px-2 py-0.5 font-normal transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring/35 focus-visible:outline-none"
                                        tabindex="0"
                                    >
                                        <UserRound
                                            class="size-3 shrink-0 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <span class="truncate">{{
                                            row.doctorName ?? 'Unassigned'
                                        }}</span>
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent
                                    side="top"
                                    class="max-w-56 rounded-lg px-2 py-1 text-[11px] shadow-lg"
                                >
                                    {{ row.doctorName ?? 'Unassigned' }}
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </td>
                    <td class="px-3 py-2.5 align-middle">
                        <div class="truncate">{{ row.coverageLabel }}</div>
                    </td>
                    <td class="px-3 py-2.5 align-middle">
                        <span
                            class="inline-flex items-center gap-1.5 rounded-md bg-muted/65 px-2 py-0.5 font-normal tabular-nums"
                        >
                            <Clock3
                                class="size-3 text-muted-foreground"
                                aria-hidden="true"
                            />
                            {{ row.durationLabel }}
                        </span>
                    </td>
                    <td class="px-3 py-2.5 align-middle">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span
                                class="inline-flex rounded-md px-2 py-0.5 text-[11px] font-normal"
                                :class="statusClass(row.statusTone)"
                                >{{ row.statusLabel }}</span
                            >
                            <span
                                v-if="row.priority === 'urgent'"
                                class="inline-flex rounded-md bg-rose-100/70 px-2 py-0.5 text-[11px] font-normal text-rose-700"
                                >Urgent</span
                            >
                        </div>
                    </td>
                    <td class="px-3 py-2 text-right align-middle">
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
                            <DropdownMenuContent
                                align="end"
                                class="w-48 rounded-xl border-border/70 p-1.5 shadow-xl shadow-black/5"
                            >
                                <DropdownMenuItem
                                    as-child
                                    class="min-h-8 rounded-lg px-2.5 text-[13px]"
                                >
                                    <Link :href="visitHref(row.visitNumber)"
                                        >View Visit</Link
                                    >
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-if="row.can.viewPatient"
                                    as-child
                                    class="min-h-8 rounded-lg px-2.5 text-[13px]"
                                >
                                    <Link :href="patientHref(row.patientNumber)"
                                        >View Patient</Link
                                    >
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-if="row.can.update"
                                    as-child
                                    class="min-h-8 rounded-lg px-2.5 text-[13px]"
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
                                    class="min-h-8 rounded-lg px-2.5 text-[13px]"
                                    @select="$emit('sendToWaiting', row)"
                                    >Send to Waiting</DropdownMenuItem
                                >
                                <DropdownMenuItem
                                    v-if="row.can.call"
                                    class="min-h-8 rounded-lg px-2.5 text-[13px]"
                                    @select="$emit('call', row)"
                                    >Call In</DropdownMenuItem
                                >
                                <DropdownMenuItem
                                    v-if="row.can.openConsultation"
                                    class="min-h-8 rounded-lg px-2.5 text-[13px]"
                                    @select="$emit('openConsultation', row)"
                                    >Open Consultation</DropdownMenuItem
                                >
                                <DropdownMenuItem
                                    v-if="row.can.cancel"
                                    class="min-h-8 rounded-lg px-2.5 text-[13px] text-red-700 focus:text-red-700"
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
