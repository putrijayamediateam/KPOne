<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { DisclosureText } from '@/components/ui/disclosure';
import { CompactPagination } from '@/components/ui/pagination';
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
    } | null;
};

const props = defineProps<{
    branch: { id: number; code: string; name: string };
    items: QrIntakeItem[];
    history: { total: number; currentPage: number; lastPage: number };
}>();

defineEmits<{
    'history-page-change': [page: number];
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
const purpose = (item: QrIntakeItem) =>
    item.summary
        ? (purposeLabel[item.summary.purpose ?? ''] ?? 'Not stated')
        : null;

// The pending/under_review/correction_required queue is always shown in full,
// FIFO order — it is never paginated. Only the reviewed/rejected/expired
// history tier is (R1-05); `history` describes that tier's page only.
const pendingItems = computed(() =>
    props.items.filter((item) => item.displayStatus === 'pending'),
);
const historyItems = computed(() =>
    props.items.filter((item) => item.displayStatus !== 'pending'),
);
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

        <!-- Desktop: table -->
        <div class="hidden overflow-x-auto md:block">
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
                    <template v-for="item in pendingItems" :key="item.publicId">
                        <tr class="border-b border-border/60 hover:bg-muted/20">
                            <td class="px-3 py-2.5 align-middle">
                                <div v-if="item.summary" class="min-w-0">
                                    <div class="truncate font-medium">
                                        {{ item.summary.name }}
                                    </div>
                                    <div
                                        class="text-[11px] text-muted-foreground"
                                    >
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
                                {{ purpose(item) ?? '—' }}
                            </td>
                            <td class="px-3 py-2.5 align-middle">
                                <DisclosureText
                                    :text="item.summary?.complaint"
                                />
                            </td>
                            <td class="px-3 py-2.5 align-middle">
                                {{ item.summary?.duration ?? '—' }}
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
                                    <Link :href="item.visitUrl"
                                        >View Visit</Link
                                    >
                                </Button>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                        </tr>
                    </template>
                    <tr v-if="historyItems.length" class="border-b bg-muted/10">
                        <td
                            colspan="7"
                            class="px-3 py-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            History · reviewed, rejected and expired
                        </td>
                    </tr>
                    <tr
                        v-for="item in historyItems"
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
                            {{ purpose(item) ?? '—' }}
                        </td>
                        <td class="px-3 py-2.5 align-middle">
                            <DisclosureText :text="item.summary?.complaint" />
                        </td>
                        <td class="px-3 py-2.5 align-middle">
                            {{ item.summary?.duration ?? '—' }}
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
                            <span v-else class="text-muted-foreground">—</span>
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

        <!-- Mobile: stacked cards, no horizontal scroll -->
        <div class="divide-y md:hidden">
            <article
                v-for="item in pendingItems"
                :key="item.publicId"
                class="flex flex-col gap-1.5 px-3 py-3 text-sm"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p v-if="item.summary" class="truncate font-medium">
                            {{ item.summary.name }}
                        </p>
                        <p v-else class="text-muted-foreground">
                            Redacted after retention period
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ formatDateTime(item.submittedAt) }}
                            <template v-if="item.summary">
                                · {{ item.summary.age ?? '—' }} years ·
                                {{
                                    item.submissionType === 'guardian'
                                        ? 'Guardian'
                                        : 'Patient'
                                }}
                            </template>
                        </p>
                    </div>
                    <span
                        class="inline-flex shrink-0 rounded-md px-2 py-0.5 text-[11px] font-medium"
                        :class="statusClass[item.displayStatus]"
                        >{{ statusLabel[item.displayStatus] }}</span
                    >
                </div>
                <p v-if="purpose(item)" class="text-xs text-muted-foreground">
                    {{ purpose(item) }}
                    <template v-if="item.summary?.duration">
                        · {{ item.summary.duration }}</template
                    >
                </p>
                <DisclosureText
                    :text="item.summary?.complaint"
                    variant="clamp-2"
                />
                <div class="pt-1">
                    <Button
                        v-if="item.canVerify"
                        as-child
                        size="sm"
                        class="h-8 w-full"
                    >
                        <Link :href="`/registration-review/${item.publicId}`"
                            >Verify</Link
                        >
                    </Button>
                    <Button
                        v-else-if="item.visitUrl"
                        as-child
                        size="sm"
                        variant="outline"
                        class="h-8 w-full"
                    >
                        <Link :href="item.visitUrl">View Visit</Link>
                    </Button>
                </div>
            </article>
            <p
                v-if="historyItems.length"
                class="border-t bg-muted/10 px-3 py-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
            >
                History · reviewed, rejected and expired
            </p>
            <article
                v-for="item in historyItems"
                :key="item.publicId"
                class="flex flex-col gap-1.5 px-3 py-3 text-sm"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p v-if="item.summary" class="truncate font-medium">
                            {{ item.summary.name }}
                        </p>
                        <p v-else class="text-muted-foreground">
                            Redacted after retention period
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ formatDateTime(item.submittedAt) }}
                            <template v-if="item.summary">
                                · {{ item.summary.age ?? '—' }} years ·
                                {{
                                    item.submissionType === 'guardian'
                                        ? 'Guardian'
                                        : 'Patient'
                                }}
                            </template>
                        </p>
                    </div>
                    <span
                        class="inline-flex shrink-0 rounded-md px-2 py-0.5 text-[11px] font-medium"
                        :class="statusClass[item.displayStatus]"
                        >{{ statusLabel[item.displayStatus] }}</span
                    >
                </div>
                <p v-if="purpose(item)" class="text-xs text-muted-foreground">
                    {{ purpose(item) }}
                    <template v-if="item.summary?.duration">
                        · {{ item.summary.duration }}</template
                    >
                </p>
                <DisclosureText
                    :text="item.summary?.complaint"
                    variant="clamp-2"
                />
                <div v-if="item.canVerify || item.visitUrl" class="pt-1">
                    <Button
                        v-if="item.canVerify"
                        as-child
                        size="sm"
                        class="h-8 w-full"
                    >
                        <Link :href="`/registration-review/${item.publicId}`"
                            >Verify</Link
                        >
                    </Button>
                    <Button
                        v-else-if="item.visitUrl"
                        as-child
                        size="sm"
                        variant="outline"
                        class="h-8 w-full"
                    >
                        <Link :href="item.visitUrl">View Visit</Link>
                    </Button>
                </div>
            </article>
            <p
                v-if="items.length === 0"
                class="px-4 py-12 text-center text-sm text-muted-foreground"
            >
                No QR intake records are available for this branch.
            </p>
        </div>

        <div v-if="history.lastPage > 1" class="border-t px-3 py-2">
            <CompactPagination
                :current-page="history.currentPage"
                :last-page="history.lastPage"
                :total="history.total"
                @change="(page) => $emit('history-page-change', page)"
            />
        </div>
    </section>
</template>
