<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { PageFrame } from '@/components/ui/page-frame';
import V2Field from '@/components/v2/V2Field.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
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

const submit = () => form.patch(`/staff/${props.staff.id}`);

const control =
    'h-11 w-full rounded-[10px] border bg-card px-3 text-base outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
const border = (error?: string) =>
    error ? 'border-destructive' : 'border-input';
</script>

<template>
    <Head :title="`Edit ${staff.name}`" />
    <PageFrame width="wide">
        <V2WorkLayout
            title="Edit staff profile"
            description="Identity and employment fields only. Roles, status and branches are managed separately."
        >
            <template #main>
                <form class="max-w-3xl" @submit.prevent="submit">
                    <V2FormCard
                        :title="staff.name"
                        description="Protected ownership and access attributes are not accepted by this form."
                    >
                        <V2Field
                            v-slot="{ id, describedBy }"
                            label="Full name"
                            :error="form.errors.name"
                        >
                            <input
                                :id="id"
                                v-model="form.name"
                                :aria-describedby="describedBy"
                                :class="[control, border(form.errors.name)]"
                            />
                        </V2Field>
                        <V2Field
                            v-slot="{ id, describedBy }"
                            label="Work email"
                            :error="form.errors.email"
                        >
                            <input
                                :id="id"
                                v-model="form.email"
                                type="email"
                                :aria-describedby="describedBy"
                                :class="[control, border(form.errors.email)]"
                            />
                        </V2Field>
                        <V2Field
                            v-slot="{ id, describedBy }"
                            label="Staff number"
                            :error="form.errors.staff_number"
                        >
                            <input
                                :id="id"
                                v-model="form.staff_number"
                                :aria-describedby="describedBy"
                                :class="[
                                    control,
                                    border(form.errors.staff_number),
                                ]"
                            />
                        </V2Field>
                        <V2Field
                            v-slot="{ id, describedBy }"
                            label="Job title"
                            :error="form.errors.job_title"
                        >
                            <input
                                :id="id"
                                v-model="form.job_title"
                                :aria-describedby="describedBy"
                                :class="[
                                    control,
                                    border(form.errors.job_title),
                                ]"
                            />
                        </V2Field>
                        <V2Field
                            v-slot="{ id, describedBy }"
                            label="Department"
                            :error="form.errors.department_id"
                        >
                            <select
                                :id="id"
                                v-model="form.department_id"
                                :aria-describedby="describedBy"
                                :class="[
                                    control,
                                    border(form.errors.department_id),
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
