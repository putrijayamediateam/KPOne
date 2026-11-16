<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { useOpenShiftsPage } from '@/composables/hr/useOpenShiftsPage';
import type { OpenShiftsPageProps } from '@/composables/hr/useOpenShiftsPage';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 Open shifts (shown only in V2; Classic is `pages/HR/OpenShifts.vue`, untouched). Same props, routes,
 * permissions, lock versions and idempotency keys: every request comes from `useOpenShiftsPage`, which Classic uses too,
 * so this page sends none of its own. The one filled action is "Post an open shift" (managers); a locum's "Ask for this
 * shift", Confirm, Decline and Withdraw are outline, and the filled button is in the dialog. Red is for decline and
 * withdraw; a decline reason starts empty. A locum never sees who else asked.
 */
const props = defineProps<OpenShiftsPageProps>();
usePageCrumbs(() => [{ title: 'Open shifts' }]);

const {
    errors,
    failure,
    tabs,
    selectTab,
    managed,
    shiftTone,
    askTone,
    busy,
    form,
    startPost,
    missing,
    submit,
    confirming,
    confirm,
    declining,
    decline,
    withdrawing,
    withdraw,
    ask,
    withdrawAsk,
} = useOpenShiftsPage(props);

const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const dangerButton = `${button} text-destructive`;
const primaryButton =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50 lg:min-h-9';
const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';

// The manager sentence belongs to the manager tabs only; a locum reads the locum sentence (Daniel).
const description = computed(() =>
    props.openShifts.tab === 'open' || props.openShifts.tab === 'closed'
        ? 'Post shifts that need a locum. Locums ask; you choose one, and the shift joins the next draft roster for that week.'
        : 'Shifts the clinic needs covered at your branches. Ask for one; HR chooses who takes it.',
);
// V2Chip knows five tones; the server's "accent" reads as info.
const chip = (tone: string) =>
    (tone === 'accent' ? 'info' : tone) as
        'neutral' | 'info' | 'success' | 'warning' | 'danger';
const shiftLabel = (s: { status: string; needsPosting?: boolean }) =>
    s.status === 'open'
        ? 'Open'
        : s.needsPosting
          ? 'Needs posting again'
          : s.status === 'filled'
            ? 'Filled'
            : 'Withdrawn';
const declineMissing = computed(() =>
    declining.reason === '' ? 'Pick a reason to continue.' : '',
);
</script>

