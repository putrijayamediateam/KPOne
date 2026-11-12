<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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
    <main
        class="mx-auto flex w-full max-w-[1280px] flex-1 flex-col gap-5 px-4 py-6 pb-28 md:px-6"
    >
        <nav
            aria-label="Breadcrumb"
            class="flex flex-wrap gap-2 text-sm text-muted-foreground"
        >
            <Link
                href="/clinic-management"
                class="inline-flex min-h-11 items-center rounded hover:underline focus-visible:outline-2 lg:min-h-0"
                >Clinic management</Link
            ><span class="self-center" aria-hidden="true">/</span
            ><span class="self-center">Registration settings</span>
        </nav>
        <PageHeader
            title="Registration settings"
            description="These settings apply to new patient registration across the organisation. Existing patient records and visits are unchanged."
        />
        <form
            id="registration-settings-form"
            class="flex max-w-3xl flex-col gap-4"
            novalidate
            @submit.prevent="submit"
        >
            <fieldset class="overflow-hidden rounded-[14px] border bg-card">
                <legend class="sr-only">
                    Choose which details are required in patient registration
                </legend>
                <h2 class="border-b px-5 py-4 text-lg font-semibold">
                    Configure required patient details
                </h2>
                <p
                    class="px-5 pt-3 text-sm text-muted-foreground"
                    aria-hidden="true"
                >
                    Choose which details are required in patient registration
                </p>
                <ul class="divide-y">
                    <li class="px-5 py-3">
                        <label
                            class="flex min-h-11 items-center gap-3 text-sm font-medium"
                            ><input
                                type="checkbox"
                                checked
                                disabled
                                class="size-5 accent-primary lg:size-4"
                            /><span>Name</span
                            ><span
                                class="text-sm font-normal text-muted-foreground"
                                >Required by system</span
                            ></label
                        >
                    </li>
                    <li class="px-5 py-3">
                        <label
                            class="flex min-h-11 items-center gap-3 text-sm font-medium"
                            ><input
                                type="checkbox"
                                checked
                                disabled
                                class="size-5 accent-primary lg:size-4"
                            /><span>Phone number</span
                            ><span
                                class="text-sm font-normal text-muted-foreground"
                                >Required by system</span
                            ></label
                        >
                    </li>
                    <li class="px-5 py-3">
                        <label
                            class="flex min-h-11 items-center gap-3 text-sm font-medium"
                            ><input
                                type="checkbox"
                                checked
                                disabled
                                class="size-5 accent-primary lg:size-4"
                            /><span>NRIC/Passport</span
                            ><span
                                class="text-sm font-normal text-muted-foreground"
                                >Required by system</span
                            ></label
                        >
                    </li>
                    <li class="px-5 py-3">
                        <label
                            class="flex min-h-11 items-center gap-3 text-sm font-medium"
                            ><input
                                v-model="form.require_date_of_birth"
                                type="checkbox"
                                class="size-5 accent-primary lg:size-4"
                                aria-describedby="dob-help"
                            /><span>Date of birth</span></label
                        >
                        <p
                            id="dob-help"
                            class="ml-8 text-sm text-muted-foreground lg:ml-7"
                        >
                            Required for new staff-created patients when
                            selected. Public QR always requires date of birth
                            for the minor and guardian check.
                        </p>
                    </li>
                    <li class="px-5 py-3">
                        <label
                            class="flex min-h-11 items-center gap-3 text-sm font-medium"
                            ><input
                                v-model="form.require_nationality"
                                type="checkbox"
                                class="size-5 accent-primary lg:size-4"
                                aria-describedby="nationality-help"
                            /><span>Nationality</span></label
                        >
                        <p
                            id="nationality-help"
                            class="ml-8 text-sm text-muted-foreground lg:ml-7"
                        >
                            Applies to new Patient creation, Quick create and
                            public QR intake.
                        </p>
                    </li>
                    <li class="px-5 py-3">
                        <label
                            class="flex min-h-11 items-center gap-3 text-sm font-medium"
                            ><input
                                v-model="form.require_email"
                                type="checkbox"
                                class="size-5 accent-primary lg:size-4"
                                aria-describedby="email-help"
                            /><span>Email</span></label
                        >
                        <p
                            id="email-help"
                            class="ml-8 text-sm text-muted-foreground lg:ml-7"
                        >
                            Applies to new Patient creation, Quick create and
                            public QR intake.
                        </p>
                    </li>
                    <li class="px-5 py-3">
                        <label
                            class="flex min-h-11 items-center gap-3 text-sm font-medium"
                            ><input
                                v-model="form.require_address"
                                type="checkbox"
                                class="size-5 accent-primary lg:size-4"
                                aria-describedby="address-help"
                            /><span>Address</span></label
                        >
                        <p
                            id="address-help"
                            class="ml-8 text-sm text-muted-foreground lg:ml-7"
                        >
                            Requires line 1, city, state, postcode and country.
                            Line 2 stays optional.
                        </p>
                    </li>
                    <li class="px-5 py-3">
                        <label
                            class="flex min-h-11 items-center gap-3 text-sm font-medium"
                            ><input
                                type="checkbox"
                                checked
                                disabled
                                class="size-5 accent-primary lg:size-4"
                            /><span>Gender</span
                            ><span
                                class="text-sm font-normal text-muted-foreground"
                                >Required; Unknown allowed</span
                            ></label
                        >
                    </li>
                </ul>
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
            <div
                v-if="
                    form.errors.lock_version || form.errors.expected_branch_id
                "
            >
                <Button
                    type="button"
                    variant="outline"
                    class="max-lg:h-11"
                    @click="reloadLatest"
                    >Reload latest settings</Button
                >
            </div>
            <p v-if="saved" role="status" class="text-sm font-medium">
                Registration settings saved.
            </p>
        </form>
        <div
            class="fixed inset-x-0 bottom-0 z-20 flex justify-end border-t bg-card px-4 py-3 md:px-8"
        >
            <Button
                type="submit"
                form="registration-settings-form"
                class="max-lg:h-11"
                :disabled="form.processing"
                >{{ form.processing ? 'Saving…' : 'Save' }}</Button
            >
        </div>
    </main>
</template>
