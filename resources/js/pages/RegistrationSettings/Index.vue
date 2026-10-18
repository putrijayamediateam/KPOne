<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
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
    <main class="flex flex-1 flex-col p-4 pb-28 md:px-8 md:pt-6">
        <nav
            aria-label="Breadcrumb"
            class="mb-4 flex flex-wrap gap-2 text-xs text-muted-foreground"
        >
            <Link
                href="/clinic-management"
                class="rounded hover:underline focus-visible:outline-2"
                >Clinic management</Link
            ><span aria-hidden="true">/</span><span>Registration settings</span>
        </nav>
        <h1 class="border-b pb-3 text-xl font-semibold">
            Registration settings
        </h1>
        <form
            id="registration-settings-form"
            class="mt-5 max-w-3xl space-y-5"
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
                role="alert"
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
        </form>
        <div
            class="fixed inset-x-0 bottom-0 z-20 flex justify-end border-t bg-card px-4 py-3 md:px-8"
        >
            <Button
                type="submit"
                form="registration-settings-form"
                :disabled="form.processing"
                >{{ form.processing ? 'Saving…' : 'Save' }}</Button
            >
        </div>
    </main>
</template>
