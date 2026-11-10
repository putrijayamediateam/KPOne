<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Plus, Search, X } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { PageHeader } from '@/components/ui/page-header';
import { OperationalSelect } from '@/components/ui/select';
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
    // HR-17e: HR's staff-account requests; null for anyone who may not create staff accounts.
    accountRequests?: AccountRequest[] | null;
    // HR-15a: locum sign-ins HR asked for, and the locum accounts that can be linked; same audience.
    locumSignIns?: { requests: LocumSignIn[] } | null;
}>();

type LocumSignIn = {
    id: string;
    name: string;
    email: string;
    requestedOn: string;
    requestedBy: string;
    lockVersion: number;
    // The account with the email HR asked for, holding the Locum role only; null until it is created.
    account: { id: string; name: string } | null;
};
const page = usePage();
const linkError = computed(() => {
    const errors = (page.props.errors ?? {}) as Record<
        string,
        string | undefined
    >;

    return errors.user_id ?? errors.request ?? errors.records ?? null;
});
const linking = ref<string | null>(null);
const linkLocum = (request: LocumSignIn) => {
    linking.value = request.id;
    router.post(
        `/staff/locum-sign-ins/${request.id}/link`,
        {
            user_id: request.account?.id ?? '',
            expected_lock_version: request.lockVersion,
            idempotency_key: crypto.randomUUID(),
        },
        { preserveScroll: true, onFinish: () => (linking.value = null) },
    );
};

type AccountRequest = {
    id: string;
    name: string;
    email: string;
    position: string;
    branch: string;
    requestedOn: string;
    requestedBy: string;
    lockVersion: number;
};
const marking = ref<AccountRequest | null>(null);
const markKey = ref(crypto.randomUUID());
const markProcessing = ref(false);
const markDone = () => {
    const request = marking.value;

    if (!request) {
        return;
    }

    markProcessing.value = true;
    router.post(
        `/staff/account-requests/${request.id}/done`,
        {
            expected_lock_version: request.lockVersion,
            idempotency_key: markKey.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                marking.value = null;
                markKey.value = crypto.randomUUID();
            },
            onFinish: () => (markProcessing.value = false),
        },
    );
};

