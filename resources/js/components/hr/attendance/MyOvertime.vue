<script setup lang="ts">
// HR-3b on My attendance: my overtime requests (withdraw while waiting) and my month's figures.
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import type { MyOvertime } from './types';
import { requestTone } from './types';

defineProps<{ data: MyOvertime }>();

const withdrawing = ref('');
const withdraw = (publicId: string, lockVersion: number) => {
    if (withdrawing.value !== '') {
        return;
    }

    withdrawing.value = publicId;
    router.post(
        `/hr/attendance/overtime/${publicId}/withdraw`,
        {
            tab: 'my',
            expected_lock_version: lockVersion,
            idempotency_key: crypto.randomUUID(),
        },
        { preserveScroll: true, onFinish: () => (withdrawing.value = '') },
    );
};
</script>

<template>
    <div class="flex flex-col gap-2" data-test="my-overtime">
        <h3 class="text-base font-semibold">My overtime</h3>
        <EmptyState
            v-if="data.requests.length === 0"
            title="No overtime requests yet."
            :description="
                !data.ready
                    ? 'Overtime is not set up yet. HR will tell you when it is.'
                    : data.eligible
                      ? 'On a day you worked past your roster, press Ask for overtime on that day.'
                      : 'Your role cannot claim overtime. Ask HR if this is wrong.'
            "
        />
        <ul v-else class="flex flex-col gap-2">
            <li
                v-for="r in data.requests"
                :key="r.publicId"
                class="flex flex-wrap items-center justify-between gap-2 rounded-xl border bg-card p-3 text-sm"
            >
                <div class="flex min-w-0 flex-col gap-1">
                    <span class="font-medium"
                        >{{ r.dayLabel }} · {{ r.dayTypeLabel }}</span
                    >
                    <span class="text-muted-foreground tabular-nums"
                        >Asked for {{ r.requestedLabel
                        }}<span v-if="r.approvedLabel">
                            · Approved {{ r.approvedLabel }}</span
                        >
                        · {{ r.reason
                        }}<span v-if="r.decisionReason">
                            · {{ r.decisionReason }}</span
                        ></span
                    >
                </div>
                <div class="flex items-center gap-2">
                    <StatusBadge
                        :status="r.status"
                        :label="r.statusLabel"
                        :tone="requestTone(r)"
                    />
                    <Button
                        v-if="r.canWithdraw"
                        variant="outline"
                        class="min-h-11 sm:min-h-8"
                        :disabled="withdrawing !== ''"
                        @click="withdraw(r.publicId, r.lockVersion)"
                    >
                        Withdraw<span class="sr-only">
                            overtime for {{ r.dayLabel }}</span
                        >
                    </Button>
                </div>
            </li>
        </ul>
    </div>
    <div class="flex flex-col gap-2" data-test="my-month">
        <h3 class="text-base font-semibold">
            My month
            <span class="text-sm font-normal text-muted-foreground">{{
                data.month.label
            }}</span>
        </h3>
        <dl
            class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 rounded-xl border bg-card p-4 text-sm sm:grid-cols-[auto_1fr_auto_1fr]"
        >
            <dt class="text-muted-foreground">Rostered</dt>
            <dd class="tabular-nums">{{ data.month.figures.rostered }}</dd>
            <dt class="text-muted-foreground">Worked</dt>
            <dd class="tabular-nums">{{ data.month.figures.worked }}</dd>
            <dt class="text-muted-foreground">Late</dt>
            <dd class="tabular-nums">{{ data.month.figures.late }}</dd>
            <dt class="text-muted-foreground">Left early</dt>
            <dd class="tabular-nums">{{ data.month.figures.leftEarly }}</dd>
            <dt class="text-muted-foreground">No clock-in</dt>
            <dd class="tabular-nums">{{ data.month.figures.noClockIn }}</dd>
            <dt class="text-muted-foreground">No clock-out</dt>
            <dd class="tabular-nums">{{ data.month.figures.noClockOut }}</dd>
            <dt class="text-muted-foreground">Overtime approved</dt>
            <dd class="tabular-nums">{{ data.month.figures.overtime }}</dd>
        </dl>
    </div>
</template>
