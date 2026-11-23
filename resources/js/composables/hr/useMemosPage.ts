import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import type FileChooseButton from '@/components/catalogue/FileChooseButton.vue';
import { csrf } from '@/components/hr/records/types';

/**
 * The Memos page's state and actions (HR-8) for `pages/HR/Memos.vue`: every request, lock version and idempotency key.
 * A memo's text is fetched only when a person presses Open (the server records the open first); nothing is prefetched
 * or kept in props.
 */
export type Tab = 'my' | 'manage';
export type Status = 'open' | 'closed' | 'replaced' | 'withdrawn';
export type Option = { value: string; label: string };
export type MyMemo = {
    id: string;
    title: string;
    publishedBy: string;
    publishedOn: string;
    status: Status;
    hasPdf: boolean;
    opened: boolean;
    pdfOpened: boolean;
    acknowledgedOn: string | null;
    openUntil: string | null;
    replacedBy: string | null;
    replacementWithdrawnOn: string | null;
};
export type ManagedMemo = {
    id: string;
    title: string;
    status: Status;
    publishedOn: string;
    publishedBy: string;
    hasPdf: boolean;
    audienceLabel: string;
    openUntil: string | null;
    replacesTitle: string | null;
    replacedBy: string | null;
    replacedByTitle: string | null;
    replacementWithdrawnOn: string | null;
    withdrawnOn: string | null;
    withdrawnBy: string | null;
    withdrawnReason: string | null;
    lockVersion: number;
    read: number;
    expected: number;
    notYet: {
        name: string;
        role: string;
        branch: string | null;
        publishedOn: string;
    }[];
};

export type MemosPageProps = {
    memos: {
        tab: Tab;
        tabs: Tab[];
        canPublish: boolean;
        options: {
            roles: Option[];
            branches: Option[];
            withdrawReasons: Option[];
            maxMb: number;
            maxBody: number;
            today: string;
        };
        my: MyMemo[] | null;
        manage: { memos: ManagedMemo[] } | null;
    };
};

