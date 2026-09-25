<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { OperationalSelect } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';

type ServiceRow = {
    publicId: string;
    code: string;
    displayName: string;
    orderUnit: string;
    isActive: boolean;
};

const props = defineProps<{
    services: {
        data: ServiceRow[];
        total: number;
        currentPage: number;
        lastPage: number;
    };
    filters: { search: string; status: string };
}>();

const search = ref(props.filters.search);
const status = ref(props.filters.status);
const statusOptions = [
    { value: '', label: 'All statuses' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];

const visit = (overrides: Record<string, string | number> = {}) => {
    router.get(
        '/clinical-services',
        {
            search: search.value || undefined,
            status: status.value || undefined,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const dialogOpen = ref(false);
const editing = ref<ServiceRow | null>(null);
const form = useForm({
    code: '',
    display_name: '',
    order_unit: '',
});

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
};
const openEdit = (row: ServiceRow) => {
    editing.value = row;
    form.clearErrors();
    form.code = row.code;
    form.display_name = row.displayName;
    form.order_unit = row.orderUnit;
    dialogOpen.value = true;
};
const submit = () => {
    if (editing.value) {
        form.patch(`/clinical-services/${editing.value.publicId}`, {
            preserveScroll: true,
            onSuccess: () => {
                dialogOpen.value = false;
            },
        });
    } else {
        form.post('/clinical-services', {
            preserveScroll: true,
            onSuccess: () => {
                dialogOpen.value = false;
            },
        });
    }
};

const pendingToggle = reactive<{ row: ServiceRow | null }>({ row: null });
const toggleBusy = ref(false);
const requestToggle = (row: ServiceRow) => {
    pendingToggle.row = row;
};
const confirmToggle = () => {
    const row = pendingToggle.row;

    if (!row) {
        return;
    }

    toggleBusy.value = true;
    router.post(
        `/clinical-services/${row.publicId}/${row.isActive ? 'deactivate' : 'activate'}`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                toggleBusy.value = false;
                pendingToggle.row = null;
            },
        },
    );
};
const toggleDescription = computed(() => {
    const row = pendingToggle.row;

    if (!row) {
        return '';
    }

    return row.isActive
        ? `${row.displayName} will no longer be available to order on a Treatment Plan.`
        : `${row.displayName} will become available to order on a Treatment Plan again.`;
});
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-4 px-4 py-6 md:px-6"
    >
        <PageHeader
            title="Clinical Service Catalogue"
            description="Create and manage clinical services available for ordering on a Treatment Plan."
        >
            <template #actions>
                <Button size="sm" @click="openCreate">
                    <Plus class="size-4" /> Add Clinical Service
                </Button>
            </template>
        </PageHeader>

        <form class="flex flex-wrap items-end gap-2" @submit.prevent="visit()">
            <label class="grid min-w-56 gap-1 text-xs">
                Search
                <Input
                    v-model="search"
                    placeholder="Code or name"
                    autocomplete="off"
                />
            </label>
            <OperationalSelect
                v-model="status"
                label="Status"
                :options="statusOptions"
            />
            <Button size="sm" type="submit">Apply</Button>
        </form>

        <OperationalTable
            label="Clinical Service Catalogue"
            :columns="4"
            :empty="services.data.length === 0"
            empty-message="No clinical services match this filter."
        >
            <template #head>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Order unit</th>
                    <th class="text-right">Status</th>
                </tr>
            </template>
            <template #body>
                <tr v-for="row in services.data" :key="row.publicId">
                    <td class="font-medium tabular-nums">{{ row.code }}</td>
                    <td>{{ row.displayName }}</td>
                    <td>{{ row.orderUnit }}</td>
                    <td class="text-right">
                        <div class="flex items-center justify-end gap-2">
                            <StatusBadge
                                :status="row.isActive ? 'active' : 'inactive'"
                                :tone="row.isActive ? 'success' : 'neutral'"
                            />
                            <Button
                                size="sm"
                                variant="outline"
                                @click="openEdit(row)"
                                >Edit</Button
                            >
                            <Button
                                size="sm"
                                :variant="row.isActive ? 'ghost' : 'secondary'"
                                @click="requestToggle(row)"
                            >
                                {{ row.isActive ? 'Deactivate' : 'Activate' }}
                            </Button>
                        </div>
                    </td>
                </tr>
            </template>
        </OperationalTable>

        <CompactPagination
            :current-page="services.currentPage"
            :last-page="services.lastPage"
            :total="services.total"
            @change="(page) => visit({ page })"
        />

        <Dialog v-model:open="dialogOpen">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{
                        editing
                            ? 'Edit Clinical Service'
                            : 'Add Clinical Service'
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            editing
                                ? 'Update this Clinical Service Catalogue entry.'
                                : 'Register a new clinical service in the organisation catalogue.'
                        }}
                    </DialogDescription>
                </DialogHeader>
                <form class="grid gap-3" @submit.prevent="submit">
                    <label class="grid gap-1 text-xs">
                        Code
                        <Input
                            v-model="form.code"
                            autocomplete="off"
                            maxlength="64"
                        />
                        <InputError :message="form.errors.code" />
                    </label>
                    <label class="grid gap-1 text-xs">
                        Display name
                        <Input
                            v-model="form.display_name"
                            autocomplete="off"
                            maxlength="500"
                        />
                        <InputError :message="form.errors.display_name" />
                    </label>
                    <label class="grid gap-1 text-xs">
                        Order unit
                        <Input
                            v-model="form.order_unit"
                            autocomplete="off"
                            maxlength="100"
                            placeholder="e.g. consultation, session, procedure"
                        />
                        <InputError :message="form.errors.order_unit" />
                    </label>
                    <DialogFooter class="mt-2">
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="form.processing"
                            @click="dialogOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            {{ editing ? 'Save' : 'Add Clinical Service' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <OperationalConfirmDialog
            :open="pendingToggle.row !== null"
            :title="
                pendingToggle.row?.isActive
                    ? 'Deactivate Clinical Service'
                    : 'Activate Clinical Service'
            "
            :description="toggleDescription"
            :confirm-label="
                pendingToggle.row?.isActive ? 'Deactivate' : 'Activate'
            "
            :destructive="pendingToggle.row?.isActive ?? false"
            :processing="toggleBusy"
            @update:open="
                (open) => {
                    if (!open) pendingToggle.row = null;
                }
            "
            @confirm="confirmToggle"
        />
    </main>
</template>
