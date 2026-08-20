<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Search, Users, X } from '@lucide/vue';
import { reactive } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatRoleLabel } from '@/lib/displayLabels';
import type { PaginatedStaff, StaffOptions } from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Staff', href: '/staff' }] },
});

const props = defineProps<{
    staff: PaginatedStaff;
    filters: {
        search?: string;
        branch?: string;
        department?: string;
        role?: string;
        status?: string;
    };
    filterOptions: StaffOptions;
    canCreate: boolean;
}>();

const form = reactive({
    search: props.filters.search ?? '',
    branch: props.filters.branch ?? '',
    department: props.filters.department ?? '',
    role: props.filters.role ?? '',
    status: props.filters.status ?? '',
});

const applyFilters = () =>
    router.get('/staff', form, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
const clearFilters = () => {
    Object.assign(form, {
        search: '',
        branch: '',
        department: '',
        role: '',
        status: '',
    });
    applyFilters();
};
</script>

<template>
    <Head title="Staff directory" />
    <main class="flex flex-1 flex-col gap-5 p-4 md:p-7">
        <div
            class="flex flex-col justify-between gap-4 md:flex-row md:items-start"
        >
            <div class="flex items-start gap-3">
                <div class="rounded-xl bg-emerald-100 p-2.5 text-emerald-800">
                    <Users class="size-5" />
                </div>
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Staff directory
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Operational identities and access within your authorised
                        scope.
                    </p>
                </div>
            </div>
            <Button v-if="canCreate" as-child
                ><Link href="/staff/create"
                    ><Plus class="size-4" /> Create staff</Link
                ></Button
            >
        </div>

        <Card>
            <CardHeader class="pb-4"
                ><CardTitle class="text-base">Find staff</CardTitle></CardHeader
            >
            <CardContent>
                <form
                    class="grid gap-3 lg:grid-cols-[minmax(220px,1.4fr)_repeat(4,minmax(130px,1fr))_auto]"
                    @submit.prevent="applyFilters"
                >
                    <label class="relative">
                        <span class="sr-only">Search</span
                        ><Search
                            class="absolute top-2.5 left-3 size-4 text-muted-foreground"
                        />
                        <input
                            v-model="form.search"
                            class="h-9 w-full rounded-md border bg-background pr-3 pl-9 text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/10"
                            placeholder="Name, email, staff number"
                        />
                    </label>
                    <select
                        v-model="form.branch"
                        class="h-9 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="">All branches</option>
                        <option
                            v-for="branch in filterOptions.branches"
                            :key="branch.id"
                            :value="String(branch.id)"
                        >
                            {{ branch.name }}
                        </option>
                    </select>
                    <select
                        v-model="form.department"
                        class="h-9 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="">All departments</option>
                        <option
                            v-for="department in filterOptions.departments"
                            :key="department.id"
                            :value="String(department.id)"
                        >
                            {{ department.name }}
                        </option>
                    </select>
                    <select
                        v-model="form.role"
                        class="h-9 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="">All roles</option>
                        <option
                            v-for="role in filterOptions.roles"
                            :key="role"
                            :value="role"
                        >
                            {{ formatRoleLabel(role) }}
                        </option>
                    </select>
                    <select
                        v-model="form.status"
                        class="h-9 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="">Any status</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    <div class="flex gap-2">
                        <Button size="sm" type="submit">Apply</Button>
                        <Button
                            size="sm"
                            type="button"
                            variant="outline"
                            aria-label="Clear filters"
                            @click="clearFilters"
                            ><X class="size-4"
                        /></Button>
                    </div>
                </form>
            </CardContent>
        </Card>

        <Card>
            <CardHeader class="border-b py-4">
                <div class="flex items-center justify-between gap-4">
                    <CardTitle class="text-base"
                        >{{ staff.total }} staff account{{
                            staff.total === 1 ? '' : 's'
                        }}</CardTitle
                    >
                    <span class="text-xs text-muted-foreground"
                        >Current effective assignments shown</span
                    >
                </div>
            </CardHeader>
            <CardContent class="p-0">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[980px] text-left text-sm">
                        <thead
                            class="border-b bg-muted/35 text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="px-5 py-3">Staff member</th>
                                <th class="px-5 py-3">Employment</th>
                                <th class="px-5 py-3">Roles</th>
                                <th class="px-5 py-3">Current access</th>
                                <th class="px-5 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="member in staff.data"
                                :key="member.id"
                                class="hover:bg-muted/20"
                            >
                                <td class="px-5 py-4 align-top">
                                    <Link
                                        :href="`/staff/${member.id}`"
                                        class="font-medium text-foreground hover:text-emerald-700 hover:underline"
                                        >{{ member.name }}</Link
                                    >
                                    <div
                                        class="mt-0.5 text-xs text-muted-foreground"
                                    >
                                        {{ member.email }}
                                    </div>
                                    <div
                                        v-if="member.staffNumber"
                                        class="mt-1 text-xs font-medium text-muted-foreground"
                                    >
                                        {{ member.staffNumber }}
                                    </div>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <div>
                                        {{
                                            member.department ?? 'Not assigned'
                                        }}
                                    </div>
                                    <div
                                        class="mt-0.5 text-xs text-muted-foreground"
                                    >
                                        {{ member.jobTitle ?? 'No job title' }}
                                    </div>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <div class="flex max-w-56 flex-wrap gap-1">
                                        <Badge
                                            v-for="role in member.roles"
                                            :key="role"
                                            variant="secondary"
                                            >{{ formatRoleLabel(role) }}</Badge
                                        >
                                    </div>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <div
                                        v-if="member.currentAssignments.length"
                                        class="flex max-w-72 flex-wrap gap-1"
                                    >
                                        <Badge
                                            v-for="assignment in member.currentAssignments"
                                            :key="assignment.id"
                                            variant="outline"
                                            :class="
                                                assignment.isPrimary &&
                                                'border-emerald-300 bg-emerald-50 text-emerald-800'
                                            "
                                            >{{ assignment.branch.code
                                            }}<span v-if="assignment.isPrimary">
                                                · Primary</span
                                            ></Badge
                                        >
                                    </div>
                                    <span v-else class="text-xs text-amber-700"
                                        >No current assignment</span
                                    >
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <Badge
                                        :variant="
                                            member.isActive
                                                ? 'secondary'
                                                : 'destructive'
                                        "
                                        :class="
                                            member.isActive &&
                                            'bg-emerald-100 text-emerald-800 hover:bg-emerald-100'
                                        "
                                        >{{
                                            member.isActive
                                                ? 'Active'
                                                : 'Inactive'
                                        }}</Badge
                                    >
                                </td>
                            </tr>
                            <tr v-if="staff.data.length === 0">
                                <td
                                    colspan="5"
                                    class="px-6 py-12 text-center text-sm text-muted-foreground"
                                >
                                    No staff accounts match the current filters.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    v-if="staff.last_page > 1"
                    class="flex items-center justify-between border-t px-5 py-3"
                >
                    <span class="text-xs text-muted-foreground"
                        >Showing {{ staff.from }}–{{ staff.to }} of
                        {{ staff.total }}</span
                    >
                    <div class="flex gap-1">
                        <template v-for="link in staff.links" :key="link.label">
                            <Button
                                v-if="link.url"
                                size="sm"
                                :variant="link.active ? 'default' : 'outline'"
                                as-child
                                ><Link
                                    :href="link.url"
                                    preserve-scroll
                                    preserve-state
                                    >{{
                                        link.label
                                            .replace('&laquo;', '‹')
                                            .replace('&raquo;', '›')
                                    }}</Link
                                ></Button
                            >
                            <Button
                                v-else
                                size="sm"
                                variant="outline"
                                disabled
                                >{{
                                    link.label
                                        .replace('&laquo;', '‹')
                                        .replace('&raquo;', '›')
                                }}</Button
                            >
                        </template>
                    </div>
                </div>
            </CardContent>
        </Card>
    </main>
</template>