<template>
    <Head title="Open shifts" />
    <PageFrame>
        <V2WorkLayout title="Open shifts" :description="description">
            <template v-if="openShifts.canManage" #actions>
                <button
                    type="button"
                    :class="primaryButton"
                    data-test="open-shift-new"
                    @click="startPost"
                >
                    Post an open shift
                </button>
            </template>
            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <SyntheticNote />
                    <p
                        v-if="
                            failure &&
                            !form.open &&
                            !declining.ask &&
                            !confirming.ask &&
                            !withdrawing.shift
                        "
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ failure }}
                    </p>
                    <p
                        v-if="openShifts.canAsk && !openShifts.linked"
                        class="text-sm text-muted-foreground"
                        data-test="open-shift-not-linked"
                    >
                        Your sign-in is not linked to a locum file yet. Ask HR.
                    </p>

                    <V2Tabs
                        v-if="tabs.length > 1"
                        :model-value="openShifts.tab"
                        :tabs="tabs"
                        ariaLabel="Open shift sections"
                        @update:model-value="selectTab"
                    />

                    <!-- A locum: open shifts at their branches -->
                    <template v-if="openShifts.tab === 'available'">
                        <div
                            v-if="openShifts.available.length === 0"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">
                                No open shifts at your branches.
                            </p>
                            <p class="text-sm text-muted-foreground">
                                When HR posts one, it shows here.
                            </p>
                        </div>
                        <ul
                            v-else
                            class="divide-y rounded-[14px] border bg-card"
                            data-test="open-shift-available"
                        >
                            <li
                                v-for="s in openShifts.available"
                                :key="s.id"
                                class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div class="min-w-0">
                                    <p class="font-medium">{{ s.when }}</p>
                                    <p class="text-sm text-muted-foreground">
                                        {{ s.branch }} · {{ s.sessionLabel }} ·
                                        {{ s.roleLabel }}
                                        <template v-if="s.breakMinutes">
                                            · {{ s.breakMinutes }} min
                                            break</template
                                        >
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    :class="button"
                                    :disabled="busy.id === s.id"
                                    :aria-label="`Ask for the shift on ${s.when}`"
                                    data-test="open-shift-ask"
                                    @click="ask(s)"
                                >
                                    {{
                                        busy.id === s.id
                                            ? 'Asking…'
                                            : 'Ask for this shift'
                                    }}
                                </button>
                            </li>
                        </ul>
                    </template>

                    <!-- A locum: their own asks -->
                    <template v-else-if="openShifts.tab === 'mine'">
                        <div
                            v-if="openShifts.mine.length === 0"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">
                                You have not asked for a shift.
                            </p>
                            <p class="text-sm text-muted-foreground">
                                Ask for one on the Open shifts tab.
                            </p>
                        </div>
                        <ul
                            v-else
                            class="divide-y rounded-[14px] border bg-card"
                            data-test="open-shift-mine"
                        >
                            <li
                                v-for="m in openShifts.mine"
                                :key="m.requestId"
                                class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div class="min-w-0">
                                    <p
                                        class="flex flex-wrap items-center gap-2 font-medium"
                                    >
                                        <span>{{ m.when }}</span>
                                        <V2Chip
                                            :tone="
                                                chip(askTone(m.requestStatus))
                                            "
                                            :label="m.requestStatusLabel"
                                        />
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        {{ m.branch }} · {{ m.sessionLabel }} ·
                                        {{ m.roleLabel }}
                                    </p>
                                </div>
                                <button
                                    v-if="m.requestStatus === 'asked'"
                                    type="button"
                                    :class="dangerButton"
                                    :disabled="busy.id === m.requestId"
                                    :aria-label="`Withdraw your ask for ${m.when}`"
                                    data-test="open-shift-ask-withdraw"
                                    @click="withdrawAsk(m)"
                                >
                                    Withdraw
                                </button>
                            </li>
                        </ul>
                    </template>

                    <!-- Managers: open, or filled and withdrawn -->
                    <template v-else>
                        <div
                            v-if="managed.length === 0"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">
                                {{
                                    openShifts.tab === 'closed'
                                        ? 'No filled or withdrawn shifts yet.'
                                        : 'No open shifts.'
                                }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    openShifts.tab === 'closed'
                                        ? 'Shifts move here once a locum is confirmed or the shift is withdrawn.'
                                        : 'Use Post an open shift when a shift needs a locum.'
                                }}
                            </p>
                        </div>
                        <ul
                            v-else
                            class="flex flex-col gap-3"
                            data-test="open-shift-managed"
                        >
                            <li
                                v-for="s in managed"
                                :key="s.id"
                                class="flex flex-col gap-3 rounded-[14px] border bg-card p-4"
                            >
                                <div
                                    class="flex flex-wrap items-start justify-between gap-2"
                                >
                                    <div class="min-w-0">
                                        <p
                                            class="flex flex-wrap items-center gap-2 font-medium"
                                        >
                                            <span>{{ s.when }}</span>
                                            <V2Chip
                                                :tone="
                                                    s.needsPosting
                                                        ? 'warning'
                                                        : chip(
                                                              shiftTone(
                                                                  s.status,
                                                              ),
                                                          )
                                                "
                                                :label="shiftLabel(s)"
                                            />
                                        </p>
                                        <p
                                            v-if="s.needsPosting"
                                            class="text-sm text-muted-foreground"
                                            data-test="open-shift-needs-posting"
                                        >
                                            The roster no longer has this shift
                                            for the confirmed locum. Post it
                                            again if it is still needed.
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            {{ s.branch }} ·
                                            {{ s.sessionLabel }} ·
                                            {{ s.roleLabel }}
                                            <template v-if="s.breakMinutes">
                                                · {{ s.breakMinutes }} min
                                                break</template
                                            >
                                        </p>
                                    </div>
                                    <button
                                        v-if="s.status === 'open'"
                                        type="button"
                                        :class="dangerButton"
                                        :aria-label="`Withdraw the shift on ${s.when}`"
                                        data-test="open-shift-withdraw"
                                        @click="withdrawing.shift = s"
                                    >
                                        Withdraw shift
                                    </button>
                                </div>
                                <p
                                    v-if="s.asks.length === 0"
                                    class="text-sm text-muted-foreground"
                                >
                                    No locum has asked yet.
                                </p>
                                <ul
                                    v-else
                                    class="flex flex-col divide-y rounded-[10px] border"
                                >
                                    <li
                                        v-for="a in s.asks"
                                        :key="a.id"
                                        class="flex flex-col gap-2 p-3 text-sm sm:flex-row sm:items-center sm:justify-between"
                                        data-test="open-shift-ask-row"
                                    >
                                        <div class="min-w-0">
                                            <p
                                                class="flex flex-wrap items-center gap-2 font-medium"
                                            >
                                                <span
                                                    class="[overflow-wrap:anywhere]"
                                                    >{{ a.locum }}</span
                                                >
                                                <V2Chip
                                                    :tone="
                                                        chip(askTone(a.status))
                                                    "
                                                    :label="a.statusLabel"
                                                />
                                            </p>
                                            <p class="text-muted-foreground">
                                                Asked on {{ a.askedOn }}
                                            </p>
                                        </div>
                                        <div
                                            v-if="
                                                a.status === 'asked' &&
                                                s.status === 'open'
                                            "
                                            class="flex flex-wrap gap-2"
                                        >
                                            <button
                                                type="button"
                                                :class="button"
                                                :disabled="a.own || s.started"
                                                :aria-label="`Confirm ${a.locum}`"
                                                data-test="open-shift-confirm"
                                                @click="
                                                    confirming.ask = a;
                                                    confirming.shift = s;
                                                "
                                            >
                                                Confirm
                                            </button>
                                            <button
                                                type="button"
                                                :class="dangerButton"
                                                :disabled="a.own"
                                                :aria-label="`Decline ${a.locum}`"
                                                data-test="open-shift-decline"
                                                @click="
                                                    declining.ask = a;
                                                    declining.reason = '';
                                                "
                                            >
                                                Decline
                                            </button>
                                        </div>
                                        <p
                                            v-if="
                                                a.status === 'asked' &&
                                                s.status === 'open' &&
                                                (a.own || s.started)
                                            "
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{
                                                a.own
                                                    ? 'This is your own ask. Someone else decides it.'
                                                    : 'This shift has started, so it can no longer be confirmed.'
                                            }}
                                        </p>
                                    </li>
                                </ul>
                            </li>
                        </ul>
                    </template>
                </div>
            </template>
        </V2WorkLayout>

        <!-- Confirm one locum -->
        <V2Dialog
            :open="confirming.ask !== null"
            :title="`Confirm ${confirming.ask?.locum ?? ''}?`"
            :description="`The shift on ${confirming.shift?.when ?? ''} joins the draft roster for that week at ${confirming.shift?.branch ?? ''}, and the other asks are declined. The locum sees it on My roster once the week is approved.`"
            :confirm-label="
                busy.id === confirming.ask?.id ? 'Confirming…' : 'Confirm'
            "
            :busy="busy.id === confirming.ask?.id"
            @update:open="(v: boolean) => !v && (confirming.ask = null)"
            @confirm="confirm"
        >
            <p
                v-if="errors.open_shift ?? errors.staff_user_id"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ errors.open_shift ?? errors.staff_user_id }}
            </p>
        </V2Dialog>

        <!-- Decline with a reason -->
        <V2Dialog
            :open="declining.ask !== null"
            :title="`Decline ${declining.ask?.locum ?? ''}?`"
            description="The locum sees that they were not chosen, with the reason."
            :confirm-label="
                busy.id === declining.ask?.id ? 'Declining…' : 'Decline'
            "
            tone="danger"
            :busy="busy.id === declining.ask?.id"
            :confirm-disabled="declineMissing !== ''"
            :confirm-disabled-reason="declineMissing"
            @update:open="(v: boolean) => !v && (declining.ask = null)"
            @confirm="decline"
        >
            <V2OptionGroup
                :model-value="declining.reason || null"
                :options="openShifts.declineReasons"
                label="Reason"
                required
                :error="errors.reason"
                @update:model-value="declining.reason = $event ?? ''"
            />
            <p
                v-if="errors.open_shift"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ errors.open_shift }}
            </p>
        </V2Dialog>

        <!-- Withdraw a shift -->
        <V2Dialog
            :open="withdrawing.shift !== null"
            title="Withdraw this open shift?"
            :description="`The shift on ${withdrawing.shift?.when ?? ''} is no longer offered, and every waiting ask is declined. This cannot be undone.`"
            :confirm-label="
                busy.id === withdrawing.shift?.id
                    ? 'Withdrawing…'
                    : 'Withdraw shift'
            "
            tone="danger"
            :busy="busy.id === withdrawing.shift?.id"
            @update:open="(v: boolean) => !v && (withdrawing.shift = null)"
            @confirm="withdraw"
        >
            <p
                v-if="errors.open_shift"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ errors.open_shift }}
            </p>
        </V2Dialog>

        <!-- Post an open shift -->
        <V2Dialog
            :open="form.open"
            title="Post an open shift"
            description="Locums assigned to the branch can ask for it. The roster rules apply: at most 16 hours, a break shorter than the shift."
            size="tall"
            :confirm-label="form.processing ? 'Posting…' : 'Post open shift'"
            :busy="form.processing"
            :confirm-disabled="missing !== null"
            :confirm-disabled-reason="missing ?? ''"
            @update:open="form.open = $event"
            @confirm="submit"
        >
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
            <V2OptionGroup
                :model-value="form.branch || null"
                :options="openShifts.branches"
                label="Branch"
                required
                :error="errors.branch_id"
                @update:model-value="form.branch = $event ?? ''"
            />
            <V2DateField
                v-model="form.date"
                label="Date"
                required
                :min="openShifts.today"
                :error="errors.date"
            />
            <div class="grid gap-4 sm:grid-cols-2 sm:items-start">
                <V2Field
                    v-slot="{ id, describedBy }"
                    label="Start time"
                    required
                    :error="errors.start_time"
                >
                    <input
                        :id="id"
                        v-model="form.start_time"
                        type="time"
                        :class="inputClass"
                        :aria-invalid="errors.start_time ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    />
                </V2Field>
                <V2Field
                    v-slot="{ id, describedBy }"
                    label="End time"
                    required
                    hint="If the end is earlier than the start, the shift ends the next day."
                    :error="errors.end_time"
                >
                    <input
                        :id="id"
                        v-model="form.end_time"
                        type="time"
                        :class="inputClass"
                        :aria-invalid="errors.end_time ? 'true' : undefined"
                        :aria-describedby="describedBy"
                    />
                </V2Field>
            </div>
            <V2OptionGroup
                :model-value="form.session_type || null"
                :options="openShifts.sessions"
                label="Session"
                required
                :error="errors.session_type"
                @update:model-value="form.session_type = $event ?? ''"
            />
            <V2Field
                v-slot="{ id, describedBy }"
                label="Break (minutes)"
                :error="errors.break_minutes"
            >
                <input
                    :id="id"
                    v-model="form.break_minutes"
                    type="number"
                    min="0"
                    max="180"
                    inputmode="numeric"
                    :class="inputClass"
                    :aria-invalid="errors.break_minutes ? 'true' : undefined"
                    :aria-describedby="describedBy"
                />
            </V2Field>
            <V2OptionGroup
                :model-value="form.role_needed || null"
                :options="openShifts.roles"
                label="Who is needed"
                required
                :error="errors.role_needed"
                @update:model-value="form.role_needed = $event ?? ''"
            />
        </V2Dialog>
    </PageFrame>
</template>
