<script setup lang="ts">
// HR-18 /hr/labour-cost (owner, 18 and 20 Nov 2026): read-only labour cost by branch for one month, from approved pay
// runs and locum months Finance recorded as paid. No person, role or pay group; a branch with fewer than 3 people is not
// shown. Classic only (owner, 2026-11-20).
import { Head, router } from '@inertiajs/vue3';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import { OperationalSelect } from '@/components/ui/select';
import { EmptyState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';

// A null amount is withheld so no one person's pay can be worked out (every shown figure covers 0 or at least 3 people).
type Amounts = {
    people: number;
    pay: string | null;
    employer: string | null;
    locum: string | null;
    total: string | null;
};
type Row = {
    branch: string;
    state: 'shown' | 'few' | 'none';
} & Partial<Amounts>;

defineProps<{
    labourCost: {
        month: string;
        monthLabel: string;
        months: { value: string; label: string }[];
        rows: Row[];
        total: Amounts | null;
        totalHidden: boolean;
        someHidden: boolean;
        runs: number;
        waiting: number;
        unassigned: number;
        locumCorrections: string | null;
        unreadable: number;
    };
}>();

const chooseMonth = (value: string | number) =>
    router.get(
        '/hr/labour-cost',
        { month: String(value) },
        { preserveScroll: true, preserveState: true },
    );

const hiddenText: Record<string, string> = {
    few: 'Fewer than 3 people: not shown',
    none: 'No pay recorded',
};

const NOT_SHOWN = 'Not shown';
const amount = (value: string | null | undefined) => value ?? NOT_SHOWN;
const muted = (value: string | null | undefined) =>
    value == null ? 'text-muted-foreground font-normal' : '';
</script>

<template>
    <Head title="Labour cost" />
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-4 px-4 py-4 md:px-5">
        <div class="flex flex-col gap-1">
            <PageHeader
                eyebrow="KPOne HR"
                title="Labour cost"
                description="Pay, employer contributions and locum pay by branch for one month. Read only; it changes nothing."
            />
            <SyntheticNote />
        </div>

        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-base font-semibold">
                    {{ labourCost.monthLabel }}
                </h2>
                <StatusBadge
                    status="estimate"
                    label="Estimate"
                    tone="warning"
                    data-test="labour-cost-estimate"
                />
            </div>
            <div class="flex items-center gap-2">
                <Label for="labour-cost-month">Month</Label>
                <OperationalSelect
                    id="labour-cost-month"
                    :model-value="labourCost.month"
                    :options="labourCost.months"
                    label="Month"
                    class="min-w-48"
                    @update:model-value="chooseMonth"
                />
            </div>
        </div>

        <p class="max-w-prose text-sm text-muted-foreground">
            From approved pay runs and locum months Finance recorded as paid.
            Staff count at their primary branch on the month's last day; locum
            pay counts at the branch of each paid shift. An estimate until the
            pay rules are checked against current law. The Director's and HR
            Manager's own pay is kept outside KPOne.
        </p>

        <EmptyState
            v-if="
                labourCost.runs === 0 &&
                !labourCost.total &&
                !labourCost.totalHidden
            "
            title="No approved pay run for this month yet."
            description="Labour cost shows once a pay run is approved or a locum month is recorded as paid."
            data-test="labour-cost-empty"
        />

        <template v-else>
            <div class="hidden lg:block">
                <OperationalTable
                    :label="`Labour cost by branch, ${labourCost.monthLabel}`"
                    :columns="6"
                    min-width="880px"
                >
                    <template #head>
                        <tr>
                            <th scope="col">Branch</th>
                            <th scope="col" class="text-right">People paid</th>
                            <th scope="col" class="text-right">Pay</th>
                            <th scope="col" class="text-right">
                                Employer contributions
                            </th>
                            <th scope="col" class="text-right">Locum pay</th>
                            <th scope="col" class="text-right">Total</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr
                            v-for="row in labourCost.rows"
                            :key="row.branch"
                            data-test="labour-cost-row"
                        >
                            <th scope="row" class="font-medium">
                                {{ row.branch }}
                            </th>
                            <template v-if="row.state === 'shown'">
                                <td class="text-right tabular-nums">
                                    {{ row.people }}
                                </td>
                                <td
                                    class="text-right whitespace-nowrap tabular-nums"
                                    :class="muted(row.pay)"
                                >
                                    {{ amount(row.pay) }}
                                </td>
                                <td
                                    class="text-right whitespace-nowrap tabular-nums"
                                    :class="muted(row.employer)"
                                >
                                    {{ amount(row.employer) }}
                                </td>
                                <td
                                    class="text-right whitespace-nowrap tabular-nums"
                                    :class="muted(row.locum)"
                                >
                                    {{ amount(row.locum) }}
                                </td>
                                <td
                                    class="text-right font-semibold whitespace-nowrap tabular-nums"
                                    :class="muted(row.total)"
                                >
                                    {{ amount(row.total) }}
                                </td>
                            </template>
                            <td
                                v-else
                                colspan="5"
                                class="text-muted-foreground"
                                data-test="labour-cost-hidden"
                            >
                                {{ hiddenText[row.state] }}
                            </td>
                        </tr>
                        <tr
                            v-if="labourCost.total"
                            class="border-t-2"
                            data-test="labour-cost-total"
                        >
                            <th scope="row" class="font-semibold">
                                Clinic total
                            </th>
                            <td class="text-right font-semibold tabular-nums">
                                {{ labourCost.total.people }}
                            </td>
                            <td
                                class="text-right font-semibold whitespace-nowrap tabular-nums"
                                :class="muted(labourCost.total.pay)"
                            >
                                {{ amount(labourCost.total.pay) }}
                            </td>
                            <td
                                class="text-right font-semibold whitespace-nowrap tabular-nums"
                                :class="muted(labourCost.total.employer)"
                            >
                                {{ amount(labourCost.total.employer) }}
                            </td>
                            <td
                                class="text-right font-semibold whitespace-nowrap tabular-nums"
                                :class="muted(labourCost.total.locum)"
                            >
                                {{ amount(labourCost.total.locum) }}
                            </td>
                            <td
                                class="text-right font-semibold whitespace-nowrap tabular-nums"
                                :class="muted(labourCost.total.total)"
                            >
                                {{ amount(labourCost.total.total) }}
                            </td>
                        </tr>
                    </template>
                </OperationalTable>
            </div>

            <ul class="flex flex-col gap-2 lg:hidden">
                <li
                    v-for="row in labourCost.rows"
                    :key="row.branch"
                    class="rounded-xl border bg-card p-4 text-sm"
                >
                    <h3 class="font-semibold">{{ row.branch }}</h3>
                    <dl
                        v-if="row.state === 'shown'"
                        class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1"
                    >
                        <dt class="text-muted-foreground">People paid</dt>
                        <dd class="text-right tabular-nums">
                            {{ row.people }}
                        </dd>
                        <dt class="text-muted-foreground">Pay</dt>
                        <dd
                            class="text-right tabular-nums"
                            :class="muted(row.pay)"
                        >
                            {{ amount(row.pay) }}
                        </dd>
                        <dt class="text-muted-foreground">
                            Employer contributions
                        </dt>
                        <dd
                            class="text-right tabular-nums"
                            :class="muted(row.employer)"
                        >
                            {{ amount(row.employer) }}
                        </dd>
                        <dt class="text-muted-foreground">Locum pay</dt>
                        <dd
                            class="text-right tabular-nums"
                            :class="muted(row.locum)"
                        >
                            {{ amount(row.locum) }}
                        </dd>
                        <dt class="font-medium">Total</dt>
                        <dd
                            class="text-right font-semibold tabular-nums"
                            :class="muted(row.total)"
                        >
                            {{ amount(row.total) }}
                        </dd>
                    </dl>
                    <p v-else class="mt-1 text-muted-foreground">
                        {{ hiddenText[row.state] }}
                    </p>
                </li>
                <li
                    v-if="labourCost.total"
                    class="rounded-xl border-2 bg-card p-4 text-sm"
                >
                    <h3 class="font-semibold">Clinic total</h3>
                    <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1">
                        <dt class="text-muted-foreground">People paid</dt>
                        <dd class="text-right tabular-nums">
                            {{ labourCost.total.people }}
                        </dd>
                        <dt class="text-muted-foreground">Pay</dt>
                        <dd
                            class="text-right tabular-nums"
                            :class="muted(labourCost.total.pay)"
                        >
                            {{ amount(labourCost.total.pay) }}
                        </dd>
                        <dt class="text-muted-foreground">
                            Employer contributions
                        </dt>
                        <dd
                            class="text-right tabular-nums"
                            :class="muted(labourCost.total.employer)"
                        >
                            {{ amount(labourCost.total.employer) }}
                        </dd>
                        <dt class="text-muted-foreground">Locum pay</dt>
                        <dd
                            class="text-right tabular-nums"
                            :class="muted(labourCost.total.locum)"
                        >
                            {{ amount(labourCost.total.locum) }}
                        </dd>
                        <dt class="font-medium">Total</dt>
                        <dd
                            class="text-right font-semibold tabular-nums"
                            :class="muted(labourCost.total.total)"
                        >
                            {{ amount(labourCost.total.total) }}
                        </dd>
                    </dl>
                </li>
            </ul>

            <p
                v-if="labourCost.someHidden"
                class="text-sm text-muted-foreground"
                data-test="labour-cost-some-hidden"
            >
                Some figures are not shown so no one person's pay can be worked
                out. Every figure shown covers no one or at least 3 people.
            </p>
            <p
                v-if="labourCost.totalHidden"
                class="text-sm text-muted-foreground"
                data-test="labour-cost-total-hidden"
            >
                The clinic total covers fewer than 3 people, so it is not shown.
            </p>
            <ul class="flex flex-col gap-1 text-sm text-muted-foreground">
                <li
                    v-if="labourCost.locumCorrections"
                    data-test="labour-cost-corrections"
                >
                    Locum corrections not tied to a branch:
                    {{ labourCost.locumCorrections }}, counted in the clinic
                    total only.
                </li>
                <li
                    v-if="labourCost.unassigned > 0"
                    data-test="labour-cost-unassigned"
                >
                    {{ labourCost.unassigned }}
                    {{
                        labourCost.unassigned === 1
                            ? 'person had'
                            : 'people had'
                    }}
                    no primary branch on the month's last day, counted in the
                    clinic total only.
                </li>
                <li
                    v-if="labourCost.waiting > 0"
                    data-test="labour-cost-waiting"
                >
                    {{ labourCost.waiting }}
                    {{
                        labourCost.waiting === 1 ? 'pay run is' : 'pay runs are'
                    }}
                    not approved yet and not counted.
                </li>
                <li
                    v-if="labourCost.unreadable > 0"
                    role="status"
                    class="text-foreground"
                    data-test="labour-cost-unreadable"
                >
                    {{ labourCost.unreadable }}
                    {{
                        labourCost.unreadable === 1
                            ? 'amount could not be read'
                            : 'amounts could not be read'
                    }}, so these figures are incomplete.
                </li>
            </ul>
        </template>
    </div>
</template>
