<script setup lang="ts">
// HR-5b planner warnings (HR-PLAN 12.6, Daniel's early look): a worded chip in warning tone opens the list in a side
// panel; each line names the person, the day and the rule. Warnings only, never a refusal. Fair rotation is counts.
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { StatusBadge } from '@/components/ui/status';

export type Compliance = {
    setUp: boolean;
    warnings: { kind: string; message: string }[];
    rotation: {
        month: string;
        rows: {
            name: string;
            weekends: number;
            holidays: number;
            nights: number;
        }[];
    };
};

const props = defineProps<{ compliance: Compliance; branch: string }>();
const open = ref(false);
const count = computed(() => props.compliance.warnings.length);
const chip = computed(() =>
    count.value === 1
        ? '1 compliance warning'
        : `${count.value} compliance warnings`,
);
const groups: { kind: string; title: string }[] = [
    { kind: 'coverage', title: 'Doctor cover' },
    { kind: 'days_in_row', title: 'Days in a row' },
    { kind: 'rest', title: 'Rest between shifts' },
    { kind: 'hours', title: 'Planned hours' },
];
</script>

<template>
    <div class="flex flex-wrap items-center gap-2" data-test="compliance">
        <template v-if="compliance.setUp">
            <StatusBadge
                v-if="count > 0"
                status="compliance"
                :label="chip"
                tone="warning"
                data-test="compliance-chip"
            />
            <span v-else class="text-sm text-muted-foreground"
                >No compliance warnings</span
            >
        </template>
        <span v-else class="text-sm text-muted-foreground"
            >Compliance rules are not set up yet.</span
        >
        <Button
            variant="outline"
            size="sm"
            class="max-lg:min-h-11"
            data-test="compliance-open"
            @click="open = true"
            >{{ count > 0 ? 'Show warnings' : 'Fair rotation' }}</Button
        >
    </div>

    <Sheet v-model:open="open">
        <SheetContent
            class="w-full overflow-y-auto sm:max-w-lg"
            data-test="compliance-sheet"
        >
            <SheetHeader>
                <SheetTitle>Compliance · {{ branch }}</SheetTitle>
                <SheetDescription
                    >Warnings only. They don't stop you saving or sending the
                    roster.</SheetDescription
                >
            </SheetHeader>
            <div class="flex flex-col gap-5 px-4 pb-6 text-sm">
                <p v-if="!compliance.setUp" class="text-muted-foreground">
                    Director or HR Manager set the opening hours, doctor cover
                    and work-hour limits under Minimum staffing.
                </p>
                <p v-else-if="count === 0" class="text-muted-foreground">
                    Nothing in this week goes against the rules set.
                </p>
                <template v-for="g in groups" :key="g.kind">
                    <section
                        v-if="
                            compliance.warnings.some((w) => w.kind === g.kind)
                        "
                        :aria-label="g.title"
                    >
                        <h3 class="mb-1 font-semibold">{{ g.title }}</h3>
                        <ul
                            class="flex flex-col gap-1"
                            data-test="compliance-list"
                        >
                            <li
                                v-for="w in compliance.warnings.filter(
                                    (x) => x.kind === g.kind,
                                )"
                                :key="w.message"
                                class="break-words"
                            >
                                {{ w.message }}
                            </li>
                        </ul>
                    </section>
                </template>

                <section aria-labelledby="rotation-title" data-test="rotation">
                    <h3 id="rotation-title" class="font-semibold">
                        Fair rotation · {{ compliance.rotation.month }}
                    </h3>
                    <p class="text-xs text-muted-foreground">
                        Resident doctors' shifts at {{ branch }} this month:
                        approved weeks and this week as planned. Counts only.
                    </p>
                    <p
                        v-if="!compliance.rotation.rows.length"
                        class="mt-2 text-muted-foreground"
                    >
                        No resident doctor shifts this month yet.
                    </p>
                    <div v-else class="mt-2 overflow-x-auto">
                        <table class="w-full text-left">
                            <caption class="sr-only">
                                Fair rotation counts
                            </caption>
                            <thead>
                                <tr class="border-b">
                                    <th
                                        scope="col"
                                        class="py-1.5 pr-3 font-medium"
                                    >
                                        Doctor
                                    </th>
                                    <th
                                        scope="col"
                                        class="py-1.5 pr-3 text-right font-medium"
                                    >
                                        Weekends
                                    </th>
                                    <th
                                        scope="col"
                                        class="py-1.5 pr-3 text-right font-medium"
                                    >
                                        Public holidays
                                    </th>
                                    <th
                                        scope="col"
                                        class="py-1.5 text-right font-medium"
                                    >
                                        Nights
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="r in compliance.rotation.rows"
                                    :key="r.name"
                                    class="border-b last:border-0"
                                >
                                    <th
                                        scope="row"
                                        class="py-1.5 pr-3 font-normal"
                                    >
                                        {{ r.name }}
                                    </th>
                                    <td
                                        class="py-1.5 pr-3 text-right tabular-nums"
                                    >
                                        {{ r.weekends }}
                                    </td>
                                    <td
                                        class="py-1.5 pr-3 text-right tabular-nums"
                                    >
                                        {{ r.holidays }}
                                    </td>
                                    <td class="py-1.5 text-right tabular-nums">
                                        {{ r.nights }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </SheetContent>
    </Sheet>
</template>
