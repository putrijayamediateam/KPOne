<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { PageFrame } from '@/components/ui/page-frame';
import V2Field from '@/components/v2/V2Field.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import V2LeaveGuard from '@/components/v2/V2LeaveGuard.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import type { StaffDetail, StaffOptions } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Staff', href: '/staff' }, { title: 'Edit' }],
    },
});

const props = defineProps<{ staff: StaffDetail; options: StaffOptions }>();

// The same fields, names and request as Classic: PATCH /staff/{id}.
const form = useForm({
    name: props.staff.name,
    email: props.staff.email,
    staff_number: props.staff.staffNumber ?? '',
    job_title: props.staff.jobTitle ?? '',
    department_id: props.staff.departmentId ?? '',
});

usePageCrumbs(() => [
    { title: 'Clinic management', href: '/clinic-management' },
    { title: 'Staff', href: '/staff' },
    { title: props.staff.name, href: `/staff/${props.staff.id}` },
]);

const submit = () => form.patch(`/staff/${props.staff.id}`);

const rawError = (key: string) =>
    (form.errors as Record<string, string | undefined>)[key];

// A plain line instead of a raw field name for the standard "required" text; real refusals (an email already used)
// keep the server's words. A field's error goes as soon as that field is edited.
const PLAIN: Record<string, string> = {
    name: 'Enter the full name.',
    email: 'Enter the work email.',
    department_id: 'Choose a department.',
};

const errorFor = (key: string): string | undefined => {
    const message = rawError(key);

    if (key === 'email' && message && /has already been taken/.test(message)) {
        return 'That email is already used by another staff member.';
    }

    return message !== undefined &&
        PLAIN[key] &&
        /field is required\.?$/.test(message)
        ? PLAIN[key]
        : message;
};

const clear = (
    key: 'name' | 'email' | 'staff_number' | 'job_title' | 'department_id',
) => form.clearErrors(key);

const control =
    'h-11 w-full rounded-[10px] border bg-card px-3 text-base outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
const border = (error?: string) =>
    error ? 'border-destructive' : 'border-input';
</script>

<template>
    <Head :title="`Edit ${staff.name}`" />
    <PageFrame width="wide">
        <V2LeaveGuard :dirty="form.isDirty" />
        <V2WorkLayout
            title="Edit staff profile"
            description="Identity and employment only. Roles, status and branches are changed on the person's own page."
        >
            <template #main>
                <form class="max-w-3xl" @submit.prevent="submit">
                    <V2FormCard :title="staff.name">
                        <V2Field
                            v-slot="{ id, describedBy }"
                            label="Full name"
                            :error="errorFor('name')"
                        >
                            <input
                                :id="id"
                                v-model="form.name"
                                @input="clear('name')"
                                :aria-describedby="describedBy"
                                :class="[control, border(errorFor('name'))]"
                            />
                        </V2Field>
                        <V2Field
                            v-slot="{ id, describedBy }"
                            label="Work email"
                            :error="errorFor('email')"
                        >
                            <input
                                :id="id"
                                v-model="form.email"
                                @input="clear('email')"
                                type="email"
                                :aria-describedby="describedBy"
                                :class="[control, border(errorFor('email'))]"
                            />
                        </V2Field>
                        <V2Field
                            v-slot="{ id, describedBy }"
                            label="Staff number"
                            :error="errorFor('staff_number')"
                        >
                            <input
                                :id="id"
                                v-model="form.staff_number"
                                @input="clear('staff_number')"
                                :aria-describedby="describedBy"
                                :class="[
                                    control,
                                    border(errorFor('staff_number')),
                                ]"
                            />
                        </V2Field>
                        <V2Field
                            v-slot="{ id, describedBy }"
                            label="Job title"
                            :error="errorFor('job_title')"
                        >
                            <input
                                :id="id"
                                v-model="form.job_title"
                                @input="clear('job_title')"
                                :aria-describedby="describedBy"
                                :class="[
                                    control,
                                    border(errorFor('job_title')),
                                ]"
                            />
                        </V2Field>
                        <V2Field
                            v-slot="{ id, describedBy }"
                            label="Department"
                            :error="errorFor('department_id')"
                        >
                            <select
                                :id="id"
                                v-model="form.department_id"
                                @change="clear('department_id')"
                                :aria-describedby="describedBy"
                                :class="[
                                    control,
                                    border(errorFor('department_id')),
                                ]"
                            >
                                <option
                                    v-for="department in options.departments"
                                    :key="department.id"
                                    :value="department.id"
                                >
                                    {{ department.name }}
                                </option>
                            </select>
                        </V2Field>
                        <div
                            class="flex flex-col-reverse gap-2 border-t pt-4 sm:flex-row sm:justify-end"
                        >
                            <Link
                                :href="`/staff/${staff.id}`"
                                class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background lg:h-9"
                                >Cancel</Link
                            >
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground outline-none hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:opacity-50 lg:h-9"
                            >
                                Save profile
                            </button>
                        </div>
                    </V2FormCard>
                </form>
            </template>
        </V2WorkLayout>
    </PageFrame>
</template>
