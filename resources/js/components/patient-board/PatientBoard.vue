<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Clock3, Ellipsis, LoaderCircle, UserRound } from '@lucide/vue';
import { ref } from 'vue';
import WhatsAppLink from '@/components/patient/WhatsAppLink.vue';
import CallReadinessActions from '@/components/queue/CallReadinessActions.vue';
import TreatmentProgressActions from '@/components/queue/TreatmentProgressActions.vue';
import TreatmentProgressBadge from '@/components/queue/TreatmentProgressBadge.vue';
import { Button } from '@/components/ui/button';
import { DisclosureText } from '@/components/ui/disclosure';
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
import { waitingTone, waitingToneDescription } from '@/lib/waiting-time';
import type { WaitingTone } from '@/lib/waiting-time';
import type { PatientBoardRow } from '@/types';

defineProps<{
    rows: PatientBoardRow[];
    branchId?: number;
    from?: 'registration' | 'queue';
    busyKey?: string | null;
    emptyMessage?: string;
}>();

defineEmits<{
    sendToWaiting: [row: PatientBoardRow];
    call: [row: PatientBoardRow];
    recall: [row: PatientBoardRow];
    callTreatment: [row: PatientBoardRow];
    openConsultation: [row: PatientBoardRow];
    dispense: [row: PatientBoardRow];
    callDispensary: [row: PatientBoardRow];
    cancel: [row: PatientBoardRow];
    readinessChanged: [];
}>();

// Q-2: one treatment dialog at a time, opened from the row menu or the inline "Mark done" button.
const treatmentDialogKey = ref<string | null>(null);
const openTreatmentDialog = (key: string) => {
    // Let the menu close and return focus first, so the dialog keeps focus.
    setTimeout(() => (treatmentDialogKey.value = key), 0);
};

// A completed visit opens its read-only history when its row is clicked. Clicks on links,
// buttons and the actions menu keep their own behaviour.
const openHistory = (event: MouseEvent, row: PatientBoardRow) => {
    if (!row.historyUrl) {
        return;
    }

    const target = event.target as HTMLElement | null;

    if (target?.closest('a, button, [role="menuitem"], [role="menu"]')) {
        return;
    }

    router.visit(row.historyUrl);
};

const paymentTooltip = (row: PatientBoardRow) =>
    [row.coverageLabel, row.panelNote].filter(Boolean).join('\n');

const patientHref = (number: string) =>
    `/patients/${encodeURIComponent(number)}`;
const visitHref = (number: string) => `/visits/${encodeURIComponent(number)}`;
const editHref = (number: string) => `${visitHref(number)}/edit`;
// DD-38 (Yasmin): theme tokens, so the chips read in dark mode. Waiting is neutral because the Q-1 time chip
// carries the long-wait amber and red; On Hold is the warning exception; Serving uses the accent tone. The
// quiet states are outline only: muted text on a muted fill measured 4.44:1, outline 4.58:1 light, 7.85:1 dark.
const statusClass = (tone: PatientBoardRow['statusTone']) =>
    ({
        neutral: 'border-border text-muted-foreground',
        waiting: 'border-border bg-muted text-foreground',
        serving: 'border-brand/25 bg-brand/10 text-brand-strong',
        held: 'border-warning/25 bg-warning/10 text-warning-strong',
        cancelled: 'border-border text-muted-foreground',
        removed: 'border-border text-muted-foreground',
    })[tone];
// Q-1: the waiting-time chip turns amber, then red, past the fixed limits.
const durationClass = (tone: WaitingTone) =>
    ({
        normal: 'bg-muted/65 font-normal',
        amber: 'bg-amber-100 font-medium text-amber-900 dark:bg-amber-950/70 dark:text-amber-200',
        red: 'bg-red-100 font-semibold text-red-800 dark:bg-red-950/70 dark:text-red-200',
    })[tone];
const durationIconClass = (tone: WaitingTone) =>
    tone === 'normal' ? 'text-muted-foreground' : 'text-current';
const rowWaitingTone = (row: PatientBoardRow) =>
    waitingTone(row.waitingMinutes);
