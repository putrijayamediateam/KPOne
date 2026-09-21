<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatDateTime } from '@/lib/presentation';

type QrIntakeItem = {
    publicId: string;
    status: string;
    displayStatus:
        'pending' | 'reviewed' | 'rejected' | 'expired' | 'data_purged';
    submissionType: 'patient' | 'guardian';
    submittedAt: string;
    expiresAt: string;
    lockVersion: number;
    canVerify: boolean;
    visitUrl: string | null;
    summary: {
        name: string;
        age: number | null;
        purpose: string | null;
        complaint: string | null;
        duration: string | null;
        duplicateStatus: 'none' | 'possible';
    } | null;
};

defineProps<{
    branch: { id: number; code: string; name: string };
    items: QrIntakeItem[];
}>();

const purposeLabel: Record<string, string> = {
    doctor_illness: 'Jumpa doktor / sakit',
    pregnancy_check: 'Pemeriksaan kehamilan',
    scan: 'Scan',
    vaccination: 'Vaksin',
    medical_checkup: 'Medical check-up',
    procedure: 'Prosedur',
    other: 'Lain-lain',
};
const statusLabel: Record<QrIntakeItem['displayStatus'], string> = {
    pending: 'Pending',
    reviewed: 'Reviewed',
    rejected: 'Rejected',
    expired: 'Expired',
    data_purged: 'Data Purged',
};
const statusClass: Record<QrIntakeItem['displayStatus'], string> = {
    pending: 'bg-amber-100 text-amber-900',
    reviewed: 'bg-emerald-100 text-emerald-900',
    rejected: 'bg-red-100 text-red-900',
    expired: 'bg-muted text-muted-foreground',
    data_purged: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <section class="min-w-0 overflow-hidden border-y bg-background">
        <div
            class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-xs text-muted-foreground"
        >
            <span>{{ items.length }} QR intake records</span>
            <span
                >{{ branch.name }} · Pending first, earliest arrival first</span
            >
        </div>
        <div class="overflow-x-auto">
            <table
                class="w-full min-w-[820px] table-fixed text-left text-[13px] leading-5"
            >
                <thead
                    class="border-y bg-muted/25 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    <tr>
                        <th class="w-[19%] px-3 py-2">Patient</th>
                        <th class="w-[15%] px-3 py-2">Submitted</th>
                        <th class="w-[16%] px-3 py-2">Purpose</th>
                        <th class="w-[24%] px-3 py-2">Visit Reason</th>
                        <th class="w-[10%] px-3 py-2">Duration</th>
                        <th class="w-[9%] px-3 py-2">Status</th>
                        <th class="w-[7%] px-3 py-2 text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in items"
                        :key="item.publicId"
                        class="border-b border-border/60 last:border-0 hover:bg-muted/20"
                    >
                        <td class="px-3 py-2.5 align-middle">
                            <div v-if="item.summary" class="min-w-0">
                                <div class="truncate font-medium">
                                    {{ item.summary.name }}
                                </div>
                                <div class="text-[11px] text-muted-foreground">
                                    {{ item.summary.age ?? '—' }} years ·
                                    {{
                                        item.submissionType === 'guardian'
                                            ? 'Guardian'
                                            : 'Patient'
                                    }}
                                </div>
                            </div>
                            <span v-else class="text-muted-foreground"
                                >Redacted after retention period</span
                            >
                        </td>
                        <td class="px-3 py-2.5 align-middle tabular-nums">
                            {{ formatDateTime(item.submittedAt) }}
                        </td>
                        <td class="px-3 py-2.5 align-middle">
                            {{
                                item.summary
                                    ? (purposeLabel[
                                          item.summary.purpose ?? ''
                                      ] ?? 'Not stated')
                                    : 'â€”'
                            }}
                        </td>
                        <td class="px-3 py-2.5 align-middle">
                            <template v-if="item.summary?.complaint">
                                <TooltipProvider :delay-duration="300">
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <button
                                                type="button"
                                                class="hidden w-full truncate rounded text-left text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none md:block"
                                            >
                                                {{ item.summary.complaint }}
                                            </button>
                                        </TooltipTrigger>
                                        <TooltipContent
                                            side="top"
                                            class="max-w-sm break-words whitespace-normal"
                                        >
                                            {{ item.summary.complaint }}
                                        </TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                                <details class="group md:hidden">
                                    <summary
                                        class="flex cursor-pointer list-none items-center gap-1 rounded focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    >
                                        <span
                                            class="min-w-0 flex-1 truncate text-muted-foreground"
                                            >{{ item.summary.complaint }}</span
                                        >
                                        <ChevronDown
                                            class="size-3.5 shrink-0 group-open:rotate-180"
                                        />
                                    </summary>
                                    <p
                                        class="mt-1 max-w-full break-words whitespace-normal"
                                    >
                                        {{ item.summary.complaint }}
                                    </p>
                                </details>
                            </template>
                            <span v-else class="text-muted-foreground"
                                >â€”</span
                            >
                        </td>
                        <td class="px-3 py-2.5 align-middle">
                            {{ item.summary?.duration ?? 'â€”' }}
                        </td>
                        <td class="px-3 py-2.5 align-middle">
                            <span
                                class="inline-flex rounded-md px-2 py-0.5 text-[11px] font-medium"
                                :class="statusClass[item.displayStatus]"
                                >{{ statusLabel[item.displayStatus] }}</span
                            >
                        </td>
                        <td class="px-3 py-2.5 text-right align-middle">
                            <Button
                                v-if="item.canVerify"
                                as-child
                                size="sm"
                                class="h-8"
                            >
                                <Link
                                    :href="`/registration-review/${item.publicId}`"
                                    >Verify</Link
                                >
                            </Button>
                            <Button
                                v-else-if="item.visitUrl"
                                as-child
                                size="sm"
                                variant="outline"
                                class="h-8"
                            >
                                <Link :href="item.visitUrl">View Visit</Link>
                            </Button>
                            <span v-else class="text-muted-foreground"
                                >â€”</span
                            >
                        </td>
                    </tr>
                    <tr v-if="items.length === 0">
                        <td
                            colspan="7"
                            class="px-4 py-12 text-center text-sm text-muted-foreground"
                        >
                            No QR intake records are available for this branch.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
