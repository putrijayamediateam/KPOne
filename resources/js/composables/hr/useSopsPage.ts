import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

/**
 * The SOPs page's state and actions (HR-5c) for `pages/HR/Sops.vue`: every request, lock version and idempotency key.
 */
export type Tab = 'my' | 'manage';
export type Option = { value: string; label: string };
export type MySop = {
    versionId: string;
    title: string;
    version: number;
    publishedOn: string;
    opened: boolean;
    acknowledgedOn: string | null;
    earlier: { version: number; on: string } | null;
};
export type ManagedSop = {
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

export type SopsPageProps = {
    sops: {
        tab: Tab;
        tabs: Tab[];
        canManage: boolean;
        options: { roles: Option[]; maxMb: number };
        my: MySop[] | null;
        manage: { sops: ManagedSop[] } | null;
    };
};

export function useSopsPage(props: SopsPageProps) {
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
    const openUrl = (versionId: string) =>
        `/hr/sops/versions/${versionId}/open`;
    const markOpened = (versionId: string) => {
        openedHere.value = new Set([...openedHere.value, versionId]);
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

    return {
        errors,
        failure,
        tabs,
        selectTab,
        toRead,
        openUrl,
        markOpened,
        isOpened,
        ack,
        acknowledge,
        form,
        startPublish,
        startVersion,
        onFile,
        sendForm,
        withdrawing,
        withdrawBusy,
        askWithdraw,
        withdraw,
        expanded,
        toggle,
        active,
        withdrawn,
    };
}
