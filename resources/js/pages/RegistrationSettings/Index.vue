<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

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
    <main
        class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 px-4 py-5 pb-8 sm:px-6 lg:px-8"
    >
        <header class="space-y-3">
            <nav
                aria-label="Breadcrumb"
                class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
            >
                <Link
                    href="/clinic-management"
                    class="inline-flex min-h-11 items-center rounded-md px-1 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9"
                    >Clinic management</Link
                ><span aria-hidden="true">/</span
                ><span>Registration settings</span>
            </nav>
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Registration settings
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    New-patient requirements for the organisation. Existing
                    patients and visits are unchanged.
                </p>
            </div>
        </header>
        <form
            id="registration-settings-form"
            class="space-y-5"
            novalidate
            @submit.prevent="submit"
        >
            <section
                aria-labelledby="system-requirements-heading"
                class="rounded-xl border bg-card p-4 shadow-card md:p-6"
            >
                <h2
                    id="system-requirements-heading"
                    class="text-base font-semibold"
                >
                    System requirements
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    These rules are set by KPOne.
                </p>
                <div class="mt-4 divide-y divide-border/60">
                    <label
                        v-for="label in [
                            'Name',
                            'Phone number',
                            'NRIC/Passport',
                        ]"
                        :key="label"
                        class="flex min-h-11 flex-wrap items-center gap-3 py-3 text-sm"
                    >
                        <input
                            type="checkbox"
                            checked
                            disabled
                            class="size-4 accent-primary"
                        /><span class="flex-1">{{ label }}</span
                        ><span
                            class="rounded-full bg-muted px-2 py-1 text-xs text-muted-foreground"
                            >Required by system</span
                        >
                    </label>
                    <label
                        class="flex min-h-11 flex-wrap items-center gap-3 py-3 text-sm"
                        ><input
                            type="checkbox"
                            checked
                            disabled
                            class="size-4 accent-primary"
                        /><span class="flex-1">Gender</span
                        ><span
                            class="rounded-full bg-muted px-2 py-1 text-xs text-muted-foreground"
                            >Required; Unknown allowed</span
                        ></label
                    >
                </div>
                <p class="mt-3 text-xs leading-relaxed text-muted-foreground">
                    Phone is optional for a known minor; guardian contact is
                    required.
                </p>
            </section>
            <fieldset class="rounded-xl border bg-card p-2 shadow-card md:p-3">
                <legend class="mx-2 px-2 text-base font-semibold">
                    New-patient details
                </legend>
                <p class="px-4 pt-2 pb-3 text-sm text-muted-foreground">
                    Choose which details are required. Press Save required
                    details to apply changes.
                </p>
                <div class="divide-y divide-border/60">
                    <label
                        for="require-date-of-birth"
                        class="flex min-h-11 cursor-pointer items-center gap-4 rounded-lg px-4 py-3 focus-within:ring-2 focus-within:ring-ring lg:min-h-9"
                    >
                        <input
                            id="require-date-of-birth"
                            v-model="form.require_date_of_birth"
                            type="checkbox"
                            role="switch"
                            aria-label="Date of birth required"
                            aria-describedby="dob-help"
                            class="peer sr-only"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-2"
                                ><span class="text-sm font-medium"
                                    >Date of birth</span
                                ><span
                                    class="rounded-full px-2 py-0.5 text-xs"
                                    :class="
                                        form.require_date_of_birth
                                            ? 'bg-success-wash text-success'
                                            : 'bg-muted text-muted-foreground'
                                    "
                                    >{{
                                        form.require_date_of_birth
                                            ? 'Required'
                                            : 'Optional'
                                    }}</span
                                ></span
                            >
                            <span
                                id="dob-help"
                                class="mt-1 block text-xs leading-relaxed text-muted-foreground"
                                >Required for new staff-created patients when
                                selected. Public QR always requires date of
                                birth for the minor and guardian check.</span
                            >
                        </span>
                        <span
                            aria-hidden="true"
                            class="relative h-[26px] w-11 shrink-0 rounded-full bg-muted-foreground/30 transition-colors peer-checked:bg-success motion-reduce:transition-none"
                            ><span
                                class="absolute top-px left-px size-[22px] rounded-full bg-background shadow-sm transition-transform motion-reduce:transition-none"
                                :class="
                                    form.require_date_of_birth
                                        ? 'translate-x-5'
                                        : 'translate-x-0'
                                "
                            ></span
                        ></span>
                    </label>
                    <label
                        for="require-nationality"
                        class="flex min-h-11 cursor-pointer items-center gap-4 rounded-lg px-4 py-3 focus-within:ring-2 focus-within:ring-ring lg:min-h-9"
                    >
                        <input
                            id="require-nationality"
                            v-model="form.require_nationality"
                            type="checkbox"
                            role="switch"
                            aria-label="Nationality required"
                            aria-describedby="nationality-help"
                            class="peer sr-only"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-2"
                                ><span class="text-sm font-medium"
                                    >Nationality</span
                                ><span
                                    class="rounded-full px-2 py-0.5 text-xs"
                                    :class="
                                        form.require_nationality
                                            ? 'bg-success-wash text-success'
                                            : 'bg-muted text-muted-foreground'
                                    "
                                    >{{
                                        form.require_nationality
                                            ? 'Required'
                                            : 'Optional'
                                    }}</span
                                ></span
                            >
                            <span
                                id="nationality-help"
                                class="mt-1 block text-xs leading-relaxed text-muted-foreground"
                                >Applies to new Patient creation, Quick create
                                and public QR intake.</span
                            >
                        </span>
                        <span
                            aria-hidden="true"
                            class="relative h-[26px] w-11 shrink-0 rounded-full bg-muted-foreground/30 transition-colors peer-checked:bg-success motion-reduce:transition-none"
                            ><span
                                class="absolute top-px left-px size-[22px] rounded-full bg-background shadow-sm transition-transform motion-reduce:transition-none"
                                :class="
                                    form.require_nationality
                                        ? 'translate-x-5'
                                        : 'translate-x-0'
                                "
                            ></span
                        ></span>
                    </label>
                    <label
                        for="require-email"
                        class="flex min-h-11 cursor-pointer items-center gap-4 rounded-lg px-4 py-3 focus-within:ring-2 focus-within:ring-ring lg:min-h-9"
                    >
                        <input
                            id="require-email"
                            v-model="form.require_email"
                            type="checkbox"
                            role="switch"
                            aria-label="Email required"
                            aria-describedby="email-help"
                            class="peer sr-only"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-2"
                                ><span class="text-sm font-medium">Email</span
                                ><span
                                    class="rounded-full px-2 py-0.5 text-xs"
                                    :class="
                                        form.require_email
                                            ? 'bg-success-wash text-success'
                                            : 'bg-muted text-muted-foreground'
                                    "
                                    >{{
                                        form.require_email
                                            ? 'Required'
                                            : 'Optional'
                                    }}</span
                                ></span
                            >
                            <span
                                id="email-help"
                                class="mt-1 block text-xs leading-relaxed text-muted-foreground"
                                >Applies to new Patient creation, Quick create
                                and public QR intake.</span
                            >
                        </span>
                        <span
                            aria-hidden="true"
                            class="relative h-[26px] w-11 shrink-0 rounded-full bg-muted-foreground/30 transition-colors peer-checked:bg-success motion-reduce:transition-none"
                            ><span
                                class="absolute top-px left-px size-[22px] rounded-full bg-background shadow-sm transition-transform motion-reduce:transition-none"
                                :class="
                                    form.require_email
                                        ? 'translate-x-5'
                                        : 'translate-x-0'
                                "
                            ></span
                        ></span>
                    </label>
                    <label
                        for="require-address"
                        class="flex min-h-11 cursor-pointer items-center gap-4 rounded-lg px-4 py-3 focus-within:ring-2 focus-within:ring-ring lg:min-h-9"
                    >
                        <input
                            id="require-address"
                            v-model="form.require_address"
                            type="checkbox"
                            role="switch"
                            aria-label="Address required"
                            aria-describedby="address-help"
                            class="peer sr-only"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-2"
                                ><span class="text-sm font-medium">Address</span
                                ><span
                                    class="rounded-full px-2 py-0.5 text-xs"
                                    :class="
                                        form.require_address
                                            ? 'bg-success-wash text-success'
                                            : 'bg-muted text-muted-foreground'
                                    "
                                    >{{
                                        form.require_address
                                            ? 'Required'
                                            : 'Optional'
                                    }}</span
                                ></span
                            >
                            <span
                                id="address-help"
                                class="mt-1 block text-xs leading-relaxed text-muted-foreground"
                                >Requires line 1, city, state, postcode and
                                country. Line 2 stays optional.</span
                            >
                        </span>
                        <span
                            aria-hidden="true"
                            class="relative h-[26px] w-11 shrink-0 rounded-full bg-muted-foreground/30 transition-colors peer-checked:bg-success motion-reduce:transition-none"
                            ><span
                                class="absolute top-px left-px size-[22px] rounded-full bg-background shadow-sm transition-transform motion-reduce:transition-none"
                                :class="
                                    form.require_address
                                        ? 'translate-x-5'
                                        : 'translate-x-0'
                                "
                            ></span
                        ></span>
                    </label>
                </div>
            </fieldset>
            <section
                aria-labelledby="requirements-apply-heading"
                class="rounded-xl border bg-card p-4 shadow-card md:p-6"
            >
                <h2
                    id="requirements-apply-heading"
                    class="text-base font-semibold"
                >
                    Where these apply
                </h2>
                <dl class="mt-3 divide-y divide-border/60 text-sm">
                    <div class="flex flex-wrap justify-between gap-2 py-3">
                        <dt>Patients · Create patient</dt>
                        <dd class="text-muted-foreground">New patients only</dd>
                    </div>
                    <div class="flex flex-wrap justify-between gap-2 py-3">
                        <dt>Registration · Quick create</dt>
                        <dd class="text-muted-foreground">New patients only</dd>
                    </div>
                    <div class="flex flex-wrap justify-between gap-2 py-3">
                        <dt>Public QR check-in</dt>
                        <dd class="text-muted-foreground">New submissions</dd>
                    </div>
                </dl>
                <p class="mt-3 text-xs leading-relaxed text-muted-foreground">
                    QR forms already in progress keep the registration
                    requirements captured when they started.
                </p>
            </section>
            <div class="space-y-3 rounded-xl border bg-card p-4 md:p-6">
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
                        form.errors.lock_version ||
                        form.errors.expected_branch_id
                    "
                    type="button"
                    variant="outline"
                    size="sm"
                    class="min-h-11 lg:min-h-9"
                    @click="reloadLatest"
                    >Reload latest settings</Button
                >
                <p v-if="saved" role="status" class="text-sm">
                    Registration settings saved.
                </p>
                <Button
                    type="submit"
                    class="min-h-11 lg:min-h-9"
                    :disabled="form.processing"
                    >{{
                        form.processing ? 'Saving…' : 'Save required details'
                    }}</Button
                >
            </div>
        </form>
        <section
            v-if="autoAssign?.canManage"
            aria-labelledby="auto-assign-heading"
            class="space-y-4 rounded-xl border bg-card p-4 shadow-card md:p-6"
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
                    class="min-h-11 lg:min-h-9"
                    >Save doctor assignment</Button
                >
            </form>
        </section>
    </main>
</template>
