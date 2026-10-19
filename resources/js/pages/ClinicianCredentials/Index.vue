<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

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
</script>

<template>
    <Head title="Clinician credentials" />
    <main class="mx-auto w-full max-w-4xl space-y-5 p-4 md:p-6">
        <Link href="/clinic-management" class="text-sm underline"
            >Clinic management</Link
        >
        <h1 class="text-2xl font-semibold">
            {{
                canManage ? 'Clinician credentials' : 'My clinician credentials'
            }}
        </h1>
        <p class="text-sm text-muted-foreground">
            Professional identity for future Medical Certificates. Issuing
            certificates is not available. Verification is a manual check, not
            an automated MMC or current APC validation.
        </p>
        <p
            v-if="saved"
            role="status"
            class="rounded border border-emerald-600 p-3"
        >
            Credentials saved.
        </p>
        <p v-if="branchChanged" role="status">
            Branch changed. Reload to review credentials.
        </p>
        <div v-else class="space-y-3">
            <section
                v-for="clinician in clinicians"
                :key="clinician.userId"
                class="space-y-3 rounded-lg border bg-card p-4"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="font-semibold">{{ clinician.accountName }}</h2>
                    <Button
                        v-if="
                            canManage &&
                            clinician.active &&
                            clinician.userId !== page.props.auth.user?.id
                        "
                        variant="outline"
                        @click="edit(clinician)"
                        >Review credentials</Button
                    >
                </div>
                <dl class="grid gap-3 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-muted-foreground">
                            Registered professional name
                        </dt>
                        <dd class="break-words">
                            {{ clinician.professionalName || 'Not configured' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">
                            MMC registration number
                        </dt>
                        <dd>{{ clinician.mmcNumber || 'Not configured' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Verification</dt>
                        <dd>
                            {{
                                clinician.verified
                                    ? 'Manually verified'
                                    : 'Not verified'
                            }}{{
                                clinician.active ? '' : ' · Inactive account'
                            }}
                        </dd>
                    </div>
                </dl>
            </section>
            <p v-if="clinicians.length === 0">
                No eligible clinicians in this organisation.
            </p>
            <nav
                v-if="canManage && pagination.lastPage > 1"
                aria-label="Clinician pages"
                class="flex gap-3"
            >
                <Button
                    :disabled="pagination.currentPage <= 1"
                    variant="outline"
                    @click="
                        router.get('/clinician-credentials', {
                            page: pagination.currentPage - 1,
                        })
                    "
                    >Previous</Button
                ><span
                    >{{ pagination.currentPage }} /
                    {{ pagination.lastPage }}</span
                ><Button
                    :disabled="pagination.currentPage >= pagination.lastPage"
                    variant="outline"
                    @click="
                        router.get('/clinician-credentials', {
                            page: pagination.currentPage + 1,
                        })
                    "
                    >Next</Button
                >
            </nav>
        </div>
        <Dialog v-model:open="open"
            ><DialogContent class="max-h-[calc(100dvh-2rem)] overflow-y-auto"
                ><DialogHeader
                    ><DialogTitle>Review clinician credentials</DialogTitle
                    ><DialogDescription
                        >Confirm the registered professional identity for
                        {{ subject?.accountName }}. This does not grant clinical
                        permissions or authorise MC issuance.</DialogDescription
                    ></DialogHeader
                >
                <form class="space-y-4" @submit.prevent="save">
                    <label class="grid gap-1" for="professional-name"
                        >Registered professional name<input
                            id="professional-name"
                            v-model="form.professional_name"
                            maxlength="200"
                            required
                            class="min-h-10 min-w-0 rounded border bg-background px-3"
                            :aria-invalid="!!form.errors.professional_name"
                            aria-describedby="professional-name-error" /><InputError
                            id="professional-name-error"
                            :message="form.errors.professional_name"
                    /></label>
                    <label class="grid gap-1" for="mmc-number"
                        >MMC registration number<input
                            id="mmc-number"
                            v-model="form.mmc_number"
                            maxlength="30"
                            inputmode="numeric"
                            pattern="[0-9]{1,30}"
                            required
                            class="min-h-10 min-w-0 rounded border bg-background px-3"
                            :aria-invalid="!!form.errors.mmc_number"
                            aria-describedby="mmc-number-error" /><InputError
                            id="mmc-number-error"
                            :message="form.errors.mmc_number"
                    /></label>
                    <label class="flex items-start gap-2"
                        ><input
                            v-model="form.verified"
                            type="checkbox"
                            class="mt-1"
                        /><span
                            >I have checked the professional name and MMC
                            registration number. Uncheck to mark these
                            credentials unverified.</span
                        ></label
                    >
                    <InputError
                        :message="
                            form.errors.verified ||
                            form.errors.idempotency_key ||
                            form.errors.expected_branch_id
                        "
                    /><InputError :message="form.errors.lock_version" />
                    <Button
                        v-if="form.errors.lock_version"
                        type="button"
                        variant="outline"
                        @click="
                            open = false;
                            router.reload();
                        "
                        >Reload latest credentials</Button
                    >
                    <DialogFooter
                        ><Button
                            type="button"
                            variant="outline"
                            @click="open = false"
                            >Cancel</Button
                        ><Button
                            type="submit"
                            :disabled="form.processing || branchChanged"
                            >{{
                                form.processing ? 'Saving…' : 'Save credentials'
                            }}</Button
                        ></DialogFooter
                    >
                </form>
            </DialogContent></Dialog
        >
    </main>
</template>
