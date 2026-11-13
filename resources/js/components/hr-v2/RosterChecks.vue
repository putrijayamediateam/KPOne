<script setup lang="ts">
import { computed, ref } from 'vue';
import type { Compliance } from '@/components/hr/compliance/CompliancePanel.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import { gapCount, groupChecks, groupCompliance } from '@/lib/hr-roster-v2';

/**
 * Version 2 "Check before approval" for the Roster planner (Daniel, 2026-11-13): minimum staffing grouped by day, one
 * gap per line, the first three days open and the rest behind "Show all days"; the HR-5b rules grouped by kind; Fair
 * rotation in a dialog. Warnings only: nothing here blocks saving, sending or approving. The rail and the disclosure
 * bar show the same component. The lines come from the server; this only regroups them.
 */
const props = defineProps<{
    warnings: string[];
    compliance: Compliance | null;
    branch: string;
    /** False on an approved or replaced week: nothing waits for approval, so the words are notes, never checks. */
    awaitingApproval?: boolean;
}>();

const grouped = computed(() => groupChecks(props.warnings));
const rules = computed(() => groupCompliance(props.compliance?.warnings ?? []));
const showAllDays = ref(false);
const openGroups = ref<Record<string, boolean>>({});
const rotationOpen = ref(false);

const shownDays = computed(() =>
    showAllDays.value ? grouped.value.days : grouped.value.days.slice(0, 3),
);

const textLink =
    'inline-flex min-h-11 items-center text-sm font-medium text-primary underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9';
</script>

<template>
    <div class="flex flex-col gap-4 text-sm" data-test="roster-checks">
        <section
            v-if="grouped.days.length > 0"
            aria-labelledby="checks-minimum"
            class="flex flex-col gap-2"
        >
            <h3 id="checks-minimum" class="font-semibold">
                Below minimum staffing
            </h3>
            <ul class="flex flex-col gap-3">
                <li
                    v-for="d in shownDays"
                    :key="d.day"
                    class="flex flex-col gap-1"
                >
                    <p class="font-medium">
                        {{ d.day }}
                        <span class="font-normal text-muted-foreground"
                            >· {{ gapCount(d.gaps.length) }}</span
                        >
                    </p>
                    <ul class="flex flex-col gap-0.5 text-muted-foreground">
                        <li v-for="gap in d.gaps" :key="gap">{{ gap }}</li>
                    </ul>
                </li>
            </ul>
            <button
                v-if="grouped.days.length > 3"
                type="button"
                :class="[textLink, 'self-start']"
                :aria-expanded="showAllDays"
                @click="showAllDays = !showAllDays"
            >
                {{
                    showAllDays
                        ? 'Show fewer days'
                        : `Show all days (${grouped.days.length})`
                }}
            </button>
        </section>

        <section
            v-if="grouped.other.length > 0"
            aria-labelledby="checks-other"
            class="flex flex-col gap-2"
        >
            <h3 id="checks-other" class="font-semibold">
                {{
                    awaitingApproval === false ? 'Other notes' : 'Other checks'
                }}
            </h3>
            <ul class="flex flex-col gap-1">
                <li v-for="line in grouped.other" :key="line">{{ line }}</li>
            </ul>
        </section>

        <template v-if="compliance">
            <p
                v-if="!compliance.setUp"
                class="text-[13px] text-muted-foreground"
            >
                Compliance rules are not set up yet.
            </p>
            <section
                v-for="g in rules"
                :key="g.kind"
                :aria-label="g.title"
                class="flex flex-col gap-1"
            >
                <h3 class="font-semibold">
                    {{ g.title }}
                    <span class="font-normal text-muted-foreground"
                        >· {{ g.lines.length }}</span
                    >
                </h3>
                <ul class="flex flex-col gap-0.5 text-muted-foreground">
                    <li
                        v-for="line in openGroups[g.kind]
                            ? g.lines
                            : g.lines.slice(0, 3)"
                        :key="line"
                        class="break-words"
                    >
                        {{ line }}
                    </li>
                </ul>
                <button
                    v-if="g.lines.length > 3"
                    type="button"
                    :class="[textLink, 'self-start']"
                    :aria-expanded="!!openGroups[g.kind]"
                    @click="openGroups[g.kind] = !openGroups[g.kind]"
                >
                    {{
                        openGroups[g.kind]
                            ? 'Show fewer'
                            : `Show ${g.lines.length - 3} more`
                    }}<span class="sr-only"> · {{ g.title }}</span>
                </button>
            </section>
            <button
                type="button"
                :class="[textLink, 'self-start']"
                data-test="rotation-open"
                @click="rotationOpen = true"
            >
                Fair rotation · {{ compliance.rotation.month }}
            </button>
        </template>

        <V2Dialog
            v-if="compliance"
            :open="rotationOpen"
            :title="`Fair rotation · ${compliance.rotation.month}`"
            :description="`Resident doctors' shifts at ${branch} this month: approved weeks and this week as planned. Counts only.`"
            confirm-label="Close"
            @update:open="rotationOpen = $event"
            @confirm="rotationOpen = false"
        >
            <p
                v-if="compliance.rotation.rows.length === 0"
                class="text-sm text-muted-foreground"
            >
                No resident doctor shifts this month yet.
            </p>
            <table v-else class="w-full text-left text-sm">
                <caption class="sr-only">
                    Fair rotation counts
                </caption>
                <thead>
                    <tr class="border-b text-[13px] text-muted-foreground">
                        <th scope="col" class="py-1.5 font-medium">Doctor</th>
                        <th scope="col" class="py-1.5 text-right font-medium">
                            Weekends
                        </th>
                        <th scope="col" class="py-1.5 text-right font-medium">
                            Public holidays
                        </th>
                        <th scope="col" class="py-1.5 text-right font-medium">
                            Nights
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="r in compliance.rotation.rows"
                        :key="r.name"
                        class="border-b last:border-b-0"
                    >
                        <th scope="row" class="py-1.5 font-normal">
                            {{ r.name }}
                        </th>
                        <td class="py-1.5 text-right tabular-nums">
                            {{ r.weekends }}
                        </td>
                        <td class="py-1.5 text-right tabular-nums">
                            {{ r.holidays }}
                        </td>
                        <td class="py-1.5 text-right tabular-nums">
                            {{ r.nights }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </V2Dialog>
    </div>
</template>