const form = reactive({
    search: props.filters.search ?? '',
    branch: props.filters.branch ?? '',
    department: props.filters.department ?? '',
    role: props.filters.role ?? '',
    status: props.filters.status ?? '',
});
const branchOptions = computed(() => [
    { value: '', label: 'All branches' },
    ...props.filterOptions.branches.map((branch) => ({
        value: String(branch.id),
        label: branch.name,
    })),
]);
const departmentOptions = computed(() => [
    { value: '', label: 'All departments' },
    ...props.filterOptions.departments.map((department) => ({
        value: String(department.id),
        label: department.name,
    })),
]);
const roleOptions = computed(() => [
    { value: '', label: 'All roles' },
    ...props.filterOptions.roles.map((role) => ({
        value: role,
        label: formatRoleLabel(role),
    })),
]);
const statusOptions = [
    { value: '', label: 'Any status' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];

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
        <PageHeader
            title="Staff directory"
            description="Operational identities and access within your authorised scope."
        >
            <template v-if="canCreate" #actions>
                <Button as-child
                    ><Link href="/staff/create"
                        ><Plus class="size-4" /> Create staff</Link
                    ></Button
                >
            </template>
        </PageHeader>

        <Card
            v-if="accountRequests && accountRequests.length"
            data-test="staff-account-requests"
        >
            <CardHeader>
                <CardTitle class="text-base"
                    >Staff accounts HR asked for</CardTitle
                >
                <CardDescription>
                    Candidates who passed and accepted. Create each account the
                    usual way with Create staff, then mark the request done.
                    Nothing is created from this list.
                </CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-3">
                <ul class="flex flex-col gap-2">
                    <li
                        v-for="request in accountRequests"
                        :key="request.id"
                        class="flex flex-col gap-2 rounded-lg border p-3 text-sm sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="flex min-w-0 flex-col">
                            <span class="font-medium break-words">{{
                                request.name
                            }}</span>
                            <span class="break-all text-muted-foreground">{{
                                request.email
                            }}</span>
                            <span class="text-muted-foreground"
                                >{{ request.position }} · {{ request.branch }} ·
                                asked on {{ request.requestedOn }} by
                                {{ request.requestedBy }}</span
                            >
                        </div>
                        <Button
                            variant="outline"
                            class="max-lg:min-h-11"
                            :aria-label="`Mark done: ${request.name}`"
                            data-test="staff-account-request-done"
                            @click="marking = request"
                            >Mark done</Button
                        >
                    </li>
                </ul>
            </CardContent>
        </Card>

        <Card
            v-if="locumSignIns && locumSignIns.requests.length"
            data-test="staff-locum-sign-ins"
        >
            <CardHeader>
                <CardTitle class="text-base"
                    >Locum sign-ins HR asked for</CardTitle
                >
                <CardDescription>
                    Create each account the usual way with Create staff, with
                    the Locum role only, then link it here. A locum sees only
                    their own approved shifts and the open shifts at their
                    branches.
                </CardDescription>
                <p
                    class="text-sm text-muted-foreground"
                    data-test="locum-director-only"
                >
                    Only the Director can create a Locum account.
                </p>
            </CardHeader>
            <CardContent class="flex flex-col gap-3">
                <p
                    v-if="linkError"
                    role="alert"
                    class="text-sm text-destructive"
                    data-test="staff-locum-link-error"
                >
                    {{ linkError }}
                </p>
                <ul class="flex flex-col gap-2">
                    <li
                        v-for="request in locumSignIns.requests"
                        :key="request.id"
                        class="flex flex-col gap-3 rounded-lg border p-3 text-sm lg:flex-row lg:items-end lg:justify-between"
                    >
                        <div class="flex min-w-0 flex-col">
                            <span class="font-medium break-words">{{
                                request.name
                            }}</span>
                            <span class="break-all text-muted-foreground">{{
                                request.email
                            }}</span>
                            <span class="text-muted-foreground"
                                >Locum · asked on {{ request.requestedOn }} by
                                {{ request.requestedBy }}</span
                            >
                        </div>
                        <Button
                            v-if="request.account"
                            variant="outline"
                            class="max-lg:min-h-11"
                            :disabled="linking === request.id"
                            :aria-label="`Link ${request.account.name} to ${request.name}`"
                            data-test="staff-locum-link"
                            @click="linkLocum(request)"
                            >{{
                                linking === request.id
                                    ? 'Linking…'
                                    : `Link ${request.account.name}`
                            }}</Button
                        >
                        <p
                            v-else
                            class="text-muted-foreground lg:max-w-xs"
                            data-test="staff-locum-none"
                        >
                            No account with this email yet. Create it with
                            Create staff, using this email and the Locum role
                            only.
                        </p>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <OperationalConfirmDialog
            :open="marking !== null"
            :title="`Mark done: ${marking?.name ?? ''}?`"
            description="Only once you have created their staff account with Create staff. HR sees that the account exists."
            confirm-label="Mark done"
            cancel-label="Not yet"
            :processing="markProcessing"
            @update:open="(open) => !open && (marking = null)"
            @confirm="markDone"
        />

        <Card>
            <CardHeader class="pb-4"
                ><CardTitle class="text-base">Find staff</CardTitle></CardHeader
            >
            <CardContent>
                <form
                    class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(220px,1.4fr)_repeat(4,minmax(130px,1fr))_auto]"
                    @submit.prevent="applyFilters"
                >
                    <label class="relative">
                        <span class="sr-only">Search</span
                        ><Search
                            class="absolute top-2.5 left-3 size-4 text-muted-foreground"
                        />
                        <input
                            v-model="form.search"
                            class="h-9 w-full rounded-md border bg-background pr-3 pl-9 text-sm outline-none focus:border-ring focus:ring-2 focus:ring-ring/20"
                            placeholder="Name, email, staff number"
                        />
                    </label>
                    <OperationalSelect
                        id="staff-filter-branch"
                        v-model="form.branch"
                        label="Branch"
                        :options="branchOptions"
                    />
                    <OperationalSelect
                        id="staff-filter-department"
                        v-model="form.department"
                        label="Department"
                        :options="departmentOptions"
                    />
                    <OperationalSelect
                        id="staff-filter-role"
                        v-model="form.role"
                        label="Role"
                        :options="roleOptions"
                    />
                    <OperationalSelect
                        id="staff-filter-status"
                        v-model="form.status"
                        label="Status"
                        :options="statusOptions"
                    />
                    <div class="flex gap-2 md:col-span-2 xl:col-span-1">
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
                                        class="font-medium text-foreground hover:text-success hover:underline"
                                        >{{ member.name }}</Link
                                    >
                                    <div
                                        class="mt-0.5 text-xs [overflow-wrap:anywhere] text-muted-foreground"
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
                                                'border-success/25 bg-success/10 text-success'
                                            "
                                            >{{ assignment.branch.code
                                            }}<span v-if="assignment.isPrimary">
                                                · Primary</span
                                            ></Badge
                                        >
                                    </div>
                                    <span
                                        v-else
                                        class="text-xs text-warning-strong"
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
                                            'border-success/25 bg-success/10 text-success hover:bg-success/10'
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
