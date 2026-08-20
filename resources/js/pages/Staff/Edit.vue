<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, UserRoundPen } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { StaffDetail, StaffOptions } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Staff', href: '/staff' }, { title: 'Edit' }],
    },
});
const props = defineProps<{ staff: StaffDetail; options: StaffOptions }>();
const form = useForm({
    name: props.staff.name,
    email: props.staff.email,
    staff_number: props.staff.staffNumber ?? '',
    job_title: props.staff.jobTitle ?? '',
    department_id: props.staff.departmentId ?? '',
});
const submit = () => form.patch(`/staff/${props.staff.id}`);
</script>

<template>
    <Head :title="`Edit ${staff.name}`" />
    <main class="flex flex-1 flex-col gap-5 p-4 md:p-7">
        <div class="flex items-start gap-3">
            <div class="rounded-xl bg-emerald-100 p-2.5 text-emerald-800">
                <UserRoundPen class="size-5" />
            </div>
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Edit staff profile
                </h1>
                <p class="text-sm text-muted-foreground">
                    Identity and employment fields only. Roles, status and
                    branches are managed separately.
                </p>
            </div>
        </div>
        <Card class="max-w-3xl"
            ><CardHeader
                ><CardTitle class="text-base">{{ staff.name }}</CardTitle
                ><CardDescription
                    >Protected ownership and access attributes are not accepted
                    by this form.</CardDescription
                ></CardHeader
            ><CardContent>
                <form
                    class="grid gap-5 sm:grid-cols-2"
                    @submit.prevent="submit"
                >
                    <div class="grid gap-2 sm:col-span-2">
                        <label class="text-sm font-medium">Full name</label
                        ><input
                            v-model="form.name"
                            class="h-9 rounded-md border bg-background px-3 text-sm"
                        /><InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <label class="text-sm font-medium">Work email</label
                        ><input
                            v-model="form.email"
                            type="email"
                            class="h-9 rounded-md border bg-background px-3 text-sm"
                        /><InputError :message="form.errors.email" />
                    </div>
                    <div class="grid gap-2">
                        <label class="text-sm font-medium">Staff number</label
                        ><input
                            v-model="form.staff_number"
                            class="h-9 rounded-md border bg-background px-3 text-sm"
                        /><InputError :message="form.errors.staff_number" />
                    </div>
                    <div class="grid gap-2">
                        <label class="text-sm font-medium">Job title</label
                        ><input
                            v-model="form.job_title"
                            class="h-9 rounded-md border bg-background px-3 text-sm"
                        /><InputError :message="form.errors.job_title" />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <label class="text-sm font-medium">Department</label
                        ><select
                            v-model="form.department_id"
                            class="h-9 rounded-md border bg-background px-3 text-sm"
                        >
                            <option
                                v-for="department in options.departments"
                                :key="department.id"
                                :value="department.id"
                            >
                                {{ department.name }}
                            </option></select
                        ><InputError :message="form.errors.department_id" />
                    </div>
                    <div class="flex gap-2 sm:col-span-2">
                        <Button type="submit" :disabled="form.processing"
                            >Save profile</Button
                        ><Button type="button" variant="outline" as-child
                            ><Link :href="`/staff/${staff.id}`"
                                ><ArrowLeft class="size-4" /> Cancel</Link
                            ></Button
                        >
                    </div>
                </form>
            </CardContent></Card
        >
    </main>
</template>
