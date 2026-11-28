<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { PageFrame } from '@/components/ui/page-frame';
import { PageHeader } from '@/components/ui/page-header';

const props = defineProps<{
    branchId: number;
    requirements: {
        requireDateOfBirth: boolean;
        requireNationality: boolean;
        requireEmail: boolean;
        requireAddress: boolean;
        lockVersion: number;
    };
    // AUTO-1: shown only to the Director (registration.auto_assign.manage.branch).
    autoAssign?: {
        canManage: boolean;
        enabled: boolean;
        lockVersion: number;
        branchName: string | null;
    } | null;
}>();
const saved = ref(false);
const autoForm = useForm({
    expected_branch_id: props.branchId,
    enabled: props.autoAssign?.enabled ?? false,
    lock_version: props.autoAssign?.lockVersion ?? 0,
});
// An unsaved change to the switch is never lost silently: it shows a cue, and leaving the page asks first.
const autoDirty = computed(
    () => autoForm.enabled !== (props.autoAssign?.enabled ?? false),
);
const warnBeforeUnload = (event: BeforeUnloadEvent) => {
    if (autoDirty.value) {
        event.preventDefault();
    }
};
window.addEventListener('beforeunload', warnBeforeUnload);
const removeVisitGuard = router.on('before', () =>
    autoDirty.value && !autoForm.processing
        ? window.confirm(
              'You have an unsaved change to automatic doctor assignment. Leave without saving?',
          )
        : undefined,
);
onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', warnBeforeUnload);
    removeVisitGuard();
});
const saveAuto = () =>
    autoForm.post('/registration-settings/auto-assign', {
        preserveScroll: true,
        onSuccess: () => {
            autoForm.lock_version = props.autoAssign?.lockVersion ?? 0;
            autoForm.enabled = props.autoAssign?.enabled ?? false;
        },
    });
const form = useForm({
    expected_branch_id: props.branchId,
    lock_version: props.requirements.lockVersion,
    idempotency_key: crypto.randomUUID(),
    require_date_of_birth: props.requirements.requireDateOfBirth,
    require_nationality: props.requirements.requireNationality,
    require_email: props.requirements.requireEmail,
    require_address: props.requirements.requireAddress,
});
watch(
    () => props.branchId,
    () => reloadLatest(),
);
const reloadLatest = () =>
    router.reload({
        onSuccess: () => {
            form.expected_branch_id = props.branchId;
            form.lock_version = props.requirements.lockVersion;
            form.require_date_of_birth = props.requirements.requireDateOfBirth;
            form.require_nationality = props.requirements.requireNationality;
            form.require_email = props.requirements.requireEmail;
            form.require_address = props.requirements.requireAddress;
            form.idempotency_key = crypto.randomUUID();
            form.clearErrors();
            saved.value = false;
        },
    });
const errorFor = (key: string) => (form.errors as Record<string, string>)[key];
const submit = () => {
    saved.value = false;
    form.post('/registration-settings', {
        preserveScroll: true,
        onSuccess: () => {
            form.lock_version = props.requirements.lockVersion;
            form.idempotency_key = crypto.randomUUID();
            saved.value = true;
        },
    });
};
</script>

