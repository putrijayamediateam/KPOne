<script setup lang="ts">
// HR-14 /hr/messages: which channels KPOne may use to tell you about your own leave, roster, payslip and claims
// (everyone), each channel's switch, price and monthly cap, and the messages sent (Director, HR Manager). HR-PLAN 15.3.
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import { EmptyState, ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTabs } from '@/components/ui/tabs';

type Tab = 'my' | 'settings' | 'sent';
type MyChannel = {
    channel: string;
    label: string;
    connected: boolean;
    switchedOn: boolean;
    optedIn: boolean;
    lockVersion: number;
    address: string | null;
};
type ChannelSettings = {
    channel: string;
    label: string;
    connected: boolean;
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

for (const s of props.messages.settings ?? []) {
    forms[s.channel] = {
        enabled: s.enabled,
        price: s.price ? s.price.replace(/^RM /, '').replace(/,/g, '') : '',
        cap: s.monthlyCap?.toString() ?? '',
        key: crypto.randomUUID(),
        processing: false,
    };
}

const save = (s: ChannelSettings) => {
    const f = forms[s.channel];
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
const goSent = (n: number) =>
    router.get(
        '/hr/messages',
        { tab: 'sent', ...(n > 1 ? { page: n } : {}) },
        { preserveScroll: true },
    );
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
                        <template v-if="!c.connected"
                            >Not connected yet. The clinic has not set up
                            {{ c.label }} messages.</template
                        >
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
                        :status="c.optedIn ? 'on' : 'off'"
                        :label="c.optedIn ? 'On' : 'Off'"
                        :tone="c.optedIn ? 'success' : 'neutral'"
                    />
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
                        v-else
                        class="max-lg:min-h-11"
                        :disabled="!c.connected || choosing.processing"
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
                                    : 'Not connected: no provider has been chosen yet.'
                            }}
                        </p>
                    </div>
                    <StatusBadge
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
                    ><template v-if="s.cost"> · {{ s.cost }}</template
                    ><template v-if="s.failed">
                        · {{ s.failed }} failed</template
                    ><template v-if="s.skipped">
                        · {{ s.skipped }} not sent (cap reached)</template
                    >
                </p>
                <form
                    v-if="forms[s.channel]"
                    class="grid gap-3 sm:grid-cols-[auto_10rem_10rem_auto] sm:items-end"
                    @submit.prevent="save(s)"
                >
                    <label class="flex min-h-11 items-center gap-3 text-sm">
                        <input
                            v-model="forms[s.channel].enabled"
                            type="checkbox"
                            class="size-5"
                            :disabled="!s.connected"
                            :data-test="`enabled-${s.channel}`"
                        />
                        Switched on
                    </label>
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
                        />
                    </div>
                    <Button
                        type="submit"
                        class="max-lg:min-h-11"
                        :disabled="forms[s.channel].processing"
                        :data-test="`save-${s.channel}`"
                        >Save</Button
                    >
                </form>
            </article>
            <InputError :message="errors.enabled" />
            <InputError :message="errors.monthly_cap" />
            <InputError :message="errors.price" />
        </section>

        <!-- Sent -->
        <section
            v-if="messages.tab === 'sent' && messages.sent"
            aria-labelledby="sent-title"
            class="flex flex-col gap-3"
            data-test="sent-messages"
        >
            <h2 id="sent-title" class="sr-only">Sent messages</h2>
            <p class="text-sm text-muted-foreground">
                One row per message. The text itself is never kept.
            </p>
            <EmptyState
                v-if="!messages.sent.rows.length"
                title="No messages sent yet."
                description="Messages appear here once staff choose a channel and HR switches it on."
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
