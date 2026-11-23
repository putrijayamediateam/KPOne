import { router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, reactive, ref, watch } from 'vue';

/**
 * The Messages page's state and actions (HR-14) for `pages/HR/Messages.vue`: every request, lock version and
 * idempotency key. Messages are for staff and HR only; the opt-in and delivery wording stays exactly as it is.
 */
export type Tab = 'my' | 'settings' | 'sent';
export type MyChannel = {
    channel: string;
    label: string;
    connected: boolean;
    notConnected: string;
    switchedOn: boolean;
    optedIn: boolean;
    lockVersion: number;
    address: string | null;
};
export type ChannelSettings = {
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
export type SentRow = {
    id: string;
    to: string;
    channel: string;
    template: string;
    status: string;
    statusLabel: string;
    error: string | null;
    at: string;
};

export type MessagesPageProps = {
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
};

export function useMessagesPage(props: MessagesPageProps) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    const failure = computed(
        () => errors.value.messages ?? errors.value.records,
    );
    const tabLabels: Record<Tab, string> = {
        my: 'My messages',
        settings: 'Settings',
        sent: 'Sent',
    };
    const tabs = computed(() =>
        props.messages.tabs.map((value) => ({
            value,
            label: tabLabels[value],
        })),
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
    const errorFor = (
        channel: string,
        key: 'enabled' | 'price' | 'monthly_cap',
    ) => (lastSaved.value === channel ? errors.value[key] : undefined);
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

    return {
        errors,
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
    };
}
