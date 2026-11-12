<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';

type PaymentMethodRow = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    requiresReference: boolean;
    sortOrder: number;
    isActive: boolean;
    classification: 'cash' | 'non_cash' | null;
    lockVersion: number;
};

defineProps<{ methods: PaymentMethodRow[] }>();

const createForm = useForm({
    code: '',
    name: '',
    description: '',
    requires_reference: false,
    sort_order: 100,
});
const create = () => {
    createForm
        .transform((data) => ({
            ...data,
            description: data.description.trim() || null,
        }))
        .post('/payment-methods', {
            preserveScroll: true,
            onSuccess: () => createForm.reset(),
        });
};

const classifying = ref<PaymentMethodRow | null>(null);
const classificationForm = useForm({
    classification: null as 'cash' | 'non_cash' | null,
    lock_version: 0,
});
const beginClassification = (method: PaymentMethodRow) => {
    classifying.value = method;
    classificationForm.classification = method.classification;
    classificationForm.lock_version = method.lockVersion;
    classificationForm.clearErrors();
};
const saveClassification = () => {
    if (!classifying.value) {
        return;
    }

    classificationForm.patch(
        `/payment-methods/${classifying.value.id}/classification`,
        {
            preserveScroll: true,
            onSuccess: () => {
                classifying.value = null;
                classificationForm.reset();
            },
        },
    );
};
const editingId = ref<number | null>(null);
const editForm = useForm({
    name: '',
    description: '',
    requires_reference: false,
    sort_order: 100,
});
const beginEdit = (method: PaymentMethodRow) => {
    editingId.value = method.id;
    editForm.name = method.name;
    editForm.description = method.description ?? '';
    editForm.requires_reference = method.requiresReference;
    editForm.sort_order = method.sortOrder;
    editForm.clearErrors();
};
const cancelEdit = () => {
    editingId.value = null;
    editForm.reset();
};
const saveEdit = () => {
    if (editingId.value === null) {
        return;
    }

    editForm
        .transform((data) => ({
            ...data,
            description: data.description.trim() || null,
        }))
        .patch(`/payment-methods/${editingId.value}`, {
            preserveScroll: true,
            onSuccess: cancelEdit,
        });
};
const classifyOpen = computed({
    get: () => classifying.value !== null,
    set: (open: boolean) => {
        if (!open) {
            classifying.value = null;
        }
    },
});
const editOpen = computed({
    get: () => editingId.value !== null,
    set: (open: boolean) => {
        if (!open) {
            cancelEdit();
        }
    },
});
const classificationLabel = (method: PaymentMethodRow) =>
    method.classification === 'cash'
        ? 'Cash'
        : method.classification === 'non_cash'
          ? 'Non-cash'
          : 'Not classified';
