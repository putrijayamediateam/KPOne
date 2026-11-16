<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { useMessagesPage } from '@/composables/hr/useMessagesPage';
import type { MessagesPageProps } from '@/composables/hr/useMessagesPage';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 Messages (shown only in V2; Classic is `pages/HR/Messages.vue`, untouched). Same props, routes, permissions,
 * lock versions and idempotency keys: every request comes from `useMessagesPage`, which Classic uses too, so this page
 * sends none of its own. Messages are for staff and HR only; the opt-in and delivery wording is Classic's, word for word.
 * Nothing here is filled: turning a channel on or off and saving a channel's settings are outline buttons, so a page with
 * several channels never shows several filled buttons.
 */
const props = defineProps<MessagesPageProps>();
usePageCrumbs(() => [{ title: 'Messages' }]);

const {
    failure,
    tabs,
    selectTab,
    choosing,
    choose,
    forms,
    dirty,
    errorFor,
    save,
    sentPages,
    goSent,
    sentRange,
    tone,
} = useMessagesPage(props);

const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:border-dashed disabled:bg-transparent disabled:text-muted-foreground disabled:hover:bg-transparent lg:min-h-9';
const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60 md:text-[15px] lg:h-9';
// V2Chip knows five tones; the server's "sending" tone (accent) reads as info.
const chipTone = (status: string) => {
    const t = tone(status);

    return t === 'accent' ? 'info' : t;
};
</script>

