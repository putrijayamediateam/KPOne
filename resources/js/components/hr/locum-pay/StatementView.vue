<script setup lang="ts">
/**
 * HR-15g: one locum's month statement, shared by Classic and Version 2 and by My pay. From 768 px a table; below it one
 * card per shift. Plain theme tokens only, so it reads the same in both versions and in dark mode. Only HR (canEdit)
 * sees the "Not worked" and "Remove" buttons; the buttons are outline and emit to the page, which sends the request.
 */
import type {
    Correction,
    Line,
    Statement,
} from '@/composables/hr/useLocumPayPage';

defineProps<{
    statement: Statement;
    canEdit?: boolean;
    busyId?: string | null;
    /** The locum's own view: a shift without a rate reads "Being checked by HR". */
    own?: boolean;
}>();
const emit = defineEmits<{
    notWorked: [line: Line];
    countAgain: [line: Line];
    removeCorrection: [item: Correction];
}>();

const outline =
    'inline-flex min-h-11 items-center justify-center rounded-md border border-border bg-background px-3 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 md:min-h-9';
const pending = (own: boolean | undefined) =>
    own ? 'Being checked by HR' : 'No confirmed rate';
</script>

<template>
    <div class="flex flex-col gap-4" data-test="locum-statement">
        <p
            v-if="statement.lines.length === 0"
            class="rounded-xl border bg-card p-4 text-sm text-muted-foreground"
        >
            No approved shifts this month.
        </p>

        <!-- Phones: one card per shift. -->
        <ul
            v-if="statement.lines.length > 0"
            class="flex flex-col gap-3 md:hidden"
            data-test="locum-statement-cards"
        >
            <li
                v-for="line in statement.lines"
                :key="line.shiftId"
                class="flex flex-col gap-1 rounded-xl border bg-card p-4 text-sm"
                :class="line.notWorked ? 'border-dashed' : ''"
            >
                <div class="flex items-baseline justify-between gap-3">
                    <span class="font-medium">{{ line.dateLabel }}</span>
                    <span class="font-medium tabular-nums">{{
                        line.amountLabel
                    }}</span>
                </div>
                <span class="tabular-nums">{{ line.time }}</span>
                <span class="text-muted-foreground"
                    >{{ line.branch }} · {{ line.hours }}</span
                >
                <span v-if="line.notWorked" class="text-muted-foreground"
                    >Not worked: {{ line.notWorked.reason }}</span
                >
                <span v-else-if="line.noRate" class="text-warning-strong">{{
                    pending(own)
                }}</span>
                <span v-else class="text-muted-foreground">{{
                    line.basis
                }}</span>
                <div v-if="canEdit" class="pt-2">
                    <button
                        v-if="line.notWorked"
                        type="button"
                        :class="outline"
                        :disabled="busyId === line.shiftId"
                        :aria-label="`Count the shift on ${line.dateLabel} again`"
                        @click="emit('countAgain', line)"
                    >
                        Count again
                    </button>
                    <button
                        v-else
                        type="button"
                        :class="outline"
                        :aria-label="`Mark the shift on ${line.dateLabel} not worked`"
                        data-test="locum-not-worked"
                        @click="emit('notWorked', line)"
                    >
                        Not worked
                    </button>
                </div>
            </li>
        </ul>

        <!-- From 768 px: a table. -->
        <div
            v-if="statement.lines.length > 0"
            class="hidden overflow-x-auto rounded-xl border bg-card md:block"
        >
            <table class="w-full text-sm" data-test="locum-statement-table">
                <caption class="sr-only">
                    Shifts this month
                </caption>
                <thead class="border-b text-left text-xs text-muted-foreground">
                    <tr>
                        <th scope="col" class="px-3 py-2 font-medium">Date</th>
                        <th scope="col" class="px-3 py-2 font-medium">Time</th>
                        <th scope="col" class="px-3 py-2 font-medium">
                            Branch
                        </th>
                        <th scope="col" class="px-3 py-2 font-medium">
                            How it is worked out
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2 text-right font-medium"
                        >
                            Amount
                        </th>
                        <th v-if="canEdit" scope="col" class="px-3 py-2">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="line in statement.lines"
                        :key="line.shiftId"
                        class="align-top"
                    >
                        <td class="px-3 py-2 whitespace-nowrap">
                            {{ line.dateLabel }}
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap tabular-nums">
                            {{ line.time }}
                        </td>
                        <td class="px-3 py-2">{{ line.branch }}</td>
                        <td class="px-3 py-2">
                            <span
                                v-if="line.notWorked"
                                class="text-muted-foreground"
                                >Not worked: {{ line.notWorked.reason }}</span
                            >
                            <span
                                v-else-if="line.noRate"
                                class="text-warning-strong"
                                >{{ pending(own) }} ({{ line.hours }})</span
                            >
                            <span v-else>{{ line.basis }}</span>
                        </td>
                        <td
                            class="px-3 py-2 text-right whitespace-nowrap tabular-nums"
                        >
                            {{ line.amountLabel }}
                        </td>
                        <td v-if="canEdit" class="px-3 py-1.5 text-right">
                            <button
                                v-if="line.notWorked"
                                type="button"
                                :class="outline"
                                :disabled="busyId === line.shiftId"
                                :aria-label="`Count the shift on ${line.dateLabel} again`"
                                @click="emit('countAgain', line)"
                            >
                                Count again
                            </button>
                            <button
                                v-else
                                type="button"
                                :class="outline"
                                :aria-label="`Mark the shift on ${line.dateLabel} not worked`"
                                @click="emit('notWorked', line)"
                            >
                                Not worked
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <section
            v-if="statement.corrections.length > 0"
            aria-labelledby="locum-corrections-heading"
            class="flex flex-col gap-2"
        >
            <h3 id="locum-corrections-heading" class="text-sm font-semibold">
                Corrections
            </h3>
            <ul class="divide-y rounded-xl border bg-card text-sm">
                <li
                    v-for="(item, i) in statement.corrections"
                    :key="item.id ?? i"
                    class="flex flex-wrap items-center justify-between gap-3 p-3"
                >
                    <span
                        >{{ item.reason
                        }}<template v-if="item.forMonth">
                            ({{ item.forMonth }})</template
                        ></span
                    >
                    <span class="flex items-center gap-3">
                        <span class="font-medium tabular-nums">{{
                            item.amountLabel
                        }}</span>
                        <button
                            v-if="canEdit"
                            type="button"
                            :class="outline"
                            :disabled="busyId === item.id"
                            :aria-label="`Remove the correction ${item.reason}, ${item.amountLabel}`"
                            @click="emit('removeCorrection', item)"
                        >
                            Remove
                        </button>
                    </span>
                </li>
            </ul>
        </section>

        <div
            class="flex items-baseline justify-between gap-3 border-t pt-3"
            data-test="locum-statement-total"
        >
            <span class="font-semibold">{{
                statement.paid ? 'Paid' : 'Total so far'
            }}</span>
            <span class="text-lg font-semibold tabular-nums">{{
                statement.totalLabel
            }}</span>
        </div>
        <p
            v-if="statement.payment"
            class="text-sm text-muted-foreground"
            data-test="locum-statement-payment"
        >
            Paid outside KPOne on {{ statement.payment.paidOnLabel
            }}<template v-if="statement.payment.reference">
                · reference {{ statement.payment.reference }}</template
            ><template v-if="statement.payment.paidBy">
                · recorded by {{ statement.payment.paidBy }}</template
            >. This month is locked.
        </p>
    </div>
</template>
