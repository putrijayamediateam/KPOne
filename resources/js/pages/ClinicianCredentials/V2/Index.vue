<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2KeyValueList from '@/components/v2/V2KeyValueList.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

type Clinician = {
    userId: number;
    accountName: string;
    active: boolean;
    professionalName: string;
    mmcNumber: string;
    verified: boolean;
    lockVersion: number;
};
const props = defineProps<{
    branchId: number;
    canManage: boolean;
    clinicians: Clinician[];
    pagination: { currentPage: number; lastPage: number };
}>();
const page = usePage();
const branchChanged = computed(
    () => props.branchId !== page.props.branchContext?.active?.id,
);
const open = ref(false);
const subject = ref<Clinician | null>(null);
const saved = ref(false);
const form = useForm({
    expected_branch_id: props.branchId,
    lock_version: 0,
    idempotency_key: crypto.randomUUID(),
    professional_name: '',
    mmc_number: '',
    verified: false,
});
const clear = () => {
    form.cancel();
    form.reset();
    form.clearErrors();
    subject.value = null;
};
const edit = (clinician: Clinician) => {
    clear();
    saved.value = false;
    subject.value = clinician;
    form.expected_branch_id = props.branchId;
    form.lock_version = clinician.lockVersion;
    form.professional_name = clinician.professionalName;
    form.mmc_number = clinician.mmcNumber;
    form.verified = clinician.verified;
    form.idempotency_key = crypto.randomUUID();
    open.value = true;
};
watch(
    () => [form.professional_name, form.mmc_number, form.verified],
    () => {
        if (!form.processing) {
            form.idempotency_key = crypto.randomUUID();
        }
    },
);
watch(open, (value) => {
    if (!value) {
        clear();
    }
});
watch(
    [() => props.branchId, () => page.props.branchContext?.active?.id],
    () => {
        open.value = false;
        saved.value = false;
        clear();
    },
);
onBeforeUnmount(clear);
const save = () => {
    if (!subject.value || branchChanged.value) {
        return;
    }

    form.post(`/clinician-credentials/${subject.value.userId}`, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            saved.value = true;
        },
    });
};
usePageCrumbs(() => [
    { title: 'Clinic management', href: '/clinic-management' },
    { title: 'Clinician credentials' },
]);
// V2 only: unsaved typing in the open sheet asks before the sheet closes; Save stays off until the two fields are filled.
const changed = computed(
    () =>
        open.value &&
        subject.value !== null &&
        (form.professional_name !== subject.value.professionalName ||
            form.mmc_number !== subject.value.mmcNumber ||
            form.verified !== subject.value.verified),
);
const saveReason = computed(() => {
    if (!form.professional_name.trim()) {
        return 'Enter the registered professional name to continue.';
    }

    if (!/^[0-9]{1,30}$/.test(form.mmc_number)) {
        return 'Enter the MMC registration number (digits only) to continue.';
    }

    return undefined;
});
const outlineButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9';
const box =
    'w-full min-h-11 rounded-[10px] border bg-background px-3 text-sm lg:min-h-9';
const items = (clinician: Clinician) => [
    {
        label: 'Registered professional name',
        value: clinician.professionalName || 'Not configured',
    },
    {
        label: 'MMC registration number',
        value: clinician.mmcNumber || 'Not configured',
    },
];
</script>

