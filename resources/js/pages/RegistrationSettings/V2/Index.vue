<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import V2StickyBar from '@/components/v2/V2StickyBar.vue';
import V2SwitchRow from '@/components/v2/V2SwitchRow.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 Registration settings (shown only in V2; Classic is `pages/RegistrationSettings/Index.vue`, untouched).
 * Same props, same route (`/registration-settings`), same payload and lock version. Look only.
 */
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

usePageCrumbs(() => [
    { title: 'Clinic management', href: '/clinic-management' },
    { title: 'Registration settings' },
]);

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
const dirty = computed(
    () =>
        form.require_date_of_birth !== props.requirements.requireDateOfBirth ||
        form.require_nationality !== props.requirements.requireNationality ||
        form.require_email !== props.requirements.requireEmail ||
        form.require_address !== props.requirements.requireAddress,
);
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
const message = computed(
    () =>
        form.errors.lock_version ||
        errorFor('settings') ||
        form.errors.expected_branch_id ||
        form.errors.idempotency_key ||
        form.errors.require_date_of_birth ||
        form.errors.require_nationality ||
        form.errors.require_email ||
        form.errors.require_address,
);

const outlineButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9';
const filledButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50 lg:h-9';
</script>

<template>
    <Head title="Registration settings" />
    <PageFrame>
        <V2WorkLayout
            title="Registration settings"
            description="Choose which patient details are required when a new patient is registered."
        >
            <template #main>
                <form
                    id="registration-settings-form"
                    class="flex max-w-3xl min-w-0 flex-col gap-4"
                    novalidate
                    @submit.prevent="submit"
                >
                    <V2FormCard
                        title="Required patient details"
                        description="These settings apply to new patient registration across the organisation. Existing patient records and visits are unchanged."
                    >
                        <div class="flex flex-col divide-y">
                            <V2SwitchRow
                                v-for="label in [
                                    'Name',
                                    'Phone number',
                                    'NRIC/Passport',
                                ]"
                                :key="label"
                                :model-value="true"
                                :label="label"
                                disabled
                                disabled-reason="Required by system"
                            />
                            <V2SwitchRow
                                v-model="form.require_date_of_birth"
                                label="Date of birth"
                                help="Required for new staff-created patients when selected. Public QR always requires date of birth for the minor and guardian check."
                            />
                            <V2SwitchRow
                                v-model="form.require_nationality"
                                label="Nationality"
                                help="Applies to new Patient creation, Quick create and public QR intake."
                            />
                            <V2SwitchRow
                                v-model="form.require_email"
                                label="Email"
                                help="Applies to new Patient creation, Quick create and public QR intake."
                            />
                            <V2SwitchRow
                                v-model="form.require_address"
                                label="Address"
                                help="Requires line 1, city, state, postcode and country. Line 2 stays optional."
                            />
                            <V2SwitchRow
                                :model-value="true"
                                label="Gender"
                                disabled
                                disabled-reason="Required; Unknown allowed"
                            />
                        </div>
                    </V2FormCard>

                    <p
                        v-if="message"
                        role="alert"
                        class="rounded-[10px] border border-destructive px-3 py-2 text-sm text-destructive"
                    >
                        {{ message }}
                    </p>
                    <div
                        v-if="
                            form.errors.lock_version ||
                            form.errors.expected_branch_id
                        "
                    >
                        <button
                            type="button"
                            :class="outlineButton"
                            @click="reloadLatest"
                        >
                            Reload latest settings
                        </button>
                    </div>
                    <p v-if="saved" role="status" class="text-sm">
                        Registration settings saved.
                    </p>

                    <p
                        class="text-sm text-muted-foreground"
                        data-test="applies-note"
                    >
                        Applies to new registrations from the moment you save.
                    </p>

                    <V2StickyBar always>
                        <button
                            type="submit"
                            :class="dirty ? filledButton : outlineButton"
                            :disabled="form.processing"
                        >
                            {{ form.processing ? 'Saving…' : 'Save' }}
                        </button>
                    </V2StickyBar>
                </form>
            </template>
        </V2WorkLayout>
    </PageFrame>
</template>