<template>
    <Head title="Registration settings" />
    <PageFrame width="narrow">
        <nav
            aria-label="Breadcrumb"
            class="-mb-3 flex flex-wrap gap-2 text-[13px] text-muted-foreground"
        >
            <Link
                href="/clinic-management"
                class="min-h-11 rounded py-3 text-link hover:underline focus-visible:outline-2 lg:min-h-0 lg:py-0"
                >Clinic management</Link
            ><span aria-hidden="true">/</span
            ><span class="font-semibold text-foreground"
                >Registration settings</span
            >
        </nav>
        <PageHeader title="Registration settings" />
        <form
            id="registration-settings-form"
            class="space-y-5 rounded-[14px] border bg-card p-4 md:p-5"
            novalidate
            @submit.prevent="submit"
        >
            <div>
                <h2 class="text-sm font-medium">
                    Configure required patient details
                </h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    These settings apply to new patient registration across the
                    organisation. Existing patient records and visits are
                    unchanged.
                </p>
            </div>
            <fieldset class="space-y-4">
                <legend class="mb-4 text-sm font-medium">
                    Choose which details are required in patient registration
                </legend>
                <label
                    v-for="label in ['Name', 'Phone number', 'NRIC/Passport']"
                    :key="label"
                    class="flex items-center gap-3 text-sm"
                    ><input
                        type="checkbox"
                        checked
                        disabled
                        class="size-4 accent-primary"
                    /><span>{{ label }}</span
                    ><span class="text-xs text-muted-foreground"
                        >Required by system</span
                    ></label
                >
                <div>
                    <label class="flex items-center gap-3 text-sm"
                        ><input
                            v-model="form.require_date_of_birth"
                            type="checkbox"
                            class="size-4 accent-primary"
                            aria-describedby="dob-help"
                        /><span>Date of birth</span></label
                    >
                    <p
                        id="dob-help"
                        class="mt-1 ml-7 text-xs text-muted-foreground"
                    >
                        Required for new staff-created patients when selected.
                        Public QR always requires date of birth for the minor
                        and guardian check.
                    </p>
                </div>
                <div>
                    <label class="flex items-center gap-3 text-sm"
                        ><input
                            v-model="form.require_nationality"
                            type="checkbox"
                            class="size-4 accent-primary"
                            aria-describedby="nationality-help"
                        /><span>Nationality</span></label
                    >
                    <p
                        id="nationality-help"
                        class="mt-1 ml-7 text-xs text-muted-foreground"
                    >
                        Applies to new Patient creation, Quick create and public
                        QR intake.
                    </p>
                </div>
                <div>
                    <label class="flex items-center gap-3 text-sm"
                        ><input
                            v-model="form.require_email"
                            type="checkbox"
                            class="size-4 accent-primary"
                            aria-describedby="email-help"
                        /><span>Email</span></label
                    >
                    <p
                        id="email-help"
                        class="mt-1 ml-7 text-xs text-muted-foreground"
                    >
                        Applies to new Patient creation, Quick create and public
                        QR intake.
                    </p>
                </div>
                <div>
                    <label class="flex items-center gap-3 text-sm"
                        ><input
                            v-model="form.require_address"
                            type="checkbox"
                            class="size-4 accent-primary"
                            aria-describedby="address-help"
                        /><span>Address</span></label
                    >
                    <p
                        id="address-help"
                        class="mt-1 ml-7 text-xs text-muted-foreground"
                    >
                        Requires line 1, city, state, postcode and country. Line
                        2 stays optional.
                    </p>
                </div>
                <label class="flex items-center gap-3 text-sm"
                    ><input
                        type="checkbox"
                        checked
                        disabled
                        class="size-4 accent-primary"
                    /><span>Gender</span
                    ><span class="text-xs text-muted-foreground"
                        >Required; Unknown allowed</span
                    ></label
                >
            </fieldset>
            <InputError
                :message="
                    form.errors.lock_version ||
                    errorFor('settings') ||
                    form.errors.expected_branch_id ||
                    form.errors.idempotency_key ||
                    form.errors.require_date_of_birth ||
                    form.errors.require_nationality ||
                    form.errors.require_email ||
                    form.errors.require_address
                "
            />
            <Button
                v-if="
                    form.errors.lock_version || form.errors.expected_branch_id
                "
                type="button"
                variant="outline"
                size="sm"
                @click="reloadLatest"
                >Reload latest settings</Button
            >
            <p v-if="saved" role="status" class="text-sm">
                Registration settings saved.
            </p>
            <Button
                type="submit"
                class="min-h-11"
                :disabled="form.processing"
                >{{ form.processing ? 'Saving…' : 'Save' }}</Button
            >
        </form>
        <section
            v-if="autoAssign?.canManage"
            aria-labelledby="auto-assign-heading"
            class="mt-6 max-w-3xl space-y-3 rounded-lg border bg-card p-4 md:p-5"
        >
            <h2 id="auto-assign-heading" class="text-sm font-medium">
                Assign the doctor automatically
                <span class="font-normal text-muted-foreground"
                    >({{ autoAssign.branchName }})</span
                >
            </h2>
            <p class="text-xs text-muted-foreground">
                When this is on, a visit registered without a doctor goes to the
                next free doctor on today's roster. The CA can still choose a
                doctor, with a reason. It is off by default.
            </p>
            <form class="space-y-3" novalidate @submit.prevent="saveAuto">
                <label class="flex min-h-11 items-center gap-3 text-sm"
                    ><input
                        v-model="autoForm.enabled"
                        type="checkbox"
                        class="size-4 accent-primary"
                    />Assign the doctor automatically at this branch</label
                >
                <p
                    v-if="autoDirty"
                    role="status"
                    class="text-xs font-medium text-warning-strong"
                >
                    Unsaved change. Press Save to keep it.
                </p>
                <InputError :message="autoForm.errors.lock_version" />
                <Button
                    type="submit"
                    :disabled="autoForm.processing"
                    class="min-h-11"
                    >Save</Button
                >
            </form>
        </section>
    </PageFrame>
</template>