<template>
    <Head title="Messages" />
    <PageFrame>
        <V2WorkLayout
            title="Messages"
            description="Choose how KPOne tells you about your own leave, roster, payslip and claims. Messages never carry an amount, a reason or anything about patients."
        >
            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <SyntheticNote />
                    <V2Tabs
                        v-if="tabs.length > 1"
                        :model-value="messages.tab"
                        :tabs="tabs"
                        ariaLabel="Message sections"
                        @update:model-value="selectTab"
                    />

                    <p
                        v-if="failure"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ failure }}
                    </p>

                    <!-- My messages -->
                    <section
                        v-if="messages.tab === 'my' && messages.my"
                        aria-labelledby="v2-my-messages-title"
                        class="flex flex-col gap-3"
                        data-test="my-messages"
                    >
                        <h2 id="v2-my-messages-title" class="sr-only">
                            My messages
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            You always see these in KPOne. Choose a channel to
                            also get them there. Nothing is on unless you turn
                            it on.
                        </p>
                        <article
                            v-for="c in messages.my"
                            :key="c.channel"
                            class="flex flex-wrap items-center justify-between gap-3 rounded-[14px] border bg-card p-4"
                            :data-test="`my-channel-${c.channel}`"
                        >
                            <div class="min-w-0">
                                <h3 class="font-semibold">{{ c.label }}</h3>
                                <p
                                    class="text-sm [overflow-wrap:anywhere] text-muted-foreground"
                                >
                                    <template v-if="!c.connected">{{
                                        c.notConnected
                                    }}</template>
                                    <template v-else-if="c.address"
                                        >To {{ c.address }}, the address you
                                        sign in with.</template
                                    >
                                    <template
                                        v-if="c.connected && !c.switchedOn"
                                    >
                                        HR has not switched this channel on yet,
                                        so nothing is sent for now.</template
                                    >
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <V2Chip
                                    v-if="!c.connected"
                                    tone="neutral"
                                    label="Not connected"
                                />
                                <V2Chip
                                    v-else
                                    :tone="c.optedIn ? 'success' : 'neutral'"
                                    :label="c.optedIn ? 'On' : 'Off'"
                                />
                                <!-- A channel that is not connected has no button: there is nothing to choose. -->
                                <button
                                    v-if="c.optedIn"
                                    type="button"
                                    :class="button"
                                    :disabled="choosing.processing"
                                    :aria-label="`Turn off ${c.label} messages`"
                                    :data-test="`my-off-${c.channel}`"
                                    @click="choose(c, false)"
                                >
                                    Turn off
                                </button>
                                <button
                                    v-else-if="c.connected"
                                    type="button"
                                    :class="button"
                                    :disabled="choosing.processing"
                                    :aria-label="`Turn on ${c.label} messages`"
                                    :data-test="`my-on-${c.channel}`"
                                    @click="choose(c, true)"
                                >
                                    Turn on
                                </button>
                            </div>
                        </article>
                        <section
                            class="flex flex-col gap-2 rounded-[14px] border bg-card p-4"
                            aria-labelledby="v2-what-sent-title"
                        >
                            <h3 id="v2-what-sent-title" class="font-semibold">
                                What you would get
                            </h3>
                            <ul class="flex flex-col gap-1.5 text-sm">
                                <li
                                    v-for="t in messages.templates"
                                    :key="t.code"
                                >
                                    <span class="font-medium"
                                        >{{ t.label }}:</span
                                    >
                                    <span class="text-muted-foreground">
                                        "{{ t.example }}"</span
                                    >
                                </li>
                            </ul>
                        </section>
                    </section>

                    <!-- Settings -->
                    <section
                        v-if="messages.tab === 'settings' && messages.settings"
                        aria-labelledby="v2-settings-title"
                        class="flex flex-col gap-3"
                        data-test="message-settings"
                    >
                        <h2 id="v2-settings-title" class="sr-only">
                            Channel settings
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            A channel sends only when it is connected, switched
                            on here and under its monthly cap. The price is the
                            clinic's own figure, used to show this month's cost;
                            KPOne ships no number.
                        </p>
                        <article
                            v-for="s in messages.settings"
                            :key="s.channel"
                            class="flex flex-col gap-3 rounded-[14px] border bg-card p-4"
                            :data-test="`settings-${s.channel}`"
                        >
                            <div
                                class="flex flex-wrap items-start justify-between gap-2"
                            >
                                <div class="min-w-0">
                                    <h3 class="font-semibold">{{ s.label }}</h3>
                                    <p class="text-sm text-muted-foreground">
                                        {{
                                            s.connected
                                                ? `${s.people} staff chose it.`
                                                : s.notConnected
                                        }}
                                    </p>
                                </div>
                                <V2Chip
                                    v-if="!s.connected"
                                    tone="neutral"
                                    label="Not connected"
                                />
                                <V2Chip
                                    v-else
                                    :tone="s.enabled ? 'success' : 'neutral'"
                                    :label="s.enabled ? 'On' : 'Off'"
                                />
                            </div>
                            <p
                                class="text-sm tabular-nums"
                                :data-test="`count-${s.channel}`"
                            >
                                {{ s.month }}: {{ s.sent }} sent<template
                                    v-if="s.monthlyCap"
                                >
                                    of {{ s.monthlyCap }}</template
                                ><template v-if="s.cost">
                                    · cost {{ s.cost }}</template
                                ><template v-if="s.failed">
                                    · {{ s.failed }} failed</template
                                ><template v-if="s.skipped">
                                    · {{ s.skipped }} not sent (cap
                                    reached)</template
                                >
                            </p>
                            <p
                                v-if="!s.connected"
                                class="text-sm text-muted-foreground"
                                :data-test="`settings-closed-${s.channel}`"
                            >
                                Settings open when this channel is connected.
                            </p>
                            <form
                                v-if="forms[s.channel]"
                                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_12rem_10rem_auto] lg:items-end"
                                @submit.prevent="save(s)"
                            >
                                <div class="flex flex-col gap-1">
                                    <label
                                        class="flex min-h-11 items-center gap-3 text-sm"
                                    >
                                        <input
                                            v-model="forms[s.channel].enabled"
                                            type="checkbox"
                                            class="size-5"
                                            :disabled="!s.connected"
                                            :aria-invalid="
                                                errorFor(s.channel, 'enabled')
                                                    ? 'true'
                                                    : undefined
                                            "
                                            :aria-describedby="`enabled-${s.channel}-error`"
                                            :data-test="`enabled-${s.channel}`"
                                        />
                                        Switched on
                                    </label>
                                    <p
                                        v-if="errorFor(s.channel, 'enabled')"
                                        :id="`enabled-${s.channel}-error`"
                                        role="alert"
                                        class="text-sm text-destructive"
                                    >
                                        {{ errorFor(s.channel, 'enabled') }}
                                    </p>
                                </div>
                                <div class="flex flex-col gap-1.5">
                                    <label
                                        :for="`price-${s.channel}`"
                                        class="text-sm font-medium"
                                        >Price per message (RM)</label
                                    >
                                    <input
                                        :id="`price-${s.channel}`"
                                        v-model="forms[s.channel].price"
                                        inputmode="decimal"
                                        placeholder="Not set"
                                        :class="inputClass"
                                        :disabled="!s.connected"
                                        :aria-invalid="
                                            errorFor(s.channel, 'price')
                                                ? 'true'
                                                : undefined
                                        "
                                        :aria-describedby="`price-${s.channel}-error`"
                                    />
                                    <p
                                        v-if="errorFor(s.channel, 'price')"
                                        :id="`price-${s.channel}-error`"
                                        role="alert"
                                        class="text-sm text-destructive"
                                    >
                                        {{ errorFor(s.channel, 'price') }}
                                    </p>
                                </div>
                                <div class="flex flex-col gap-1.5">
                                    <label
                                        :for="`cap-${s.channel}`"
                                        class="text-sm font-medium"
                                        >Monthly cap</label
                                    >
                                    <input
                                        :id="`cap-${s.channel}`"
                                        v-model="forms[s.channel].cap"
                                        inputmode="numeric"
                                        placeholder="Not set"
                                        :class="inputClass"
                                        :disabled="!s.connected"
                                        :aria-invalid="
                                            errorFor(s.channel, 'monthly_cap')
                                                ? 'true'
                                                : undefined
                                        "
                                        :aria-describedby="`cap-${s.channel}-error`"
                                    />
                                    <p
                                        v-if="
                                            errorFor(s.channel, 'monthly_cap')
                                        "
                                        :id="`cap-${s.channel}-error`"
                                        role="alert"
                                        class="text-sm text-destructive"
                                    >
                                        {{ errorFor(s.channel, 'monthly_cap') }}
                                    </p>
                                </div>
                                <button
                                    type="submit"
                                    :class="button"
                                    :disabled="
                                        !s.connected ||
                                        !dirty(s) ||
                                        forms[s.channel].processing
                                    "
                                    :data-test="`save-${s.channel}`"
                                >
                                    Save
                                </button>
                            </form>
                        </article>
                    </section>

                    <!-- Sent -->
                    <section
                        v-if="messages.tab === 'sent' && messages.sent"
                        aria-labelledby="v2-sent-title"
                        class="flex flex-col gap-3"
                        data-test="sent-messages"
                    >
                        <h2 id="v2-sent-title" class="sr-only">
                            Sent messages
                        </h2>
                        <div
                            class="flex flex-wrap items-end justify-between gap-3"
                        >
                            <p class="text-sm text-muted-foreground">
                                One row per message. The text itself is never
                                kept.
                                <span
                                    v-if="sentRange"
                                    class="block tabular-nums"
                                    data-test="sent-range"
                                    >{{ sentRange }}.</span
                                >
                            </p>
                            <div class="flex w-full flex-col gap-1.5 sm:w-56">
                                <label
                                    id="v2-sent-status-label"
                                    class="text-sm font-medium"
                                    >Status</label
                                >
                                <OperationalSelect
                                    :model-value="messages.sent.status"
                                    :options="messages.sent.statuses"
                                    label="Status"
                                    labelledby="v2-sent-status-label"
                                    trigger-class="max-lg:min-h-11"
                                    @update:model-value="
                                        (v) => goSent(1, String(v))
                                    "
                                />
                            </div>
                        </div>
                        <div
                            v-if="!messages.sent.rows.length"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">
                                {{
                                    messages.sent.status === 'all'
                                        ? 'No messages sent yet.'
                                        : 'No messages with this status.'
                                }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    messages.sent.status === 'all'
                                        ? 'Messages appear here once staff choose a channel and HR switches it on.'
                                        : 'Choose All to see every message.'
                                }}
                            </p>
                        </div>
                        <ul v-else class="flex flex-col gap-2">
                            <li
                                v-for="r in messages.sent.rows"
                                :key="r.id"
                                class="flex flex-wrap items-center justify-between gap-3 rounded-[14px] border bg-card p-3"
                                data-test="sent-row"
                            >
                                <div class="min-w-0">
                                    <p
                                        class="font-medium [overflow-wrap:anywhere]"
                                    >
                                        {{ r.to }}
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        {{ r.template }} · {{ r.channel }} ·
                                        {{ r.at }}
                                        <template v-if="r.error">
                                            · {{ r.error }}</template
                                        >
                                    </p>
                                </div>
                                <V2Chip
                                    :tone="chipTone(r.status)"
                                    :label="r.statusLabel"
                                />
                            </li>
                        </ul>
                        <nav
                            v-if="sentPages > 1"
                            class="flex items-center justify-between gap-2 text-sm"
                            aria-label="Sent message pages"
                        >
                            <button
                                type="button"
                                :class="button"
                                :disabled="messages.sent.page <= 1"
                                @click="goSent(messages.sent.page - 1)"
                            >
                                Previous
                            </button>
                            <span class="tabular-nums"
                                >Page {{ messages.sent.page }} of
                                {{ sentPages }}</span
                            >
                            <button
                                type="button"
                                :class="button"
                                :disabled="messages.sent.page >= sentPages"
                                @click="goSent(messages.sent.page + 1)"
                            >
                                Next
                            </button>
                        </nav>
                    </section>
                </div>
            </template>
        </V2WorkLayout>
    </PageFrame>
</template>