</script>

<template>
    <div class="patient-board overflow-x-auto">
        <table
            class="w-full table-fixed text-left text-[13px] leading-5 md:min-w-[1040px]"
        >
            <thead
                class="border-y bg-muted/25 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
            >
                <tr>
                    <th class="w-[15%] px-3 py-2">Patient</th>
                    <th class="w-[7%] px-3 py-2">Queue</th>
                    <th class="w-[9%] px-3 py-2">Arrived</th>
                    <th class="w-[12%] px-3 py-2">Visit notes</th>
                    <th class="w-[10%] px-3 py-2">Doctor</th>
                    <th class="w-[18%] px-3 py-2">Payment</th>
                    <th class="w-[8%] px-3 py-2">Duration</th>
                    <th class="w-[15%] px-3 py-2">Status</th>
                    <th class="w-[112px] px-3 py-2 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="row in rows"
                    :key="row.key"
                    class="border-b border-border/60 transition-colors last:border-0 hover:bg-muted/20"
                    :class="row.historyUrl ? 'cursor-pointer' : ''"
                    :data-history-url="row.historyUrl ?? undefined"
                    @click="openHistory($event, row)"
                >
                    <td class="px-3 py-2.5 align-middle" data-label="Patient">
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
                    <td class="px-3 py-2.5 align-middle" data-label="Queue">
                        <span
                            class="inline-flex min-w-9 justify-center rounded-md bg-muted/65 px-2 py-0.5 font-normal text-foreground tabular-nums"
                            >{{ row.queueNumber ?? '—' }}</span
                        >
                    </td>
                    <td
                        class="px-3 py-2.5 align-middle font-normal tabular-nums"
                        data-label="Arrived"
                    >
                        <span class="block truncate" :title="row.arrivedDate">{{
                            row.arrivedDate
                        }}</span>
                        <span
                            class="block text-xs whitespace-nowrap text-muted-foreground"
                            >{{ row.arrivedTime }}</span
                        >
                    </td>
                    <td
                        class="px-3 py-2.5 align-middle"
                        data-label="Visit notes"
                    >
                        <div class="text-xs leading-5">
                            <DisclosureText
                                :text="row.visitNotes"
                                variant="clamp-2"
                                fallback="No reason recorded"
                            />
                        </div>
                    </td>
                    <td class="px-3 py-2.5 align-middle" data-label="Doctor">
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
                    <td class="px-3 py-2.5 align-middle" data-label="Payment">
                        <TooltipProvider :delay-duration="350">
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <span
                                        tabindex="0"
                                        class="block min-h-10 max-w-full rounded-sm focus-visible:ring-2 focus-visible:ring-ring/35 focus-visible:outline-none"
                                        :title="paymentTooltip(row)"
                                        data-testid="board-payment"
                                        @click.stop
                                    >
                                        <span
                                            class="block font-semibold break-words"
                                            data-testid="board-payment-name"
                                            >{{ row.coverageLabel }}</span
                                        >
                                        <span
                                            v-if="row.panelNote"
                                            class="block truncate text-[11px] leading-4 text-muted-foreground"
                                            data-testid="board-panel-note"
                                            >{{ row.panelNote }}</span
                                        >
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent class="max-w-80 break-words">
                                    <span class="block font-semibold">{{
                                        row.coverageLabel
                                    }}</span>
                                    <span
                                        v-if="row.panelNote"
                                        class="block whitespace-pre-wrap"
                                        >{{ row.panelNote }}</span
                                    >
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </td>
                    <td class="px-3 py-2.5 align-middle" data-label="Duration">
                        <span
                            class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 tabular-nums"
                            :class="durationClass(rowWaitingTone(row))"
                            :data-waiting-tone="rowWaitingTone(row)"
                        >
                            <Clock3
                                class="size-3"
                                :class="durationIconClass(rowWaitingTone(row))"
                                aria-hidden="true"
                            />
                            {{ row.durationLabel }}
                            <span
                                v-if="
                                    waitingToneDescription(rowWaitingTone(row))
                                "
                                class="sr-only"
                                >({{
                                    waitingToneDescription(rowWaitingTone(row))
                                }})</span
                            >
                        </span>
                    </td>
                    <td
                        class="px-3 py-2.5 align-middle"
                        data-label="Status"
                        :data-treatment-row="
                            row.treatmentProgress?.state ||
                            row.treatmentProgress?.canManage
                                ? ''
                                : undefined
                        "
                    >
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span
                                class="inline-flex rounded-md border px-2 py-0.5 text-[11px] font-normal"
                                :class="statusClass(row.statusTone)"
                                >{{ row.statusLabel }}</span
                            >
                            <span
                                v-if="row.priority === 'urgent'"
                                class="inline-flex rounded-md border border-destructive/25 bg-destructive/10 px-2 py-0.5 text-[11px] font-normal text-destructive"
                                >Urgent</span
                            >
                            <span
                                v-if="row.returnedFromDispensary"
                                class="inline-flex rounded-md border border-border bg-muted px-2 py-0.5 text-[11px] font-normal text-foreground"
                                >Returned</span
                            >
                            <span
                                v-if="row.completedAt"
                                class="text-[11px] text-muted-foreground"
                                >{{ row.completedAt }}</span
                            >
                            <span
                                v-if="
                                    row.callHold?.active ||
                                    row.callHold?.overridden
                                "
                                class="block w-full rounded-md border border-warning/25 bg-warning/10 p-2 text-xs break-words text-warning-strong"
                                data-testid="call-readiness-badge"
                            >
                                {{
                                    row.callHold.active
                                        ? 'Not ready to call'
                                        : 'Overridden by doctor'
                                }}: {{ row.callHold.reason }}
                            </span>
                            <CallReadinessActions
                                v-if="branchId"
                                :row="row"
                                :branch-id="branchId"
                                :from="from ?? 'queue'"
                                @changed="$emit('readinessChanged')"
                            />
                            <TreatmentProgressBadge
                                :progress="row.treatmentProgress"
                                :minutes="row.treatmentMinutes"
                            />
                            <TreatmentProgressActions
                                v-if="branchId && row.treatmentProgress"
                                :open="treatmentDialogKey === row.key"
                                :progress="row.treatmentProgress"
                                :visit-number="row.visitNumber"
                                :branch-id="branchId"
                                from="queue"
                                inline="running"
                                :subject="`${row.queueNumber ?? row.visitNumber} · ${row.patientName}`"
                                @update:open="
                                    treatmentDialogKey = $event ? row.key : null
                                "
                            />
                        </div>
                    </td>
                    <td
                        class="px-3 py-2 text-right align-middle"
                        data-label="Actions"
                    >
                        <div class="flex min-h-11 items-center justify-end">
                            <WhatsAppLink
                                :can-whatsapp="row.canWhatsapp"
                                :route-key="row.visitNumber"
                                context="visit"
                                compact
                            />
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="min-h-11 min-w-11"
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
                                        <Link
                                            :href="
                                                patientHref(row.patientNumber)
                                            "
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
                                    <DropdownMenuItem
                                        v-if="
                                            branchId &&
                                            row.treatmentProgress?.canManage
                                        "
                                        class="min-h-11 rounded-lg px-2.5 text-[13px]"
                                        data-testid="treatment-progress-menu"
                                        @select="openTreatmentDialog(row.key)"
                                        >Treatment progress…</DropdownMenuItem
                                    >
                                    <DropdownMenuSeparator
                                        v-if="
                                            row.can.sendToWaiting ||
                                            row.can.call ||
                                            row.can.recall ||
                                            row.can.callTreatment ||
                                            row.can.openConsultation ||
                                            row.can.openDispensary ||
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
                                        :disabled="row.callHold?.active"
                                        :title="
                                            row.callHold?.active
                                                ? `Not ready to call: ${row.callHold.reason}`
                                                : undefined
                                        "
                                        class="min-h-11 rounded-lg px-2.5 text-[13px]"
                                        @select="$emit('call', row)"
                                        >{{
                                            row.callHold?.active
                                                ? `Call In — ${row.callHold.reason}`
                                                : 'Call In'
                                        }}</DropdownMenuItem
                                    >
                                    <DropdownMenuItem
                                        v-if="
                                            row.can.call &&
                                            row.callHold?.active &&
                                            row.callHold.canOverride
                                        "
                                        class="min-h-11 whitespace-normal"
                                        @select="$emit('call', row)"
                                        >Override flag and
                                        call</DropdownMenuItem
                                    >
                                    <DropdownMenuItem
                                        v-if="row.can.recall"
                                        class="min-h-8 rounded-lg px-2.5 text-[13px]"
                                        @select="$emit('recall', row)"
                                        >Call again</DropdownMenuItem
                                    >
                                    <!-- prettier-ignore -->
                                    <DropdownMenuItem
                                        v-if="row.can.callTreatment"
                                        class="min-h-8 rounded-lg px-2.5 text-[13px]"
                                        @select="$emit('callTreatment', row)"
                                        >Call to treatment room</DropdownMenuItem
                                    >
                                    <DropdownMenuItem
                                        v-if="row.can.openConsultation"
                                        class="min-h-8 rounded-lg px-2.5 text-[13px]"
                                        @select="$emit('openConsultation', row)"
                                        >Open Consultation</DropdownMenuItem
                                    >
                                    <DropdownMenuItem
                                        v-if="row.can.callDispensary"
                                        class="min-h-8 rounded-lg px-2.5 text-[13px]"
                                        data-action="call-dispensary"
                                        @select="$emit('callDispensary', row)"
                                        >Call to Dispensary</DropdownMenuItem
                                    >
                                    <DropdownMenuItem
                                        v-if="row.can.dispense"
                                        class="min-h-8 rounded-lg px-2.5 text-[13px]"
                                        data-action="dispense"
                                        @select="$emit('dispense', row)"
                                        >Dispense</DropdownMenuItem
                                    >
                                    <DropdownMenuItem
                                        v-if="
                                            row.can.openDispensary &&
                                            row.dispensaryUrl
                                        "
                                        as-child
                                        class="min-h-8 rounded-lg px-2.5 text-[13px]"
                                    >
                                        <Link :href="row.dispensaryUrl"
                                            >Open Dispensary</Link
                                        >
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="row.can.cancel"
                                        variant="destructive"
                                        class="min-h-8 rounded-lg px-2.5 text-[13px]"
                                        @select="$emit('cancel', row)"
                                        >Cancel Visit</DropdownMenuItem
                                    >
                                    <DropdownMenuItem
                                        v-if="row.historyUrl"
                                        as-child
                                        class="min-h-8 rounded-lg px-2.5 text-[13px]"
                                    >
                                        <Link :href="row.historyUrl"
                                            >Invoices</Link
                                        >
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="
                                            row.can.openBilling &&
                                            row.billingUrl
                                        "
                                        as-child
                                        class="min-h-8 rounded-lg px-2.5 text-[13px]"
                                    >
                                        <Link :href="row.billingUrl"
                                            >Open Billing</Link
                                        >
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
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

<style scoped>
@media (width < 768px) {
    .patient-board table,
    .patient-board tbody {
        display: block;
    }
    .patient-board thead {
        display: none;
    }
    .patient-board tbody > tr {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        margin-bottom: 12px;
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 8px;
    }
    .patient-board td {
        display: block;
        min-width: 0;
        padding: 6px;
    }
    .patient-board td::before {
        content: attr(data-label);
        display: block;
        color: var(--muted-foreground);
        font-size: 11px;
    }
    .patient-board td[data-label='Patient'],
    .patient-board td[data-label='Visit notes'],
    .patient-board td[data-label='Payment'] {
        grid-column: 1 / -1;
    }
    /* Q-2: a row with treatment progress gives its Status cell the full card width. */
    .patient-board td[data-label='Status'][data-treatment-row] {
        grid-column: 1 / -1;
    }
}
</style>
