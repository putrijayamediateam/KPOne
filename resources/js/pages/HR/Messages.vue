<script setup lang="ts">
// HR-14 /hr/messages: which channels KPOne may use to tell you about your own leave, roster, payslip and claims
// (everyone), each channel's switch, price and monthly cap, and the messages sent (Director, HR Manager). HR-PLAN 15.3.
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import { OperationalSelect } from '@/components/ui/select';
import { EmptyState, ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTabs } from '@/components/ui/tabs';

type Tab = 'my' | 'settings' | 'sent';
type MyChannel = {
    channel: string;
    label: string;
    connected: boolean;
    notConnected: string;
    switchedOn: boolean;
    optedIn: boolean;
    lockVersion: number;
    address: string | null;
};
type ChannelSettings = {
    channel: string;
    label: string;
    connected: boolean;
    notConnected: string;
    version: number;
    enabled: boolean;
    monthlyCap: number | null;
    price: string | null;
    month: string;
    sent: number;
    failed: number;
    skipped: number;
    cost: string | null;
    people: number;
};
type SentRow = {
    id: string;
    to: string;
    channel: string;
    template: string;
    status: string;
    statusLabel: string;
    error: string | null;
    at: string;
};

const props = defineProps<{
    messages: {
        tab: Tab;
        tabs: Tab[];
        templates: { code: string; label: string; example: string }[];
        my: MyChannel[] | null;
        settings: ChannelSettings[] | null;
        sent: {
            status: string;
            statuses: { value: string; label: string }[];
            page: number;
            perPage: number;
            total: number;
            rows: SentRow[];
        } | null;
    };
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const failure = computed(() => errors.value.messages ?? errors.value.records);
const tabLabels: Record<Tab, string> = {
    my: 'My messages',
    settings: 'Settings',
    sent: 'Sent',
};
const tabs = computed(() =>
    props.messages.tabs.map((value) => ({ value, label: tabLabels[value] })),
);
const selectTab = (value: string | number) =>
    router.get('/hr/messages', { tab: value }, { preserveScroll: true });

// ---- My messages ----
const choosing = reactive({ channel: '', processing: false });
const choose = (c: MyChannel, on: boolean) => {
    choosing.channel = c.channel;
    choosing.processing = true;
    router.post(
        `/hr/messages/my/${c.channel}`,
        {
            opted_in: on,
            expected_lock_version: c.lockVersion,
            idempotency_key: crypto.randomUUID(),
        },
        {
            preserveScroll: true,
            onFinish: () => (choosing.processing = false),
        },
    );
};

// ---- Settings ----
const forms = reactive<
    Record<
        string,
        {
            enabled: boolean;
            price: string;
            cap: string;
            key: string;
            processing: boolean;
        }
    >
>({});

// The saved values, so Save is the primary button only while a card has unsaved changes (Daniel, HR-14).
const saved = (s: ChannelSettings) => ({
    enabled: s.enabled,
    price: s.price ? s.price.replace(/^RM /, '').replace(/,/g, '') : '',
    cap: s.monthlyCap?.toString() ?? '',
});
const versions: Record<string, number> = {};
const resetForms = (all: boolean) => {
    for (const s of props.messages.settings ?? []) {
        if (all || versions[s.channel] !== s.version) {
            forms[s.channel] = {
                ...saved(s),
                key: crypto.randomUUID(),
                processing: false,
            };
            versions[s.channel] = s.version;
        }
    }
};
resetForms(true);
watch(
    () => props.messages.settings,
    () => resetForms(false),
);
const dirty = (s: ChannelSettings) => {
    const f = forms[s.channel];
    const before = saved(s);

    return (
        !!f &&
        (f.enabled !== before.enabled ||
            f.price.trim() !== before.price ||
            f.cap.trim() !== before.cap)
    );
};

// Errors show inside the card that was saved, next to their field.
const lastSaved = ref('');
const errorFor = (channel: string, key: 'enabled' | 'price' | 'monthly_cap') =>
    lastSaved.value === channel ? errors.value[key] : undefined;
const save = (s: ChannelSettings) => {
    const f = forms[s.channel];
    lastSaved.value = s.channel;
    f.processing = true;
    router.post(
        `/hr/messages/channels/${s.channel}`,
        {
            enabled: f.enabled,
            price: f.price.trim() === '' ? null : f.price.trim(),
            monthly_cap: f.cap.trim() === '' ? null : f.cap.trim(),
            expected_version: s.version,
            idempotency_key: f.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (f.key = crypto.randomUUID()),
            // Focus moves to the first field with an error in this card.
            onError: () =>
                nextTick(() => {
                    const first = (
                        [
                            ['enabled', `enabled-${s.channel}`],
                            ['price', `price-${s.channel}`],
                            ['monthly_cap', `cap-${s.channel}`],
                        ] as const
                    ).find(([key]) => errors.value[key]);

                    if (first) {
                        document.getElementById(first[1])?.focus();
                    }
                }),
            onFinish: () => (f.processing = false),
        },
    );
};

const sentPages = computed(() =>
    props.messages.sent
        ? Math.max(
              1,
              Math.ceil(
                  props.messages.sent.total / props.messages.sent.perPage,
              ),
          )
        : 1,
);
const goSent = (n: number, status = props.messages.sent?.status ?? 'all') =>
    router.get(
        '/hr/messages',
        {
            tab: 'sent',
            ...(status !== 'all' ? { status } : {}),
            ...(n > 1 ? { page: n } : {}),
        },
        { preserveScroll: true },
    );
const sentRange = computed(() => {
    const sent = props.messages.sent;

    if (!sent || sent.total === 0) {
        return '';
    }

    const from = (sent.page - 1) * sent.perPage + 1;

    return `Showing ${from}–${Math.min(sent.total, from + sent.rows.length - 1)} of ${sent.total}, newest first`;
});
const tone = (status: string) =>
    (
        ({
            sent: 'success',
            sending: 'accent',
            failed: 'danger',
            skipped_cap: 'warning',
        }) as const
    )[status] ?? 'neutral';
</script>

<template>
    <Head title="Messages" />
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-4 px-4 py-4 md:px-5">
        <div class="flex flex-col gap-1">
            <PageHeader
                eyebrow="KPOne HR"
                title="Messages"
                description="Choose how KPOne tells you about your own leave, roster, payslip and claims. Messages never carry an amount, a reason or anything about patients."
            />
            <SyntheticNote />
        </div>

        <OperationalTabs
            v-if="tabs.length > 1"
            :model-value="messages.tab"
            :tabs="tabs"
            label="Message sections"
            class="min-w-0 max-lg:[&>button]:h-11 max-sm:[&>button]:px-2.5"
            @update:model-value="selectTab"
        />

        <ErrorState v-if="failure" :message="failure" />

        <!-- My messages -->
        <section
            v-if="messages.tab === 'my' && messages.my"
            aria-labelledby="my-messages-title"
            class="flex flex-col gap-3"
            data-test="my-messages"
        >
            <h2 id="my-messages-title" class="sr-only">My messages</h2>
            <p class="text-sm text-muted-foreground">
                You always see these in KPOne. Choose a channel to also get them
                there. Nothing is on unless you turn it on.
            </p>
            <article
                v-for="c in messages.my"
                :key="c.channel"
                class="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-4"
                :data-test="`my-channel-${c.channel}`"
            >
                <div class="min-w-0">
                    <h3 class="font-semibold">{{ c.label }}</h3>
                    <p class="text-sm break-words text-muted-foreground">
                        <template v-if="!c.connected">{{
                            c.notConnected
                        }}</template>
                        <template v-else-if="c.address"
                            >To {{ c.address }}, the address you sign in
                            with.</template
                        >
                        <template v-if="c.connected && !c.switchedOn">
                            HR has not switched this channel on yet, so nothing
                            is sent for now.</template
                        >
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <StatusBadge
                        v-if="!c.connected"
                        status="not_connected"
                        label="Not connected"
                        tone="neutral"
                    />
                    <StatusBadge
                        v-else
                        :status="c.optedIn ? 'on' : 'off'"
                        :label="c.optedIn ? 'On' : 'Off'"
                        :tone="c.optedIn ? 'success' : 'neutral'"
                    />
                    <!-- A channel that is not connected has no button: there is nothing to choose (Daniel, HR-14). -->
                    <Button
                        v-if="c.optedIn"
                        variant="outline"
                        class="max-lg:min-h-11"
                        :disabled="choosing.processing"
                        :aria-label="`Turn off ${c.label} messages`"
                        :data-test="`my-off-${c.channel}`"
                        @click="choose(c, false)"
                        >Turn off</Button
                    >
                    <Button
                        v-else-if="c.connected"
                        class="max-lg:min-h-11"
                        :disabled="choosing.processing"
                        :aria-label="`Turn on ${c.label} messages`"
                        :data-test="`my-on-${c.channel}`"
                        @click="choose(c, true)"
                        >Turn on</Button
                    >
                </div>
            </article>
            <section
                class="flex flex-col gap-2 rounded-xl border bg-card p-4"
                aria-labelledby="what-sent-title"
            >
                <h3 id="what-sent-title" class="font-semibold">
                    What you would get
                </h3>
                <ul class="flex flex-col gap-1.5 text-sm">
                    <li v-for="t in messages.templates" :key="t.code">
                        <span class="font-medium">{{ t.label }}:</span>
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
            aria-labelledby="settings-title"
            class="flex flex-col gap-3"
            data-test="message-settings"
        >
            <h2 id="settings-title" class="sr-only">Channel settings</h2>
            <p class="text-sm text-muted-foreground">
                A channel sends only when it is connected, switched on here and
                under its monthly cap. The price is the clinic's own figure,
                used to show this month's cost; KPOne ships no number.
            </p>
            <article
                v-for="s in messages.settings"
                :key="s.channel"
                class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                :data-test="`settings-${s.channel}`"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
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
                    <StatusBadge
                        v-if="!s.connected"
                        status="not_connected"
                        label="Not connected"
                        tone="neutral"
                    />
                    <StatusBadge
                        v-else
                        :status="s.enabled ? 'on' : 'off'"
                        :label="s.enabled ? 'On' : 'Off'"
                        :tone="s.enabled ? 'success' : 'neutral'"
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
                    ><template v-if="s.cost"> · cost {{ s.cost }}</template
                    ><template v-if="s.failed">
                        · {{ s.failed }} failed</template
                    ><template v-if="s.skipped">
                        · {{ s.skipped }} not sent (cap reached)</template
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
                    class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_12rem_10rem_auto] sm:items-start"
                    @submit.prevent="save(s)"
                >
                    <!-- Centred on the fields beside it: their label (20 px) and gap (6 px) sit above them. -->
                    <div class="flex flex-col gap-1 sm:pt-[1.375rem]">
                        <label class="flex min-h-11 items-center gap-3 text-sm">
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
                        <InputError
                            :id="`enabled-${s.channel}-error`"
                            :message="errorFor(s.channel, 'enabled')"
                        />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label :for="`price-${s.channel}`"
                            >Price per message (RM)</Label
                        >
                        <Input
                            :id="`price-${s.channel}`"
                            v-model="forms[s.channel].price"
                            inputmode="decimal"
                            placeholder="Not set"
                            class="max-lg:min-h-11"
                            :disabled="!s.connected"
                            :aria-invalid="
                                errorFor(s.channel, 'price')
                                    ? 'true'
                                    : undefined
                            "
                            :aria-describedby="`price-${s.channel}-error`"
                        />
                        <InputError
                            :id="`price-${s.channel}-error`"
                            :message="errorFor(s.channel, 'price')"
                        />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label :for="`cap-${s.channel}`">Monthly cap</Label>
                        <Input
                            :id="`cap-${s.channel}`"
                            v-model="forms[s.channel].cap"
                            inputmode="numeric"
                            placeholder="Not set"
                            class="max-lg:min-h-11"
                            :disabled="!s.connected"
                            :aria-invalid="
                                errorFor(s.channel, 'monthly_cap')
                                    ? 'true'
                                    : undefined
                            "
                            :aria-describedby="`cap-${s.channel}-error`"
                        />
                        <InputError
                            :id="`cap-${s.channel}-error`"
                            :message="errorFor(s.channel, 'monthly_cap')"
                        />
                    </div>
                    <Button
                        type="submit"
                        class="max-lg:min-h-11 sm:mt-[1.625rem]"
                        :variant="dirty(s) ? 'default' : 'outline'"
                        :disabled="
                            !s.connected ||
                            !dirty(s) ||
                            forms[s.channel].processing
                        "
                        :data-test="`save-${s.channel}`"
                        >Save</Button
                    >
                </form>
            </article>
        </section>

        <!-- Sent -->
        <section
            v-if="messages.tab === 'sent' && messages.sent"
            aria-labelledby="sent-title"
            class="flex flex-col gap-3"
            data-test="sent-messages"
        >
            <h2 id="sent-title" class="sr-only">Sent messages</h2>
            <div class="flex flex-wrap items-end justify-between gap-3">
                <p class="text-sm text-muted-foreground">
                    One row per message. The text itself is never kept.
                    <span
                        v-if="sentRange"
                        class="block tabular-nums"
                        data-test="sent-range"
                        >{{ sentRange }}.</span
                    >
                </p>
                <div class="flex w-full flex-col gap-1.5 sm:w-56">
                    <Label id="sent-status-label">Status</Label>
                    <OperationalSelect
                        :model-value="messages.sent.status"
                        :options="messages.sent.statuses"
                        label="Status"
                        labelledby="sent-status-label"
                        trigger-class="max-lg:min-h-11"
                        @update:model-value="(v) => goSent(1, String(v))"
                    />
                </div>
            </div>
            <EmptyState
                v-if="!messages.sent.rows.length"
                :title="
                    messages.sent.status === 'all'
                        ? 'No messages sent yet.'
                        : 'No messages with this status.'
                "
                :description="
                    messages.sent.status === 'all'
                        ? 'Messages appear here once staff choose a channel and HR switches it on.'
                        : 'Choose All to see every message.'
                "
            />
            <ul v-else class="flex flex-col gap-2">
                <li
                    v-for="r in messages.sent.rows"
                    :key="r.id"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-3"
                    data-test="sent-row"
                >
                    <div class="min-w-0">
                        <p class="font-medium break-words">{{ r.to }}</p>
                        <p class="text-sm text-muted-foreground">
                            {{ r.template }} · {{ r.channel }} · {{ r.at }}
                            <template v-if="r.error"> · {{ r.error }}</template>
                        </p>
                    </div>
                    <StatusBadge
                        :status="r.status"
                        :label="r.statusLabel"
                        :tone="tone(r.status)"
                    />
                </li>
            </ul>
            <nav
                v-if="sentPages > 1"
                class="flex items-center justify-between gap-2 text-sm"
                aria-label="Sent message pages"
            >
                <Button
                    variant="outline"
                    class="max-lg:min-h-11"
                    :disabled="messages.sent.page <= 1"
                    @click="goSent(messages.sent.page - 1)"
                    >Previous</Button
                >
                <span class="tabular-nums"
                    >Page {{ messages.sent.page }} of {{ sentPages }}</span
                >
                <Button
                    variant="outline"
                    class="max-lg:min-h-11"
                    :disabled="messages.sent.page >= sentPages"
                    @click="goSent(messages.sent.page + 1)"
                    >Next</Button
                >
            </nav>
        </section>
    </div>
</template>
