<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import FileChooseButton from '@/components/catalogue/FileChooseButton.vue';
import MaskedValue from '@/components/hr/records/MaskedValue.vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { useApplicationsPage } from '@/composables/hr/useApplicationsPage';
import type { ApplicationsPageProps } from '@/composables/hr/useApplicationsPage';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 Job applications (shown only in V2; Classic is `pages/HR/Applications.vue`, untouched). Same props, routes,
 * permissions, lock versions and idempotency keys: every request comes from `useApplicationsPage`, which Classic uses
 * too, so this page sends none of its own. Contact details arrive masked and are shown only through the same audited
 * Show component as Classic (a Show records the view first); a CV opens only through its audited link; nothing is
 * prefetched, cached or put in props. This file is built in two commits: the list, "needs you" and the application
 * sheet first; positions, the talent pool and settings second.
 */
const props = defineProps<ApplicationsPageProps>();
usePageCrumbs(() => [{ title: 'Job applications' }]);

const {
    errors,
    failure,
    tabs,
    selectTab,
    list,
    statusFilter,
    positionFilter,
    filter,
    pages,
    tone,
    needsYouCount,
    interviewAction,
    interviewPost,
    extending,
    extendError,
    extendDeadline,
    focusOn,
    showAll,
    viewing,
    moving,
    openApplication,
    screening,
    rejecting,
    recordPass,
    askReject,
    confirmReject,
    arrangedOutside,
    moveSteps,
    confirming,
    askMove,
    move,
    label,
    accountAction,
    accountPost,
    locumConfirm,
    locumAnnounce,
    locumError,
    askLocumFile,
    makeLocumFile,
    positionForm,
    branchOptions,
    startAdd,
    startEdit,
    savePosition,
    checklist,
    saveChecklist,
    checklistDirty,
    retentionDirty,
    retention,
    saveRetention,
    poolDays,
    poolDaysDirty,
    savePoolDays,
    invitePick,
    inviting,
    inviteProcessing,
    askInvite,
    sendInvite,
    invitationLink,
    linkCopied,
    copyInvitationLink,
    DOCS_MAX,
    documentForm,
    documentPicker,
    documentFull,
    chooseDocument,
    uploadDocument,
    removingDocument,
    removeDocumentProcessing,
    removeDocument,
} = useApplicationsPage(props);

const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const dangerButton = `${button} text-destructive`;
const primaryButton =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
const closing = ['not_taken_forward', 'withdrawn', 'passed', 'failed'];
const chip = (status: string) => {
    const t = tone(status);

    return (t === 'accent' ? 'info' : t) as
        'neutral' | 'info' | 'success' | 'warning' | 'danger';
};
const needsYouGroups = computed(() =>
    [
        {
            key: 'today',
            title: 'Interviews today',
            data: props.applications.needsYou.today,
        },
        {
            key: 'picks',
            title: 'Times to confirm',
            data: props.applications.needsYou.picks,
        },
    ].filter((g) => g.data.count),
);
// The stage's own action is the one filled button; a screening form has its own, so no move button is filled then.
const filledMove = computed(() => {
    if (viewing.value?.status === 'phone_screening') {
        return null;
    }

    return (
        moveSteps.value.find(
            (s) => !closing.includes(s.value) && !arrangedOutside(s.value),
        )?.value ?? null
    );
});
const rejectMissing = computed(() =>
    rejecting.reason === '' ? 'Pick a reason to continue.' : '',
);
const cancelMissing = computed(() =>
    interviewAction.reason === '' ? 'Pick a reason to continue.' : '',
);
const ratingMissing = computed(() =>
    screening.rating === '' ? 'Record how they came across to continue.' : '',
);
const ratingOptions = [1, 2, 3, 4, 5].map((n) => ({
    value: String(n),
    label: String(n),
}));
</script>