<template>
    <Head title="Clinician credentials" />
    <PageFrame>
        <V2WorkLayout
            :title="
                canManage ? 'Clinician credentials' : 'My clinician credentials'
            "
            description="Professional identity for future Medical Certificates. Issuing certificates is not available. Verification is a manual check, not an automated MMC or current APC validation."
        >
            <template #main>
                <p
                    v-if="saved"
                    role="status"
                    class="rounded-[14px] border border-success/40 bg-success/10 p-4 text-sm"
                >
                    Credentials saved.
                </p>
                <p
                    v-if="branchChanged"
                    role="status"
                    class="rounded-[14px] border bg-card p-4 text-sm"
                >
                    Branch changed. Reload to review credentials.
                </p>
                <div v-else class="grid gap-3">
                    <section
                        v-for="clinician in clinicians"
                        :key="clinician.userId"
                        :aria-label="`${clinician.accountName} credentials`"
                        class="grid min-w-0 gap-4 rounded-[14px] border bg-card p-4 sm:p-5"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-3"
                        >
                            <div
                                class="flex min-w-0 flex-wrap items-center gap-2"
                            >
                                <h2 class="text-base font-semibold break-words">
                                    {{ clinician.accountName }}
                                </h2>
                                <V2Chip
                                    :tone="
                                        clinician.verified
                                            ? 'success'
                                            : 'warning'
                                    "
                                    :label="
                                        clinician.verified
                                            ? 'Manually verified'
                                            : 'Not verified'
                                    "
                                />
                                <V2Chip
                                    v-if="!clinician.active"
                                    tone="neutral"
                                    label="Inactive account"
                                />
                            </div>
                            <button
                                v-if="
                                    canManage &&
                                    clinician.active &&
                                    clinician.userId !==
                                        page.props.auth.user?.id
                                "
                                type="button"
                                :class="[outlineButton, 'max-sm:w-full']"
                                @click="edit(clinician)"
                            >
                                Review credentials
                            </button>
                        </div>
                        <V2KeyValueList :items="items(clinician)" />
                    </section>
                    <p
                        v-if="clinicians.length === 0"
                        class="rounded-[14px] border bg-card p-6 text-center text-sm text-muted-foreground"
                    >
                        No eligible clinicians in this organisation.
                    </p>
                    <nav
                        v-if="canManage && pagination.lastPage > 1"
                        aria-label="Clinician pages"
                        class="flex flex-wrap items-center gap-3"
                    >
                        <button
                            type="button"
                            :class="outlineButton"
                            :disabled="pagination.currentPage <= 1"
                            @click="
                                router.get('/clinician-credentials', {
                                    page: pagination.currentPage - 1,
                                })
                            "
                        >
                            Previous
                        </button>
                        <span class="text-sm tabular-nums"
                            >{{ pagination.currentPage }} /
                            {{ pagination.lastPage }}</span
                        >
                        <button
                            type="button"
                            :class="outlineButton"
                            :disabled="
                                pagination.currentPage >= pagination.lastPage
                            "
                            @click="
                                router.get('/clinician-credentials', {
                                    page: pagination.currentPage + 1,
                                })
                            "
                        >
                            Next
                        </button>
                    </nav>
                </div>
            </template>
        </V2WorkLayout>
    </PageFrame>

    <V2Dialog
        :open="open"
        title="Review clinician credentials"
        :description="`Confirm the registered professional identity for ${subject?.accountName ?? ''}. This does not grant clinical permissions or authorise MC issuance.`"
        confirm-label="Save credentials"
        :busy="form.processing"
        :dirty="changed"
        :confirm-disabled="!!saveReason || branchChanged"
        :confirm-disabled-reason="saveReason"
        @update:open="(value) => (open = value)"
        @confirm="save"
    >
        <form class="grid gap-4" @submit.prevent="!saveReason && save()">
            <V2Field
                v-slot="{ id, describedBy }"
                label="Registered professional name"
                required
                :error="form.errors.professional_name"
            >
                <input
                    :id="id"
                    v-model="form.professional_name"
                    maxlength="200"
                    required
                    :aria-describedby="describedBy"
                    :aria-invalid="!!form.errors.professional_name"
                    :class="box"
                />
            </V2Field>
            <V2Field
                v-slot="{ id, describedBy }"
                label="MMC registration number"
                required
                :error="form.errors.mmc_number"
            >
                <input
                    :id="id"
                    v-model="form.mmc_number"
                    maxlength="30"
                    inputmode="numeric"
                    pattern="[0-9]{1,30}"
                    required
                    :aria-describedby="describedBy"
                    :aria-invalid="!!form.errors.mmc_number"
                    :class="box"
                />
            </V2Field>
            <label class="flex min-h-11 items-start gap-3 py-1 text-sm">
                <Checkbox v-model="form.verified" class="mt-0.5 shrink-0" />
                <span
                    >I have checked the professional name and MMC registration
                    number. Uncheck to mark these credentials unverified.</span
                >
            </label>
            <p
                v-if="
                    form.errors.verified ||
                    form.errors.idempotency_key ||
                    form.errors.expected_branch_id
                "
                role="alert"
                class="text-sm text-destructive"
            >
                {{
                    form.errors.verified ||
                    form.errors.idempotency_key ||
                    form.errors.expected_branch_id
                }}
            </p>
            <p
                v-if="form.errors.lock_version"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ form.errors.lock_version }}
            </p>
            <div v-if="form.errors.lock_version">
                <button
                    type="button"
                    :class="outlineButton"
                    @click="
                        open = false;
                        router.reload();
                    "
                >
                    Reload latest credentials
                </button>
            </div>
        </form>
    </V2Dialog>
</template>
