<script setup lang="ts">
// HR-10a /hr/letters: your own letters (everyone with a staff file) and issued letters and templates (Director, HR
// Manager). Letters are printed and signed by hand; nothing is sent by email or message.
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import { csrf } from '@/components/hr/records/types';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
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

type Tab = 'mine' | 'issued' | 'templates';
type Status = 'current' | 'replaced' | 'void';
type LetterRow = {
    id: string;
    reference: string;
    title: string;
    type: string;
    typeLabel: string;
    language: string;
    issuedOn: string;
    givenOn: string | null;
    status: Status;
    voidReason: string | null;
    replacedBy: string | null;
};
type IssuedRow = LetterRow & {
    recipient: string;
    workerId: string | null;
    issuedBy: string;
    lockVersion: number;
    refusal: string | null;
};
type Template = {
    id: string;
    type: string;
    typeLabel: string;
    title: string;
    version: number;
    retired: boolean;
    lockVersion: number;
    bodyEn: string;
    bodyBm: string;
    hasDetails: boolean;
};
type WorkerOption = {
    id: string;
    name: string;
    kind: string;
    refusal: string | null;
};

const props = defineProps<{
    letters: {
        tab: Tab;
        tabs: Tab[];
        canIssue: boolean;
        mine: LetterRow[];
        issued: IssuedRow[];
        templates: Template[];
        workers: WorkerOption[];
        types: Record<string, string>;
        voidReasons: Record<string, string>;
        placeholders: string[];
        detailsTypes: string[];
        today: string;
    };
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const failure = computed(
    () => errors.value.letter ?? errors.value.records ?? errors.value.template,
);
const issuedLetter = computed(
    () =>
        (page as unknown as { flash?: { issuedLetter?: string } }).flash
            ?.issuedLetter ?? null,
);
const statusLabel: Record<Status, string> = {
    current: 'Current',
    replaced: 'Replaced',
    void: 'Void',
};
const tabLabels: Record<Tab, string> = {
    mine: 'My letters',
    issued: 'Issued letters',
    templates: 'Templates',
};
const tabs = computed(() =>
    props.letters.tabs.map((value) => ({ value, label: tabLabels[value] })),
);
const selectTab = (value: string | number) =>
    router.get('/hr/letters', { tab: value }, { preserveScroll: true });
const letterUrl = (id: string) => `/hr/letters/${id}`;
const option = (record: Record<string, string>) =>
    Object.entries(record).map(([value, label]) => ({ value, label }));
const fields = computed(() =>
    props.letters.placeholders.map((p) => `{${p}}`).join(', '),
);

// ---- Issue ----
const activeTemplates = computed(() =>
    props.letters.templates.filter((t) => !t.retired),
);
const workerOptions = computed(() =>
    props.letters.workers
        .filter((w) => w.refusal === null)
        .map((w) => ({
            value: w.id,
            label: w.kind === 'locum' ? `${w.name} (locum)` : w.name,
        })),
);
const issue = reactive({
    open: false,
    worker: '',
    template: '',
    language: 'en',
    details: '',
    replaces: '',
    replacesReference: '',
    key: '',
    processing: false,
});
const chosenTemplate = computed(() =>
    props.letters.templates.find((t) => t.id === issue.template),
);
const templateOptions = computed(() =>
    activeTemplates.value
        .filter(
            (t) =>
                !issue.replaces ||
                t.type === replacedType.value ||
                t.id === issue.template,
        )
        .map((t) => ({
            value: t.id,
            label: `${t.title} (${t.typeLabel}, version ${t.version})`,
        })),
);
const replacedType = computed(
    () => props.letters.issued.find((l) => l.id === issue.replaces)?.type ?? '',
);
const startIssue = (replacing: IssuedRow | null = null) => {
    Object.assign(issue, {
        open: true,
        worker: replacing?.workerId ?? '',
        template: '',
        language: 'en',
        details: '',
        replaces: replacing?.id ?? '',
        replacesReference: replacing?.reference ?? '',
        key: crypto.randomUUID(),
    });
    preview.text = null;
    preview.error = '';
};

// The preview comes from the server, so it is exactly what will be stored. Any change clears it.
const preview = reactive({
    text: null as string | null,
    title: '',
    version: 0,
    error: '',
    busy: false,
});
watch(
    () => [issue.worker, issue.template, issue.language, issue.details],
    () => {
        preview.text = null;
        preview.error = '';
    },
);
const loadPreview = async () => {
    preview.busy = true;
    preview.error = '';

    try {
        const response = await fetch('/hr/letters/preview', {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({
                worker_id: issue.worker,
                template_id: issue.template,
                language: issue.language,
                details: issue.details,
            }),
        });
        const data = (await response.json().catch(() => ({}))) as {
            body?: string;
            title?: string;
            templateVersion?: number;
            errors?: Record<string, string>;
        };

        if (!response.ok || typeof data.body !== 'string') {
            preview.error =
                Object.values(data.errors ?? {})[0] ??
                'The preview could not be made. Reload the page.';

            return;
        }

        preview.text = data.body;
        preview.title = data.title ?? '';
        preview.version = data.templateVersion ?? 0;
    } catch {
        preview.error = 'The preview could not be made. Check your connection.';
    } finally {
        preview.busy = false;
    }
};
const confirmIssue = ref(false);
const workerName = computed(
    () => props.letters.workers.find((w) => w.id === issue.worker)?.name ?? '',
);
const sendIssue = () => {
    confirmIssue.value = false;
    issue.processing = true;
    router.post(
        '/hr/letters',
        {
            worker_id: issue.worker,
            template_id: issue.template,
            language: issue.language,
            details: issue.details,
            ...(issue.replaces ? { replaces: issue.replaces } : {}),
            expected_template_version: preview.version,
            idempotency_key: issue.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (issue.open = false),
            onFinish: () => (issue.processing = false),
        },
    );
};

// ---- Void and given ----
const voiding = ref<IssuedRow | null>(null);
const voidReason = ref('');
const voidKey = ref('');
const voidBusy = ref(false);
const askVoid = (letter: IssuedRow) => {
    voiding.value = letter;
    voidReason.value = '';
    voidKey.value = crypto.randomUUID();
};
const sendVoid = () => {
    const letter = voiding.value;

    if (!letter) {
        return;
    }

    voidBusy.value = true;
    router.post(
        `/hr/letters/${letter.id}/void`,
        {
            reason: voidReason.value,
            expected_lock_version: letter.lockVersion,
            idempotency_key: voidKey.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => (voiding.value = null),
            onFinish: () => (voidBusy.value = false),
        },
    );
};
const giving = ref<IssuedRow | null>(null);
const givenOn = ref('');
const givenKey = ref('');
const givenBusy = ref(false);
const askGiven = (letter: IssuedRow) => {
    giving.value = letter;
    givenOn.value = props.letters.today;
    givenKey.value = crypto.randomUUID();
};
const sendGiven = () => {
    const letter = giving.value;

    if (!letter) {
        return;
    }

    givenBusy.value = true;
    router.post(
        `/hr/letters/${letter.id}/given`,
        {
            given_on: givenOn.value,
            expected_lock_version: letter.lockVersion,
            idempotency_key: givenKey.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => (giving.value = null),
            onFinish: () => (givenBusy.value = false),
        },
    );
};

// ---- Templates ----
const tpl = reactive({
    open: false,
    id: '',
    type: '',
    title: '',
    bodyEn: '',
    bodyBm: '',
    lockVersion: 0,
    key: '',
    processing: false,
});
const startTemplate = (template: Template | null = null) => {
    Object.assign(tpl, {
        open: true,
        id: template?.id ?? '',
        type: template?.type ?? '',
        title: template?.title ?? '',
        bodyEn: template?.bodyEn ?? '',
        bodyBm: template?.bodyBm ?? '',
        lockVersion: template?.lockVersion ?? 0,
        key: crypto.randomUUID(),
    });
};
const detailsAllowed = computed(() =>
    props.letters.detailsTypes.includes(tpl.type),
);
const saveTemplate = () => {
    tpl.processing = true;
    const data = {
        title: tpl.title,
        body_en: tpl.bodyEn,
        body_bm: tpl.bodyBm,
        idempotency_key: tpl.key,
    };
    router.post(
        tpl.id
            ? `/hr/letters/templates/${tpl.id}/revise`
            : '/hr/letters/templates',
        tpl.id
            ? { ...data, expected_lock_version: tpl.lockVersion }
            : { ...data, type: tpl.type },
        {
            preserveScroll: true,
            onSuccess: () => (tpl.open = false),
            onFinish: () => (tpl.processing = false),
        },
    );
};
const retiring = ref<Template | null>(null);
const retireKey = ref('');
const retireBusy = ref(false);
const askRetire = (template: Template) => {
    retiring.value = template;
    retireKey.value = crypto.randomUUID();
};
const sendRetire = () => {
    const template = retiring.value;

    if (!template) {
        return;
    }

    retireBusy.value = true;
    router.post(
        `/hr/letters/templates/${template.id}/retire`,
        {
            expected_lock_version: template.lockVersion,
            idempotency_key: retireKey.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => (retiring.value = null),
            onFinish: () => (retireBusy.value = false),
        },
    );
};
const textareaClass =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none aria-[invalid=true]:border-destructive';
</script>

<template>
    <Head title="Letters" />
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-4 px-4 py-4 md:px-5">
        <div class="flex flex-col gap-1">
            <PageHeader
                eyebrow="KPOne HR"
                title="Letters"
                :description="
                    letters.canIssue
                        ? 'Write letter templates and issue numbered letters to a staff file. A letter is printed, signed by hand and given in person; nothing is sent. An issued letter is never edited: replace it or void it.'
                        : 'Letters HR issued to you. Open one to read or print it.'
                "
            />
            <SyntheticNote />
        </div>

        <OperationalTabs
            v-if="tabs.length > 1"
            :model-value="letters.tab"
            :tabs="tabs"
            label="Letter sections"
            class="min-w-0 max-lg:[&>button]:h-11 max-sm:[&>button]:px-2.5"
            @update:model-value="selectTab"
        />

        <ErrorState
            v-if="failure && !issue.open && !tpl.open && !voiding && !giving"
            :message="failure"
        />

        <div
            v-if="issuedLetter"
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-4"
            role="status"
            data-test="letter-issued"
        >
            <p class="text-sm">
                The letter is issued. Open it to print, then sign it and give it
                by hand.
            </p>
            <Button as-child class="max-lg:min-h-11">
                <Link :href="letterUrl(issuedLetter)">Open to print</Link>
            </Button>
        </div>

        <!-- My letters -->
        <section
            v-if="letters.tab === 'mine'"
            aria-labelledby="my-letters-title"
            class="flex flex-col gap-3"
            data-test="my-letters"
        >
            <h2 id="my-letters-title" class="sr-only">My letters</h2>
            <EmptyState
                v-if="!letters.mine.length"
                title="No letters for you."
                description="When HR issues a letter to you, it appears here."
            />
            <ul v-else class="flex flex-col divide-y rounded-xl border bg-card">
                <li
                    v-for="letter in letters.mine"
                    :key="letter.id"
                    class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                    data-test="my-letter"
                >
                    <div class="min-w-0">
                        <p class="font-medium break-words">
                            {{ letter.title }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ letter.reference }} · issued
                            {{ letter.issuedOn }}
                            <template v-if="letter.givenOn">
                                · given {{ letter.givenOn }}</template
                            ><template v-if="letter.replacedBy">
                                · replaced by {{ letter.replacedBy }}</template
                            >
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <StatusBadge
                            v-if="letter.status !== 'current'"
                            :status="letter.status"
                            :label="statusLabel[letter.status]"
                            tone="neutral"
                        />
                        <Button
                            as-child
                            variant="outline"
                            class="max-lg:min-h-11"
                        >
                            <Link
                                :href="letterUrl(letter.id)"
                                :aria-label="`Open ${letter.title}, ${letter.reference}`"
                                >Open</Link
                            >
                        </Button>
                    </div>
                </li>
            </ul>
        </section>

        <!-- Issued letters (Director, HR Manager) -->
        <section
            v-if="letters.tab === 'issued'"
            aria-labelledby="issued-title"
            class="flex flex-col gap-3"
            data-test="issued-letters"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="issued-title" class="text-base font-semibold">
                    Issued letters
                </h2>
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        v-if="!activeTemplates.length"
                        id="issue-hint"
                        class="text-sm text-muted-foreground"
                        >Add a template first.</span
                    >
                    <Button
                        class="max-lg:min-h-11"
                        :disabled="!activeTemplates.length"
                        :aria-describedby="
                            activeTemplates.length ? undefined : 'issue-hint'
                        "
                        data-test="letter-issue"
                        @click="startIssue()"
                        >Issue a letter</Button
                    >
                </div>
            </div>
            <EmptyState
                v-if="!letters.issued.length"
                title="No letters issued yet."
                description="Add a template, then issue a letter to a staff file."
            />
            <ul v-else class="flex flex-col divide-y rounded-xl border bg-card">
                <li
                    v-for="letter in letters.issued"
                    :key="letter.id"
                    class="flex flex-col gap-2 px-4 py-3 lg:flex-row lg:items-center lg:justify-between"
                    data-test="issued-letter"
                >
                    <div class="min-w-0">
                        <p class="font-medium break-words">
                            {{ letter.recipient }} · {{ letter.title }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ letter.reference }} · {{ letter.typeLabel }} ·
                            {{ letter.language }} · issued
                            {{ letter.issuedOn }} by {{ letter.issuedBy
                            }}<template v-if="letter.givenOn">
                                · given {{ letter.givenOn }}</template
                            ><template v-if="letter.voidReason">
                                · void ({{ letter.voidReason }})</template
                            ><template v-if="letter.replacedBy">
                                · replaced by {{ letter.replacedBy }}</template
                            >
                        </p>
                        <p
                            v-if="letter.refusal"
                            class="text-sm text-muted-foreground"
                        >
                            {{ letter.refusal }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <StatusBadge
                            v-if="letter.status !== 'current'"
                            :status="letter.status"
                            :label="statusLabel[letter.status]"
                            tone="neutral"
                        />
                        <template v-if="!letter.refusal">
                            <Button
                                as-child
                                variant="outline"
                                class="max-lg:min-h-11"
                            >
                                <Link
                                    :href="letterUrl(letter.id)"
                                    :aria-label="`Open ${letter.reference}`"
                                    >Open</Link
                                >
                            </Button>
                            <template v-if="letter.status === 'current'">
                                <Button
                                    v-if="!letter.givenOn"
                                    variant="outline"
                                    class="max-lg:min-h-11"
                                    :aria-label="`Record when ${letter.reference} was given`"
                                    data-test="letter-given"
                                    @click="askGiven(letter)"
                                    >Record given</Button
                                >
                                <Button
                                    variant="outline"
                                    class="max-lg:min-h-11"
                                    :disabled="!activeTemplates.length"
                                    :aria-label="`Replace ${letter.reference}`"
                                    data-test="letter-replace"
                                    @click="startIssue(letter)"
                                    >Replace</Button
                                >
                                <Button
                                    variant="outline"
                                    class="text-destructive max-lg:min-h-11"
                                    :aria-label="`Void ${letter.reference}`"
                                    data-test="letter-void"
                                    @click="askVoid(letter)"
                                    >Void</Button
                                >
                            </template>
                        </template>
                    </div>
                </li>
            </ul>
        </section>

        <!-- Templates (Director, HR Manager) -->
        <section
            v-if="letters.tab === 'templates'"
            aria-labelledby="templates-title"
            class="flex flex-col gap-3"
            data-test="letter-templates"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="templates-title" class="text-base font-semibold">
                    Templates
                </h2>
                <Button
                    variant="outline"
                    class="max-lg:min-h-11"
                    data-test="template-new"
                    @click="startTemplate()"
                    >New template</Button
                >
            </div>
            <p class="text-sm text-muted-foreground">
                KPOne ships no letter wording. Have the clinic's adviser approve
                each template before real use.
            </p>
            <EmptyState
                v-if="!letters.templates.length"
                title="No templates yet."
                description="Write the clinic's own wording in English and Malay."
            />
            <ul v-else class="flex flex-col divide-y rounded-xl border bg-card">
                <li
                    v-for="template in letters.templates"
                    :key="template.id"
                    class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                    data-test="letter-template"
                >
                    <div class="min-w-0">
                        <p class="font-medium break-words">
                            {{ template.title }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ template.typeLabel }} · version
                            {{ template.version }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <StatusBadge
                            v-if="template.retired"
                            status="retired"
                            label="Retired"
                            tone="neutral"
                        />
                        <template v-else>
                            <Button
                                variant="outline"
                                class="max-lg:min-h-11"
                                :aria-label="`Edit ${template.title}`"
                                @click="startTemplate(template)"
                                >Edit</Button
                            >
                            <Button
                                variant="outline"
                                class="max-lg:min-h-11"
                                :aria-label="`Retire ${template.title}`"
                                @click="askRetire(template)"
                                >Retire</Button
                            >
                        </template>
                    </div>
                </li>
            </ul>
        </section>

        <!-- Issue sheet -->
        <Sheet v-model:open="issue.open">
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-lg"
                data-test="issue-sheet"
            >
                <SheetHeader>
                    <SheetTitle>{{
                        issue.replaces
                            ? `Replace ${issue.replacesReference}`
                            : 'Issue a letter'
                    }}</SheetTitle>
                    <SheetDescription>{{
                        issue.replaces
                            ? 'The new letter gets the next number, and the old one becomes void as replaced. Both are kept.'
                            : 'Check the preview, then issue. The letter gets the next number and cannot be edited.'
                    }}</SheetDescription>
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="confirmIssue = true"
                >
                    <ErrorState v-if="failure" :message="failure" />
                    <div class="grid gap-1.5">
                        <Label id="issue-worker-label">Staff file *</Label>
                        <OperationalSelect
                            :model-value="issue.worker"
                            :options="workerOptions"
                            label="Staff file"
                            labelledby="issue-worker-label"
                            placeholder="Choose a staff file"
                            :disabled="!!issue.replaces"
                            trigger-class="max-lg:min-h-11"
                            @update:model-value="
                                (v) => (issue.worker = String(v))
                            "
                        />
                        <InputError :message="errors.worker_id" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label id="issue-template-label">Template *</Label>
                        <OperationalSelect
                            :model-value="issue.template"
                            :options="templateOptions"
                            label="Template"
                            labelledby="issue-template-label"
                            placeholder="Choose a template"
                            trigger-class="max-lg:min-h-11"
                            @update:model-value="
                                (v) => (issue.template = String(v))
                            "
                        />
                        <InputError :message="errors.template_id" />
                    </div>
                    <fieldset class="flex flex-col gap-1">
                        <legend class="mb-1 text-sm font-medium">
                            Language *
                        </legend>
                        <label
                            v-for="l in [
                                { value: 'en', label: 'English' },
                                { value: 'bm', label: 'Malay' },
                            ]"
                            :key="l.value"
                            class="flex min-h-11 items-center gap-2 text-sm lg:min-h-0"
                        >
                            <input
                                v-model="issue.language"
                                type="radio"
                                name="issue-language"
                                :value="l.value"
                                class="size-4"
                            />
                            {{ l.label }}
                        </label>
                        <InputError :message="errors.language" />
                    </fieldset>
                    <div v-if="chosenTemplate?.hasDetails" class="grid gap-1.5">
                        <Label for="issue-details"
                            >Paragraph for this letter *</Label
                        >
                        <textarea
                            id="issue-details"
                            v-model="issue.details"
                            rows="6"
                            maxlength="2000"
                            :class="textareaClass"
                            aria-describedby="issue-details-help"
                            :aria-invalid="errors.details ? 'true' : undefined"
                        ></textarea>
                        <p
                            id="issue-details-help"
                            class="text-xs text-muted-foreground"
                        >
                            It fills {details} in the template. Plain text,
                            {{ issue.details.length }} of 2,000 characters.
                        </p>
                        <InputError :message="errors.details" />
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        class="max-lg:min-h-11"
                        :disabled="
                            !issue.worker || !issue.template || preview.busy
                        "
                        data-test="letter-preview"
                        @click="loadPreview"
                        >{{ preview.busy ? 'Checking…' : 'Preview' }}</Button
                    >
                    <p
                        v-if="preview.error"
                        class="text-sm text-destructive"
                        role="alert"
                    >
                        {{ preview.error }}
                    </p>
                    <div
                        v-if="preview.text !== null"
                        class="flex flex-col gap-2 rounded-lg border bg-muted/40 p-3"
                        data-test="letter-preview-text"
                    >
                        <p class="text-sm font-semibold">{{ preview.title }}</p>
                        <p class="text-sm break-words whitespace-pre-wrap">
                            {{ preview.text }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            The number replaces "……" when you issue.
                        </p>
                    </div>
                    <Button
                        type="submit"
                        class="max-lg:min-h-11"
                        :disabled="preview.text === null || issue.processing"
                        :aria-describedby="
                            preview.text === null
                                ? 'issue-needs-preview'
                                : undefined
                        "
                        data-test="letter-issue-save"
                    >
                        {{ issue.processing ? 'Issuing…' : 'Issue letter' }}
                    </Button>
                    <p
                        v-if="preview.text === null"
                        id="issue-needs-preview"
                        class="text-xs text-muted-foreground"
                    >
                        Preview the letter first.
                    </p>
                </form>
            </SheetContent>
        </Sheet>

        <OperationalConfirmDialog
            :open="confirmIssue"
            :title="
                issue.replaces ? 'Issue the replacement?' : 'Issue this letter?'
            "
            :description="`${preview.title} for ${workerName}. It gets the next number and cannot be edited.${issue.replaces ? ` ${issue.replacesReference} becomes void as replaced.` : ''}`"
            :confirm-label="
                issue.replaces ? 'Issue replacement' : 'Issue letter'
            "
            processing-label="Issuing…"
            :processing="issue.processing"
            @update:open="(v: boolean) => (confirmIssue = v)"
            @confirm="sendIssue"
        />

        <OperationalConfirmDialog
            :open="voiding !== null"
            title="Void this letter?"
            :description="
                voiding
                    ? `${voiding.reference} for ${voiding.recipient}. It stays on record as void with its reason, and the number is never used again.`
                    : ''
            "
            destructive
            confirm-label="Void"
            processing-label="Voiding…"
            :processing="voidBusy"
            :error="failure"
            @update:open="(v: boolean) => !v && (voiding = null)"
            @confirm="sendVoid"
        >
            <div v-if="voiding" class="grid gap-1.5 px-1 pt-2">
                <Label id="void-reason-label">Why *</Label>
                <OperationalSelect
                    :model-value="voidReason"
                    :options="option(letters.voidReasons)"
                    label="Why"
                    labelledby="void-reason-label"
                    placeholder="Choose a reason"
                    trigger-class="max-lg:min-h-11"
                    @update:model-value="(v) => (voidReason = String(v))"
                />
                <InputError :message="errors.reason" />
            </div>
        </OperationalConfirmDialog>

        <OperationalConfirmDialog
            :open="giving !== null"
            title="Record when it was given"
            :description="
                giving
                    ? `${giving.reference} for ${giving.recipient}. Recorded once.`
                    : ''
            "
            confirm-label="Record"
            processing-label="Saving…"
            :processing="givenBusy"
            :error="failure"
            @update:open="(v: boolean) => !v && (giving = null)"
            @confirm="sendGiven"
        >
            <div v-if="giving" class="grid gap-1.5 px-1 pt-2">
                <Label for="given-on">Given on *</Label>
                <DateField
                    id="given-on"
                    v-model="givenOn"
                    :max="letters.today"
                    :aria-invalid="!!errors.given_on"
                />
                <InputError :message="errors.given_on" />
            </div>
        </OperationalConfirmDialog>

        <!-- Template sheet -->
        <Sheet v-model:open="tpl.open">
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-lg"
                data-test="template-sheet"
            >
                <SheetHeader>
                    <SheetTitle>{{
                        tpl.id ? 'Edit template' : 'New template'
                    }}</SheetTitle>
                    <SheetDescription
                        >Each save is a new version. Letters already issued keep
                        their own text.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="saveTemplate"
                >
                    <ErrorState v-if="failure" :message="failure" />
                    <div v-if="!tpl.id" class="grid gap-1.5">
                        <Label id="tpl-type-label">Kind of letter *</Label>
                        <OperationalSelect
                            :model-value="tpl.type"
                            :options="option(letters.types)"
                            label="Kind of letter"
                            labelledby="tpl-type-label"
                            placeholder="Choose a kind"
                            trigger-class="max-lg:min-h-11"
                            @update:model-value="(v) => (tpl.type = String(v))"
                        />
                        <InputError :message="errors.type" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="tpl-title">Title *</Label>
                        <Input
                            id="tpl-title"
                            v-model="tpl.title"
                            maxlength="80"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.title"
                        />
                        <InputError :message="errors.title" />
                    </div>
                    <p
                        id="tpl-fields-help"
                        class="text-xs text-muted-foreground"
                    >
                        Fields KPOne fills: {{ fields }}.
                        <template v-if="detailsAllowed"
                            >{details} is the paragraph you write for each
                            letter.</template
                        ><template v-else
                            >{details} is only for warning and show-cause
                            letters.</template
                        >
                    </p>
                    <div class="grid gap-1.5">
                        <Label for="tpl-en">English text *</Label>
                        <textarea
                            id="tpl-en"
                            v-model="tpl.bodyEn"
                            rows="10"
                            maxlength="5000"
                            :class="textareaClass"
                            aria-describedby="tpl-fields-help"
                            :aria-invalid="errors.body_en ? 'true' : undefined"
                        ></textarea>
                        <InputError :message="errors.body_en" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="tpl-bm">Malay text *</Label>
                        <textarea
                            id="tpl-bm"
                            v-model="tpl.bodyBm"
                            rows="10"
                            maxlength="5000"
                            :class="textareaClass"
                            aria-describedby="tpl-fields-help"
                            :aria-invalid="errors.body_bm ? 'true' : undefined"
                        ></textarea>
                        <InputError :message="errors.body_bm" />
                    </div>
                    <Button
                        type="submit"
                        class="max-lg:min-h-11"
                        :disabled="tpl.processing"
                        data-test="template-save"
                    >
                        {{ tpl.processing ? 'Saving…' : 'Save template' }}
                    </Button>
                </form>
            </SheetContent>
        </Sheet>

        <OperationalConfirmDialog
            :open="retiring !== null"
            title="Retire this template?"
            :description="
                retiring
                    ? `${retiring.title}. It can no longer be used to issue letters. Letters already issued are kept.`
                    : ''
            "
            confirm-label="Retire"
            cancel-label="Keep template"
            processing-label="Retiring…"
            :processing="retireBusy"
            :error="failure"
            @update:open="(v: boolean) => !v && (retiring = null)"
            @confirm="sendRetire"
        />
    </div>
</template>
