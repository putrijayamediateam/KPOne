<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import CompliancePanel from '@/components/hr/compliance/CompliancePanel.vue';
import ComplianceRules from '@/components/hr/compliance/ComplianceRules.vue';
import InputError from '@/components/InputError.vue';
import { DateField } from '@/components/ui/date-field';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2DisclosureBar from '@/components/v2/V2DisclosureBar.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2RowMenu from '@/components/v2/V2RowMenu.vue';
import type { RowMenuGroup } from '@/components/v2/V2RowMenu.vue';
import V2StickyBar from '@/components/v2/V2StickyBar.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { useRosterPage } from '@/composables/hr/useRosterPage';
import type { RosterProps, Version } from '@/composables/hr/useRosterPage';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 Roster (shown only in V2; Classic is `pages/HR/Roster.vue`, untouched). Same props, routes, permissions,
 * lock versions and idempotency keys: every request comes from `useRosterPage`, which Classic uses too. No backend
 * change. The planner shows day cards until Kavitha's V2WeekGrid lands (Daniel, 2026-11-13).
 */
const props = defineProps<RosterProps>();

const {
    errors,
    skipped,
    tabs,
    planner,
    shown,
    statusLabel,
    visit,
    changeTab,
    changeBranch,
    versionLine,
    dialog,
    dialogText,
    openDialog,
    confirmDialog,
    sheetOpen,
    editing,
    removeOpen,
    shiftForm,
    openAdd,
    openEdit,
    saveShift,
    removing,
    removeError,
    removeShift,
    staffOptions,
    roomOptions,
    sessionOptions,
    dayShifts,
    isShort,
    timeText,
    minimumForm,
    saveMinimums,
    minimumsEmpty,
    onLeave,
    minimumError,
} = useRosterPage(props);

usePageCrumbs(() => [{ title: 'KPOne HR', href: '/hr' }, { title: 'Roster' }]);

const statusTone: Record<
    Version['status'],
    'neutral' | 'info' | 'success' | 'warning' | 'danger'
> = {
    draft: 'neutral',
    submitted: 'info',
    approved: 'success',
    superseded: 'neutral',
    discarded: 'neutral',
};

// One filled action on the planner: the first the server allows, in this order.
type Primary = 'approve' | 'submit' | 'start' | null;
const primary = computed<Primary>(() => {
    const can = planner.value?.can;

    if (!can) {
        return null;
    }

    if (can.decide) {
        return 'approve';
    }

    if (can.submit) {
        return 'submit';
    }

    if (can.start && !shown.value) {
        return 'start';
    }

    return null;
});
const primaryLabel: Record<Exclude<Primary, null>, string> = {
    approve: 'Approve week',
    submit: 'Send for approval',
    start: 'Start draft',
};

const moreGroups = computed<RowMenuGroup[]>(() => {
    const can = planner.value?.can;

    if (!can) {
        return [];
    }

    return can.discard
        ? [
              {
                  label: 'Danger',
                  items: [
                      {
                          id: 'discard',
                          label: 'Discard draft',
                          allowed: true,
                          tone: 'danger',
                      },
                  ],
              },
          ]
        : [];
});

const checks = computed(() => planner.value?.warnings ?? []);
const checkCount = computed(
    () =>
        checks.value.length +
        (planner.value?.compliance?.warnings ?? []).reduce(
            (n, w) => n + (w.count ?? 1),
            0,
        ),
);
const checkTitle = computed(() =>
    checkCount.value === 1
        ? '1 thing to check before approval'
        : `${checkCount.value} things to check before approval`,
);

const minimumStep = (session: string, role: string, by: number) => {
    const current = Number(minimumForm.values[session][role]) || 0;
    minimumForm.values[session][role] = Math.min(20, Math.max(0, current + by));
};

const button =
    'inline-flex h-11 items-center justify-center gap-1.5 rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9';
const primaryButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50 lg:h-9';
const textLink =
    'inline-flex min-h-11 items-center text-sm font-medium text-primary underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9';
const numberClass =
    'h-11 w-20 rounded-[10px] border border-input bg-card px-2 text-center text-base tabular-nums outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
</script>