const changeStatus = (method: PaymentMethodRow) => {
    router.post(
        `/payment-methods/${method.id}/${method.isActive ? 'deactivate' : 'publish'}`,
        {},
        { preserveScroll: true },
    );
};
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-[1280px] flex-1 flex-col gap-6 px-4 py-6 md:px-6"
    >
        <PageHeader
            title="Payment Methods"
            description="Configure the organisation payment methods available at checkout."
        />

        <p class="text-sm text-muted-foreground">
            Classification changes apply to reports generated from now on,
            including historical periods. Stored payments and receipts never
            change. Not classified is counted as Non-cash in reports.
        </p>
        <section class="rounded-[14px] border bg-card p-5">
            <h2 class="text-lg font-semibold">Add Payment Method</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                New methods are inactive until published. Codes cannot be
                changed after creation.
            </p>
            <form
                class="mt-4 grid gap-4 md:grid-cols-2"
                @submit.prevent="create"
            >
                <label class="grid gap-1.5 text-sm font-medium">
                    Code
                    <Input
                        v-model="createForm.code"
                        class="max-lg:h-11"
                        autocomplete="off"
                        maxlength="40"
                        placeholder="e.g. CASH or BANK_TRANSFER"
                    />
                    <InputError :message="createForm.errors.code" />
                </label>
                <label class="grid gap-1.5 text-sm font-medium">
                    Name
                    <Input
                        v-model="createForm.name"
                        class="max-lg:h-11"
                        autocomplete="off"
                        maxlength="100"
                    />
                    <InputError :message="createForm.errors.name" />
                </label>
                <label class="grid gap-1.5 text-sm font-medium md:col-span-2">
                    Description (optional)
                    <Input
                        v-model="createForm.description"
                        class="max-lg:h-11"
                        autocomplete="off"
                        maxlength="500"
                    />
                    <InputError :message="createForm.errors.description" />
                </label>
                <label class="flex min-h-11 items-center gap-2 text-sm">
                    <Checkbox v-model:checked="createForm.requires_reference" />
                    Requires a transaction reference
                </label>
                <label class="grid gap-1.5 text-sm font-medium">
                    Display order
                    <Input
                        v-model.number="createForm.sort_order"
                        class="max-lg:h-11"
                        type="number"
                        min="0"
                        max="32767"
                    />
                    <InputError :message="createForm.errors.sort_order" />
                </label>
                <div class="flex justify-end md:col-span-2">
                    <Button
                        type="submit"
                        class="max-lg:h-11"
                        :disabled="createForm.processing"
                    >
                        Add Payment Method
                    </Button>
                </div>
            </form>
        </section>

        <section class="flex flex-col gap-3">
            <h2 class="text-lg font-semibold">Organisation Payment Methods</h2>
            <div
                v-if="methods.length === 0"
                class="rounded-[14px] border border-dashed bg-card p-5 text-sm text-muted-foreground"
            >
                No Payment Methods are configured. Add and publish one before
                recording payments.
            </div>
            <template v-else>
                <div class="hidden lg:block">
                    <OperationalTable
                        label="Organisation Payment Methods"
                        :columns="7"
                        min-width="760px"
                    >
                        <template #head>
                            <tr class="text-left">
                                <th>Code</th>
                                <th>Name</th>
                                <th>Classification</th>
                                <th>Reference</th>
                                <th>Order</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </template>
                        <template #body>
                            <tr v-for="method in methods" :key="method.id">
                                <td class="font-mono text-xs">
                                    {{ method.code }}
                                </td>
                                <td>
                                    <div class="font-medium">
                                        {{ method.name }}
                                    </div>
                                    <div
                                        v-if="method.description"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ method.description }}
                                    </div>
                                </td>
                                <td>
                                    <p>{{ classificationLabel(method) }}</p>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="beginClassification(method)"
                                        >Change classification</Button
                                    >
                                </td>
                                <td>
                                    {{
                                        method.requiresReference
                                            ? 'Required'
                                            : 'No'
                                    }}
                                </td>
                                <td class="tabular-nums">
                                    {{ method.sortOrder }}
                                </td>
                                <td>
                                    <StatusBadge
                                        :status="
                                            method.isActive
                                                ? 'Active'
                                                : 'Inactive'
                                        "
                                        :tone="
                                            method.isActive
                                                ? 'success'
                                                : 'neutral'
                                        "
                                    />
                                </td>
                                <td>
                                    <div class="flex flex-wrap gap-2">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            @click="beginEdit(method)"
                                        >
                                            Edit
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            :class="
                                                method.isActive
                                                    ? 'text-destructive'
                                                    : ''
                                            "
                                            @click="changeStatus(method)"
                                        >
                                            {{
                                                method.isActive
                                                    ? 'Deactivate'
                                                    : 'Publish'
                                            }}
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </OperationalTable>
                </div>
                <ul class="grid gap-3 lg:hidden">
                    <li
                        v-for="method in methods"
                        :key="method.id"
                        class="rounded-[14px] border bg-card p-4"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-medium break-words">
                                    {{ method.name }}
                                </p>
                                <p
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{ method.code }}
                                </p>
                                <p
                                    v-if="method.description"
                                    class="mt-1 text-sm text-muted-foreground"
                                >
                                    {{ method.description }}
                                </p>
                            </div>
                            <StatusBadge
                                :status="
                                    method.isActive ? 'Active' : 'Inactive'
                                "
                                :tone="method.isActive ? 'success' : 'neutral'"
                            />
                        </div>
                        <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Classification
                                </dt>
                                <dd>{{ classificationLabel(method) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Reference
                                </dt>
                                <dd>
                                    {{
                                        method.requiresReference
                                            ? 'Required'
                                            : 'No'
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Order
                                </dt>
                                <dd class="tabular-nums">
                                    {{ method.sortOrder }}
                                </dd>
                            </div>
                        </dl>
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <Button
                                variant="outline"
                                class="col-span-2 max-lg:h-11"
                                @click="beginClassification(method)"
                                >Change classification</Button
                            >
                            <Button
                                variant="outline"
                                class="max-lg:h-11"
                                @click="beginEdit(method)"
                            >
                                Edit
                            </Button>
                            <Button
                                variant="outline"
                                class="max-lg:h-11"
                                :class="
                                    method.isActive ? 'text-destructive' : ''
                                "
                                @click="changeStatus(method)"
                            >
                                {{ method.isActive ? 'Deactivate' : 'Publish' }}
                            </Button>
                        </div>
                    </li>
                </ul>
            </template>
        </section>

        <Dialog v-model:open="classifyOpen">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Change classification</DialogTitle>
                    <DialogDescription>{{
                        classifying?.name
                    }}</DialogDescription>
                </DialogHeader>
                <form class="grid gap-4" @submit.prevent="saveClassification">
                    <label class="grid gap-1.5 text-sm font-medium"
                        >Classification
                        <select
                            v-model="classificationForm.classification"
                            class="h-11 rounded-[10px] border bg-card px-3 text-sm font-normal text-foreground lg:h-9"
                        >
                            <option :value="null">Not classified</option>
                            <option value="cash">Cash</option>
                            <option value="non_cash">Non-cash</option>
                        </select>
                    </label>
                    <InputError
                        :message="classificationForm.errors.classification"
                    />
                    <InputError
                        :message="classificationForm.errors.lock_version"
                    />
                    <p class="text-sm text-muted-foreground">
                        Not classified counts as Non-cash. Reports use the
                        current classification; stored receipts are unchanged.
                    </p>
                    <DialogFooter class="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            class="max-lg:h-11"
                            @click="classifying = null"
                            >Cancel</Button
                        >
                        <Button
                            type="submit"
                            class="max-lg:h-11"
                            :disabled="classificationForm.processing"
                            >Save classification</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
        <Dialog v-model:open="editOpen">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Edit Payment Method</DialogTitle>
                    <DialogDescription>
                        Code is retained as the immutable transaction
                        identifier.
                    </DialogDescription>
                </DialogHeader>
                <form class="grid gap-4" @submit.prevent="saveEdit">
                    <label class="grid gap-1.5 text-sm font-medium">
                        Name
                        <Input
                            v-model="editForm.name"
                            class="max-lg:h-11"
                            maxlength="100"
                        />
                        <InputError :message="editForm.errors.name" />
                    </label>
                    <label class="grid gap-1.5 text-sm font-medium">
                        Description (optional)
                        <Input
                            v-model="editForm.description"
                            class="max-lg:h-11"
                            maxlength="500"
                        />
                        <InputError :message="editForm.errors.description" />
                    </label>
                    <label class="flex min-h-11 items-center gap-2 text-sm">
                        <Checkbox
                            v-model:checked="editForm.requires_reference"
                        />
                        Requires a transaction reference
                    </label>
                    <label class="grid gap-1.5 text-sm font-medium">
                        Display order
                        <Input
                            v-model.number="editForm.sort_order"
                            class="max-lg:h-11"
                            type="number"
                            min="0"
                            max="32767"
                        />
                        <InputError :message="editForm.errors.sort_order" />
                    </label>
                    <DialogFooter class="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            class="max-lg:h-11"
                            @click="cancelEdit"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            class="max-lg:h-11"
                            :disabled="editForm.processing"
                        >
                            Save changes
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </main>
</template>
