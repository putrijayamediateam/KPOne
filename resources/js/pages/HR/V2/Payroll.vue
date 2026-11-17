<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AccountantNote from '@/components/hr/payroll/AccountantNote.vue';
import MtdScheduleSheet from '@/components/hr/payroll/MtdScheduleSheet.vue';
import ProfileSheet from '@/components/hr/payroll/ProfileSheet.vue';
import TableSheet from '@/components/hr/payroll/TableSheet.vue';
import {
    day,
    on,
    profileWords,
    runWords,
    tableWords,
    tone,
} from '@/components/hr/payroll/types';
import type { ExportRun } from '@/components/hr/payroll/types';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import { EmptyState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2SwitchRow from '@/components/v2/V2SwitchRow.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { useExportsTab } from '@/composables/hr/useExportsTab';
import { usePayrollPage } from '@/composables/hr/usePayrollPage';
import type { PayrollPageProps } from '@/composables/hr/usePayrollPage';
import { useRunsTab } from '@/composables/hr/useRunsTab';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { myr } from '@/types/billing';

/**
 * Version 2 Payroll, part (a) (shown only in V2; Classic is `pages/HR/Payroll.vue`, untouched). Same props, routes,
 * permissions, lock versions and idempotency keys: every request comes from `usePayrollPage`, `useRunsTab` and
 * `useExportsTab`, which Classic uses too, so this page sends none of its own. Runs, Exports and Settings are in the V2
 * look; Pay profiles and Tables keep their Classic markup inside the V2 frame until part (b) (owner's plan). KPOne makes
 * no payment and files nothing. Export passwords are sent once with the download and never kept.
 */
const props = defineProps<PayrollPageProps>();
usePageCrumbs(() => [{ title: 'Payroll' }]);

const {
    errors,
    failure,
    tabs,
    selectTab,
    money,
    rateLine,
    busy,
    anyDialog,
    profileSheet,
    enterFor,
    tableSheet,
    mtdSheet,
    decision,
    askDecide,
    noun,
    decide,
    withdrawing,
    askWithdraw,
    withdraw,
    profileTarget,
    tableTarget,
    mtdTarget,
    groupForm,
    openGroup,
    saveGroup,
    schemeFlags,
    typeForm,
    openType,
    saveType,
    unusedSuggestions,
    lineTypes,
    saveLineTypes,
    rates,
    saveRates,
} = usePayrollPage(props);

// The Runs tab: the same Prepare request and key as Classic's tab. `useRunsTab` gets its props once, for the form
// defaults, as Classic's tab does; the template reads `runsView`, which follows the page props live (Suresh m1).
const runsView = computed(() => ({
    runs: props.payroll.runs?.runs ?? [],
    months: props.payroll.runs?.months ?? [],
    groups: props.payroll.options.groups,
    ready: props.payroll.setUp.groups && props.payroll.setUp.checkedTables,
    prepareRefusal: props.payroll.runs?.prepareRefusal ?? null,
}));
const runsProps = runsView.value;
const { form: runForm, kinds, prepare } = useRunsTab(runsProps);

// The Exports tab: the same password dialog and recorded download as Classic's tab.
const {
    MIN_PASSWORD,
    busy: exporting,
    failure: exportFailure,
    asking,
    secret,
    ask,
    dismiss,
    download,
} = useExportsTab();
// Focus the pressed button first, so the dialog gives focus back to it on close (Safari does not focus a button on a
// mouse click); a button no longer on the page leaves focus to the dialog's default.
const askFor = (run: ExportRun, kind: 'bank' | 'accountant', event: Event) => {
    if (event.currentTarget instanceof HTMLElement) {
        event.currentTarget.focus();
    }

    ask(run, kind, event.currentTarget);
};

const description =
    'Payroll runs, pay profiles, statutory tables and pay settings. A second person checks each one before it counts. KPOne makes no payment and files nothing.';
const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const primaryButton =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring aria-[invalid=true]:border-destructive md:text-[15px] lg:h-9';
const card = 'rounded-[14px] border bg-card';

const prepareMissing = computed(() =>
    runForm.group === ''
        ? 'Choose a pay group to continue.'
        : runForm.month === ''
          ? 'Choose a month to continue.'
          : '',
);
const passwordMissing = computed(() =>
    secret.password === ''
        ? 'Type a password to continue.'
        : secret.confirm === ''
          ? 'Type the password again to continue.'
          : '',
);
const groupMissing = computed(() =>
    groupForm.name.trim() === '' ? 'Enter a name to continue.' : '',
);
const typeMissing = computed(() =>
    typeForm.name.trim() === '' ? 'Enter a name to continue.' : '',
);
const schemesOf = (t: Record<string, unknown>) =>
    schemeFlags
        .filter((f) => t[f.key])
        .map((f) => f.label)
        .join(', ') || 'no scheme';
const runTone = (status: string) =>
    status === 'approved' || status === 'locked' ? 'success' : 'neutral';
</script>

<template>
    <Head title="Payroll" />
    <PageFrame>
        <V2WorkLayout title="Payroll" :description="description">
            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <AccountantNote />
                    <V2Tabs
                        :model-value="payroll.tab"
                        :tabs="tabs"
                        ariaLabel="Payroll sections"
                        @update:model-value="selectTab"
                    />
                    <p
                        v-if="failure && !anyDialog"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ failure }}
                    </p>

                    <!-- Runs (V2) -->
                    <section
                        v-if="payroll.tab === 'runs' && payroll.runs"
                        aria-labelledby="v2-runs-title"
                        class="flex flex-col gap-4"
                        data-test="payroll-runs"
                    >
                        <div class="flex flex-col gap-1">
                            <h2
                                id="v2-runs-title"
                                class="text-base font-semibold"
                            >
                                Payroll runs
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                In this first version, the Director's and the HR
                                Manager's own pay is kept outside KPOne, and
                                locums are paid outside it too.
                            </p>
                        </div>
                        <form
                            :class="card"
                            class="flex flex-col gap-3 p-4"
                            aria-labelledby="v2-prepare-title"
                            data-test="run-prepare"
                            @submit.prevent="prepareMissing === '' && prepare()"
                        >
                            <h3
                                id="v2-prepare-title"
                                class="text-sm font-semibold"
                            >
                                Prepare a run
                            </h3>
                            <p
                                v-if="!runsView.ready"
                                class="text-sm text-muted-foreground"
                            >
                                Add a pay group, pay profiles and checked tables
                                first.
                            </p>
                            <p
                                v-if="runsView.prepareRefusal"
                                id="v2-prepare-refusal"
                                class="text-sm text-muted-foreground"
                            >
                                {{ runsView.prepareRefusal }}
                            </p>
                            <div class="grid gap-3 sm:grid-cols-3">
                                <div class="grid gap-1.5">
                                    <label
                                        id="v2-run-group-label"
                                        class="text-sm font-medium"
                                        >Pay group
                                        <span
                                            class="text-destructive"
                                            aria-hidden="true"
                                            >*</span
                                        ></label
                                    >
                                    <OperationalSelect
                                        v-model="runForm.group"
                                        labelledby="v2-run-group-label"
                                        label="Pay group"
                                        placeholder="Choose a pay group"
                                        trigger-class="max-lg:h-11"
                                        :options="runsView.groups"
                                        :invalid="!!errors.pay_group_id"
                                    />
                                    <InputError
                                        :message="errors.pay_group_id"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <label
                                        id="v2-run-month-label"
                                        class="text-sm font-medium"
                                        >Month
                                        <span
                                            class="text-destructive"
                                            aria-hidden="true"
                                            >*</span
                                        ></label
                                    >
                                    <OperationalSelect
                                        v-model="runForm.month"
                                        labelledby="v2-run-month-label"
                                        label="Month"
                                        trigger-class="max-lg:h-11"
                                        :options="runsView.months"
                                        :invalid="!!errors.month"
                                    />
                                    <InputError :message="errors.month" />
                                </div>
                                <div class="grid gap-1.5">
                                    <label
                                        id="v2-run-kind-label"
                                        class="text-sm font-medium"
                                        >Kind</label
                                    >
                                    <OperationalSelect
                                        v-model="runForm.kind"
                                        labelledby="v2-run-kind-label"
                                        label="Kind"
                                        trigger-class="max-lg:h-11"
                                        :options="kinds"
                                    />
                                </div>
                            </div>
                            <p
                                v-if="
                                    prepareMissing &&
                                    runsView.groups.length &&
                                    !runsView.prepareRefusal
                                "
                                id="v2-prepare-missing"
                                class="text-sm text-muted-foreground"
                            >
                                {{ prepareMissing }}
                            </p>
                            <button
                                type="submit"
                                :class="primaryButton"
                                class="self-start"
                                :disabled="
                                    runForm.processing ||
                                    !runsView.groups.length ||
                                    !!runsView.prepareRefusal
                                "
                                :aria-disabled="
                                    prepareMissing ? 'true' : undefined
                                "
                                :aria-describedby="
                                    runsView.prepareRefusal
                                        ? 'v2-prepare-refusal'
                                        : prepareMissing
                                          ? 'v2-prepare-missing'
                                          : undefined
                                "
                                data-test="run-prepare-send"
                            >
                                {{
                                    runForm.processing
                                        ? 'Preparing…'
                                        : 'Prepare run'
                                }}
                            </button>
                        </form>

                        <div
                            v-if="!runsView.runs.length"
                            :class="card"
                            class="p-6 text-center"
                        >
                            <p class="font-medium">No runs yet.</p>
                            <p class="text-sm text-muted-foreground">
                                Prepare a run for a pay group and month. Nothing
                                is paid or filed by KPOne.
                            </p>
                        </div>
                        <ul v-else :class="card" class="flex flex-col divide-y">
                            <li v-for="r in runsView.runs" :key="r.id">
                                <Link
                                    :href="`/hr/payroll/runs/${r.id}`"
                                    class="flex min-h-11 flex-wrap items-start justify-between gap-2 p-4 first:rounded-t-[14px] last:rounded-b-[14px] hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
                                    data-test="payroll-run"
                                >
                                    <span class="min-w-0">
                                        <span
                                            class="block font-semibold break-words"
                                            >{{ r.group }} · {{ r.month
                                            }}{{
                                                r.kind === 'adjustment'
                                                    ? ' · adjustment'
                                                    : ''
                                            }}</span
                                        >
                                        <span
                                            class="block text-sm text-muted-foreground tabular-nums"
                                            >{{ r.people }}
                                            {{
                                                r.people === 1
                                                    ? 'person'
                                                    : 'people'
                                            }}
                                            · net {{ myr(r.netSen)
                                            }}<template v-if="r.errors">
                                                · {{ r.errors }}
                                                {{
                                                    r.errors === 1
                                                        ? 'error'
                                                        : 'errors'
                                                }}</template
                                            ><template v-if="r.lockedAt">
                                                · locked
                                                {{ on(r.lockedAt) }}</template
                                            ></span
                                        >
                                    </span>
                                    <span class="flex items-center gap-3">
                                        <V2Chip
                                            :tone="runTone(r.status)"
                                            :label="runWords[r.status]"
                                        />
                                        <!-- The whole row is the link (one focus stop); this shows it can be pressed (Daniel). -->
                                        <span
                                            aria-hidden="true"
                                            class="inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium lg:min-h-9"
                                            >Open</span
                                        >
                                    </span>
                                </Link>
                            </li>
                        </ul>
                    </section>

                    <!-- Exports (V2) -->
                    <section
                        v-if="payroll.tab === 'exports' && payroll.exports"
                        aria-labelledby="v2-exports-title"
                        class="flex flex-col gap-4"
                    >
                        <div class="flex flex-col gap-1">
                            <h2
                                id="v2-exports-title"
                                class="text-base font-semibold"
                            >
                                Exports
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                From locked runs only. Each download is recorded
                                and no file is kept in KPOne. KPOne makes no
                                payment and files nothing.
                            </p>
                            <p class="text-sm text-muted-foreground">
                                Open the bank file in a spreadsheet as text, so
                                account numbers keep their leading zeros.
                            </p>
                            <p class="text-sm text-muted-foreground">
                                Each file comes as a password-protected zip.
                                Keep it in the clinic's locked folder, delete
                                other copies once sent, and send the password by
                                a different way from the file.
                            </p>
                        </div>
                        <p
                            v-if="exportFailure"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ exportFailure }}
                        </p>
                        <div
                            v-if="!payroll.exports.runs.length"
                            :class="card"
                            class="p-6 text-center"
                        >
                            <p class="font-medium">No locked runs yet.</p>
                            <p class="text-sm text-muted-foreground">
                                A run can be exported once it is approved and
                                locked.
                            </p>
                        </div>
                        <article
                            v-for="run in payroll.exports.runs"
                            :key="run.id"
                            :class="card"
                            class="flex flex-col gap-3 p-4"
                            :aria-labelledby="`v2-export-${run.id}`"
                            data-test="export-run"
                        >
                            <div
                                class="flex flex-wrap items-start justify-between gap-2"
                            >
                                <div class="min-w-0">
                                    <h3
                                        :id="`v2-export-${run.id}`"
                                        class="font-semibold break-words"
                                    >
                                        {{ run.group }} · {{ run.month
                                        }}<template
                                            v-if="run.kind === 'adjustment'"
                                        >
                                            (adjustment)</template
                                        >
                                    </h3>
                                    <p
                                        class="text-sm text-muted-foreground tabular-nums"
                                    >
                                        {{ run.people }}
                                        {{
                                            run.people === 1
                                                ? 'person'
                                                : 'people'
                                        }}
                                        · net {{ myr(run.netSen) }}
                                        <template v-if="run.exports">
                                            · downloaded {{ run.exports }}
                                            {{
                                                run.exports === 1
                                                    ? 'time'
                                                    : 'times'
                                            }}</template
                                        >
                                    </p>
                                </div>
                                <V2Chip tone="success" label="Locked" />
                            </div>
                            <p
                                v-if="run.missingBank.length"
                                class="text-sm text-muted-foreground"
                                data-test="export-missing-bank"
                            >
                                Not in the bank file:
                                {{ run.missingBank.join(', ') }}. They had no
                                confirmed bank details when the run was locked,
                                so pay them outside the file.
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    :class="button"
                                    :disabled="
                                        !!exporting ||
                                        run.missingBank.length === run.people
                                    "
                                    data-test="export-bank"
                                    @click="askFor(run, 'bank', $event)"
                                >
                                    {{
                                        exporting === `${run.id}:bank`
                                            ? 'Preparing…'
                                            : 'Download bank file'
                                    }}
                                </button>
                                <button
                                    type="button"
                                    :class="button"
                                    :disabled="!!exporting"
                                    data-test="export-accountant"
                                    @click="askFor(run, 'accountant', $event)"
                                >
                                    {{
                                        exporting === `${run.id}:accountant`
                                            ? 'Preparing…'
                                            : 'Download accountant summary'
                                    }}
                                </button>
                            </div>
                            <details
                                v-if="run.statutory.length"
                                class="group rounded-[10px] border"
                            >
                                <summary
                                    class="flex min-h-11 cursor-pointer items-center px-3 text-sm font-medium lg:min-h-9"
                                >
                                    Statutory summary for the portals
                                </summary>
                                <div class="flex flex-col gap-2 border-t p-3">
                                    <p class="text-sm text-muted-foreground">
                                        Totals to type into the KWSP, PERKESO
                                        and LHDN portals by hand. KPOne files
                                        nothing.
                                    </p>
                                    <div class="overflow-x-auto">
                                        <table
                                            class="w-full min-w-[28rem] text-sm"
                                        >
                                            <caption class="sr-only">
                                                Statutory summary for
                                                {{
                                                    run.group
                                                }}
                                                ·
                                                {{
                                                    run.month
                                                }}
                                            </caption>
                                            <thead>
                                                <tr
                                                    class="text-left text-muted-foreground"
                                                >
                                                    <th
                                                        scope="col"
                                                        class="py-1 pr-3 font-medium"
                                                    >
                                                        Scheme
                                                    </th>
                                                    <th
                                                        scope="col"
                                                        class="py-1 pr-3 text-right font-medium"
                                                    >
                                                        People
                                                    </th>
                                                    <th
                                                        scope="col"
                                                        class="py-1 pr-3 text-right font-medium"
                                                    >
                                                        Employee
                                                    </th>
                                                    <th
                                                        scope="col"
                                                        class="py-1 text-right font-medium"
                                                    >
                                                        Employer
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr
                                                    v-for="s in run.statutory"
                                                    :key="s.scheme"
                                                    class="border-t tabular-nums"
                                                >
                                                    <th
                                                        scope="row"
                                                        class="py-1 pr-3 text-left font-normal"
                                                    >
                                                        {{ s.scheme }}
                                                    </th>
                                                    <td
                                                        class="py-1 pr-3 text-right"
                                                    >
                                                        {{ s.people }}
                                                    </td>
                                                    <td
                                                        class="py-1 pr-3 text-right"
                                                    >
                                                        {{ myr(s.employeeSen) }}
                                                    </td>
                                                    <td class="py-1 text-right">
                                                        {{
                                                            s.scheme ===
                                                            'MTD (PCB)'
                                                                ? '—'
                                                                : myr(
                                                                      s.employerSen,
                                                                  )
                                                        }}
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </details>
                        </article>
                    </section>

                    <!-- Pay profiles -->
                    <section
                        v-if="payroll.tab === 'profiles' && payroll.profiles"
                        aria-labelledby="profiles-title"
                        class="flex flex-col gap-3"
                        data-test="pay-profiles"
                    >
                        <div class="flex flex-col gap-1">
                            <h2
                                id="profiles-title"
                                class="text-base font-semibold"
                            >
                                Pay profiles
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                {{ payroll.profiles.waitingCount }} waiting for
                                confirmation ·
                                {{ payroll.profiles.missingCount }} without a
                                confirmed profile
                            </p>
                            <p
                                class="text-sm text-muted-foreground"
                                data-test="managers-outside"
                            >
                                In this first version, the Director's and the HR
                                Manager's own pay is kept outside KPOne.
                            </p>
                        </div>
                        <EmptyState
                            v-if="!payroll.setUp.groups"
                            title="No pay group yet."
                            description="Add a pay group in Settings first; each pay profile belongs to one."
                        />
                        <EmptyState
                            v-else-if="!payroll.profiles.people.length"
                            title="No staff files yet."
                            description="Pay profiles are entered for staff who have a staff file."
                        />
                        <article
                            v-for="person in payroll.profiles.people"
                            :key="person.id"
                            class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                            :aria-labelledby="`person-${person.id}`"
                            data-test="pay-person"
                        >
                            <div
                                class="flex flex-wrap items-start justify-between gap-2"
                            >
                                <div class="min-w-0">
                                    <h3
                                        :id="`person-${person.id}`"
                                        class="text-base font-semibold break-words"
                                    >
                                        {{ person.name }}
                                    </h3>
                                    <p
                                        v-if="!person.active"
                                        class="text-sm text-muted-foreground"
                                    >
                                        Account inactive
                                    </p>
                                </div>
                                <Button
                                    v-if="
                                        payroll.setUp.groups &&
                                        !person.outside &&
                                        !person.enterRefusal
                                    "
                                    variant="outline"
                                    class="max-lg:min-h-11"
                                    data-test="profile-enter"
                                    @click="enterFor(person)"
                                    >{{
                                        person.current
                                            ? 'Change pay profile'
                                            : 'Enter pay profile'
                                    }}</Button
                                >
                                <p
                                    v-else-if="
                                        person.enterRefusal &&
                                        !person.waiting &&
                                        !person.outside
                                    "
                                    class="text-sm text-muted-foreground"
                                >
                                    {{ person.enterRefusal }}
                                </p>
                            </div>

                            <p
                                v-if="person.outside"
                                class="text-sm text-muted-foreground"
                                data-test="pay-outside"
                            >
                                Pay kept outside KPOne in this version.
                            </p>
                            <div
                                v-else
                                class="grid gap-3"
                                :class="
                                    person.waiting && person.current
                                        ? 'md:grid-cols-2'
                                        : ''
                                "
                            >
                                <div
                                    v-if="person.current"
                                    class="flex flex-col gap-1 rounded-lg border p-3"
                                    data-test="profile-current"
                                >
                                    <div
                                        class="flex items-center justify-between gap-2"
                                    >
                                        <span class="text-sm font-medium"
                                            >In force now</span
                                        >
                                        <StatusBadge
                                            status="confirmed"
                                            :label="profileWords.confirmed"
                                            :tone="tone('confirmed')"
                                        />
                                    </div>
                                    <p class="text-sm tabular-nums">
                                        {{ rateLine(person.current) }}
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        {{ person.current.payGroup }} · from
                                        {{ day(person.current.effectiveFrom) }}
                                    </p>
                                    <p
                                        v-if="person.current.schemes.length"
                                        class="text-sm text-muted-foreground"
                                    >
                                        {{ person.current.schemes.join(' · ') }}
                                    </p>
                                    <ul
                                        v-if="person.current.items.length"
                                        class="text-sm tabular-nums"
                                    >
                                        <li
                                            v-for="(item, i) in person.current
                                                .items"
                                            :key="i"
                                        >
                                            {{ item.name }} ·
                                            {{ money(item.amountSen) }}
                                        </li>
                                    </ul>
                                    <p class="text-xs text-muted-foreground">
                                        Confirmed by
                                        {{ person.current.decidedBy }}
                                    </p>
                                </div>
                                <p
                                    v-else-if="!person.waiting"
                                    class="text-sm text-muted-foreground"
                                >
                                    No confirmed pay profile.
                                </p>

                                <div
                                    v-if="person.waiting"
                                    class="flex flex-col gap-1 rounded-lg border border-dashed p-3"
                                    data-test="profile-waiting"
                                >
                                    <div
                                        class="flex items-center justify-between gap-2"
                                    >
                                        <span class="text-sm font-medium"
                                            >New</span
                                        >
                                        <StatusBadge
                                            status="waiting"
                                            :label="profileWords.waiting"
                                            :tone="tone('waiting')"
                                        />
                                    </div>
                                    <p class="text-sm tabular-nums">
                                        {{ rateLine(person.waiting) }}
                                        <template v-if="person.current"
                                            >(now
                                            {{
                                                money(person.current.rateSen)
                                            }})</template
                                        >
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        {{ person.waiting.payGroup }} · from
                                        {{ day(person.waiting.effectiveFrom) }}
                                    </p>
                                    <p
                                        v-if="person.waiting.schemes.length"
                                        class="text-sm text-muted-foreground"
                                    >
                                        {{ person.waiting.schemes.join(' · ') }}
                                    </p>
                                    <ul
                                        v-if="person.waiting.items.length"
                                        class="text-sm tabular-nums"
                                    >
                                        <li
                                            v-for="(item, i) in person.waiting
                                                .items"
                                            :key="i"
                                        >
                                            {{ item.name }} ·
                                            {{ money(item.amountSen) }}
                                        </li>
                                    </ul>
                                    <p class="text-xs text-muted-foreground">
                                        Entered by
                                        {{ person.waiting.enteredBy }} on
                                        {{ on(person.waiting.enteredAt) }}
                                    </p>
                                    <div
                                        class="mt-1 flex flex-wrap items-center gap-2"
                                    >
                                        <template
                                            v-if="person.waiting.canDecide"
                                        >
                                            <Button
                                                variant="outline"
                                                class="max-lg:min-h-11"
                                                data-test="profile-confirm"
                                                @click="
                                                    askDecide(
                                                        profileTarget(
                                                            person,
                                                            person.waiting,
                                                        ),
                                                        'check',
                                                    )
                                                "
                                                >Confirm</Button
                                            >
                                            <Button
                                                variant="outline"
                                                class="max-lg:min-h-11"
                                                data-test="profile-reject"
                                                @click="
                                                    askDecide(
                                                        profileTarget(
                                                            person,
                                                            person.waiting,
                                                        ),
                                                        'reject',
                                                    )
                                                "
                                                >Reject</Button
                                            >
                                        </template>
                                        <p
                                            v-else-if="
                                                person.waiting.decideRefusal
                                            "
                                            class="text-sm text-muted-foreground"
                                        >
                                            {{ person.waiting.decideRefusal }}
                                        </p>
                                        <Button
                                            v-if="person.waiting.canWithdraw"
                                            variant="outline"
                                            class="max-lg:min-h-11"
                                            data-test="profile-withdraw"
                                            @click="
                                                askWithdraw(
                                                    profileTarget(
                                                        person,
                                                        person.waiting,
                                                    ),
                                                )
                                            "
                                            >Withdraw</Button
                                        >
                                    </div>
                                </div>
                            </div>

                            <p
                                v-if="person.upcoming"
                                class="text-sm text-muted-foreground tabular-nums"
                            >
                                From {{ day(person.upcoming.effectiveFrom) }}:
                                {{ rateLine(person.upcoming) }} (confirmed)
                            </p>
                            <details
                                v-if="person.earlier.length"
                                class="text-sm"
                            >
                                <summary
                                    class="flex min-h-11 cursor-pointer items-center text-muted-foreground lg:min-h-0"
                                >
                                    Earlier pay profiles ({{
                                        person.earlier.length
                                    }})
                                </summary>
                                <ul class="mt-2 flex flex-col gap-1">
                                    <li
                                        v-for="p in person.earlier"
                                        :key="p.id"
                                        class="tabular-nums"
                                    >
                                        {{ day(p.effectiveFrom) }} ·
                                        {{ rateLine(p) }} ·
                                        {{ profileWords[p.status]
                                        }}<template v-if="p.reason"
                                            >: {{ p.reason }}</template
                                        >
                                    </li>
                                </ul>
                            </details>
                        </article>
                    </section>

                    <!-- Tables -->
                    <section
                        v-if="payroll.tab === 'tables' && payroll.tables"
                        aria-labelledby="tables-title"
                        class="flex flex-col gap-3"
                        data-test="statutory-tables"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <div class="flex flex-col gap-1">
                                <h2
                                    id="tables-title"
                                    class="text-base font-semibold"
                                >
                                    Statutory tables
                                </h2>
                                <p class="text-sm text-muted-foreground">
                                    EPF, SOCSO, EIS and the HRD levy, entered by
                                    the clinic from the official tables. KPOne
                                    ships no statutory number.
                                </p>
                            </div>
                            <Button
                                variant="outline"
                                class="max-lg:min-h-11"
                                data-test="table-enter"
                                @click="tableSheet = true"
                                >Enter a table</Button
                            >
                        </div>
                        <EmptyState
                            v-if="!payroll.tables.tables.length"
                            title="No tables checked yet."
                            description="Enter a table from the official figures; someone else checks it before it counts."
                        />
                        <article
                            v-for="t in payroll.tables.tables"
                            :key="t.id"
                            class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                            :aria-labelledby="`table-${t.id}`"
                            data-test="statutory-table"
                        >
                            <div
                                class="flex flex-wrap items-start justify-between gap-2"
                            >
                                <div class="min-w-0">
                                    <h3
                                        :id="`table-${t.id}`"
                                        class="text-base font-semibold break-words"
                                    >
                                        {{ t.schemeLabel }} · {{ t.category }}
                                    </h3>
                                    <p class="text-sm text-muted-foreground">
                                        From {{ day(t.effectiveFrom) }} ·
                                        {{ t.roundingLabel }}
                                    </p>
                                </div>
                                <StatusBadge
                                    :status="t.status"
                                    :label="
                                        t.reason
                                            ? `${tableWords[t.status]}: ${t.reason}`
                                            : tableWords[t.status]
                                    "
                                    :tone="tone(t.status)"
                                />
                            </div>
                            <p class="text-sm break-words">
                                Source: {{ t.sourceNote }}
                            </p>
                            <div class="overflow-x-auto rounded-lg border">
                                <table class="w-full min-w-[28rem] text-sm">
                                    <thead class="bg-muted/50 text-left">
                                        <tr>
                                            <th
                                                scope="col"
                                                class="px-3 py-2 font-medium"
                                            >
                                                Wages from
                                            </th>
                                            <th
                                                scope="col"
                                                class="px-3 py-2 font-medium"
                                            >
                                                To
                                            </th>
                                            <th
                                                scope="col"
                                                class="px-3 py-2 text-right font-medium"
                                            >
                                                Employee
                                            </th>
                                            <th
                                                scope="col"
                                                class="px-3 py-2 text-right font-medium"
                                            >
                                                Employer
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="(r, i) in t.rows"
                                            :key="i"
                                            class="border-t tabular-nums"
                                        >
                                            <td
                                                class="px-3 py-2 whitespace-nowrap"
                                            >
                                                {{ r.from }}
                                            </td>
                                            <td
                                                class="px-3 py-2 whitespace-nowrap"
                                            >
                                                {{ r.to ?? 'and above' }}
                                            </td>
                                            <td
                                                class="px-3 py-2 text-right whitespace-nowrap"
                                            >
                                                {{ r.employee }}
                                            </td>
                                            <td
                                                class="px-3 py-2 text-right whitespace-nowrap"
                                            >
                                                {{ r.employer }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Entered by {{ t.enteredBy }} on
                                {{ on(t.enteredAt)
                                }}<template v-if="t.decidedBy && t.decidedAt">
                                    ·
                                    {{
                                        t.status === 'rejected'
                                            ? 'Rejected'
                                            : 'Checked'
                                    }}
                                    by {{ t.decidedBy }} on
                                    {{ on(t.decidedAt) }}</template
                                >
                            </p>
                            <div
                                v-if="t.status === 'entered'"
                                class="flex flex-wrap items-center gap-2"
                            >
                                <template v-if="t.canDecide">
                                    <Button
                                        variant="outline"
                                        class="max-lg:min-h-11"
                                        data-test="table-check"
                                        @click="
                                            askDecide(tableTarget(t), 'check')
                                        "
                                        >Check</Button
                                    >
                                    <Button
                                        variant="outline"
                                        class="max-lg:min-h-11"
                                        data-test="table-reject"
                                        @click="
                                            askDecide(tableTarget(t), 'reject')
                                        "
                                        >Reject</Button
                                    >
                                </template>
                                <p
                                    v-else-if="t.decideRefusal"
                                    class="text-sm text-muted-foreground"
                                >
                                    {{ t.decideRefusal }}
                                </p>
                                <Button
                                    v-if="t.canWithdraw"
                                    variant="outline"
                                    class="max-lg:min-h-11"
                                    data-test="table-withdraw"
                                    @click="askWithdraw(tableTarget(t))"
                                    >Withdraw</Button
                                >
                            </div>
                        </article>
                    </section>

                    <!-- HR-7d: the yearly MTD schedules (HR-PLAN 18.2), on the Tables tab after the statutory tables. -->
                    <section
                        v-if="payroll.tab === 'tables' && payroll.tables"
                        aria-labelledby="mtd-title"
                        class="flex flex-col gap-3"
                        data-test="mtd-schedules"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <div class="flex flex-col gap-1">
                                <h2
                                    id="mtd-title"
                                    class="text-base font-semibold"
                                >
                                    MTD (PCB) schedules
                                </h2>
                                <p
                                    class="max-w-prose text-sm text-muted-foreground"
                                >
                                    The year's chargeable income bands and
                                    reliefs, entered by the clinic from LHDN's
                                    published schedule. Once checked, KPOne
                                    works out MTD on monthly runs for staff
                                    whose pay profile has MTD details. KPOne
                                    ships no tax number.
                                </p>
                            </div>
                            <Button
                                variant="outline"
                                class="max-lg:min-h-11"
                                data-test="mtd-enter"
                                @click="mtdSheet = true"
                                >Enter an MTD schedule</Button
                            >
                        </div>
                        <EmptyState
                            v-if="!payroll.tables.mtdSchedules.length"
                            title="No MTD schedule yet."
                            description="Until a schedule is checked for the year, MTD is typed for each person on each run."
                        />
                        <article
                            v-for="m in payroll.tables.mtdSchedules"
                            :key="m.id"
                            class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                            :aria-labelledby="`mtd-${m.id}`"
                            data-test="mtd-schedule"
                        >
                            <div
                                class="flex flex-wrap items-start justify-between gap-2"
                            >
                                <div class="min-w-0">
                                    <h3
                                        :id="`mtd-${m.id}`"
                                        class="text-base font-semibold"
                                    >
                                        MTD schedule · {{ m.year }}
                                    </h3>
                                    <p class="text-sm text-muted-foreground">
                                        {{ m.rounding }} · nothing deducted
                                        below
                                        {{ m.minimum }}
                                    </p>
                                </div>
                                <StatusBadge
                                    :status="m.status"
                                    :label="
                                        m.reason
                                            ? `${tableWords[m.status]}: ${m.reason}`
                                            : tableWords[m.status]
                                    "
                                    :tone="tone(m.status)"
                                />
                            </div>
                            <p class="text-sm break-words">
                                Source: {{ m.sourceNote }}
                            </p>
                            <dl
                                class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2"
                            >
                                <div
                                    v-for="r in m.reliefs"
                                    :key="r.label"
                                    class="flex justify-between gap-3 border-b py-1"
                                >
                                    <dt class="text-muted-foreground">
                                        {{ r.label }}
                                    </dt>
                                    <dd class="tabular-nums">{{ r.amount }}</dd>
                                </div>
                            </dl>
                            <div class="overflow-x-auto rounded-lg border">
                                <table class="w-full min-w-[36rem] text-sm">
                                    <caption class="sr-only">
                                        Chargeable income bands for
                                        {{
                                            m.year
                                        }}
                                    </caption>
                                    <thead class="bg-muted/50 text-left">
                                        <tr>
                                            <th
                                                scope="col"
                                                class="px-3 py-2 font-medium"
                                            >
                                                From (M)
                                            </th>
                                            <th
                                                scope="col"
                                                class="px-3 py-2 font-medium"
                                            >
                                                To
                                            </th>
                                            <th
                                                scope="col"
                                                class="px-3 py-2 text-right font-medium"
                                            >
                                                Rate (R)
                                            </th>
                                            <th
                                                scope="col"
                                                class="px-3 py-2 text-right font-medium"
                                            >
                                                Tax on M, categories 1 and 3
                                            </th>
                                            <th
                                                scope="col"
                                                class="px-3 py-2 text-right font-medium"
                                            >
                                                Tax on M, category 2
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="(r, i) in m.rows"
                                            :key="i"
                                            class="border-t tabular-nums"
                                        >
                                            <td
                                                class="px-3 py-2 whitespace-nowrap"
                                            >
                                                {{ r.from }}
                                            </td>
                                            <td
                                                class="px-3 py-2 whitespace-nowrap"
                                            >
                                                {{ r.to ?? 'and above' }}
                                            </td>
                                            <td
                                                class="px-3 py-2 text-right whitespace-nowrap"
                                            >
                                                {{ r.rate }}
                                            </td>
                                            <td
                                                class="px-3 py-2 text-right whitespace-nowrap"
                                            >
                                                {{ r.baseCat13 }}
                                            </td>
                                            <td
                                                class="px-3 py-2 text-right whitespace-nowrap"
                                            >
                                                {{ r.baseCat2 }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Entered by {{ m.enteredBy }} on
                                {{ on(m.enteredAt)
                                }}<template v-if="m.decidedBy && m.decidedAt">
                                    ·
                                    {{
                                        m.status === 'rejected'
                                            ? 'Rejected'
                                            : 'Checked'
                                    }}
                                    by {{ m.decidedBy }} on
                                    {{ on(m.decidedAt) }}</template
                                >
                            </p>
                            <div
                                v-if="m.status === 'entered'"
                                class="flex flex-wrap items-center gap-2"
                            >
                                <template v-if="m.canDecide">
                                    <Button
                                        variant="outline"
                                        class="max-lg:min-h-11"
                                        data-test="mtd-check"
                                        @click="
                                            askDecide(mtdTarget(m), 'check')
                                        "
                                        >Check</Button
                                    >
                                    <Button
                                        variant="outline"
                                        class="max-lg:min-h-11"
                                        data-test="mtd-reject"
                                        @click="
                                            askDecide(mtdTarget(m), 'reject')
                                        "
                                        >Reject</Button
                                    >
                                </template>
                                <p
                                    v-else-if="m.decideRefusal"
                                    class="text-sm text-muted-foreground"
                                >
                                    {{ m.decideRefusal }}
                                </p>
                                <Button
                                    v-if="m.canWithdraw"
                                    variant="outline"
                                    class="max-lg:min-h-11"
                                    data-test="mtd-withdraw"
                                    @click="askWithdraw(mtdTarget(m))"
                                    >Withdraw</Button
                                >
                            </div>
                        </article>
                    </section>

                    <!-- Settings (V2) -->
                    <section
                        v-if="payroll.tab === 'settings' && payroll.settings"
                        aria-labelledby="v2-settings-title"
                        class="flex flex-col gap-6"
                        data-test="payroll-settings"
                    >
                        <h2 id="v2-settings-title" class="sr-only">
                            Payroll settings
                        </h2>

                        <div class="flex flex-col gap-3">
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <h3 class="text-base font-semibold">
                                    Pay groups
                                </h3>
                                <button
                                    type="button"
                                    :class="button"
                                    data-test="group-add"
                                    @click="openGroup(null)"
                                >
                                    Add a pay group
                                </button>
                            </div>
                            <div
                                v-if="!payroll.settings.groups.length"
                                :class="card"
                                class="p-6 text-center"
                            >
                                <p class="font-medium">No pay group yet.</p>
                                <p class="text-sm text-muted-foreground">
                                    For example one monthly group for everyone.
                                </p>
                            </div>
                            <ul
                                v-else
                                :class="card"
                                class="flex flex-col divide-y"
                            >
                                <li
                                    v-for="g in payroll.settings.groups"
                                    :key="g.id"
                                    class="flex flex-wrap items-center justify-between gap-2 p-4"
                                    data-test="pay-group"
                                >
                                    <div class="min-w-0">
                                        <p class="font-medium break-words">
                                            {{ g.name }}
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            {{
                                                g.payDay
                                                    ? `Pay day ${g.payDay} of each month`
                                                    : 'Pay day not set'
                                            }}
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <V2Chip
                                            :tone="
                                                g.isActive
                                                    ? 'success'
                                                    : 'neutral'
                                            "
                                            :label="
                                                g.isActive
                                                    ? 'In use'
                                                    : 'Not in use'
                                            "
                                        />
                                        <button
                                            type="button"
                                            :class="button"
                                            :aria-label="`Change ${g.name}`"
                                            @click="openGroup(g)"
                                        >
                                            Change
                                        </button>
                                    </div>
                                </li>
                            </ul>
                        </div>

                        <div class="flex flex-col gap-3">
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <div>
                                    <h3 class="text-base font-semibold">
                                        Pay item types
                                    </h3>
                                    <p class="text-sm text-muted-foreground">
                                        The clinic decides which items count for
                                        each scheme. Each save is a new version.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    :class="button"
                                    data-test="type-add"
                                    @click="openType(null)"
                                >
                                    Add a pay item type
                                </button>
                            </div>
                            <div
                                v-if="unusedSuggestions.length"
                                class="flex flex-wrap items-center gap-2"
                            >
                                <span class="text-sm text-muted-foreground"
                                    >Suggested:</span
                                >
                                <button
                                    v-for="name in unusedSuggestions"
                                    :key="name"
                                    type="button"
                                    :class="button"
                                    @click="openType(null, name)"
                                >
                                    {{ name }}
                                </button>
                            </div>
                            <ul
                                v-if="payroll.settings.itemTypes.length"
                                :class="card"
                                class="flex flex-col divide-y"
                            >
                                <li
                                    v-for="t in payroll.settings.itemTypes"
                                    :key="t.id"
                                    class="flex flex-wrap items-center justify-between gap-2 p-4"
                                    data-test="item-type"
                                >
                                    <div class="min-w-0">
                                        <p class="font-medium break-words">
                                            {{ t.name }}
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            {{ t.kindLabel }} · counts for
                                            {{ schemesOf(t) }} · version
                                            {{ t.version }}
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <V2Chip
                                            :tone="
                                                t.isActive
                                                    ? 'success'
                                                    : 'neutral'
                                            "
                                            :label="
                                                t.isActive
                                                    ? 'In use'
                                                    : 'Not in use'
                                            "
                                        />
                                        <button
                                            type="button"
                                            :class="button"
                                            :aria-label="`Change ${t.name}`"
                                            @click="openType(t)"
                                        >
                                            Change
                                        </button>
                                    </div>
                                </li>
                            </ul>
                        </div>

                        <form
                            :class="card"
                            class="flex flex-col gap-3 p-4"
                            aria-labelledby="v2-line-types-title"
                            data-test="line-types"
                            @submit.prevent="saveLineTypes"
                        >
                            <div>
                                <h3
                                    id="v2-line-types-title"
                                    class="text-base font-semibold"
                                >
                                    Pay item types used by runs
                                </h3>
                                <p class="text-sm text-muted-foreground">
                                    Which pay item type a run uses for overtime,
                                    unpaid leave and staff claim refunds, so it
                                    knows which schemes each counts for. Each
                                    save is a new version. Unpaid leave lowers
                                    the EPF, SOCSO or EIS wage only when its pay
                                    item type counts for that scheme.
                                </p>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-3">
                                <div class="grid gap-1.5">
                                    <label
                                        id="v2-line-overtime-label"
                                        class="text-sm font-medium"
                                        >Overtime</label
                                    >
                                    <OperationalSelect
                                        v-model="lineTypes.overtime"
                                        labelledby="v2-line-overtime-label"
                                        label="Overtime"
                                        placeholder="Choose an earnings type"
                                        trigger-class="max-lg:h-11"
                                        :options="
                                            payroll.options.allowanceTypes
                                        "
                                        :invalid="!!errors.overtime_type_id"
                                    />
                                    <InputError
                                        :message="errors.overtime_type_id"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <label
                                        id="v2-line-unpaid-label"
                                        class="text-sm font-medium"
                                        >Unpaid leave</label
                                    >
                                    <OperationalSelect
                                        v-model="lineTypes.unpaidLeave"
                                        labelledby="v2-line-unpaid-label"
                                        label="Unpaid leave"
                                        placeholder="Choose a deduction type"
                                        trigger-class="max-lg:h-11"
                                        :options="
                                            payroll.options.deductionTypes
                                        "
                                        :invalid="!!errors.unpaid_leave_type_id"
                                    />
                                    <InputError
                                        :message="errors.unpaid_leave_type_id"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <label
                                        id="v2-line-claim-label"
                                        class="text-sm font-medium"
                                        >Staff claim refunds</label
                                    >
                                    <OperationalSelect
                                        v-model="lineTypes.claim"
                                        labelledby="v2-line-claim-label"
                                        label="Staff claim refunds"
                                        placeholder="Choose a refund type"
                                        trigger-class="max-lg:h-11"
                                        :options="payroll.options.refundTypes"
                                        :invalid="!!errors.claim_type_id"
                                    />
                                    <InputError
                                        :message="errors.claim_type_id"
                                    />
                                </div>
                            </div>
                            <button
                                type="submit"
                                :class="button"
                                class="self-start"
                                :disabled="lineTypes.processing"
                                data-test="line-types-save"
                            >
                                {{
                                    lineTypes.processing
                                        ? 'Saving…'
                                        : 'Save run line types'
                                }}
                            </button>
                        </form>

                        <form
                            :class="card"
                            class="flex flex-col gap-3 p-4"
                            aria-labelledby="v2-rates-title"
                            data-test="rate-settings"
                            @submit.prevent="saveRates"
                        >
                            <div>
                                <h3
                                    id="v2-rates-title"
                                    class="text-base font-semibold"
                                >
                                    Rate settings
                                </h3>
                                <p class="text-sm text-muted-foreground">
                                    How a day's and an hour's rate come from a
                                    monthly salary, for overtime and unpaid
                                    leave. KPOne ships no divisor; the clinic
                                    enters them.
                                </p>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2 lg:max-w-xl">
                                <V2Field
                                    v-slot="{ id, describedBy, invalid }"
                                    label="Days in a month"
                                    hint="Basic salary ÷ this = a day's rate. For example 26."
                                    :error="errors.daily_divisor"
                                    required
                                >
                                    <input
                                        :id="id"
                                        v-model="rates.daily"
                                        inputmode="decimal"
                                        :class="inputClass"
                                        class="text-right tabular-nums"
                                        :aria-describedby="describedBy"
                                        :aria-invalid="invalid"
                                    />
                                </V2Field>
                                <V2Field
                                    v-slot="{ id, describedBy, invalid }"
                                    label="Hours in a day"
                                    hint="A day's rate ÷ this = an hour's rate. For example 8."
                                    :error="errors.hourly_divisor"
                                    required
                                >
                                    <input
                                        :id="id"
                                        v-model="rates.hourly"
                                        inputmode="decimal"
                                        :class="inputClass"
                                        class="text-right tabular-nums"
                                        :aria-describedby="describedBy"
                                        :aria-invalid="invalid"
                                    />
                                </V2Field>
                            </div>
                            <button
                                type="submit"
                                :class="button"
                                class="self-start"
                                :disabled="rates.processing"
                                data-test="rates-save"
                            >
                                {{
                                    rates.processing
                                        ? 'Saving…'
                                        : 'Save rate settings'
                                }}
                            </button>
                            <ul
                                v-if="payroll.settings.rates.history.length"
                                class="flex flex-col gap-1 text-xs text-muted-foreground"
                            >
                                <li
                                    v-for="h in payroll.settings.rates.history"
                                    :key="h.version"
                                >
                                    Version {{ h.version }}: {{ h.daily }} days,
                                    {{ h.hourly }} hours · {{ h.by }} on
                                    {{ on(h.at) }}
                                </li>
                            </ul>
                        </form>
                    </section>
                </div>
            </template>
        </V2WorkLayout>

        <ProfileSheet
            v-model:open="profileSheet.open"
            :person="profileSheet.person"
            :groups="payroll.options.groups"
            :allowance-types="payroll.options.allowanceTypes"
            :categories="payroll.options.categories"
            :max-items="payroll.options.maxItems"
            :today="payroll.today"
            :mtd-categories="payroll.options.mtdCategories"
        />
        <TableSheet
            v-model:open="tableSheet"
            :schemes="payroll.options.schemes"
            :rounding="payroll.options.rounding"
            :max-rows="payroll.options.maxRows"
        />
        <MtdScheduleSheet
            v-model:open="mtdSheet"
            :rounding="payroll.options.mtdRounding"
            :reliefs="payroll.options.mtdReliefs"
            :max-bands="payroll.options.maxMtdBands"
            :year="Number(payroll.today.slice(0, 4))"
        />

        <OperationalConfirmDialog
            :open="decision.target !== null"
            :title="
                decision.action === 'reject'
                    ? `Reject this ${noun(decision.target)}?`
                    : decision.target?.kind === 'profile'
                      ? 'Confirm this pay profile?'
                      : `Check this ${noun(decision.target)}?`
            "
            :description="
                decision.target
                    ? decision.action === 'reject'
                        ? `${decision.target.label}. The rejection is kept with its reason; enter a new one to correct it.`
                        : decision.target.kind === 'table'
                          ? `${decision.target.label}. Compare every band with the official table. A checked table never changes.`
                          : decision.target.kind === 'mtd'
                            ? `${decision.target.label}. Compare every band and relief with LHDN's published schedule. KPOne works out MTD with it for that year, and it never changes.`
                            : `${decision.target.label}. It counts from that date and never changes; a later change is a new profile.`
                    : ''
            "
            :confirm-label="
                decision.action === 'reject'
                    ? 'Reject'
                    : decision.target?.kind === 'profile'
                      ? 'Confirm'
                      : 'Check'
            "
            processing-label="Saving…"
            :processing="busy"
            :error="failure"
            @update:open="(v: boolean) => !v && (decision.target = null)"
            @confirm="decide"
        >
            <div v-if="decision.action === 'reject'" class="grid gap-1.5">
                <Label for="payroll-reject-reason"
                    ><span id="payroll-reject-reason-label"
                        >Why it is rejected *</span
                    ></Label
                >
                <OperationalSelect
                    id="payroll-reject-reason"
                    v-model="decision.reason"
                    labelledby="payroll-reject-reason-label"
                    label="Why it is rejected"
                    placeholder="Choose a reason"
                    trigger-class="max-lg:h-11"
                    :options="
                        decision.target?.kind === 'profile'
                            ? payroll.options.profileRejectReasons
                            : payroll.options.tableRejectReasons
                    "
                    :invalid="!!errors.reason"
                />
                <InputError :message="errors.reason" />
            </div>
        </OperationalConfirmDialog>

        <OperationalConfirmDialog
            :open="withdrawing.target !== null"
            title="Withdraw this?"
            :description="
                withdrawing.target
                    ? `${withdrawing.target.label}. It is kept as withdrawn and nobody checks it.`
                    : ''
            "
            confirm-label="Withdraw"
            processing-label="Withdrawing…"
            :processing="busy"
            :error="failure"
            @update:open="(v: boolean) => !v && (withdrawing.target = null)"
            @confirm="withdraw"
        />

        <!-- Export password (V2): the same composable as Classic; the password is sent once and never kept. -->
        <V2Dialog
            :open="asking !== null"
            title="Protect the file with a password"
            :description="
                asking
                    ? `${asking.kind === 'bank' ? 'Bank file' : 'Accountant summary'} for ${asking.run.group} · ${asking.run.month}. KPOne never keeps this password, so note it somewhere safe: without it the file cannot be opened.`
                    : ''
            "
            :confirm-label="
                exporting ? 'Preparing…' : 'Download protected file'
            "
            :busy="!!exporting"
            :confirm-disabled="passwordMissing !== ''"
            :confirm-disabled-reason="passwordMissing"
            @update:open="(open: boolean) => !open && dismiss()"
            @confirm="download"
        >
            <form
                class="flex flex-col gap-3"
                autocomplete="off"
                @submit.prevent="download"
            >
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    :label="`Password (at least ${MIN_PASSWORD} characters)`"
                    :error="secret.passwordError"
                    required
                >
                    <input
                        :id="id"
                        v-model="secret.password"
                        :type="secret.show ? 'text' : 'password'"
                        autocomplete="new-password"
                        :class="inputClass"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        data-test="export-password-input"
                    />
                </V2Field>
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Type the password again"
                    :error="secret.confirmError"
                    required
                >
                    <input
                        :id="id"
                        v-model="secret.confirm"
                        :type="secret.show ? 'text' : 'password'"
                        autocomplete="new-password"
                        :class="inputClass"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        data-test="export-password-again"
                    />
                </V2Field>
                <V2SwitchRow v-model="secret.show" label="Show password" />
                <p
                    v-if="secret.error"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ secret.error }}
                </p>
                <div class="flex flex-col gap-1 text-xs text-muted-foreground">
                    <p>
                        Open it with 7-Zip or WinZip on Windows, or The
                        Unarchiver on a Mac. The built-in zip opener cannot open
                        protected files.
                    </p>
                    <p>
                        Many bank portals take only the CSV. Unzip it before
                        uploading.
                    </p>
                </div>
                <!-- Enter in a field sends the form; the pinned footer button is the one people see and hear. -->
                <button
                    type="submit"
                    class="sr-only"
                    tabindex="-1"
                    aria-hidden="true"
                >
                    Download protected file
                </button>
            </form>
        </V2Dialog>

        <!-- Pay group (V2) -->
        <V2Dialog
            :open="groupForm.open"
            :title="
                groupForm.group
                    ? `Change ${groupForm.group.name}`
                    : 'Add a pay group'
            "
            description="Each pay profile belongs to one pay group."
            :confirm-label="groupForm.processing ? 'Saving…' : 'Save pay group'"
            :busy="groupForm.processing"
            :confirm-disabled="groupMissing !== ''"
            :confirm-disabled-reason="groupMissing"
            @update:open="(v: boolean) => (groupForm.open = v)"
            @confirm="saveGroup"
        >
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
            <V2Field
                v-slot="{ id, describedBy, invalid }"
                label="Name"
                :error="errors.name"
                required
            >
                <input
                    :id="id"
                    v-model="groupForm.name"
                    maxlength="60"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid"
                />
            </V2Field>
            <V2Field
                v-slot="{ id, describedBy, invalid }"
                label="Pay day (optional)"
                hint="Day of the month, 1 to 31."
                :error="errors.pay_day"
            >
                <input
                    :id="id"
                    v-model="groupForm.payDay"
                    inputmode="numeric"
                    :class="inputClass"
                    class="w-24 text-right tabular-nums"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid"
                />
            </V2Field>
            <V2SwitchRow
                v-if="groupForm.group"
                v-model="groupForm.isActive"
                label="In use"
            />
        </V2Dialog>

        <!-- Pay item type (V2) -->
        <V2Dialog
            :open="typeForm.open"
            :title="
                typeForm.type
                    ? `Change ${typeForm.type.name}`
                    : 'Add a pay item type'
            "
            description="Saves a new version; profiles keep the version they used."
            :confirm-label="
                typeForm.processing
                    ? 'Saving…'
                    : typeForm.type
                      ? 'Save new version'
                      : 'Save pay item type'
            "
            :busy="typeForm.processing"
            :confirm-disabled="typeMissing !== ''"
            :confirm-disabled-reason="typeMissing"
            @update:open="(v: boolean) => (typeForm.open = v)"
            @confirm="saveType"
        >
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
            <V2Field
                v-slot="{ id, describedBy, invalid }"
                label="Name"
                :error="errors.name"
                required
            >
                <input
                    :id="id"
                    v-model="typeForm.name"
                    maxlength="60"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid"
                />
            </V2Field>
            <div class="grid gap-1.5">
                <label id="v2-type-kind-label" class="text-sm font-medium"
                    >Kind
                    <span class="text-destructive" aria-hidden="true"
                        >*</span
                    ></label
                >
                <OperationalSelect
                    v-model="typeForm.kind"
                    labelledby="v2-type-kind-label"
                    label="Kind"
                    trigger-class="max-lg:h-11"
                    :options="payroll.options.kinds"
                    :invalid="!!errors.kind"
                />
                <InputError :message="errors.kind" />
            </div>
            <fieldset class="flex flex-col gap-1">
                <legend class="mb-1 text-sm font-medium">
                    Counts as wages for
                </legend>
                <V2SwitchRow
                    v-for="f in schemeFlags"
                    :key="f.key"
                    v-model="typeForm.flags[f.key]"
                    :label="f.label"
                />
            </fieldset>
            <V2SwitchRow
                v-if="typeForm.type"
                v-model="typeForm.isActive"
                label="In use"
            />
        </V2Dialog>
    </PageFrame>
</template>
