<script setup lang="ts">
import V2Chip from '@/components/v2/V2Chip.vue';
import V2KeyValueList from '@/components/v2/V2KeyValueList.vue';
import V2Stat from '@/components/v2/V2Stat.vue';
import {
    clinicStats,
    doctorLine,
    moneyStats,
    panelItems,
    stockItems,
} from '@/lib/agent-summary';
import type { Lina } from '@/lib/agent-summary';
import { formatDate, formatDateTime } from '@/lib/presentation';

/**
 * Version 2 Daily summary: one of Lina's reports (the 07:00 report or the 14:00 update) for one branch. The newest report
 * is drawn open with `heading`; an older one sits in a closed bar, so it has no heading of its own. Panel and Stock are
 * snapshots, so only the newest report carries them (`includePanelStock`). A part the server did not send is not drawn.
 */
defineProps<{
    lina: Lina;
    heading?: string;
    includePanelStock?: boolean;
}>();

// Two amounts to a row fit a phone only while the longest is 12 characters or fewer ("RM 12,345.67"); a longer one gets a row.
const MONEY_TWO_UP_MAX = 12;
const moneyColumns = (lina: Lina) =>
    moneyStats(lina).some((stat) => stat.value.length > MONEY_TWO_UP_MAX)
        ? 'grid-cols-1'
        : 'grid-cols-1 min-[390px]:grid-cols-2';
</script>

<template>
    <section
        class="flex min-w-0 flex-col gap-3"
        :data-test="`lina-${lina.slot}`"
    >
        <div class="flex flex-col gap-1">
            <div class="flex flex-wrap items-center gap-2">
                <h3 v-if="heading" class="text-base font-semibold">
                    {{ heading }}
                </h3>
                <V2Chip tone="info" label="AI agent" />
                <span class="text-sm text-muted-foreground">Lina</span>
            </div>
            <p class="text-[13px] leading-[18px] text-muted-foreground">
                {{
                    lina.builtAt
                        ? `Built ${formatDateTime(lina.builtAt)}.`
                        : 'Built.'
                }}
                <template v-if="lina.comparedWith">
                    Comparisons are with {{ formatDate(lina.comparedWith) }},
                    the same weekday last week.
                </template>
                <template v-else>
                    Half a day, so no comparison is shown.
                </template>
            </p>
        </div>

        <div
            v-if="lina.clinic"
            class="grid grid-cols-1 gap-3 min-[360px]:grid-cols-2 xl:grid-cols-4"
            data-test="lina-clinic"
        >
            <V2Stat
                v-for="stat in clinicStats(lina)"
                :key="stat.label"
                :label="stat.label"
                :value="stat.value"
                :detail="stat.detail"
            />
        </div>

        <div
            v-if="lina.doctors && lina.doctors.length > 0"
            class="flex flex-col gap-2"
            data-test="lina-doctors"
        >
            <h4 class="text-sm font-semibold">Seen by each doctor</h4>
            <V2KeyValueList
                :items="
                    lina.doctors.map((doctor) => ({
                        label: doctor.doctor,
                        value: doctorLine(doctor),
                    }))
                "
            />
        </div>

        <div
            v-if="lina.money"
            :class="[
                'grid gap-3 sm:grid-cols-2 xl:grid-cols-3',
                moneyColumns(lina),
            ]"
            data-test="lina-money"
        >
            <V2Stat
                v-for="stat in moneyStats(lina)"
                :key="stat.label"
                class="max-sm:!p-3 max-sm:[&_span.tabular-nums]:text-base"
                :label="stat.label"
                :value="stat.value"
                :detail="stat.detail"
            />
        </div>

        <template v-if="includePanelStock">
            <div
                v-if="lina.panel"
                class="flex flex-col gap-2"
                data-test="lina-panel"
            >
                <h4 class="text-sm font-semibold">
                    Panel claims when this report was built
                </h4>
                <V2KeyValueList :items="panelItems(lina.panel)" />
            </div>
            <div
                v-if="lina.stock"
                class="flex flex-col gap-2"
                data-test="lina-stock"
            >
                <h4 class="text-sm font-semibold">
                    Stock when this report was built
                </h4>
                <V2KeyValueList :items="stockItems(lina.stock)" />
            </div>
        </template>
    </section>
</template>
