<script setup lang="ts">
// HR-8 /hr/memos: memos to read (everyone) and the memo list with who has not read yet (Director, HR Manager).
// In KPOne only: nothing is sent by email, push, SMS or WhatsApp.
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import { csrf } from '@/components/hr/records/types';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { PageHeader } from '@/components/ui/page-header';
import { OperationalSelect } from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { EmptyState, ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTabs } from '@/components/ui/tabs';

type Tab = 'my' | 'manage';
type Status = 'open' | 'closed' | 'replaced' | 'withdrawn';
type Option = { value: string; label: string };
type MyMemo = {
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
};
type ManagedMemo = {
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

const props = defineProps<{
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
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const failure = computed(
    () => errors.value.memo ?? errors.value.records ?? errors.value.audience,
);
const statusLabel: Record<Status, string> = {
    open: 'Open',
    closed: 'Closed',
    replaced: 'Replaced',
    withdrawn: 'Withdrawn',
};
const tabLabels: Record<Tab, string> = { my: 'My memos', manage: 'All memos' };
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
            [memo.id]: 'The memo could not be opened. Check your connection.',
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
const startPublish = (replaces = '') =>
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

const sendForm = () => {
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
    (props.memos.manage?.memos ?? []).filter((m) => m.status !== 'withdrawn'),
);
const withdrawn = computed(() =>
    (props.memos.manage?.memos ?? []).filter((m) => m.status === 'withdrawn'),
);
</script>

<template>
    <Head title="Memos" />
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-4 px-4 py-4 md:px-5">
        <div class="flex flex-col gap-1">
            <PageHeader
                eyebrow="KPOne HR"
                title="Memos"
                :description="
                    memos.canPublish
                        ? 'Write a memo for all staff, a branch or a role, and see who has not read it yet. A correction is a new memo that replaces the old one. Nothing is sent by email or message: people see it here.'
                        : 'Memos from HR. Open each one, then press I have read this.'
                "
            />
            <SyntheticNote />
        </div>

        <OperationalTabs
            v-if="tabs.length > 1"
            :model-value="memos.tab"
            :tabs="tabs"
            label="Memo sections"
            class="min-w-0 max-lg:[&>button]:h-11 max-sm:[&>button]:px-2.5"
            @update:model-value="selectTab"
        />

        <ErrorState
            v-if="failure && !form.open && !withdrawing"
            :message="failure"
        />

        <!-- My memos -->
        <section
            v-if="memos.tab === 'my' && memos.my"
            aria-labelledby="my-memos-title"
            class="flex flex-col gap-3"
            data-test="my-memos"
        >
            <h2 id="my-memos-title" class="sr-only">My memos</h2>
            <EmptyState
                v-if="!memos.my.length"
                title="No memos for you."
                description="When HR publishes a memo for you, it appears here."
            />
            <article
                v-for="memo in memos.my"
                :key="memo.id"
                class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                :aria-labelledby="`memo-${memo.id}`"
                data-test="memo-card"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h3
                            :id="`memo-${memo.id}`"
                            class="text-base font-semibold break-words"
                        >
                            {{ memo.title }}
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            {{ memo.publishedBy }} · {{ memo.publishedOn
                            }}<template v-if="memo.openUntil">
                                · open until {{ memo.openUntil }}</template
                            >
                        </p>
                    </div>
                    <StatusBadge
                        v-if="memo.acknowledgedOn"
                        status="acknowledged"
                        label="Read"
                        tone="success"
                    />
                    <StatusBadge
                        v-else-if="memo.status !== 'open'"
                        :status="memo.status"
                        :label="statusLabel[memo.status]"
                        tone="neutral"
                    />
                </div>

                <p
                    v-if="memo.status === 'replaced' && !memo.acknowledgedOn"
                    class="text-sm"
                    data-test="memo-replaced"
                >
                    This memo was replaced by a newer memo. Please read the
                    newer one.
                </p>
                <p
                    v-else-if="memo.status === 'closed' && !memo.acknowledgedOn"
                    class="text-sm text-muted-foreground"
                    data-test="memo-closed"
                >
                    This memo is closed. It no longer takes new reads.
                </p>
                <p
                    v-if="memo.acknowledgedOn"
                    class="text-sm text-muted-foreground"
                >
                    You read this on {{ memo.acknowledgedOn }}.
                </p>

                <div
                    v-if="bodies[memo.id] !== undefined"
                    class="rounded-lg border bg-muted/40 p-3 text-sm break-words whitespace-pre-wrap"
                    data-test="memo-body"
                >
                    {{ bodies[memo.id] }}
                </div>
                <p
                    v-if="openError[memo.id]"
                    class="text-sm text-destructive"
                    role="alert"
                >
                    {{ openError[memo.id] }}
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        variant="outline"
                        class="max-lg:min-h-11"
                        :disabled="opening === memo.id"
                        :aria-label="`${bodies[memo.id] === undefined ? 'Open' : 'Reload'} ${memo.title}`"
                        data-test="memo-open"
                        @click="openMemo(memo, true)"
                    >
                        {{
                            opening === memo.id
                                ? 'Opening…'
                                : bodies[memo.id] === undefined
                                  ? 'Open'
                                  : 'Open again'
                        }}
                    </Button>
                    <Button
                        v-if="bodies[memo.id] !== undefined"
                        variant="outline"
                        class="max-lg:min-h-11"
                        @click="hideBody(memo.id)"
                        >Hide</Button
                    >
                    <Button
                        v-if="memo.hasPdf"
                        as-child
                        variant="outline"
                        class="max-lg:min-h-11"
                    >
                        <a
                            :href="pdfUrl(memo.id)"
                            :aria-label="`Open the PDF of ${memo.title}`"
                            data-test="memo-pdf"
                            @click="markPdfOpened(memo.id)"
                            >Open the PDF</a
                        >
                    </Button>
                    <template
                        v-if="memo.status === 'open' && !memo.acknowledgedOn"
                    >
                        <Button
                            class="max-lg:min-h-11"
                            :disabled="
                                !ready(memo) ||
                                (ack.processing && ack.id === memo.id)
                            "
                            :aria-describedby="
                                ready(memo) ? undefined : `hint-${memo.id}`
                            "
                            data-test="memo-acknowledge"
                            @click="acknowledge(memo)"
                        >
                            {{
                                ack.processing && ack.id === memo.id
                                    ? 'Saving…'
                                    : 'I have read this'
                            }}
                        </Button>
                        <span
                            v-if="!ready(memo)"
                            :id="`hint-${memo.id}`"
                            class="text-sm text-muted-foreground"
                            >{{ hint(memo) }}</span
                        >
                    </template>
                </div>
            </article>
        </section>

        <!-- All memos (Director, HR Manager) -->
        <section
            v-if="memos.tab === 'manage' && memos.manage"
            aria-labelledby="manage-memos-title"
            class="flex flex-col gap-3"
            data-test="manage-memos"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="manage-memos-title" class="text-base font-semibold">
                    Published memos
                </h2>
                <Button
                    variant="outline"
                    class="max-lg:min-h-11"
                    data-test="memo-publish"
                    @click="startPublish()"
                    >Write a memo</Button
                >
            </div>
            <EmptyState
                v-if="!published.length"
                title="No memos yet."
                description="Write a memo and choose who it is for."
            />
            <article
                v-for="memo in published"
                :key="memo.id"
                class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                :aria-labelledby="`manage-${memo.id}`"
                data-test="managed-memo"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h3
                            :id="`manage-${memo.id}`"
                            class="text-base font-semibold break-words"
                        >
                            {{ memo.title }}
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            {{ memo.publishedOn }} by {{ memo.publishedBy }} ·
                            for {{ memo.audienceLabel
                            }}<template v-if="memo.openUntil">
                                · open until {{ memo.openUntil }}</template
                            ><template v-if="memo.hasPdf"> · with PDF</template
                            ><template v-if="memo.replacesTitle">
                                · replaces "{{ memo.replacesTitle }}"</template
                            >
                        </p>
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        <StatusBadge
                            v-if="memo.status !== 'open'"
                            :status="memo.status"
                            :label="statusLabel[memo.status]"
                            tone="neutral"
                        />
                        <p
                            class="text-sm font-medium tabular-nums"
                            data-test="memo-count"
                        >
                            {{ memo.read }} of {{ memo.expected }} expected now
                            have read
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        class="max-lg:min-h-11"
                        :disabled="opening === memo.id"
                        @click="openMemo(memo, false)"
                        >Read</Button
                    >
                    <Button
                        v-if="memo.hasPdf"
                        as-child
                        variant="outline"
                        class="max-lg:min-h-11"
                    >
                        <a
                            :href="pdfUrl(memo.id)"
                            :aria-label="`Open the PDF of ${memo.title}`"
                            >Open the PDF</a
                        >
                    </Button>
                    <Button
                        variant="outline"
                        class="max-lg:min-h-11"
                        :aria-expanded="expanded === memo.id"
                        :aria-controls="`details-${memo.id}`"
                        @click="toggle(memo.id)"
                        >{{
                            expanded === memo.id
                                ? 'Hide who has not read'
                                : `Who hasn't read yet (${memo.notYet.length})`
                        }}</Button
                    >
                    <Button
                        v-if="
                            memo.status === 'open' || memo.status === 'closed'
                        "
                        variant="outline"
                        class="max-lg:min-h-11"
                        data-test="memo-replace"
                        @click="startPublish(memo.id)"
                        >Replace with a new memo</Button
                    >
                    <Button
                        variant="outline"
                        class="max-lg:min-h-11"
                        @click="askWithdraw(memo)"
                        >Withdraw</Button
                    >
                </div>
                <p
                    v-if="openError[memo.id]"
                    class="text-sm text-destructive"
                    role="alert"
                >
                    {{ openError[memo.id] }}
                </p>
                <div
                    v-if="bodies[memo.id] !== undefined"
                    class="rounded-lg border bg-muted/40 p-3 text-sm break-words whitespace-pre-wrap"
                    data-test="memo-body"
                >
                    {{ bodies[memo.id] }}
                </div>
                <div
                    v-if="expanded === memo.id"
                    :id="`details-${memo.id}`"
                    class="flex flex-col gap-2"
                >
                    <p
                        v-if="memo.status === 'replaced'"
                        class="text-sm"
                        data-test="memo-replaced-note"
                    >
                        Replaced by "{{ memo.replacedByTitle }}". The people
                        below are asked to read the newer memo.
                    </p>
                    <h4 class="text-sm font-semibold">
                        Who hasn't read<template
                            v-if="memo.status === 'replaced'"
                        >
                            (replaced memo)</template
                        >
                    </h4>
                    <p
                        v-if="!memo.notYet.length"
                        class="text-sm text-muted-foreground"
                    >
                        Everyone expected now has read it.
                    </p>
                    <ul
                        v-else
                        class="flex flex-col divide-y text-sm"
                        data-test="memo-not-yet"
                    >
                        <li
                            v-for="p in memo.notYet"
                            :key="p.name + p.role"
                            class="py-2"
                        >
                            <span class="font-medium">{{ p.name }}</span>
                            · {{ p.role
                            }}<template v-if="p.branch">
                                · {{ p.branch }}</template
                            >
                            · published {{ p.publishedOn }}
                        </li>
                    </ul>
                </div>
            </article>

            <section
                v-if="withdrawn.length"
                aria-labelledby="withdrawn-title"
                class="flex flex-col gap-2"
            >
                <h2 id="withdrawn-title" class="text-sm font-semibold">
                    Withdrawn
                </h2>
                <ul
                    class="flex flex-col divide-y rounded-xl border bg-card text-sm"
                >
                    <li
                        v-for="memo in withdrawn"
                        :key="memo.id"
                        class="px-4 py-3"
                    >
                        {{ memo.title }} · withdrawn {{ memo.withdrawnOn }} by
                        {{ memo.withdrawnBy
                        }}<template v-if="memo.withdrawnReason">
                            ({{ memo.withdrawnReason }})</template
                        >. {{ memo.read }} of {{ memo.expected }} had read it;
                        every read is kept.
                    </li>
                </ul>
            </section>
        </section>

        <Sheet v-model:open="form.open">
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-md"
                data-test="memo-sheet"
            >
                <SheetHeader>
                    <SheetTitle>Write a memo</SheetTitle>
                    <SheetDescription
                        >A published memo cannot be edited. To correct it,
                        publish a new memo that replaces it.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="sendForm"
                >
                    <ErrorState v-if="failure" :message="failure" />
                    <div class="grid gap-1.5">
                        <Label for="memo-title">Title *</Label>
                        <Input
                            id="memo-title"
                            v-model="form.title"
                            maxlength="120"
                            class="max-lg:h-11"
                            aria-describedby="memo-title-help"
                            :aria-invalid="!!errors.title"
                        />
                        <p
                            id="memo-title-help"
                            class="text-xs text-muted-foreground"
                        >
                            Don't put a staff member's name, health or pay in
                            the title.
                        </p>
                        <InputError :message="errors.title" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="memo-body">Memo *</Label>
                        <textarea
                            id="memo-body"
                            v-model="form.body"
                            rows="8"
                            :maxlength="memos.options.maxBody"
                            class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none aria-[invalid=true]:border-destructive"
                            aria-describedby="memo-body-help"
                            :aria-invalid="errors.body ? 'true' : undefined"
                        ></textarea>
                        <p
                            id="memo-body-help"
                            class="text-xs text-muted-foreground"
                        >
                            Plain text, {{ form.body.length }} of
                            {{ memos.options.maxBody }} characters.
                        </p>
                        <InputError :message="errors.body" />
                    </div>
                    <fieldset class="flex flex-col gap-1">
                        <legend class="mb-1 text-sm font-medium">
                            Branches (none ticked means all)
                        </legend>
                        <label
                            v-for="b in memos.options.branches"
                            :key="b.value"
                            class="flex min-h-11 items-center gap-2 text-sm lg:min-h-0"
                        >
                            <input
                                v-model="form.branches"
                                type="checkbox"
                                :value="b.value"
                                class="size-4"
                            />
                            {{ b.label }}
                        </label>
                    </fieldset>
                    <fieldset class="flex flex-col gap-1">
                        <legend class="mb-1 text-sm font-medium">
                            Roles (none ticked means all)
                        </legend>
                        <label
                            v-for="r in memos.options.roles"
                            :key="r.value"
                            class="flex min-h-11 items-center gap-2 text-sm lg:min-h-0"
                        >
                            <input
                                v-model="form.roles"
                                type="checkbox"
                                :value="r.value"
                                class="size-4"
                            />
                            {{ r.label }}
                        </label>
                        <p class="text-xs text-muted-foreground">
                            With both branches and roles ticked, a person must
                            match a ticked branch and a ticked role.
                        </p>
                    </fieldset>
                    <p
                        class="text-sm font-medium"
                        role="status"
                        data-test="memo-audience-count"
                    >
                        <template v-if="preview.count !== null"
                            >Who will see this: {{ preview.count }}
                            {{ preview.count === 1 ? 'person' : 'people' }}
                        </template>
                        <template v-else>Who will see this: checking…</template>
                    </p>
                    <InputError :message="errors.audience" />
                    <div class="grid gap-1.5">
                        <Label for="memo-until">Open until (optional)</Label>
                        <Input
                            id="memo-until"
                            v-model="form.openUntil"
                            type="date"
                            :min="memos.options.today"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.open_until"
                        />
                        <InputError :message="errors.open_until" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label id="memo-replaces-label"
                            >Replaces (optional)</Label
                        >
                        <OperationalSelect
                            :model-value="form.replaces"
                            :options="replaceOptions"
                            label="Replaces"
                            labelledby="memo-replaces-label"
                            trigger-class="max-lg:min-h-11"
                            @update:model-value="
                                (v) => (form.replaces = String(v))
                            "
                        />
                        <InputError :message="errors.replaces" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="memo-file">PDF file (optional)</Label>
                        <input
                            id="memo-file"
                            type="file"
                            accept="application/pdf"
                            class="min-h-11 text-sm file:mr-3 file:rounded-md file:border file:bg-card file:px-3 file:py-2"
                            :aria-invalid="!!errors.file"
                            aria-describedby="memo-file-help"
                            @change="onFile"
                        />
                        <p
                            id="memo-file-help"
                            class="text-xs text-muted-foreground"
                        >
                            PDF only, up to {{ memos.options.maxMb }} MB. People
                            open it before they can mark the memo as read.
                        </p>
                        <InputError :message="errors.file" />
                    </div>
                    <Button
                        type="submit"
                        class="max-lg:min-h-11"
                        :disabled="form.processing"
                        data-test="memo-save"
                    >
                        {{ form.processing ? 'Publishing…' : 'Publish memo' }}
                    </Button>
                </form>
            </SheetContent>
        </Sheet>

        <OperationalConfirmDialog
            :open="withdrawing !== null"
            title="Withdraw this memo?"
            :description="
                withdrawing
                    ? `${withdrawing.title}. It leaves everyone's list. Every read already given is kept. It cannot come back; publish a new memo instead.`
                    : ''
            "
            confirm-label="Withdraw"
            processing-label="Withdrawing…"
            :processing="withdrawBusy"
            :error="failure"
            @update:open="(v: boolean) => !v && (withdrawing = null)"
            @confirm="withdraw"
        >
            <div v-if="withdrawing" class="grid gap-1.5 px-1 pt-2">
                <Label id="withdraw-reason-label">Why *</Label>
                <OperationalSelect
                    :model-value="withdrawReason"
                    :options="memos.options.withdrawReasons"
                    label="Why"
                    labelledby="withdraw-reason-label"
                    placeholder="Choose a reason"
                    trigger-class="max-lg:min-h-11"
                    @update:model-value="(v) => (withdrawReason = String(v))"
                />
                <InputError :message="errors.reason" />
            </div>
        </OperationalConfirmDialog>
    </div>
</template>