export function useMemosPage(props: MemosPageProps) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    const failure = computed(
        () =>
            errors.value.memo ?? errors.value.records ?? errors.value.audience,
    );
    const statusLabel: Record<Status, string> = {
        open: 'Open',
        closed: 'Closed',
        replaced: 'Replaced',
        withdrawn: 'Withdrawn',
    };
    const tabLabels: Record<Tab, string> = {
        my: 'My memos',
        manage: 'All memos',
    };
    const toRead = computed(
        () =>
            (props.memos.my ?? []).filter(
                (m) => m.status === 'open' && !m.acknowledgedOn,
            ).length,
    );
    const tabs = computed(() =>
        props.memos.tabs.map((value) => ({
            value,
            label:
                value === 'my' && toRead.value > 0
                    ? `My memos (${toRead.value})`
                    : tabLabels[value],
        })),
    );
    const selectTab = (value: string | number) =>
        router.get('/hr/memos', { tab: value }, { preserveScroll: true });

    // ---- My memos ----
    // "Open" asks the server for the text; the server records the open first. The PDF download records its own open. Until
    // the page reloads, the button knows what was opened here, and the server still refuses a read without the recorded opens.
    const bodies = ref<Record<string, string>>({});
    const openedHere = ref(new Set<string>());
    const pdfOpenedHere = ref(new Set<string>());
    const opening = ref('');
    const openError = ref<Record<string, string>>({});
    const pdfUrl = (id: string) => `/hr/memos/${id}/pdf`;
    const openMemo = async (memo: MyMemo | ManagedMemo, mine: boolean) => {
        if (opening.value) {
            return;
        }

        opening.value = memo.id;
        openError.value = { ...openError.value, [memo.id]: '' };

        try {
            const response = await fetch(`/hr/memos/${memo.id}/open`, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrf(),
                },
                body: '{}',
            });
            const data = (await response.json().catch(() => ({}))) as {
                body?: string;
                errors?: Record<string, string[]>;
            };

            if (!response.ok || typeof data.body !== 'string') {
                openError.value = {
                    ...openError.value,
                    [memo.id]:
                        data.errors?.memo?.[0] ??
                        'The memo could not be opened. Reload the page.',
                };

                return;
            }

            bodies.value = { ...bodies.value, [memo.id]: data.body };

            if (mine) {
                openedHere.value = new Set([...openedHere.value, memo.id]);
            }
        } catch {
            openError.value = {
                ...openError.value,
                [memo.id]:
                    'The memo could not be opened. Check your connection.',
            };
        } finally {
            opening.value = '';
        }
    };
    const hideBody = (id: string) => {
        const rest = { ...bodies.value };
        delete rest[id];
        bodies.value = rest;
    };
    const markPdfOpened = (id: string) => {
        pdfOpenedHere.value = new Set([...pdfOpenedHere.value, id]);
    };
    const memoOpened = (m: MyMemo) => m.opened || openedHere.value.has(m.id);
    const pdfOpened = (m: MyMemo) =>
        !m.hasPdf || m.pdfOpened || pdfOpenedHere.value.has(m.id);
    const ready = (m: MyMemo) => memoOpened(m) && pdfOpened(m);
    const hint = (m: MyMemo) =>
        !memoOpened(m) ? 'Open the memo first.' : 'Open the PDF first.';
    const ack = reactive({ id: '', key: '', processing: false });
    const acknowledge = (m: MyMemo) => {
        if (!ready(m) || ack.processing) {
            return;
        }

        if (ack.id !== m.id) {
            Object.assign(ack, { id: m.id, key: crypto.randomUUID() });
        }

        ack.processing = true;
        router.post(
            `/hr/memos/${m.id}/acknowledge`,
            { idempotency_key: ack.key },
            {
                preserveScroll: true,
                onSuccess: () => (ack.id = ''),
                onFinish: () => (ack.processing = false),
            },
        );
    };

    // ---- Publish ----
    const form = reactive({
        open: false,
        title: '',
        body: '',
        roles: [] as string[],
        branches: [] as string[],
        openUntil: '',
        replaces: '',
        file: null as File | null,
        key: '',
        processing: false,
    });
    const fileInput = ref<InstanceType<typeof FileChooseButton> | null>(null);
    const fileStatus = computed(() => form.file?.name ?? 'No file chosen');
    const startPublish = (replaces = '') => {
        fileInput.value?.clear();
        Object.assign(form, {
            open: true,
            title: '',
            body: '',
            roles: [],
            branches: [],
            openUntil: '',
            replaces,
            file: null,
            key: crypto.randomUUID(),
        });
    };
    const onFile = (event: Event) => {
        form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
    };
    const replaceable = computed(() =>
        (props.memos.manage?.memos ?? [])
            .filter((m) => m.status === 'open' || m.status === 'closed')
            .map((m) => ({
                value: m.id,
                label: `${m.title} (${m.publishedOn})`,
            })),
    );
    const replaceOptions = computed(() => [
        { value: '', label: 'None, this is a new memo' },
        ...replaceable.value,
    ]);

    // "Who will see this: N people", from the server, so it follows the same rules as publishing.
    const preview = reactive({ count: null as number | null, busy: false });
    let previewGeneration = 0;
    watch(
        () => [form.open, [...form.roles], [...form.branches]] as const,
        async ([open]) => {
            if (!open) {
                return;
            }

            const mine = ++previewGeneration;
            preview.busy = true;
            const query = new URLSearchParams();
            form.roles.forEach((r) => query.append('roles[]', r));
            form.branches.forEach((b) => query.append('branches[]', b));

            try {
                const response = await fetch(
                    `/hr/memos/audience?${query.toString()}`,
                    {
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: { Accept: 'application/json' },
                    },
                );
                const data = (await response.json().catch(() => ({}))) as {
                    count?: number;
                };

                if (mine === previewGeneration) {
                    preview.count =
                        response.ok && typeof data.count === 'number'
                            ? data.count
                            : null;
                }
            } catch {
                if (mine === previewGeneration) {
                    preview.count = null;
                }
            } finally {
                if (mine === previewGeneration) {
                    preview.busy = false;
                }
            }
        },
        { immediate: true, deep: true },
    );

    // Where it goes, in words, so "none ticked means all" cannot be missed.
    const audienceWords = computed(() => {
        const branches = props.memos.options.branches
            .filter((b) => form.branches.includes(b.value))
            .map((b) => b.label);
        const roles = props.memos.options.roles
            .filter((r) => form.roles.includes(r.value))
            .map((r) => r.label);

        return `${branches.length ? branches.join(', ') : 'all branches'}, ${roles.length ? roles.join(', ') : 'all roles'}`;
    });
    const peopleWords = computed(() =>
        preview.count === null
            ? 'checking…'
            : `${preview.count} ${preview.count === 1 ? 'person' : 'people'}`,
    );
    const confirmPublish = ref(false);
    const sendForm = () => {
        confirmPublish.value = false;
        form.processing = true;
        router.post(
            '/hr/memos',
            {
                title: form.title,
                body: form.body,
                roles: form.roles,
                branches: form.branches,
                ...(form.openUntil ? { open_until: form.openUntil } : {}),
                ...(form.replaces ? { replaces: form.replaces } : {}),
                ...(form.file ? { file: form.file } : {}),
                idempotency_key: form.key,
            },
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => (form.open = false),
                onFinish: () => (form.processing = false),
            },
        );
    };

    // ---- Withdraw ----
    const withdrawing = ref<ManagedMemo | null>(null);
    const withdrawReason = ref('');
    const withdrawKey = ref('');
    const withdrawBusy = ref(false);
    const askWithdraw = (memo: ManagedMemo) => {
        withdrawing.value = memo;
        withdrawReason.value = '';
        withdrawKey.value = crypto.randomUUID();
    };
    const withdraw = () => {
        const memo = withdrawing.value;

        if (!memo) {
            return;
        }

        withdrawBusy.value = true;
        router.post(
            `/hr/memos/${memo.id}/withdraw`,
            {
                reason: withdrawReason.value,
                expected_lock_version: memo.lockVersion,
                idempotency_key: withdrawKey.value,
            },
            {
                preserveScroll: true,
                onSuccess: () => (withdrawing.value = null),
                onFinish: () => (withdrawBusy.value = false),
            },
        );
    };

    const expanded = ref<string | null>(null);
    const toggle = (id: string) =>
        (expanded.value = expanded.value === id ? null : id);
    const published = computed(() =>
        (props.memos.manage?.memos ?? []).filter(
            (m) => m.status !== 'withdrawn',
        ),
    );
    const withdrawn = computed(() =>
        (props.memos.manage?.memos ?? []).filter(
            (m) => m.status === 'withdrawn',
        ),
    );

    return {
        errors,
        failure,
        statusLabel,
        tabs,
        selectTab,
        bodies,
        opening,
        openError,
        pdfUrl,
        openMemo,
        hideBody,
        markPdfOpened,
        ready,
        hint,
        ack,
        acknowledge,
        form,
        fileInput,
        fileStatus,
        startPublish,
        onFile,
        replaceOptions,
        audienceWords,
        peopleWords,
        confirmPublish,
        sendForm,
        withdrawing,
        withdrawReason,
        withdrawBusy,
        askWithdraw,
        withdraw,
        expanded,
        toggle,
        published,
        withdrawn,
    };
}