<template>
    <Head title="Job applications" />
    <PageFrame>
        <V2WorkLayout
            title="Job applications"
            description="Applications sent from the public form, the positions shown on it, and how long applications are kept. Turning an applicant into staff stays with the account administrator."
        >
            <template v-if="applications.tab === 'positions'" #actions>
                <button
                    type="button"
                    :class="primaryButton"
                    data-test="position-add"
                    @click="startAdd"
                >
                    Add a position
                </button>
            </template>
            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <SyntheticNote />

                    <p
                        class="rounded-[14px] border bg-card p-3 text-sm"
                        data-test="applications-form-state"
                    >
                        <template v-if="applications.form.state === 'on'">
                            The public form is <strong>on</strong> at
                            <span class="break-all">{{
                                applications.form.url
                            }}</span
                            >.
                        </template>
                        <template
                            v-else-if="
                                applications.form.state === 'needs_retention'
                            "
                        >
                            The public form is <strong>off</strong> until you
                            set how long applications are kept (Settings),
                            because the privacy notice promises deletion after
                            that period.
                        </template>
                        <template v-else>
                            The public form is <strong>off</strong>. Nobody can
                            apply until the privacy notice is approved and the
                            form is switched on.
                        </template>
                        <span class="text-muted-foreground">
                            Privacy notice version
                            {{ applications.form.privacyNoticeVersion }}.</span
                        >
                        <span
                            class="block text-muted-foreground"
                            data-test="hiring-email-state"
                        >
                            <template v-if="applications.email.enabled">
                                Hiring email is on: candidates get each message
                                by email as well as on their private
                                page.<template
                                    v-if="!applications.email.mailbox"
                                >
                                    No hiring mailbox is set, so reminders show
                                    here only.</template
                                >
                            </template>
                            <template v-else>
                                Hiring email is off: candidates see the clinic's
                                messages on their private page only.
                            </template>
                        </span>
                    </p>

                    <V2Tabs
                        :model-value="applications.tab"
                        :tabs="tabs"
                        ariaLabel="Job application sections"
                        @update:model-value="selectTab"
                    />

                    <p
                        v-if="failure && !viewing"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ failure }}
                    </p>

                    <!-- Applications -->
                    <section
                        v-if="applications.tab === 'applications' && list"
                        aria-labelledby="v2-applications-title"
                        class="flex flex-col gap-3"
                        data-test="applications-list"
                    >
                        <h2 id="v2-applications-title" class="sr-only">
                            Applications
                        </h2>
                        <section
                            v-if="
                                needsYouCount > 0 ||
                                applications.needsYou.noWorkingDays
                            "
                            aria-labelledby="v2-needs-you-title"
                            class="flex flex-col gap-3 rounded-[14px] border bg-card p-4"
                            data-test="needs-you"
                        >
                            <h3
                                id="v2-needs-you-title"
                                class="text-base font-semibold"
                            >
                                Needs you
                                <span
                                    v-if="needsYouCount"
                                    class="font-normal text-muted-foreground"
                                    >({{ needsYouCount }})</span
                                >
                            </h3>
                            <p
                                v-if="applications.needsYou.noWorkingDays"
                                class="rounded-[10px] border border-warning/40 bg-warning/10 p-2 text-sm"
                                data-test="needs-you-no-working-days"
                            >
                                A branch has no working days set in KPOne HR
                                leave settings, so its applications are never
                                closed automatically. Set the working days to
                                start the 3-day count.
                            </p>
                            <div
                                v-for="group in needsYouGroups"
                                :key="group.key"
                                class="flex flex-col gap-1.5"
                                :data-test="`needs-you-${group.key}`"
                            >
                                <h4 class="text-sm font-medium">
                                    {{ group.title }} ({{ group.data.count }})
                                </h4>
                                <ul class="flex flex-col gap-1.5">
                                    <li
                                        v-for="row in group.data.rows"
                                        :key="row.id"
                                        class="flex flex-wrap items-center justify-between gap-2 rounded-[10px] border p-2"
                                    >
                                        <div class="min-w-0">
                                            <p
                                                class="text-sm font-medium [overflow-wrap:anywhere]"
                                            >
                                                {{ row.name }} ·
                                                {{ row.position }}
                                            </p>
                                            <p
                                                class="text-sm text-muted-foreground"
                                            >
                                                {{ row.why }}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            :class="button"
                                            :aria-label="`Open the application from ${row.name}`"
                                            @click="focusOn(row)"
                                        >
                                            Open
                                        </button>
                                    </li>
                                </ul>
                            </div>
                            <!-- The urgent ones in full; new applications as one line, since the list below shows them. -->
                            <div
                                v-if="applications.needsYou.closing.count"
                                class="flex flex-col gap-1.5"
                                data-test="needs-you-closing"
                            >
                                <h4 class="text-sm font-medium">
                                    Closing at the start of the next working day
                                    ({{ applications.needsYou.closing.count }})
                                </h4>
                                <ul class="flex flex-col gap-1.5">
                                    <li
                                        v-for="row in applications.needsYou
                                            .closing.rows"
                                        :key="row.id"
                                        class="flex flex-wrap items-center justify-between gap-2 rounded-[10px] border p-2"
                                    >
                                        <div class="min-w-0">
                                            <p
                                                class="text-sm font-medium [overflow-wrap:anywhere]"
                                            >
                                                {{ row.name }} ·
                                                {{ row.position }}
                                            </p>
                                            <p
                                                class="text-sm text-muted-foreground"
                                            >
                                                {{ row.why }}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            :class="button"
                                            :aria-label="`Open the application from ${row.name}`"
                                            @click="focusOn(row)"
                                        >
                                            Open
                                        </button>
                                    </li>
                                </ul>
                                <p
                                    v-if="
                                        applications.needsYou.closing.count >
                                        applications.needsYou.closing.rows
                                            .length
                                    "
                                    class="text-sm text-muted-foreground"
                                >
                                    And
                                    {{
                                        applications.needsYou.closing.count -
                                        applications.needsYou.closing.rows
                                            .length
                                    }}
                                    more in the list below.
                                </p>
                            </div>
                            <p
                                v-if="applications.needsYou.new.count"
                                class="flex flex-wrap items-center gap-x-2 text-sm"
                                data-test="needs-you-new"
                            >
                                {{ applications.needsYou.new.count }} new
                                {{
                                    applications.needsYou.new.count === 1
                                        ? 'application'
                                        : 'applications'
                                }}
                                <span aria-hidden="true">·</span>
                                <button
                                    type="button"
                                    :class="button"
                                    data-test="needs-you-new-show"
                                    @click="filter({ status: 'new' })"
                                >
                                    Show
                                </button>
                            </p>
                        </section>
                        <p
                            v-if="list.filters.focus"
                            class="flex flex-wrap items-center gap-2 text-sm"
                            data-test="applications-focus"
                        >
                            Showing one application.
                            <button
                                type="button"
                                class="inline-flex min-h-11 items-center text-primary underline underline-offset-4 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9"
                                @click="showAll"
                            >
                                Show all open applications
                            </button>
                        </p>
                        <div class="grid gap-3 sm:grid-cols-2 lg:max-w-3xl">
                            <div class="flex flex-col gap-1.5">
                                <label
                                    id="v2-filter-status-label"
                                    class="text-sm font-medium"
                                    >Step</label
                                >
                                <OperationalSelect
                                    :model-value="list.filters.status"
                                    :options="statusFilter"
                                    label="Step"
                                    labelledby="v2-filter-status-label"
                                    trigger-class="max-lg:min-h-11"
                                    @update:model-value="
                                        (v) => filter({ status: String(v) })
                                    "
                                />
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label
                                    id="v2-filter-position-label"
                                    class="text-sm font-medium"
                                    >Position</label
                                >
                                <OperationalSelect
                                    :model-value="
                                        list.filters.position || 'all'
                                    "
                                    :options="positionFilter"
                                    label="Position"
                                    labelledby="v2-filter-position-label"
                                    trigger-class="max-lg:min-h-11"
                                    @update:model-value="
                                        (v) => filter({ position: String(v) })
                                    "
                                />
                            </div>
                        </div>

                        <div
                            v-if="!list.rows.length"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">No applications here.</p>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    applications.form.state === 'on'
                                        ? 'Applications sent from the public form appear here.'
                                        : 'The public form is off, so no applications arrive yet.'
                                }}
                            </p>
                        </div>
                        <ul v-else class="flex flex-col gap-2">
                            <li
                                v-for="row in list.rows"
                                :key="row.id"
                                class="flex flex-wrap items-center justify-between gap-3 rounded-[14px] border bg-card p-3"
                                data-test="application-row"
                            >
                                <div class="min-w-0">
                                    <p
                                        class="font-medium [overflow-wrap:anywhere]"
                                    >
                                        {{
                                            row.purged
                                                ? 'Details deleted'
                                                : row.name
                                        }}
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        {{ row.position }} · sent
                                        {{ row.submittedAt }}
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <V2Chip
                                        :tone="chip(row.status)"
                                        :label="row.statusLabel"
                                    />
                                    <button
                                        type="button"
                                        :class="button"
                                        :aria-label="`Open the application from ${row.purged ? 'a deleted applicant' : row.name}`"
                                        data-test="application-open"
                                        @click="openApplication(row)"
                                    >
                                        Open
                                    </button>
                                </div>
                            </li>
                        </ul>
                        <nav
                            v-if="pages > 1"
                            class="flex items-center justify-between gap-2 text-sm"
                            aria-label="Application pages"
                        >
                            <button
                                type="button"
                                :class="button"
                                :disabled="list.page <= 1"
                                @click="filter({ page: list.page - 1 })"
                            >
                                Previous
                            </button>
                            <span class="text-center tabular-nums"
                                >Page {{ list.page }} of {{ pages }} ·
                                {{ list.total }} applications</span
                            >
                            <button
                                type="button"
                                :class="button"
                                :disabled="list.page >= pages"
                                @click="filter({ page: list.page + 1 })"
                            >
                                Next
                            </button>
                        </nav>
                    </section>

                    <!-- Positions -->
                    <section
                        v-if="applications.tab === 'positions'"
                        aria-labelledby="v2-positions-title"
                        class="flex flex-col gap-3"
                        data-test="positions"
                    >
                        <h2
                            id="v2-positions-title"
                            class="text-base font-semibold"
                        >
                            Positions
                        </h2>
                        <div
                            v-if="!applications.positions.length"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">No positions yet.</p>
                            <p class="text-sm text-muted-foreground">
                                Add the positions you are hiring for. Only open
                                positions show on the public form.
                            </p>
                        </div>
                        <ul v-else class="flex flex-col gap-2">
                            <li
                                v-for="position in applications.positions"
                                :key="position.id"
                                class="flex flex-wrap items-center justify-between gap-3 rounded-[14px] border bg-card p-3"
                                data-test="position-row"
                            >
                                <div class="min-w-0">
                                    <p
                                        class="font-medium [overflow-wrap:anywhere]"
                                    >
                                        {{ position.title }}
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        {{ position.typeLabel }} ·
                                        {{ position.branch ?? 'Any branch' }} ·
                                        {{ position.openApplications }} open of
                                        {{ position.applications }}
                                        {{
                                            position.applications === 1
                                                ? 'application'
                                                : 'applications'
                                        }}
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <V2Chip
                                        :tone="
                                            position.isOpen
                                                ? 'success'
                                                : 'neutral'
                                        "
                                        :label="
                                            position.isOpen ? 'Open' : 'Closed'
                                        "
                                    />
                                    <button
                                        type="button"
                                        :class="button"
                                        :aria-label="`Edit ${position.title}`"
                                        data-test="position-edit"
                                        @click="startEdit(position)"
                                    >
                                        Edit
                                    </button>
                                </div>
                            </li>
                        </ul>
                    </section>

                    <!-- Talent pool (HR-17e) -->
                    <section
                        v-if="applications.tab === 'pool'"
                        aria-labelledby="v2-pool-title"
                        class="flex flex-col gap-3"
                        data-test="talent-pool"
                    >
                        <div class="flex flex-col gap-1">
                            <h2
                                id="v2-pool-title"
                                class="text-base font-semibold"
                            >
                                Talent pool
                            </h2>
                            <p class="max-w-3xl text-sm text-muted-foreground">
                                Candidates who ticked the optional talent-pool
                                box and whose application closed as Passed or
                                Failed, kept for the period set in Settings. You
                                can invite them to an open position of the same
                                type; that starts a new application at phone
                                screening with the same answers and CV. They can
                                leave the pool on their private page at any
                                time.
                            </p>
                        </div>
                        <div
                            v-if="invitationLink"
                            class="flex max-w-3xl flex-col gap-2 rounded-[14px] border bg-card p-3 text-sm"
                            data-test="pool-invitation-link"
                        >
                            <p>
                                <strong
                                    >The candidate's new private link, shown
                                    once. Give it only to the candidate, and do
                                    not keep it.</strong
                                >
                                {{
                                    applications.email.enabled
                                        ? 'It was also emailed to them.'
                                        : 'Hiring email is off, so pass it on yourself, by phone or WhatsApp.'
                                }}
                            </p>
                            <div class="flex flex-wrap items-center gap-2">
                                <input
                                    :value="invitationLink"
                                    readonly
                                    aria-label="New private link"
                                    :class="`${inputClass} min-w-0 flex-1`"
                                />
                                <button
                                    type="button"
                                    :class="button"
                                    @click="copyInvitationLink"
                                >
                                    Copy
                                </button>
                            </div>
                            <p
                                v-if="linkCopied"
                                role="status"
                                class="text-muted-foreground"
                            >
                                {{
                                    linkCopied === 'copied'
                                        ? 'Copied.'
                                        : 'Copy did not work here. The link is selected: copy it by hand.'
                                }}
                            </p>
                        </div>
                        <p
                            v-if="errors.position || errors.application"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ errors.position ?? errors.application }}
                        </p>
                        <div
                            v-if="applications.pool.days === null"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">No talent pool period set</p>
                            <p class="text-sm text-muted-foreground">
                                Until a period is set in Settings, nobody is
                                kept in the pool beyond the usual retention.
                            </p>
                        </div>
                        <div
                            v-else-if="applications.pool.members.length === 0"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">Nobody in the talent pool</p>
                            <p class="text-sm text-muted-foreground">
                                A candidate appears here once their application
                                closes as Passed or Failed, if they ticked the
                                box.
                            </p>
                        </div>
                        <ul v-else class="flex flex-col gap-2">
                            <li
                                v-for="member in applications.pool.members"
                                :key="member.id"
                                class="flex flex-col gap-3 rounded-[14px] border bg-card p-3 text-sm lg:flex-row lg:items-center lg:justify-between"
                                data-test="pool-member"
                            >
                                <div class="flex min-w-0 flex-col gap-0.5">
                                    <span
                                        class="font-medium [overflow-wrap:anywhere]"
                                        >{{ member.name }}</span
                                    >
                                    <span class="text-muted-foreground"
                                        >{{ member.position }} ·
                                        {{ member.resultLabel }}
                                        {{ member.closedOn }} · in the pool
                                        until {{ member.until }}</span
                                    >
                                    <span
                                        v-if="member.invitedTo.length"
                                        class="text-muted-foreground"
                                        >Invited to
                                        {{ member.invitedTo.join(', ') }}</span
                                    >
                                </div>
                                <div
                                    v-if="member.positions.length"
                                    class="flex flex-wrap items-center gap-2"
                                >
                                    <span
                                        :id="`v2-pool-pick-${member.id}`"
                                        class="sr-only"
                                        >Position to invite
                                        {{ member.name }} to</span
                                    >
                                    <OperationalSelect
                                        :model-value="
                                            invitePick[member.id] ?? ''
                                        "
                                        :options="member.positions"
                                        label="Open position"
                                        :labelledby="`v2-pool-pick-${member.id}`"
                                        placeholder="Choose a position"
                                        trigger-class="min-w-48 max-lg:min-h-11"
                                        @update:model-value="
                                            (v) =>
                                                (invitePick[member.id] =
                                                    String(v))
                                        "
                                    />
                                    <button
                                        type="button"
                                        :class="button"
                                        :disabled="!invitePick[member.id]"
                                        data-test="pool-invite"
                                        @click="askInvite(member)"
                                    >
                                        Invite
                                    </button>
                                    <span
                                        v-if="!invitePick[member.id]"
                                        class="text-sm text-muted-foreground"
                                        data-test="pool-invite-why"
                                        >Choose a position first.</span
                                    >
                                </div>
                                <p v-else class="text-muted-foreground">
                                    No open position of this type.
                                </p>
                            </li>
                        </ul>
                    </section>

                    <!-- Settings: how long applications are kept -->
                    <section
                        v-if="applications.tab === 'settings'"
                        aria-labelledby="v2-settings-title"
                        class="flex max-w-xl flex-col gap-3 rounded-[14px] border bg-card p-4"
                        data-test="applications-settings"
                    >
                        <h2
                            id="v2-settings-title"
                            class="text-base font-semibold"
                        >
                            How long applications are kept
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Days before the applicant's details and CV are
                            deleted, counted from when the application closed
                            (Passed, Failed, rejected or Withdrawn), or from
                            when it was sent if nobody closed it. KPOne ships no
                            number. Leave it empty to keep applications.
                        </p>
                        <p class="text-sm" data-test="retention-in-force">
                            In force:
                            <strong>{{
                                applications.settings.retentionDays === null
                                    ? 'Not set (kept)'
                                    : `${applications.settings.retentionDays} days`
                            }}</strong>
                        </p>
                        <p
                            v-if="applications.settings.pending"
                            class="text-sm"
                            data-test="retention-pending"
                        >
                            From {{ applications.settings.pending.from }}:
                            {{
                                applications.settings.pending.days === null
                                    ? 'kept'
                                    : `${applications.settings.pending.days} days`
                            }}. A first or shorter period waits
                            {{ applications.options.graceDays }} days, so a
                            mistake can be undone.
                        </p>
                        <V2Field
                            v-slot="{ id, describedBy }"
                            label="Days after closing"
                            :error="errors.retention_days"
                        >
                            <input
                                :id="id"
                                v-model="retention.days"
                                inputmode="numeric"
                                :class="`${inputClass} max-w-40`"
                                :aria-invalid="
                                    errors.retention_days ? 'true' : undefined
                                "
                                :aria-describedby="describedBy"
                            />
                        </V2Field>
                        <div class="flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                :class="retentionDirty ? primaryButton : button"
                                :disabled="
                                    !retentionDirty || retention.processing
                                "
                                data-test="retention-save"
                                @click="saveRetention"
                            >
                                {{ retention.processing ? 'Saving…' : 'Save' }}
                            </button>
                            <span
                                v-if="retentionDirty"
                                class="text-sm text-muted-foreground"
                                role="status"
                                >Changes not saved yet.</span
                            >
                        </div>
                    </section>

                    <!-- Settings: the talent pool period (HR-17e) -->
                    <section
                        v-if="applications.tab === 'settings'"
                        aria-labelledby="v2-pool-days-title"
                        class="flex max-w-xl flex-col gap-3 rounded-[14px] border bg-card p-4"
                        data-test="pool-settings"
                    >
                        <h2
                            id="v2-pool-days-title"
                            class="text-base font-semibold"
                        >
                            How long the talent pool keeps a candidate
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Days after a Passed or Failed application closes
                            that a candidate who ticked the talent-pool box is
                            kept, and can be invited to a new position. KPOne
                            ships no number. Leave it empty to keep nobody in
                            the pool beyond the usual retention.
                        </p>
                        <p class="text-sm" data-test="pool-days-in-force">
                            In force:
                            <strong>{{
                                applications.pool.days === null
                                    ? 'Not set (no pool)'
                                    : `${applications.pool.days} days`
                            }}</strong>
                        </p>
                        <p v-if="applications.pool.pending" class="text-sm">
                            From {{ applications.pool.pending.from }}:
                            {{
                                applications.pool.pending.days === null
                                    ? 'no pool'
                                    : `${applications.pool.pending.days} days`
                            }}. A first or shorter period waits
                            {{ applications.options.graceDays }} days, so a
                            mistake can be undone.
                        </p>
                        <V2Field
                            v-slot="{ id, describedBy }"
                            label="Days after closing"
                            :error="errors.pool_days"
                        >
                            <input
                                :id="id"
                                v-model="poolDays.days"
                                inputmode="numeric"
                                :class="`${inputClass} max-w-40`"
                                :aria-invalid="
                                    errors.pool_days ? 'true' : undefined
                                "
                                :aria-describedby="describedBy"
                            />
                        </V2Field>
                        <div class="flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                :class="poolDaysDirty ? primaryButton : button"
                                :disabled="
                                    !poolDaysDirty || poolDays.processing
                                "
                                data-test="pool-days-save"
                                @click="savePoolDays"
                            >
                                {{ poolDays.processing ? 'Saving…' : 'Save' }}
                            </button>
                            <span
                                v-if="poolDaysDirty"
                                class="text-sm text-muted-foreground"
                                role="status"
                                >Changes not saved yet.</span
                            >
                        </div>
                    </section>

                    <!-- Settings: the phone-screening call checklist (HR-17a) -->
                    <section
                        v-if="applications.tab === 'settings'"
                        aria-labelledby="v2-checklist-title"
                        class="flex max-w-xl flex-col gap-3 rounded-[14px] border bg-card p-4"
                        data-test="screening-checklist"
                    >
                        <h2
                            id="v2-checklist-title"
                            class="text-base font-semibold"
                        >
                            Phone screening checklist
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            The questions you ask on the call, one per line, up
                            to 15. KPOne ships none. Each save is a new version;
                            calls already recorded keep the version they used.
                        </p>
                        <V2Field
                            v-slot="{ id, describedBy }"
                            label="Questions"
                            :error="errors.items"
                        >
                            <textarea
                                :id="id"
                                v-model="checklist.text"
                                rows="6"
                                class="w-full rounded-[10px] border border-input bg-card px-3 py-2 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px]"
                                placeholder="For example: Speaks clearly and politely"
                                :aria-invalid="
                                    errors.items ? 'true' : undefined
                                "
                                :aria-describedby="describedBy"
                            ></textarea>
                        </V2Field>
                        <div class="flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                :class="checklistDirty ? primaryButton : button"
                                :disabled="
                                    !checklistDirty || checklist.processing
                                "
                                data-test="checklist-save"
                                @click="saveChecklist"
                            >
                                {{ checklist.processing ? 'Saving…' : 'Save' }}
                            </button>
                            <span
                                v-if="checklistDirty"
                                class="text-sm text-muted-foreground"
                                role="status"
                                >Changes not saved yet.</span
                            >
                        </div>
                    </section>

                    <!-- Settings: interview documents (HR-17d) -->
                    <section
                        v-if="applications.tab === 'settings'"
                        aria-labelledby="v2-interview-docs-title"
                        class="flex max-w-xl flex-col gap-4 rounded-[14px] border bg-card p-4"
                        data-test="interview-documents"
                    >
                        <div class="flex flex-col gap-1">
                            <h2
                                id="v2-interview-docs-title"
                                class="text-base font-semibold"
                            >
                                Interview documents
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                PDFs a candidate can download from their private
                                page once their interview is confirmed, for
                                their position type only. Up to
                                {{ DOCS_MAX }} per type, 10 MB each. Each
                                download is recorded. Do not upload a document
                                that contains a person's private details.
                            </p>
                        </div>
                        <div
                            v-for="group in applications.interviewDocuments"
                            :key="group.type"
                            class="flex flex-col gap-2"
                            :data-test="`interview-documents-${group.type}`"
                        >
                            <h3 class="text-sm font-semibold">
                                {{ group.label }}
                                <span class="font-normal text-muted-foreground"
                                    >· {{ group.documents.length }} of
                                    {{ DOCS_MAX }}</span
                                >
                            </h3>
                            <p
                                v-if="group.documents.length === 0"
                                class="text-sm text-muted-foreground"
                            >
                                None yet.
                            </p>
                            <ul v-else class="flex flex-col gap-2">
                                <li
                                    v-for="doc in group.documents"
                                    :key="doc.id"
                                    class="flex flex-wrap items-center justify-between gap-2 rounded-[10px] border p-2 text-sm"
                                >
                                    <div class="flex min-w-0 flex-col">
                                        <a
                                            :href="`/hr/applications/interview-documents/${doc.id}`"
                                            class="inline-flex min-h-11 items-center font-medium [overflow-wrap:anywhere] text-primary underline underline-offset-4 lg:min-h-0"
                                            >{{ doc.title }}</a
                                        >
                                        <span
                                            class="text-xs text-muted-foreground"
                                            >PDF · {{ doc.size }} · added
                                            {{ doc.uploadedOn }} by
                                            {{ doc.uploadedBy }} · downloaded by
                                            {{ doc.candidates }}
                                            {{
                                                doc.candidates === 1
                                                    ? 'candidate'
                                                    : 'candidates'
                                            }}</span
                                        >
                                    </div>
                                    <button
                                        type="button"
                                        :class="dangerButton"
                                        :aria-label="`Remove ${doc.title}`"
                                        data-test="interview-document-remove"
                                        @click="
                                            removingDocument = {
                                                id: doc.id,
                                                title: doc.title,
                                                lockVersion: doc.lockVersion,
                                            }
                                        "
                                    >
                                        Remove
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <form
                            class="flex flex-col gap-3 border-t pt-4"
                            data-test="interview-document-add"
                            @submit.prevent="uploadDocument"
                        >
                            <h3 class="text-sm font-semibold">
                                Add a document
                            </h3>
                            <div class="flex flex-col gap-1.5">
                                <label
                                    id="v2-interview-doc-type-label"
                                    class="text-sm font-medium"
                                    >Position type</label
                                >
                                <OperationalSelect
                                    v-model="documentForm.type"
                                    :options="applications.options.types"
                                    label="Position type"
                                    labelledby="v2-interview-doc-type-label"
                                    trigger-class="max-lg:min-h-11"
                                />
                                <p
                                    v-if="errors.position_type"
                                    role="alert"
                                    class="text-sm text-destructive"
                                >
                                    {{ errors.position_type }}
                                </p>
                            </div>
                            <V2Field
                                v-slot="{ id, describedBy }"
                                label="Title"
                                :error="errors.title"
                            >
                                <input
                                    :id="id"
                                    v-model="documentForm.title"
                                    maxlength="80"
                                    :class="inputClass"
                                    placeholder="For example: Clinic induction booklet"
                                    :aria-invalid="
                                        errors.title ? 'true' : undefined
                                    "
                                    :aria-describedby="describedBy"
                                />
                            </V2Field>
                            <FileChooseButton
                                ref="documentPicker"
                                accept="application/pdf,.pdf"
                                :label="
                                    documentForm.file
                                        ? 'Choose another PDF'
                                        : 'Choose PDF'
                                "
                                :status="
                                    documentForm.file
                                        ? documentForm.file.name
                                        : 'No PDF chosen'
                                "
                                status-id="v2-interview-doc-file-status"
                                :disabled="documentForm.processing"
                                @change="chooseDocument"
                            />
                            <p
                                v-if="errors.file"
                                role="alert"
                                class="text-sm text-destructive"
                            >
                                {{ errors.file }}
                            </p>
                            <p
                                v-if="documentFull"
                                class="text-sm text-muted-foreground"
                                data-test="interview-document-full"
                            >
                                This position type has {{ DOCS_MAX }} documents.
                                Remove one to add another.
                            </p>
                            <div class="flex flex-wrap items-center gap-3">
                                <button
                                    type="submit"
                                    :class="button"
                                    :disabled="
                                        documentForm.processing ||
                                        documentFull ||
                                        !documentForm.file ||
                                        documentForm.title.trim().length < 3
                                    "
                                    data-test="interview-document-upload"
                                >
                                    {{
                                        documentForm.processing
                                            ? 'Adding…'
                                            : 'Add document'
                                    }}
                                </button>
                                <span
                                    v-if="
                                        !documentFull &&
                                        (!documentForm.file ||
                                            documentForm.title.trim().length <
                                                3)
                                    "
                                    class="text-sm text-muted-foreground"
                                    data-test="interview-document-why"
                                    >Choose a PDF and give it a title
                                    first.</span
                                >
                            </div>
                        </form>
                    </section>
                </div>
            </template>
        </V2WorkLayout>

        <!-- One application -->
        <V2Dialog
            :open="viewing !== null"
            :title="
                viewing?.purged
                    ? 'Application (details deleted)'
                    : (viewing?.name ?? '')
            "
            :description="
                viewing
                    ? `${viewing.position} · sent ${viewing.submittedAt}`
                    : ''
            "
            size="tall"
            confirm-label="Close"
            single-action
            single-action-style="outline"
            @update:open="(open: boolean) => !open && (viewing = null)"
            @confirm="viewing = null"
        >
            <template v-if="viewing">
                <p
                    v-if="(errors.to || failure) && !locumConfirm"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ errors.to ?? failure }}
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <V2Chip
                        :tone="chip(viewing.status)"
                        :label="viewing.statusLabel"
                    />
                    <span
                        v-if="viewing.closedAt"
                        class="text-sm text-muted-foreground"
                        >Closed {{ viewing.closedAt }}</span
                    >
                </div>
                <p
                    v-if="viewing.autoRejectOn"
                    class="rounded-[10px] text-sm"
                    :class="
                        viewing.autoRejectSoon
                            ? 'border border-warning/40 bg-warning/10 p-2 text-foreground'
                            : 'text-muted-foreground'
                    "
                    :role="viewing.autoRejectSoon ? 'status' : undefined"
                    data-test="application-auto-reject"
                >
                    {{
                        viewing.status === 'scheduling'
                            ? 'If the candidate does not choose times,'
                            : 'Without a decision,'
                    }}
                    KPOne closes this as Auto-rejected on
                    {{ viewing.autoRejectOn }} and tells the candidate.
                </p>
                <!-- HR gives more time; the count restarts from today. -->
                <div
                    v-if="viewing.autoRejectOn"
                    class="flex flex-col items-start gap-1"
                >
                    <button
                        type="button"
                        :class="button"
                        :disabled="
                            extending ||
                            viewing.extensionsUsed >= viewing.extensionsMax
                        "
                        aria-describedby="v2-application-extend-help"
                        data-test="application-extend"
                        @click="extendDeadline"
                    >
                        Give {{ viewing.extendDays }} more working days
                    </button>
                    <p
                        id="v2-application-extend-help"
                        class="text-xs text-muted-foreground"
                        data-test="application-extend-help"
                    >
                        <template
                            v-if="
                                viewing.extensionsUsed >= viewing.extensionsMax
                            "
                            >More time has been given
                            {{ viewing.extensionsMax }} times in this stage.
                            Close the application or move it on; for a real
                            exception, ask the Director.</template
                        >
                        <template v-else
                            >{{ viewing.extensionsUsed }} of
                            {{ viewing.extensionsMax }} extensions used. The 3
                            working days count again from today. Nothing is sent
                            to the candidate.</template
                        >
                    </p>
                    <p
                        v-if="extendError"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ extendError }}
                    </p>
                </div>

                <!-- The candidate's times at Scheduling, the confirmed interview at Interview. -->
                <section
                    v-if="viewing.interview"
                    class="flex flex-col gap-3 rounded-[10px] border p-3"
                    aria-labelledby="v2-interview-title"
                    data-test="application-interview"
                >
                    <h3 id="v2-interview-title" class="font-semibold">
                        Interview
                        <span
                            v-if="viewing.interview.branch"
                            class="font-normal text-muted-foreground"
                            >· {{ viewing.interview.branch }}</span
                        >
                    </h3>
                    <template v-if="viewing.interview.confirmed">
                        <p class="text-sm">
                            Confirmed for
                            <strong>{{ viewing.interview.confirmed }}</strong
                            >.
                        </p>
                        <button
                            type="button"
                            :class="`${button} w-fit`"
                            :disabled="interviewAction.processing"
                            data-test="interview-cancel"
                            @click="
                                Object.assign(interviewAction, {
                                    cancelOpen: true,
                                    reason: '',
                                })
                            "
                        >
                            Cancel and ask for new times
                        </button>
                        <div
                            class="flex flex-col gap-1"
                            data-test="interview-downloads"
                        >
                            <h4 class="text-sm font-medium">
                                Interview documents
                            </h4>
                            <p
                                v-if="!viewing.interview.documents?.length"
                                class="text-sm text-muted-foreground"
                            >
                                None for this position type. Add them in
                                Settings.
                            </p>
                            <ul v-else class="flex flex-col gap-1 text-sm">
                                <li
                                    v-for="(doc, i) in viewing.interview
                                        .documents"
                                    :key="i"
                                >
                                    {{ doc.title }} ·
                                    <span
                                        :class="
                                            doc.downloadedAt
                                                ? ''
                                                : 'text-muted-foreground'
                                        "
                                        >{{
                                            doc.downloadedAt
                                                ? `downloaded ${doc.downloadedAt}`
                                                : 'not downloaded yet'
                                        }}</span
                                    >
                                </li>
                            </ul>
                        </div>
                    </template>
                    <template v-else-if="viewing.interview.picks.length">
                        <p class="text-sm text-muted-foreground">
                            The candidate's three times. Confirm one; the
                            candidate is told.
                        </p>
                        <ul class="flex flex-col gap-2">
                            <li
                                v-for="pick in viewing.interview.picks"
                                :key="pick.start"
                                class="flex flex-wrap items-center justify-between gap-2 rounded-[10px] border p-2 text-sm"
                            >
                                <span>
                                    {{ pick.label }}
                                    <span
                                        v-if="pick.taken || pick.past"
                                        class="text-muted-foreground"
                                        >·
                                        {{
                                            pick.taken
                                                ? 'already taken'
                                                : 'passed'
                                        }}</span
                                    >
                                </span>
                                <button
                                    type="button"
                                    :class="button"
                                    :disabled="
                                        pick.taken ||
                                        pick.past ||
                                        interviewAction.processing
                                    "
                                    :aria-label="`Confirm ${pick.label}`"
                                    data-test="interview-confirm"
                                    @click="
                                        confirming = {
                                            start: pick.start,
                                            label: pick.label,
                                        }
                                    "
                                >
                                    Confirm
                                </button>
                            </li>
                        </ul>
                        <button
                            type="button"
                            :class="`${button} w-fit`"
                            :disabled="interviewAction.processing"
                            data-test="interview-ask-again"
                            @click="interviewPost('ask-again')"
                        >
                            None suit: ask for new times
                        </button>
                    </template>
                    <p v-else class="text-sm text-muted-foreground">
                        Waiting for the candidate to choose three times on their
                        private page.
                    </p>
                    <p
                        v-if="errors.start"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ errors.start }}
                    </p>
                </section>

                <!-- The stage's own action comes first; the application's details follow. -->
                <form
                    v-if="viewing.status === 'phone_screening'"
                    class="flex flex-col gap-3 rounded-[10px] border p-3"
                    aria-labelledby="v2-screening-form-title"
                    data-test="screening-form"
                    @submit.prevent="recordPass"
                >
                    <h3
                        id="v2-screening-form-title"
                        class="text-sm font-semibold"
                    >
                        Record the phone call
                    </h3>
                    <fieldset
                        v-if="applications.checklist.items.length"
                        class="flex flex-col gap-1"
                    >
                        <legend class="mb-1 text-sm text-muted-foreground">
                            Tick what the candidate showed on the call
                        </legend>
                        <label
                            v-for="(item, i) in applications.checklist.items"
                            :key="item"
                            class="flex min-h-11 items-center gap-3 text-sm"
                        >
                            <input
                                v-model="screening.ticked"
                                type="checkbox"
                                :value="i"
                                class="size-5"
                            />
                            {{ item }}
                        </label>
                        <p
                            v-if="errors.ticked"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ errors.ticked }}
                        </p>
                    </fieldset>
                    <p v-else class="text-sm text-muted-foreground">
                        No call checklist yet. Add the questions you ask in
                        Settings.
                    </p>
                    <V2OptionGroup
                        :model-value="screening.rating || null"
                        :options="ratingOptions"
                        label="How did they come across? (1 Weak, 5 Strong)"
                        required
                        :error="errors.rating"
                        data-test="screening-rating"
                        @update:model-value="screening.rating = $event ?? ''"
                    />
                    <div class="flex flex-col gap-1.5">
                        <label
                            for="v2-screening-note"
                            class="text-sm font-medium"
                            >Note (optional)</label
                        >
                        <textarea
                            id="v2-screening-note"
                            v-model="screening.note"
                            rows="3"
                            maxlength="500"
                            class="w-full rounded-[10px] border border-input bg-card px-3 py-2 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px]"
                            aria-describedby="v2-screening-note-hint"
                        ></textarea>
                        <p
                            id="v2-screening-note-hint"
                            class="text-xs text-muted-foreground"
                        >
                            Only HR sees it. Do not note health details or
                            anything about pay.
                        </p>
                        <p
                            v-if="errors.note"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ errors.note }}
                        </p>
                    </div>
                    <p
                        v-if="errors.outcome"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ errors.outcome }}
                    </p>
                    <p
                        v-if="ratingMissing"
                        id="v2-screening-pass-hint"
                        class="text-sm text-muted-foreground"
                        data-test="screening-pass-hint"
                    >
                        {{ ratingMissing }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="submit"
                            :class="primaryButton"
                            :disabled="
                                screening.processing || ratingMissing !== ''
                            "
                            :aria-describedby="
                                ratingMissing
                                    ? 'v2-screening-pass-hint'
                                    : undefined
                            "
                            data-test="screening-pass"
                        >
                            {{
                                screening.processing &&
                                screening.outcome === 'pass'
                                    ? 'Saving…'
                                    : 'Pass: schedule the interview'
                            }}
                        </button>
                        <button
                            type="button"
                            :class="dangerButton"
                            :disabled="screening.processing"
                            data-test="screening-reject"
                            @click="askReject"
                        >
                            Reject…
                        </button>
                    </div>
                </form>

                <section
                    v-if="viewing.screening"
                    class="flex flex-col gap-2 rounded-[10px] border p-3 text-sm"
                    aria-labelledby="v2-screening-record-title"
                    data-test="screening-record"
                >
                    <h3 id="v2-screening-record-title" class="font-semibold">
                        Phone screening:
                        {{
                            viewing.screening.outcome === 'pass'
                                ? 'passed'
                                : 'rejected'
                        }}, rating {{ viewing.screening.rating }} of 5
                    </h3>
                    <p v-if="viewing.screening.reason">
                        Reason: {{ viewing.screening.reason }}
                    </p>
                    <ul
                        v-if="viewing.screening.items.length"
                        class="flex flex-col gap-1"
                    >
                        <li
                            v-for="item in viewing.screening.items"
                            :key="item.text"
                        >
                            {{ item.ticked ? 'Yes' : 'No' }} · {{ item.text }}
                        </li>
                    </ul>
                    <p
                        v-if="viewing.screening.note"
                        class="[overflow-wrap:anywhere] whitespace-pre-line"
                    >
                        {{ viewing.screening.note }}
                    </p>
                    <p class="text-muted-foreground">
                        By {{ viewing.screening.by }},
                        {{ viewing.screening.at }}
                    </p>
                </section>

                <section
                    v-if="['passed', 'failed'].includes(viewing.status)"
                    class="flex flex-col gap-2 rounded-[10px] border p-3"
                    aria-labelledby="v2-after-result-title"
                    data-test="application-after-result"
                >
                    <h3
                        id="v2-after-result-title"
                        class="text-sm font-semibold"
                    >
                        After the result
                    </h3>
                    <p class="text-sm" data-test="application-pool-state">
                        {{
                            viewing.poolUntil
                                ? `In the talent pool until ${viewing.poolUntil}.`
                                : viewing.poolConsent
                                  ? 'Ticked the talent pool, but no pool period is set or it has ended.'
                                  : 'Not in the talent pool.'
                        }}
                    </p>
                    <p
                        class="sr-only"
                        role="status"
                        data-test="application-locum-announce"
                    >
                        {{ locumAnnounce }}
                    </p>
                    <template v-if="viewing.status === 'passed'">
                        <div
                            v-if="viewing.locumHire"
                            class="flex flex-col gap-2"
                            data-test="application-locum-hire"
                        >
                            <p class="text-sm">
                                Locum file: {{ viewing.locumHire.name }}
                            </p>
                            <p
                                class="flex items-center gap-2 text-sm text-muted-foreground"
                            >
                                <span
                                    class="size-2 shrink-0 rounded-full bg-warning"
                                    :class="{
                                        'bg-success':
                                            viewing.locumHire.signIn ===
                                            'Sign-in made',
                                    }"
                                    aria-hidden="true"
                                />
                                {{ viewing.locumHire.signIn }}
                            </p>
                            <Link
                                :href="`/hr/records/${viewing.locumHire.workerId}`"
                                :class="`${button} w-full sm:w-auto sm:self-start`"
                                data-test="application-locum-open"
                                >Open the locum file</Link
                            >
                        </div>
                        <p
                            v-else-if="viewing.accountRequest"
                            class="text-sm"
                            data-test="application-account-request"
                        >
                            Staff account:
                            {{ viewing.accountRequest.statusLabel }} ·
                            {{ viewing.accountRequest.at }}
                        </p>
                        <p
                            v-else-if="viewing.type === 'locum'"
                            class="text-sm text-muted-foreground"
                        >
                            No offer is made in KPOne. When they accept, make
                            the locum file. KPOne then asks the account
                            administrator for their sign-in.
                        </p>
                        <p v-else class="text-sm text-muted-foreground">
                            No offer is made in KPOne. When they accept, ask the
                            account administrator for a staff account.
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-if="
                                    viewing.type === 'locum' &&
                                    !viewing.locumHire &&
                                    viewing.accountRequest?.status !== 'open' &&
                                    viewing.accountRequest?.status !== 'done' &&
                                    !viewing.purged
                                "
                                type="button"
                                :class="button"
                                :disabled="accountAction.processing"
                                data-test="application-locum-make"
                                @click="askLocumFile"
                            >
                                Make the locum file and ask for a sign-in
                            </button>
                            <button
                                v-if="
                                    viewing.type !== 'locum' &&
                                    viewing.accountRequest?.status !== 'open' &&
                                    viewing.accountRequest?.status !== 'done' &&
                                    !viewing.purged
                                "
                                type="button"
                                :class="button"
                                :disabled="accountAction.processing"
                                data-test="application-account-ask"
                                @click="
                                    accountPost(
                                        `/hr/applications/${viewing.id}/account-request`,
                                        viewing.lockVersion,
                                    )
                                "
                            >
                                Ask for a staff account
                            </button>
                            <button
                                v-if="viewing.accountRequest?.status === 'open'"
                                type="button"
                                :class="button"
                                :disabled="accountAction.processing"
                                data-test="application-account-cancel"
                                @click="
                                    accountPost(
                                        `/hr/applications/account-requests/${viewing.accountRequest.id}/cancel`,
                                        viewing.accountRequest.lockVersion,
                                    )
                                "
                            >
                                Cancel the request
                            </button>
                        </div>
                    </template>
                </section>

                <div v-if="viewing.next.length" class="flex flex-col gap-2">
                    <h3 class="text-sm font-semibold">Move to</h3>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="step in moveSteps"
                            :key="step.value"
                            type="button"
                            :class="
                                step.value === filledMove
                                    ? primaryButton
                                    : closing.includes(step.value) &&
                                        step.value !== 'passed'
                                      ? dangerButton
                                      : button
                            "
                            :disabled="moving.processing"
                            :data-test="`application-move-${step.value}`"
                            @click="askMove(step.value)"
                        >
                            {{
                                arrangedOutside(step.value)
                                    ? 'Arranged outside KPOne'
                                    : step.label
                            }}
                        </button>
                    </div>
                </div>

                <section
                    class="flex flex-col gap-3 border-t pt-4"
                    aria-labelledby="v2-application-details-title"
                >
                    <h3
                        id="v2-application-details-title"
                        class="text-sm font-semibold"
                    >
                        Application
                    </h3>
                    <dl v-if="!viewing.purged" class="grid gap-3 text-sm">
                        <div>
                            <dt class="text-muted-foreground">Phone</dt>
                            <dd>
                                <MaskedValue
                                    v-if="viewing.phoneMask"
                                    label="Phone"
                                    field="phone"
                                    :mask="viewing.phoneMask"
                                    :last4="
                                        viewing.phoneMask.includes(' ')
                                            ? viewing.phoneMask.slice(-4)
                                            : null
                                    "
                                    worker-id=""
                                    show-field="phone"
                                    :url="`/hr/applications/${viewing.id}/show-contact`"
                                    :reset-key="viewing.id"
                                />
                                <span v-else class="text-muted-foreground"
                                    >None given</span
                                >
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Email</dt>
                            <dd>
                                <MaskedValue
                                    v-if="viewing.emailMask"
                                    label="Email"
                                    field="email"
                                    :mask="viewing.emailMask"
                                    worker-id=""
                                    show-field="email"
                                    :url="`/hr/applications/${viewing.id}/show-contact`"
                                    :reset-key="viewing.id"
                                />
                                <span v-else class="text-muted-foreground"
                                    >None given</span
                                >
                            </dd>
                        </div>
                        <div v-for="d in viewing.details" :key="d.label">
                            <dt class="text-muted-foreground">
                                {{ d.label }}
                            </dt>
                            <dd class="[overflow-wrap:anywhere]">
                                {{ d.value }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Message</dt>
                            <dd
                                class="[overflow-wrap:anywhere] whitespace-pre-line"
                            >
                                {{ viewing.message ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Privacy notice
                            </dt>
                            <dd>Version {{ viewing.privacyNoticeVersion }}</dd>
                        </div>
                    </dl>
                    <p v-else class="text-sm text-muted-foreground">
                        The applicant's details and CV were deleted after the
                        retention period. The step and dates are kept.
                    </p>
                    <a
                        v-if="viewing.hasCv"
                        :href="`/hr/applications/${viewing.id}/cv`"
                        :class="`${button} w-fit`"
                        :aria-label="`Open CV (PDF, ${viewing.cvKb} KB)`"
                        data-test="application-cv"
                        >Open CV ({{ viewing.cvKb }} KB)</a
                    >
                    <p
                        v-if="viewing.hasCv"
                        class="text-xs text-muted-foreground"
                    >
                        Each open of a CV is recorded.
                    </p>
                </section>

                <section
                    class="flex flex-col gap-3 border-t pt-4"
                    aria-labelledby="v2-application-messages-title"
                    data-test="application-messages"
                >
                    <h3
                        id="v2-application-messages-title"
                        class="text-sm font-semibold"
                    >
                        Messages
                    </h3>
                    <p
                        v-if="!viewing.messages.length"
                        class="text-sm text-muted-foreground"
                    >
                        No messages yet.
                    </p>
                    <ul v-else class="flex flex-col gap-2">
                        <li
                            v-for="(m, i) in viewing.messages"
                            :key="i"
                            class="rounded-[10px] border p-3 text-sm"
                        >
                            <details class="group">
                                <summary
                                    class="flex min-h-11 cursor-pointer list-none flex-wrap items-center justify-between gap-x-3 gap-y-1 [&::-webkit-details-marker]:hidden"
                                >
                                    <span
                                        class="flex items-center gap-1 font-medium"
                                    >
                                        <svg
                                            class="size-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-90 motion-reduce:transition-none"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="2"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            aria-hidden="true"
                                        >
                                            <path d="m9 6 6 6-6 6" />
                                        </svg>
                                        {{ m.label }}
                                        <span
                                            class="font-normal text-muted-foreground"
                                            >·
                                            {{
                                                m.recipient === 'mailbox'
                                                    ? 'to the hiring mailbox'
                                                    : 'to the candidate'
                                            }}</span
                                        >
                                    </span>
                                    <span
                                        :class="
                                            m.status === 'failed'
                                                ? 'font-medium text-destructive'
                                                : 'text-muted-foreground'
                                        "
                                        >{{ m.atLabel }} ·
                                        {{ m.statusLabel }}</span
                                    >
                                </summary>
                                <p
                                    class="mt-2 font-medium [overflow-wrap:anywhere]"
                                >
                                    {{ m.subject }}
                                </p>
                                <p
                                    class="mt-1 [overflow-wrap:anywhere] whitespace-pre-line text-muted-foreground"
                                >
                                    {{ m.body }}
                                </p>
                            </details>
                        </li>
                    </ul>
                </section>
            </template>
        </V2Dialog>

        <!-- HR-15d: make the locum file -->
        <V2Dialog
            :open="locumConfirm"
            :title="`Make the locum file for ${viewing?.name ?? ''} and ask for a sign-in?`"
            description="KPOne makes the locum file from the application's name and phone, and asks the account administrator for a sign-in with the email they applied with."
            :confirm-label="
                accountAction.processing ? 'Making…' : 'Make file and ask'
            "
            :busy="accountAction.processing"
            data-test="application-locum-confirm"
            @update:open="(open: boolean) => !open && (locumConfirm = false)"
            @confirm="makeLocumFile"
        >
            <p v-if="locumError" role="alert" class="text-sm text-destructive">
                {{ locumError }}
            </p>
        </V2Dialog>

        <!-- Confirm one interview time -->
        <V2Dialog
            :open="confirming !== null"
            :title="`Confirm ${confirming?.label ?? ''}?`"
            description="The candidate is told the date, time and branch."
            :confirm-label="
                interviewAction.processing ? 'Confirming…' : 'Confirm interview'
            "
            :busy="interviewAction.processing"
            data-test="interview-confirm-dialog"
            @update:open="(open: boolean) => !open && (confirming = null)"
            @confirm="
                confirming &&
                interviewPost('confirm', { start: confirming.start })
            "
        >
            <p
                v-if="errors.start"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ errors.start }}
            </p>
        </V2Dialog>

        <!-- Cancel a confirmed interview -->
        <V2Dialog
            :open="interviewAction.cancelOpen"
            title="Cancel this interview?"
            description="The slot is freed, the application goes back to Scheduling and the candidate is asked for three new times."
            confirm-label="Cancel interview"
            cancel-label="Keep interview"
            tone="danger"
            :busy="interviewAction.processing"
            :confirm-disabled="cancelMissing !== ''"
            :confirm-disabled-reason="cancelMissing"
            @update:open="
                (open: boolean) => (interviewAction.cancelOpen = open)
            "
            @confirm="
                interviewPost('cancel', { reason: interviewAction.reason })
            "
        >
            <V2OptionGroup
                :model-value="interviewAction.reason || null"
                :options="applications.options.cancelReasons"
                label="Why?"
                required
                :error="errors.reason"
                data-test="interview-cancel-reasons"
                @update:model-value="interviewAction.reason = $event ?? ''"
            />
        </V2Dialog>

        <!-- Reject after the phone call -->
        <V2Dialog
            :open="rejecting.open"
            title="Reject after the phone call?"
            description="This closes the application as Not taken forward. A closed application cannot be moved again."
            confirm-label="Reject"
            tone="danger"
            :busy="screening.processing"
            :confirm-disabled="rejectMissing !== ''"
            :confirm-disabled-reason="rejectMissing"
            @update:open="(open: boolean) => (rejecting.open = open)"
            @confirm="confirmReject"
        >
            <V2OptionGroup
                :model-value="rejecting.reason || null"
                :options="applications.options.rejectReasons"
                label="Why?"
                required
                :error="errors.reject_reason ?? errors.rating ?? errors.outcome"
                data-test="reject-reasons"
                @update:model-value="rejecting.reason = $event ?? ''"
            />
        </V2Dialog>

        <!-- A step that closes the application asks first -->
        <V2Dialog
            :open="moving.confirm"
            :title="`Move to ${label(moving.to)}?`"
            :description="
                moving.to === 'passed'
                    ? 'This records that the candidate passed the interview and closes the application. The retention period starts from today. Any offer is made outside KPOne.'
                    : 'This closes the application. A closed application cannot be moved again, and the retention period starts from today.'
            "
            :confirm-label="`Move to ${label(moving.to)}`"
            :tone="moving.to === 'passed' ? 'default' : 'danger'"
            :busy="moving.processing"
            @update:open="(open: boolean) => (moving.confirm = open)"
            @confirm="move"
        />

        <!-- Invite a pool candidate -->
        <V2Dialog
            :open="inviting !== null"
            :title="`Invite ${inviting?.member.name ?? ''} to ${inviting?.position.label ?? ''}?`"
            description="A new application starts at phone screening with the same answers and CV, and the candidate gets a new private link. Call them soon: like any application at phone screening, the daily check closes it as Auto-rejected after 3 working days without a decision."
            :confirm-label="inviteProcessing ? 'Inviting…' : 'Invite'"
            :busy="inviteProcessing"
            @update:open="(open: boolean) => !open && (inviting = null)"
            @confirm="sendInvite"
        />

        <!-- Remove an interview document -->
        <V2Dialog
            :open="removingDocument !== null"
            :title="`Remove ${removingDocument?.title ?? ''}?`"
            description="Candidates can no longer download it, and its file is deleted. The record that it existed and who downloaded it is kept."
            :confirm-label="
                removeDocumentProcessing ? 'Removing…' : 'Remove document'
            "
            cancel-label="Keep document"
            tone="danger"
            :busy="removeDocumentProcessing"
            @update:open="(open: boolean) => !open && (removingDocument = null)"
            @confirm="removeDocument"
        >
            <p
                v-if="errors.document"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ errors.document }}
            </p>
        </V2Dialog>

        <!-- Add or edit a position -->
        <V2Dialog
            :open="positionForm.open"
            :title="positionForm.position ? 'Edit position' : 'Add a position'"
            description="Open positions show on the public form. Closing one keeps its applications."
            :confirm-label="positionForm.processing ? 'Saving…' : 'Save'"
            :busy="positionForm.processing"
            :confirm-disabled="positionForm.title.trim() === ''"
            confirm-disabled-reason="Enter a title to continue."
            @update:open="(open: boolean) => (positionForm.open = open)"
            @confirm="savePosition"
        >
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
            <V2Field
                v-slot="{ id, describedBy }"
                label="Title"
                required
                :error="errors.title"
            >
                <input
                    :id="id"
                    v-model="positionForm.title"
                    maxlength="80"
                    :class="inputClass"
                    :aria-invalid="errors.title ? 'true' : undefined"
                    :aria-describedby="describedBy"
                />
            </V2Field>
            <V2OptionGroup
                :model-value="positionForm.type || null"
                :options="applications.options.types"
                label="Kind of position"
                required
                :error="errors.position_type"
                @update:model-value="positionForm.type = $event ?? ''"
            />
            <p class="text-xs text-muted-foreground">
                Decides the extra questions on the form: an intern's dates, a
                locum's availability, a doctor's MMC number and APC.
            </p>
            <p
                v-if="
                    positionForm.position &&
                    positionForm.position.openApplications > 0 &&
                    positionForm.type !== positionForm.position.type
                "
                role="status"
                class="rounded-[10px] border border-warning/40 bg-warning/10 p-2 text-xs"
                data-test="position-type-warning"
            >
                {{
                    positionForm.position.openApplications === 1
                        ? '1 open application keeps the answers it was sent with.'
                        : `${positionForm.position.openApplications} open applications keep the answers they were sent with.`
                }}
                Only new applications get the new questions.
            </p>
            <V2OptionGroup
                :model-value="positionForm.branchId || null"
                :options="branchOptions"
                label="Branch"
                :error="errors.branch_id"
                @update:model-value="positionForm.branchId = $event ?? 'any'"
            />
            <label
                v-if="positionForm.position"
                class="flex min-h-11 items-center gap-3 text-sm lg:min-h-9"
            >
                <input
                    v-model="positionForm.isOpen"
                    type="checkbox"
                    class="size-5"
                    data-test="position-open"
                />
                Open (shows on the public form)
            </label>
        </V2Dialog>
    </PageFrame>
</template>
