<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { DisclosureText } from '@/components/ui/disclosure';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2Table from '@/components/v2/V2Table.vue';
import { formatDateTime } from '@/lib/presentation';

/**
 * Version 2 QR intake tab: the same records, the same Verify and View visit links, the same pending-first order and
 * the same paged history as the Classic `components/registration/QrIntakeTable.vue` (unchanged). The pending queue is
 * never paged; only the history tier is (25 a page, `qr_history_page`).
 */
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
defineEmits<{ 'history-page-change': [page: number] }>();

const purposeLabel: Record<string, string> = {
    doctor_illness: 'See a doctor / illness',
    pregnancy_check: 'Pregnancy check',
    scan: 'Scan',
    vaccination: 'Vaccination',
    medical_checkup: 'Medical check-up',
    procedure: 'Procedure',
    other: 'Other',
};
const statusChip: Record<
    QrIntakeItem['displayStatus'],
    { label: string; tone: 'warning' | 'info' | 'danger' | 'neutral' }
> = {
    pending: { label: 'Pending', tone: 'warning' },
    reviewed: { label: 'Reviewed', tone: 'info' },
    rejected: { label: 'Rejected', tone: 'danger' },
    expired: { label: 'Expired', tone: 'neutral' },
    data_purged: { label: 'Data purged', tone: 'neutral' },
};
const purpose = (item: QrIntakeItem) =>
    item.summary
        ? (purposeLabel[item.summary.purpose ?? ''] ?? 'Not stated')
        : null;
// A muted relative age under "Submitted" for a pending intake ("2 days ago"), no colour thresholds.
const relativeAge = (iso: string) => {
    const minutes = Math.max(
        0,
        Math.floor((Date.now() - new Date(iso).getTime()) / 60000),
    );

    if (Number.isNaN(minutes)) {
        return '';
    }

    const unit = (value: number, word: string) =>
        `${value} ${word}${value === 1 ? '' : 's'} ago`;

    return minutes < 1
        ? 'Just now'
        : minutes < 60
          ? unit(minutes, 'minute')
          : minutes < 1440
            ? unit(Math.floor(minutes / 60), 'hour')
            : unit(Math.floor(minutes / 1440), 'day');
};
const hasAction = (item: QrIntakeItem) => item.canVerify || !!item.visitUrl;
const pendingItems = computed(() =>
    props.items.filter((item) => item.displayStatus === 'pending'),
);
const historyItems = computed(() =>
    props.items.filter((item) => item.displayStatus !== 'pending'),
);
</script>

<template>
    <div class="flex min-w-0 flex-col gap-4">
        <p class="text-[13px] text-muted-foreground">
            {{ pendingItems.length }} to verify · {{ history.total }} in history
            · {{ branch.name }} · Pending first, earliest arrival first
        </p>

        <template
            v-for="group in [
                {
                    key: 'pending',
                    title: `Pending (${pendingItems.length})`,
                    rows: pendingItems,
                },
                {
                    key: 'history',
                    title: `History (${history.total})`,
                    rows: historyItems,
                },
            ]"
            :key="group.key"
        >
            <section
                v-if="group.rows.length"
                class="flex flex-col gap-2"
                :aria-labelledby="`v2-qr-${group.key}`"
            >
                <h3 :id="`v2-qr-${group.key}`" class="text-base font-semibold">
                    {{ group.title }}
                </h3>
                <V2Table
                    :label="group.title"
                    :columns="6"
                    cards="lg"
                    min-width="860px"
                >
                    <template #head>
                        <tr>
                            <th scope="col" class="w-[22%]">Patient</th>
                            <th scope="col" class="w-[16%]">Submitted</th>
                            <th scope="col" class="w-[18%]">
                                Purpose and duration
                            </th>
                            <th scope="col" class="w-[24%]">Visit reason</th>
                            <th scope="col" class="w-[10%]">Status</th>
                            <th scope="col" class="w-[10%] text-right">
                                Action
                            </th>
                        </tr>
                    </template>
                    <template #body>
                        <tr v-for="item in group.rows" :key="item.publicId">
                            <td data-label="Patient" data-primary>
                                <template v-if="item.summary">
                                    <span class="block text-sm font-medium">{{
                                        item.summary.name
                                    }}</span>
                                    <span
                                        class="block text-xs font-normal text-muted-foreground"
                                        >{{ item.summary.age ?? '—' }} years ·
                                        {{
                                            item.submissionType === 'guardian'
                                                ? 'Guardian'
                                                : 'Self'
                                        }}</span
                                    >
                                </template>
                                <span
                                    v-else
                                    class="text-sm font-normal text-muted-foreground"
                                    >Redacted after retention period</span
                                >
                            </td>
                            <td data-label="Submitted" class="text-sm">
                                <span
                                    class="whitespace-nowrap max-lg:text-right lg:contents"
                                >
                                    <span class="block">{{
                                        formatDateTime(item.submittedAt)
                                    }}</span>
                                    <span
                                        v-if="item.displayStatus === 'pending'"
                                        class="block text-xs text-muted-foreground"
                                        >{{
                                            relativeAge(item.submittedAt)
                                        }}</span
                                    >
                                </span>
                            </td>
                            <td data-label="Purpose" class="text-sm">
                                <span class="max-lg:text-right lg:contents">
                                    <span class="block">{{
                                        purpose(item) ?? '—'
                                    }}</span>
                                    <span
                                        v-if="item.summary?.duration"
                                        class="block text-xs text-muted-foreground"
                                        >Duration:
                                        {{ item.summary.duration }}</span
                                    >
                                </span>
                            </td>
                            <td
                                data-label="Visit reason"
                                class="max-w-72 text-sm max-lg:max-w-none"
                            >
                                <span class="max-lg:hidden"
                                    ><DisclosureText
                                        :text="item.summary?.complaint"
                                /></span>
                                <span class="text-muted-foreground lg:hidden">{{
                                    item.summary?.complaint ?? '—'
                                }}</span>
                            </td>
                            <td data-label="Status">
                                <V2Chip
                                    :tone="statusChip[item.displayStatus].tone"
                                    :label="
                                        statusChip[item.displayStatus].label
                                    "
                                />
                            </td>
                            <td
                                data-actions
                                class="text-right"
                                :class="hasAction(item) ? '' : 'max-lg:!hidden'"
                            >
                                <Link
                                    v-if="item.canVerify"
                                    :href="`/registration-review/${item.publicId}`"
                                    :aria-label="`Verify ${item.summary?.name ?? 'intake'}`"
                                    class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                                    >Verify</Link
                                >
                                <Link
                                    v-else-if="item.visitUrl"
                                    :href="item.visitUrl"
                                    class="inline-flex h-11 items-center justify-center rounded-[10px] px-2 text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                                    >View visit</Link
                                >
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                        </tr>
                    </template>
                </V2Table>
            </section>
        </template>

        <p
            v-if="items.length === 0"
            class="rounded-[14px] border bg-card px-4 py-12 text-center text-sm text-muted-foreground"
        >
            No QR intake records are available for this branch.
        </p>

        <V2Pager
            v-if="history.lastPage > 1"
            :current-page="history.currentPage"
            :last-page="history.lastPage"
            :total="history.total"
            :per-page="25"
            @change="(page) => $emit('history-page-change', page)"
        />
    </div>
</template>