<template>
    <Head title="Roster" />
    <PageFrame>
        <V2WorkLayout
            title="Roster"
            description="Your shifts, and the week plan for each branch."
            rail-from="2xl"
            rail-label="Check before approval"
        >
            <template #actions>
                <div
                    v-if="roster.tab === 'my' && roster.my"
                    class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row"
                >
                    <Link
                        v-if="roster.my.canClock"
                        href="/hr/attendance"
                        :class="primaryButton"
                        data-test="roster-clock-link"
                        >Clock in</Link
                    >
                    <Link
                        v-if="roster.my.canAskOpenShifts"
                        href="/hr/open-shifts"
                        :class="button"
                        data-test="roster-open-shifts-link"
                        >Open shifts</Link
                    >
                </div>
                <div
                    v-else-if="roster.tab === 'planner' && planner"
                    class="flex flex-wrap items-center gap-2"
                    data-test="planner-actions"
                >
                    <button
                        v-if="primary"
                        type="button"
                        :class="[primaryButton, 'max-sm:hidden']"
                        @click="openDialog(primary)"
                    >
                        {{ primaryLabel[primary] }}
                    </button>
                    <button
                        v-if="planner.can.decide"
                        type="button"
                        :class="button"
                        @click="openDialog('return')"
                    >
                        Return to draft
                    </button>
                    <button
                        v-if="planner.can.edit"
                        type="button"
                        :class="button"
                        @click="openAdd()"
                    >
                        Add shift
                    </button>
                    <button
                        v-if="planner.can.change"
                        type="button"
                        :class="button"
                        @click="openDialog('change')"
                    >
                        Change this week
                    </button>
                    <button
                        v-if="planner.can.start && planner.can.copy && !shown"
                        type="button"
                        :class="button"
                        @click="openDialog('copy')"
                    >
                        Copy last approved week
                    </button>
                    <V2RowMenu
                        v-if="moreGroups.length"
                        label="More week actions"
                        :groups="moreGroups"
                        @select="openDialog('discard')"
                    />
                </div>
            </template>

            <template v-if="roster.tab === 'planner' && checkCount > 0" #rail>
                <div class="flex flex-col gap-3" data-test="roster-warnings">
                    <p class="text-sm font-semibold">{{ checkTitle }}</p>
                    <ul class="flex flex-col gap-2 text-sm">
                        <li
                            v-for="warning in checks"
                            :key="warning"
                            class="flex flex-col gap-1"
                        >
                            <V2Chip tone="warning" label="Check" />
                            <span>{{ warning }}</span>
                        </li>
                    </ul>
                    <CompliancePanel
                        v-if="planner?.compliance"
                        :compliance="planner.compliance"
                        :branch="planner.branch.name"
                    />
                </div>
            </template>

            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <V2Tabs
                        v-if="tabs.length > 1"
                        :model-value="roster.tab"
                        :tabs="tabs"
                        ariaLabel="Roster sections"
                        @update:model-value="changeTab"
                    />

                    <div
                        v-if="roster.tab !== 'minimums'"
                        class="flex flex-wrap items-center gap-2"
                        data-test="week-switcher"
                    >
                        <button
                            type="button"
                            :class="button"
                            @click="visit({ week: roster.week.previous })"
                        >
                            <span aria-hidden="true">‹</span> Previous<span
                                class="max-sm:sr-only"
                                >week</span
                            >
                        </button>
                        <button
                            type="button"
                            :class="button"
                            @click="visit({ week: roster.week.today })"
                        >
                            This week
                        </button>
                        <button
                            type="button"
                            :class="button"
                            @click="visit({ week: roster.week.next })"
                        >
                            Next<span class="max-sm:sr-only">week</span>
                            <span aria-hidden="true">›</span>
                        </button>
                        <span
                            class="text-sm font-medium"
                            data-test="week-label"
                            >{{ roster.week.label }}</span
                        >
                    </div>

                    <!-- My roster: one column -->
                    <section
                        v-if="roster.tab === 'my' && roster.my"
                        class="flex w-full max-w-[48rem] flex-col gap-3"
                        aria-label="My shifts"
                    >
                        <ul
                            v-if="credentialReminders?.length"
                            class="flex flex-col gap-1 rounded-[14px] border border-warning/40 bg-warning/10 p-3 text-sm"
                            data-test="credential-reminders"
                        >
                            <li v-for="r in credentialReminders" :key="r">
                                <Link
                                    href="/hr/credentials"
                                    class="underline underline-offset-2"
                                    >{{ r }}</Link
                                >
                            </li>
                        </ul>
                        <p class="text-[13px] text-muted-foreground">
                            Your approved shifts. Times are in each branch's
                            time.
                        </p>
                        <p
                            v-if="roster.my.days.length === 0"
                            class="rounded-[14px] border bg-card p-6 text-center text-sm text-muted-foreground"
                        >
                            No approved shifts this week.
                        </p>
                        <V2FormCard
                            v-for="day in roster.my.days"
                            :key="day.date"
                            :title="day.label"
                        >
                            <ul class="flex flex-col divide-y">
                                <li
                                    v-for="shift in day.shifts"
                                    :key="shift.id"
                                    class="flex flex-col gap-0.5 py-2 first:pt-0 last:pb-0"
                                >
                                    <span class="font-medium tabular-nums">{{
                                        timeText(shift)
                                    }}</span>
                                    <span class="text-sm">{{
                                        shift.branch
                                    }}</span>
                                    <span
                                        class="text-[13px] text-muted-foreground"
                                    >
                                        {{ shift.sessionLabel
                                        }}<template
                                            v-if="shift.breakMinutes > 0"
                                        >
                                            · Break
                                            {{ shift.breakMinutes }}
                                            min</template
                                        ><template v-if="shift.roomName">
                                            · {{ shift.roomName }}</template
                                        >
                                    </span>
                                </li>
                            </ul>
                        </V2FormCard>
                    </section>

                    <!-- Planner -->
                    <section
                        v-if="roster.tab === 'planner' && planner"
                        class="flex min-w-0 flex-col gap-4"
                        aria-label="Planner"
                    >
                        <div class="flex flex-wrap items-end gap-3">
                            <div class="w-full sm:w-56">
                                <V2Field v-slot="{ id }" label="Branch">
                                    <OperationalSelect
                                        :id="id"
                                        :model-value="planner.branch.id"
                                        :options="
                                            planner.branches.map((b) => ({
                                                value: b.id,
                                                label: b.name,
                                            }))
                                        "
                                        label="Branch"
                                        trigger-class="h-11 w-full lg:h-9"
                                        @update:model-value="changeBranch"
                                    />
                                </V2Field>
                            </div>
                            <V2Chip
                                v-if="shown"
                                :tone="statusTone[shown.status]"
                                :label="statusLabel[shown.status]"
                                data-test="version-status"
                            />
                            <div
                                v-if="
                                    planner.approvedVersion &&
                                    planner.openVersion
                                "
                                class="flex gap-2"
                                role="group"
                                aria-label="Version shown"
                            >
                                <button
                                    type="button"
                                    :class="[
                                        button,
                                        shown?.id === planner.approvedVersion.id
                                            ? 'border-foreground/60 bg-muted'
                                            : '',
                                    ]"
                                    :aria-pressed="
                                        shown?.id === planner.approvedVersion.id
                                    "
                                    @click="visit({ view: 'approved' })"
                                >
                                    Approved (v{{
                                        planner.approvedVersion.number
                                    }})
                                </button>
                                <button
                                    type="button"
                                    :class="[
                                        button,
                                        shown?.id === planner.openVersion.id
                                            ? 'border-foreground/60 bg-muted'
                                            : '',
                                    ]"
                                    :aria-pressed="
                                        shown?.id === planner.openVersion.id
                                    "
                                    @click="visit({ view: 'open' })"
                                >
                                    {{
                                        planner.openVersion.status ===
                                        'submitted'
                                            ? 'Waiting'
                                            : 'Draft'
                                    }}
                                    (v{{ planner.openVersion.number }})
                                </button>
                            </div>
                        </div>
                        <p
                            v-if="shown"
                            class="text-[13px] text-muted-foreground"
                            data-test="version-line"
                        >
                            {{ versionLine
                            }}<template
                                v-if="
                                    shown.returnReason &&
                                    shown.status === 'draft'
                                "
                            >
                                · returned: {{ shown.returnReason }}</template
                            >
                        </p>
                        <p
                            v-if="
                                shown?.status === 'submitted' &&
                                !planner.can.decide
                            "
                            class="text-[13px] text-muted-foreground"
                            data-test="waiting-note"
                        >
                            {{
                                shown.sentByYou
                                    ? 'You sent this week, so someone else must approve it.'
                                    : 'Waiting for the Director to approve.'
                            }}
                        </p>
                        <InputError :message="errors.roster" />

                        <!-- Below 1536 px the rail's checks sit here, above the days (Daniel). -->
                        <V2DisclosureBar
                            v-if="checkCount > 0"
                            class="2xl:hidden"
                            :title="checkTitle"
                            tone="warning"
                        >
                            <ul class="flex flex-col gap-2 text-sm">
                                <li v-for="warning in checks" :key="warning">
                                    {{ warning }}
                                </li>
                            </ul>
                            <CompliancePanel
                                v-if="planner?.compliance"
                                :compliance="planner.compliance"
                                :branch="planner.branch.name"
                            />
                        </V2DisclosureBar>

                        <V2DisclosureBar
                            v-if="skipped.length > 0"
                            title="Some shifts were not copied"
                            tone="warning"
                            default-open
                        >
                            <ul class="list-disc pl-5 text-sm">
                                <li v-for="note in skipped" :key="note">
                                    {{ note }}
                                </li>
                            </ul>
                        </V2DisclosureBar>

                        <p
                            v-if="!shown"
                            class="rounded-[14px] border bg-card p-6 text-center text-sm text-muted-foreground"
                        >
                            No roster for {{ planner.branch.name }} this week.
                            {{
                                planner.can.start
                                    ? 'Start a draft, or copy the last approved week.'
                                    : 'Nothing has been planned for this week yet.'
                            }}
                        </p>

                        <ul
                            v-else
                            class="grid gap-3 md:grid-cols-2 2xl:grid-cols-3"
                            data-test="planner-days"
                        >
                            <li
                                v-for="(day, index) in planner.days"
                                :key="day.date"
                                class="flex min-w-0 flex-col gap-2 rounded-[14px] border bg-card p-4"
                            >
                                <div
                                    class="flex items-center justify-between gap-2"
                                >
                                    <h3 class="text-sm font-semibold">
                                        {{ day.long }}
                                    </h3>
                                    <span
                                        class="flex items-center gap-2 text-[13px] text-muted-foreground"
                                        data-test="day-total"
                                    >
                                        {{ planner.totals[index] }} on shift
                                        <V2Chip
                                            v-if="isShort(day.date)"
                                            tone="warning"
                                            label="Short"
                                        />
                                    </span>
                                </div>
                                <p
                                    v-if="onLeave(day.date).length > 0"
                                    class="text-[13px] text-muted-foreground"
                                    data-test="on-leave-day"
                                >
                                    On leave: {{ onLeave(day.date).join(', ') }}
                                </p>
                                <p
                                    v-if="dayShifts(day.date).length === 0"
                                    class="text-[13px] text-muted-foreground"
                                >
                                    No shifts.
                                </p>
                                <ul v-else class="flex flex-col divide-y">
                                    <li
                                        v-for="item in dayShifts(day.date)"
                                        :key="item.shift.id"
                                        class="flex items-center justify-between gap-3 py-2"
                                    >
                                        <div class="min-w-0 text-sm">
                                            <p class="font-medium break-words">
                                                {{ item.row.name }}
                                            </p>
                                            <p
                                                class="text-[13px] text-muted-foreground"
                                            >
                                                {{ item.row.role }} ·
                                                <span class="tabular-nums">{{
                                                    timeText(item.shift)
                                                }}</span>
                                                ·
                                                {{ item.shift.sessionLabel
                                                }}<template
                                                    v-if="item.shift.roomName"
                                                >
                                                    ·
                                                    {{
                                                        item.shift.roomName
                                                    }}</template
                                                >
                                            </p>
                                        </div>
                                        <button
                                            v-if="planner.can.edit"
                                            type="button"
                                            :class="button"
                                            :aria-label="`Edit shift: ${item.row.name}, ${day.long}, ${item.shift.startLabel} to ${item.shift.endLabel}`"
                                            @click="openEdit(item.shift)"
                                        >
                                            Edit
                                        </button>
                                    </li>
                                </ul>
                                <button
                                    v-if="planner.can.edit"
                                    type="button"
                                    :class="[textLink, 'self-start']"
                                    @click="openAdd(day.date)"
                                >
                                    Add shift<span class="sr-only">
                                        on {{ day.long }}</span
                                    >
                                </button>
                            </li>
                        </ul>

                        <V2StickyBar v-if="primary">
                            <button
                                type="button"
                                :class="primaryButton"
                                @click="openDialog(primary)"
                            >
                                {{ primaryLabel[primary] }}
                            </button>
                        </V2StickyBar>
                    </section>

                    <!-- Minimum staffing -->
                    <section
                        v-if="roster.tab === 'minimums' && roster.minimums"
                        class="flex w-full max-w-[48rem] flex-col gap-4"
                        aria-label="Minimum staffing"
                    >
                        <p class="text-[13px] text-muted-foreground">
                            Shown as a warning on the planner. It never blocks
                            approval.
                        </p>
                        <div class="w-full sm:w-56">
                            <V2Field v-slot="{ id }" label="Branch">
                                <OperationalSelect
                                    :id="id"
                                    :model-value="roster.minimums.branch.id"
                                    :options="
                                        roster.minimums.branches.map((b) => ({
                                            value: b.id,
                                            label: b.name,
                                        }))
                                    "
                                    label="Branch"
                                    trigger-class="h-11 w-full lg:h-9"
                                    @update:model-value="changeBranch"
                                />
                            </V2Field>
                        </div>
                        <InputError :message="errors.minimums" />
                        <p
                            v-if="minimumsEmpty"
                            class="text-[13px] text-muted-foreground"
                        >
                            No minimums set for
                            {{ roster.minimums.branch.name }} yet. Every day
                            shows as covered.
                        </p>
                        <V2FormCard
                            v-for="session in roster.sessions"
                            :key="session.value"
                            :title="session.label"
                        >
                            <div class="grid gap-3 sm:grid-cols-3">
                                <V2Field
                                    v-for="role in roster.minimums.roles"
                                    :key="role.value"
                                    v-slot="{ id, describedBy, invalid }"
                                    :label="role.plural"
                                    :error="
                                        minimumError(session.value, role.value)
                                    "
                                >
                                    <div class="flex items-center gap-2">
                                        <button
                                            type="button"
                                            :class="[
                                                button,
                                                'w-11 px-0 lg:w-9',
                                            ]"
                                            :aria-label="`Fewer ${role.plural}, ${session.label}`"
                                            @click="
                                                minimumStep(
                                                    session.value,
                                                    role.value,
                                                    -1,
                                                )
                                            "
                                        >
                                            −
                                        </button>
                                        <input
                                            :id="id"
                                            v-model="
                                                minimumForm.values[
                                                    session.value
                                                ][role.value]
                                            "
                                            type="number"
                                            inputmode="numeric"
                                            min="0"
                                            max="20"
                                            :aria-describedby="describedBy"
                                            :aria-invalid="invalid"
                                            :class="numberClass"
                                        />
                                        <button
                                            type="button"
                                            :class="[
                                                button,
                                                'w-11 px-0 lg:w-9',
                                            ]"
                                            :aria-label="`More ${role.plural}, ${session.label}`"
                                            @click="
                                                minimumStep(
                                                    session.value,
                                                    role.value,
                                                    1,
                                                )
                                            "
                                        >
                                            +
                                        </button>
                                    </div>
                                </V2Field>
                            </div>
                        </V2FormCard>
                        <div>
                            <button
                                type="button"
                                :class="
                                    minimumForm.isDirty ? primaryButton : button
                                "
                                :disabled="minimumForm.processing"
                                @click="saveMinimums"
                            >
                                {{
                                    minimumForm.processing
                                        ? 'Saving…'
                                        : 'Save minimums'
                                }}
                            </button>
                        </div>
                        <ComplianceRules
                            v-if="roster.minimums.compliance"
                            :settings="roster.minimums.compliance"
                            :branch="roster.minimums.branch"
                            :week="roster.week.start"
                        />
                    </section>
                </div>
            </template>
        </V2WorkLayout>

        <!-- Week actions -->
        <V2Dialog
            :open="dialog.open"
            :title="dialogText.title"
            :description="dialogText.description"
            :confirm-label="
                dialog.processing ? dialogText.busy : dialogText.confirm
            "
            :tone="dialog.action === 'discard' ? 'danger' : 'default'"
            :busy="dialog.processing"
            @update:open="dialog.open = $event"
            @confirm="confirmDialog"
        >
            <V2OptionGroup
                v-if="dialog.action === 'return' && planner"
                v-model="dialog.reason"
                :options="planner.returnReasons"
                label="Reason"
                required
            />
            <InputError :message="dialog.error" />
        </V2Dialog>

        <!-- Add or edit a shift -->
        <V2Dialog
            :open="sheetOpen"
            :title="editing ? 'Edit shift' : 'Add shift'"
            :description="`${planner?.branch.name ?? ''} · ${roster.week.label}`"
            :confirm-label="shiftForm.processing ? 'Saving…' : 'Save shift'"
            :busy="shiftForm.processing"
            @update:open="sheetOpen = $event"
            @confirm="saveShift"
        >
            <div class="grid gap-4">
                <V2Field
                    v-slot="{ id, describedBy }"
                    label="Staff member"
                    required
                    :error="shiftForm.errors.staff_user_id"
                >
                    <OperationalSelect
                        :id="id"
                        v-model="shiftForm.staff_user_id"
                        :options="staffOptions"
                        label="Staff member"
                        placeholder="Choose a staff member"
                        :describedby="describedBy"
                        :invalid="!!shiftForm.errors.staff_user_id"
                        trigger-class="h-11 w-full lg:h-9"
                    />
                </V2Field>
                <V2Field
                    v-slot="{ id, invalid }"
                    label="Date"
                    required
                    :error="shiftForm.errors.date"
                >
                    <DateField
                        :id="id"
                        v-model="shiftForm.date"
                        :min="roster.week.start"
                        :max="roster.week.end"
                        :aria-invalid="invalid ? 'true' : undefined"
                    />
                </V2Field>
                <div class="grid gap-4 sm:grid-cols-2">
                    <V2Field
                        v-slot="{ id, describedBy, invalid }"
                        label="Start time"
                        required
                        :error="shiftForm.errors.start_time"
                    >
                        <input
                            :id="id"
                            v-model="shiftForm.start_time"
                            type="time"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            :class="inputClass"
                        />
                    </V2Field>
                    <V2Field
                        v-slot="{ id, describedBy, invalid }"
                        label="End time"
                        required
                        hint="If the end is earlier than the start, the shift ends the next day."
                        :error="shiftForm.errors.end_time"
                    >
                        <input
                            :id="id"
                            v-model="shiftForm.end_time"
                            type="time"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            :class="inputClass"
                        />
                    </V2Field>
                </div>
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Break (minutes)"
                    hint="Unpaid break inside the shift."
                    :error="shiftForm.errors.break_minutes"
                >
                    <input
                        :id="id"
                        v-model="shiftForm.break_minutes"
                        type="number"
                        inputmode="numeric"
                        min="0"
                        max="180"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="[numberClass, 'w-32 text-left']"
                    />
                </V2Field>
                <V2Field
                    v-slot="{ id, describedBy }"
                    label="Session"
                    required
                    :error="shiftForm.errors.session_type"
                >
                    <OperationalSelect
                        :id="id"
                        v-model="shiftForm.session_type"
                        :options="sessionOptions"
                        label="Session"
                        :describedby="describedBy"
                        :invalid="!!shiftForm.errors.session_type"
                        trigger-class="h-11 w-full lg:h-9"
                    />
                </V2Field>
                <V2Field
                    v-slot="{ id, describedBy }"
                    label="Proposed room"
                    hint="A plan only. The doctor still chooses their room on the day."
                    :error="shiftForm.errors.branch_room_id"
                >
                    <OperationalSelect
                        :id="id"
                        v-model="shiftForm.branch_room_id"
                        :options="roomOptions"
                        label="Proposed room"
                        :describedby="describedBy"
                        :invalid="!!shiftForm.errors.branch_room_id"
                        trigger-class="h-11 w-full lg:h-9"
                    />
                </V2Field>
                <InputError :message="errors.roster" />
                <button
                    v-if="editing"
                    type="button"
                    :class="[textLink, 'self-start text-destructive']"
                    @click="
                        removeError = '';
                        removeOpen = true;
                    "
                >
                    Remove shift
                </button>
            </div>
        </V2Dialog>

        <V2Dialog
            :open="removeOpen"
            title="Remove this shift?"
            description="The shift is taken out of this draft."
            :confirm-label="removing ? 'Removing…' : 'Remove shift'"
            tone="danger"
            :busy="removing"
            @update:open="removeOpen = $event"
            @confirm="removeShift"
        >
            <InputError :message="removeError" />
        </V2Dialog>
    </PageFrame>
</template>
