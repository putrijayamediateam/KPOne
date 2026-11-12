<script setup lang="ts">
// HR-5c /hr/sops: SOPs to read (everyone) and the SOP list with who has not acknowledged (Director, HR Manager).
// HR-PLAN 12.2 and 12.6, with Daniel's early look folded in.
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { PageHeader } from '@/components/ui/page-header';
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
type Option = { value: string; label: string };
type MySop = {
    versionId: string;
    title: string;
    version: number;
    publishedOn: string;
    opened: boolean;
    acknowledgedOn: string | null;
    earlier: { version: number; on: string } | null;
};
type ManagedSop = {
    id: string;
    title: string;
    version: number;
    versionId: string | null;
    roles: string[];
    roleValues: string[];
    publishedOn: string | null;
    isActive: boolean;
    withdrawnOn: string | null;
    withdrawnBy: string | null;
    lockVersion: number;
    acknowledged: number;
    audience: number;
    notYet: {
        name: string;
        role: string;
        branch: string | null;
        publishedOn: string | null;
    }[];
    versions: {
        versionId: string;
        version: number;
        title: string;
        publishedOn: string;
        publishedBy: string;
        roles: string[];
    }[];
};

const props = defineProps<{
    sops: {
        tab: Tab;
        tabs: Tab[];
        canManage: boolean;
        options: { roles: Option[]; maxMb: number };
        my: MySop[] | null;
        manage: { sops: ManagedSop[] } | null;
    };
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
// A refusal from the SOP rules, or a stale page ("This was changed by someone else"; Suresh, HR-5c S1).
const failure = computed(() => errors.value.sop ?? errors.value.records);
const tabLabels: Record<Tab, string> = {
    my: 'SOPs to read',
    manage: 'All SOPs',
};
const toRead = computed(
    () => (props.sops.my ?? []).filter((s) => !s.acknowledgedOn).length,
);
const tabs = computed(() =>
    props.sops.tabs.map((value) => ({
        value,
        label:
            value === 'my' && toRead.value > 0
                ? `SOPs to read (${toRead.value})`
                : tabLabels[value],
    })),
);
const selectTab = (value: string | number) =>
    router.get('/hr/sops', { tab: value }, { preserveScroll: true });

// ---- SOPs to read ----
// The download opens in the browser; the server records the open first. Until the page reloads, the button below
// knows it was opened here, and the server still refuses an acknowledgement without a recorded open.
const openedHere = ref(new Set<string>());
const openUrl = (versionId: string) => `/hr/sops/versions/${versionId}/open`;
// Daniel (2026-11-12): one primary per screen. Only the SOP opened most recently here shows a cobalt
// "I have read and understood"; every other card keeps it outline (and disabled until opened).
const lastOpened = ref<string | null>(null);
const markOpened = (versionId: string) => {
    openedHere.value = new Set([...openedHere.value, versionId]);
    lastOpened.value = versionId;
};
const isOpened = (sop: MySop) =>
    sop.opened || openedHere.value.has(sop.versionId);
const ack = reactive({ versionId: '', key: '', processing: false });
const acknowledge = (sop: MySop) => {
    if (!isOpened(sop) || ack.processing) {
        return;
    }

    if (ack.versionId !== sop.versionId) {
        Object.assign(ack, {
            versionId: sop.versionId,
            key: crypto.randomUUID(),
        });
    }

    ack.processing = true;
    router.post(
        `/hr/sops/versions/${sop.versionId}/acknowledge`,
        { idempotency_key: ack.key },
        {
            preserveScroll: true,
            onSuccess: () => (ack.versionId = ''),
            onFinish: () => (ack.processing = false),
        },
    );
};

// ---- Publish or a new version ----
const form = reactive({
    open: false,
    sop: null as ManagedSop | null,
    title: '',
    roles: [] as string[],
    file: null as File | null,
    key: '',
    processing: false,
});
const startPublish = () =>
    Object.assign(form, {
        open: true,
        sop: null,
        title: '',
        roles: [],
        file: null,
        key: crypto.randomUUID(),
    });
const startVersion = (sop: ManagedSop) =>
    Object.assign(form, {
        open: true,
        sop,
        title: sop.title,
        roles: [...sop.roleValues],
        file: null,
        key: crypto.randomUUID(),
    });
const onFile = (event: Event) => {
    form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
};
const sendForm = () => {
    form.processing = true;
    router.post(
        form.sop ? `/hr/sops/${form.sop.id}/versions` : '/hr/sops',
        {
            title: form.title,
            roles: form.roles,
            ...(form.file ? { file: form.file } : {}),
            ...(form.sop
                ? { expected_lock_version: form.sop.lockVersion }
                : {}),
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
const withdrawing = ref<ManagedSop | null>(null);
const withdrawKey = ref('');
const withdrawBusy = ref(false);
const askWithdraw = (sop: ManagedSop) => {
    withdrawing.value = sop;
    withdrawKey.value = crypto.randomUUID();
};
const withdraw = () => {
    const sop = withdrawing.value;

    if (!sop) {
        return;
    }

    withdrawBusy.value = true;
    router.post(
        `/hr/sops/${sop.id}/withdraw`,
        {
            expected_lock_version: sop.lockVersion,
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
const active = computed(() =>
    (props.sops.manage?.sops ?? []).filter((s) => s.isActive),
);
const withdrawn = computed(() =>
    (props.sops.manage?.sops ?? []).filter((s) => !s.isActive),
);
</script>

<template>
    <Head title="SOPs" />
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-4 px-4 py-4 md:px-5">
        <div class="flex flex-col gap-1">
            <PageHeader
                eyebrow="KPOne HR"
                title="SOPs"
                :description="
                    sops.tab === 'manage'
                        ? 'Publish SOPs to the roles that must read them, and see who has not acknowledged yet. A new file is a new version, read again by everyone it is for.'
                        : 'SOPs for your role. Open each one, then press I have read and understood.'
                "
            />
            <SyntheticNote />
        </div>

        <OperationalTabs
            v-if="tabs.length > 1"
            :model-value="sops.tab"
            :tabs="tabs"
            label="SOP sections"
            class="min-w-0 max-lg:[&>button]:h-11 max-sm:[&>button]:px-2.5"
            @update:model-value="selectTab"
        />

        <ErrorState
            v-if="failure && !form.open && !withdrawing"
            :message="failure"
        />

        <!-- SOPs to read -->
        <section
            v-if="sops.tab === 'my' && sops.my"
            aria-labelledby="my-sops-title"
            class="flex flex-col gap-3"
            data-test="my-sops"
        >
            <h2 id="my-sops-title" class="sr-only">SOPs to read</h2>
            <EmptyState
                v-if="!sops.my.length"
                title="No SOPs for your role yet."
                description="When HR publishes one for your role, it appears here."
            />
            <article
                v-for="sop in sops.my"
                :key="sop.versionId"
                class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                :aria-labelledby="`sop-${sop.versionId}`"
                data-test="sop-card"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h3
                            :id="`sop-${sop.versionId}`"
                            class="text-base font-semibold break-words"
                        >
                            {{ sop.title }}
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            Version {{ sop.version }} · published
                            {{ sop.publishedOn }}
                        </p>
                    </div>
                    <StatusBadge
                        v-if="sop.acknowledgedOn"
                        status="acknowledged"
                        label="Acknowledged"
                        tone="success"
                    />
                </div>

                <p
                    v-if="!sop.acknowledgedOn && sop.earlier"
                    class="text-sm"
                    data-test="sop-new-version"
                >
                    Version {{ sop.version }} is new. Please read it again.
                    <span class="text-muted-foreground"
                        >You acknowledged version {{ sop.earlier.version }} on
                        {{ sop.earlier.on }}.</span
                    >
                </p>

                <p
                    v-if="sop.acknowledgedOn"
                    class="text-sm text-muted-foreground"
                >
                    Acknowledged on {{ sop.acknowledgedOn }}.
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    <Button as-child variant="outline" class="max-lg:min-h-11">
                        <a
                            :href="openUrl(sop.versionId)"
                            :aria-label="`Open ${sop.title}, version ${sop.version} (PDF)`"
                            data-test="sop-open"
                            @click="markOpened(sop.versionId)"
                            >Open</a
                        >
                    </Button>
                    <template v-if="!sop.acknowledgedOn">
                        <Button
                            :variant="
                                lastOpened === sop.versionId
                                    ? 'default'
                                    : 'outline'
                            "
                            class="max-lg:min-h-11"
                            :disabled="
                                !isOpened(sop) ||
                                (ack.processing &&
                                    ack.versionId === sop.versionId)
                            "
                            :aria-describedby="
                                isOpened(sop)
                                    ? undefined
                                    : `hint-${sop.versionId}`
                            "
                            data-test="sop-acknowledge"
                            @click="acknowledge(sop)"
                        >
                            {{
                                ack.processing &&
                                ack.versionId === sop.versionId
                                    ? 'Saving…'
                                    : 'I have read and understood'
                            }}
                        </Button>
                        <span
                            v-if="!isOpened(sop)"
                            :id="`hint-${sop.versionId}`"
                            class="text-sm text-muted-foreground"
                            >Open the SOP first.</span
                        >
                    </template>
                </div>
            </article>
        </section>

        <!-- All SOPs (Director, HR Manager) -->
        <section
            v-if="sops.tab === 'manage' && sops.manage"
            aria-labelledby="manage-sops-title"
            class="flex flex-col gap-3"
            data-test="manage-sops"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="manage-sops-title" class="text-base font-semibold">
                    Published SOPs
                </h2>
                <Button
                    variant="outline"
                    class="max-lg:min-h-11"
                    data-test="sop-publish"
                    @click="startPublish"
                    >Publish an SOP</Button
                >
            </div>
            <EmptyState
                v-if="!active.length"
                title="No SOPs published yet."
                description="Publish an SOP as a PDF and choose the roles that must read it."
            />
            <article
                v-for="sop in active"
                :key="sop.id"
                class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                :aria-labelledby="`manage-${sop.id}`"
                data-test="managed-sop"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h3
                            :id="`manage-${sop.id}`"
                            class="text-base font-semibold break-words"
                        >
                            {{ sop.title }}
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            Version {{ sop.version }} · published
                            {{ sop.publishedOn }} · for
                            {{ sop.roles.join(', ') }}
                        </p>
                    </div>
                    <p
                        class="text-sm font-medium tabular-nums"
                        data-test="sop-count"
                    >
                        {{ sop.acknowledged }} of {{ sop.audience }}
                        acknowledged
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="sop.versionId"
                        as-child
                        variant="outline"
                        class="max-lg:min-h-11"
                    >
                        <a
                            :href="openUrl(sop.versionId)"
                            :aria-label="`Open ${sop.title}, version ${sop.version} (PDF)`"
                            >Open</a
                        >
                    </Button>
                    <Button
                        variant="outline"
                        class="max-lg:min-h-11"
                        :aria-expanded="expanded === sop.id"
                        :aria-controls="`details-${sop.id}`"
                        @click="toggle(sop.id)"
                        >{{
                            expanded === sop.id
                                ? 'Hide details'
                                : sop.notYet.length
                                  ? `Who hasn't acknowledged (${sop.notYet.length})`
                                  : 'Show versions'
                        }}</Button
                    >
                    <Button
                        variant="outline"
                        class="max-lg:min-h-11"
                        data-test="sop-new-version-button"
                        @click="startVersion(sop)"
                        >New version</Button
                    >
                    <Button
                        variant="outline"
                        class="max-lg:min-h-11"
                        @click="askWithdraw(sop)"
                        >Withdraw</Button
                    >
                </div>
                <div
                    v-if="expanded === sop.id"
                    :id="`details-${sop.id}`"
                    class="grid gap-4 lg:grid-cols-2"
                >
                    <section
                        v-if="sop.notYet.length"
                        :aria-labelledby="`notyet-${sop.id}`"
                    >
                        <h4
                            :id="`notyet-${sop.id}`"
                            class="mb-2 text-sm font-semibold"
                        >
                            Who hasn't acknowledged version {{ sop.version }}
                        </h4>
                        <ul
                            class="flex flex-col divide-y text-sm"
                            data-test="sop-not-yet"
                        >
                            <li
                                v-for="p in sop.notYet"
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
                    </section>
                    <section :aria-labelledby="`versions-${sop.id}`">
                        <h4
                            :id="`versions-${sop.id}`"
                            class="mb-2 text-sm font-semibold"
                        >
                            Versions
                        </h4>
                        <ul class="flex flex-col divide-y text-sm">
                            <li
                                v-for="v in sop.versions"
                                :key="v.versionId"
                                class="flex flex-wrap items-center justify-between gap-2 py-2"
                            >
                                <span class="min-w-0"
                                    >Version {{ v.version }} · {{ v.title }} ·
                                    {{ v.publishedOn }} by {{ v.publishedBy }} ·
                                    for {{ v.roles.join(', ') }}</span
                                >
                                <a
                                    :href="openUrl(v.versionId)"
                                    class="inline-flex min-h-11 items-center underline underline-offset-4 lg:min-h-0"
                                    :aria-label="`Open version ${v.version} (PDF)`"
                                    >Open</a
                                >
                            </li>
                        </ul>
                    </section>
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
                        v-for="sop in withdrawn"
                        :key="sop.id"
                        class="flex flex-wrap items-center justify-between gap-2 px-4 py-3"
                    >
                        <span class="min-w-0"
                            >{{ sop.title }} · version {{ sop.version }} ·
                            withdrawn {{ sop.withdrawnOn }} by
                            {{ sop.withdrawnBy }}. Its acknowledgements are
                            kept.</span
                        >
                        <a
                            v-if="sop.versionId"
                            :href="openUrl(sop.versionId)"
                            class="inline-flex min-h-11 items-center underline underline-offset-4 lg:min-h-0"
                            :aria-label="`Open ${sop.title}, version ${sop.version} (PDF)`"
                            >Open</a
                        >
                    </li>
                </ul>
            </section>
        </section>

        <Sheet v-model:open="form.open">
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-md"
                data-test="sop-sheet"
            >
                <SheetHeader>
                    <SheetTitle>{{
                        form.sop
                            ? `New version of ${form.sop.title}`
                            : 'Publish an SOP'
                    }}</SheetTitle>
                    <SheetDescription>{{
                        form.sop
                            ? `Version ${form.sop.version + 1}. Earlier versions and acknowledgements stay. Everyone it is for reads it again.`
                            : 'Everyone in the roles you choose sees it under SOPs to read.'
                    }}</SheetDescription>
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="sendForm"
                >
                    <ErrorState v-if="failure" :message="failure" />
                    <div class="grid gap-1.5">
                        <Label for="sop-title">Title *</Label>
                        <Input
                            id="sop-title"
                            v-model="form.title"
                            maxlength="120"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.title"
                        />
                        <InputError :message="errors.title" />
                    </div>
                    <fieldset class="flex flex-col gap-1">
                        <legend class="mb-1 text-sm font-medium">
                            Roles that must read it *
                        </legend>
                        <label
                            v-for="r in sops.options.roles"
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
                        <InputError :message="errors.roles" />
                    </fieldset>
                    <div class="grid gap-1.5">
                        <Label for="sop-file">PDF file *</Label>
                        <input
                            id="sop-file"
                            type="file"
                            accept="application/pdf"
                            class="min-h-11 text-sm file:mr-3 file:rounded-md file:border file:bg-card file:px-3 file:py-2"
                            :aria-invalid="!!errors.file"
                            aria-describedby="sop-file-help"
                            @change="onFile"
                        />
                        <p
                            id="sop-file-help"
                            class="text-xs text-muted-foreground"
                        >
                            PDF only, up to {{ sops.options.maxMb }} MB.
                        </p>
                        <InputError :message="errors.file" />
                    </div>
                    <Button
                        type="submit"
                        class="max-lg:min-h-11"
                        :disabled="form.processing"
                        data-test="sop-save"
                    >
                        {{
                            form.processing
                                ? 'Publishing…'
                                : form.sop
                                  ? 'Publish new version'
                                  : 'Publish'
                        }}
                    </Button>
                </form>
            </SheetContent>
        </Sheet>

        <OperationalConfirmDialog
            :open="withdrawing !== null"
            title="Withdraw this SOP?"
            :description="
                withdrawing
                    ? `${withdrawing.title}. It leaves everyone's SOPs to read. Its versions and acknowledgements are kept. It cannot come back; publish a new SOP instead.`
                    : ''
            "
            confirm-label="Withdraw"
            processing-label="Withdrawing…"
            :processing="withdrawBusy"
            :error="failure"
            @update:open="(v: boolean) => !v && (withdrawing = null)"
            @confirm="withdraw"
        />
    </div>
</template>
