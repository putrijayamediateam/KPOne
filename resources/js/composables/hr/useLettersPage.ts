import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { csrf } from '@/components/hr/records/types';

/**
 * HR-10a /hr/letters page state: your letters, issued letters and templates. Every request, lock version and
 * idempotency key lives here, shared by the Classic page (`pages/HR/Letters.vue`) and Version 2
 * (`pages/HR/V2/Letters.vue`), so both send identical requests. Moved out of the Classic page unchanged; the Classic
 * template did not change. The letter text of an issued letter is never in the props: only the server's preview of a
 * letter about to be issued is fetched, on request, and any change clears it. Letters are printed and signed by hand;
 * nothing is sent.
 */
export type Tab = 'mine' | 'issued' | 'templates';
export type Status = 'current' | 'replaced' | 'void';
export type LetterRow = {
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
export type IssuedRow = LetterRow & {
    recipient: string;
    workerId: string | null;
    issuedBy: string;
    lockVersion: number;
    refusal: string | null;
};
export type Template = {
    id: string;
    type: string;
    typeLabel: string;
    title: string;
    titleBm: string;
    version: number;
    retired: boolean;
    lockVersion: number;
    bodyEn: string;
    bodyBm: string;
    hasDetails: boolean;
};
export type WorkerOption = {
    id: string;
    name: string;
    kind: string;
    refusal: string | null;
};

export type LettersPageProps = {
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
};

export function useLettersPage(props: LettersPageProps) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    const failure = computed(
        () =>
            errors.value.letter ??
            errors.value.records ??
            errors.value.template,
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
        () =>
            props.letters.issued.find((l) => l.id === issue.replaces)?.type ??
            '',
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
            preview.error =
                'The preview could not be made. Check your connection.';
        } finally {
            preview.busy = false;
        }
    };
    const confirmIssue = ref(false);
    const workerName = computed(
        () =>
            props.letters.workers.find((w) => w.id === issue.worker)?.name ??
            '',
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
        titleBm: '',
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
            titleBm: template?.titleBm ?? '',
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
            title_bm: tpl.titleBm,
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

    return {
        errors,
        failure,
        issuedLetter,
        statusLabel,
        tabs,
        selectTab,
        letterUrl,
        option,
        fields,
        activeTemplates,
        workerOptions,
        issue,
        chosenTemplate,
        templateOptions,
        startIssue,
        preview,
        loadPreview,
        confirmIssue,
        workerName,
        sendIssue,
        voiding,
        voidReason,
        voidBusy,
        askVoid,
        sendVoid,
        giving,
        givenOn,
        givenBusy,
        askGiven,
        sendGiven,
        tpl,
        startTemplate,
        detailsAllowed,
        saveTemplate,
        retiring,
        retireBusy,
        askRetire,
        sendRetire,
        textareaClass,
    };
}
