<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { PageFrame, StickyActionBar } from '@/components/ui/page-frame';
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
}>();
const dobHelp =
    'Required for new staff-created patients when selected. Public QR always requires date of birth for the minor and guardian check.';
const saved = ref(false);
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
    <main class="flex flex-1 flex-col">
        <PageFrame width="narrow">
            <nav
                aria-label="Breadcrumb"
                class="flex flex-wrap gap-2 text-xs text-muted-foreground"
            >
                <Link
                    href="/clinic-management"
                    class="inline-flex min-h-11 items-center rounded text-primary hover:underline focus-visible:outline-2 lg:min-h-0"
                    >Clinic management</Link
                ><span class="inline-flex items-center" aria-hidden="true"
                    >/</span
                ><span class="inline-flex items-center"
                    >Registration settings</span
                >
            </nav>
            <PageHeader title="Registration settings" />
            <form
                id="registration-settings-form"
                class="space-y-4"
                novalidate
                @submit.prevent="submit"
            >
                <div>
                    <h2 class="text-base font-semibold">
                        Configure required patient details
                    </h2>
                    <p class="mt-1 text-xs text-muted-foreground">
                        These settings apply to new patient registration across
                        the organisation. Existing patient records and visits
                        are unchanged.
                    </p>
                </div>
                <fieldset class="overflow-hidden rounded-xl border bg-card">
                    <legend class="sr-only">
                        Choose which details are required in patient
                        registration
                    </legend>
                    <div class="divide-y">
                        <label
                            class="flex min-h-11 items-center gap-3 p-4 text-sm lg:min-h-0"
                            ><input
                                type="checkbox"
                                checked
                                disabled
                                class="size-5 accent-primary"
                            /><span class="font-medium">Name</span
                            ><span class="ml-auto text-xs text-muted-foreground"
                                >Required by system</span
                            ></label
                        >
                        <label
                            class="flex min-h-11 items-center gap-3 p-4 text-sm lg:min-h-0"
                            ><input
                                type="checkbox"
                                checked
                                disabled
                                class="size-5 accent-primary"
                            /><span class="font-medium">Phone number</span
                            ><span class="ml-auto text-xs text-muted-foreground"
                                >Required by system</span
                            ></label
                        >
                        <label
                            class="flex min-h-11 items-center gap-3 p-4 text-sm lg:min-h-0"
                            ><input
                                type="checkbox"
                                checked
                                disabled
                                class="size-5 accent-primary"
                            /><span class="font-medium">NRIC/Passport</span
                            ><span class="ml-auto text-xs text-muted-foreground"
                                >Required by system</span
                            ></label
                        >
                        <div class="p-4">
                            <label
                                class="flex min-h-11 items-center gap-3 text-sm lg:min-h-0"
                                ><input
                                    v-model="form.require_date_of_birth"
                                    type="checkbox"
                                    class="size-5 accent-primary"
                                    aria-describedby="dob-help"
                                /><span class="font-medium"
                                    >Date of birth</span
                                ></label
                            >
                            <p
                                id="dob-help"
                                class="mt-1 ml-8 text-xs text-muted-foreground"
                            >
                                {{ dobHelp }}
                            </p>
                        </div>
                        <div class="p-4">
                            <label
                                class="flex min-h-11 items-center gap-3 text-sm lg:min-h-0"
                                ><input
                                    v-model="form.require_nationality"
                                    type="checkbox"
                                    class="size-5 accent-primary"
                                    aria-describedby="nationality-help"
                                /><span class="font-medium"
                                    >Nationality</span
                                ></label
                            >
                            <p
                                id="nationality-help"
                                class="mt-1 ml-8 text-xs text-muted-foreground"
                            >
                                Applies to new Patient creation, Quick create
                                and public QR intake.
                            </p>
                        </div>
                        <div class="p-4">
                            <label
                                class="flex min-h-11 items-center gap-3 text-sm lg:min-h-0"
                                ><input
                                    v-model="form.require_email"
                                    type="checkbox"
                                    class="size-5 accent-primary"
                                    aria-describedby="email-help"
                                /><span class="font-medium">Email</span></label
                            >
                            <p
                                id="email-help"
                                class="mt-1 ml-8 text-xs text-muted-foreground"
                            >
                                Applies to new Patient creation, Quick create
                                and public QR intake.
                            </p>
                        </div>
                        <div class="p-4">
                            <label
                                class="flex min-h-11 items-center gap-3 text-sm lg:min-h-0"
                                ><input
                                    v-model="form.require_address"
                                    type="checkbox"
                                    class="size-5 accent-primary"
                                    aria-describedby="address-help"
                                /><span class="font-medium"
                                    >Address</span
                                ></label
                            >
                            <p
                                id="address-help"
                                class="mt-1 ml-8 text-xs text-muted-foreground"
                            >
                                Requires line 1, city, state, postcode and
                                country. Line 2 stays optional.
                            </p>
                        </div>
                        <label
                            class="flex min-h-11 items-center gap-3 p-4 text-sm lg:min-h-0"
                            ><input
                                type="checkbox"
                                checked
                                disabled
                                class="size-5 accent-primary"
                            /><span class="font-medium">Gender</span
                            ><span class="ml-auto text-xs text-muted-foreground"
                                >Required; Unknown allowed</span
                            ></label
                        >
                    </div>
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
                        form.errors.lock_version ||
                        form.errors.expected_branch_id
                    "
                    type="button"
                    variant="outline"
                    size="sm"
                    class="max-lg:h-11"
                    @click="reloadLatest"
                    >Reload latest settings</Button
                >
                <p v-if="saved" role="status" class="text-sm">
                    Registration settings saved.
                </p>
            </form>
        </PageFrame>
        <StickyActionBar width="narrow">
            <Button
                type="submit"
                form="registration-settings-form"
                :disabled="form.processing"
                >{{ form.processing ? 'Saving…' : 'Save' }}</Button
            >
        </StickyActionBar>
    </main>
</template>
