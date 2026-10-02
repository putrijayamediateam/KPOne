<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status';

type PaymentMethodRow = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    requiresReference: boolean;
    sortOrder: number;
    isActive: boolean;
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
        class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 px-4 py-6 md:px-6"
    >
        <PageHeader
            title="Payment Methods"
            description="Configure the organisation payment methods available at checkout."
        />

        <section class="rounded-xl border bg-card p-4">
            <h2 class="text-sm font-semibold">Add Payment Method</h2>
            <p class="mt-1 text-xs text-muted-foreground">
                New methods are inactive until published. Codes cannot be
                changed after creation.
            </p>
            <form
                class="mt-4 grid gap-3 md:grid-cols-2"
                @submit.prevent="create"
            >
                <label class="grid gap-1 text-xs">
                    Code
                    <Input
                        v-model="createForm.code"
                        autocomplete="off"
                        maxlength="40"
                        placeholder="e.g. CASH or BANK_TRANSFER"
                    />
                    <InputError :message="createForm.errors.code" />
                </label>
                <label class="grid gap-1 text-xs">
                    Name
                    <Input
                        v-model="createForm.name"
                        autocomplete="off"
                        maxlength="100"
                    />
                    <InputError :message="createForm.errors.name" />
                </label>
                <label class="grid gap-1 text-xs md:col-span-2">
                    Description (optional)
                    <Input
                        v-model="createForm.description"
                        autocomplete="off"
                        maxlength="500"
                    />
                    <InputError :message="createForm.errors.description" />
                </label>
                <label class="flex items-center gap-2 text-xs">
                    <Checkbox v-model:checked="createForm.requires_reference" />
                    Requires a transaction reference
                </label>
                <label class="grid gap-1 text-xs">
                    Display order
                    <Input
                        v-model.number="createForm.sort_order"
                        type="number"
                        min="0"
                        max="32767"
                    />
                    <InputError :message="createForm.errors.sort_order" />
                </label>
                <div class="md:col-span-2">
                    <Button type="submit" :disabled="createForm.processing">
                        Add Payment Method
                    </Button>
                </div>
            </form>
        </section>

        <section class="rounded-xl border bg-card p-4">
            <h2 class="text-sm font-semibold">Organisation Payment Methods</h2>
            <div
                v-if="methods.length === 0"
                class="mt-3 rounded-lg border border-dashed p-5 text-sm text-muted-foreground"
            >
                No Payment Methods are configured. Add and publish one before
                recording payments.
            </div>
            <div v-else class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead>
                        <tr class="border-b text-xs text-muted-foreground">
                            <th class="py-2 pr-3 font-medium">Code</th>
                            <th class="py-2 pr-3 font-medium">Name</th>
                            <th class="py-2 pr-3 font-medium">Reference</th>
                            <th class="py-2 pr-3 font-medium">Order</th>
                            <th class="py-2 pr-3 font-medium">Status</th>
                            <th class="py-2 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="method in methods"
                            :key="method.id"
                            class="border-b last:border-0"
                        >
                            <td class="py-3 pr-3 font-mono text-xs">
                                {{ method.code }}
                            </td>
                            <td class="py-3 pr-3">
                                <div class="font-medium">{{ method.name }}</div>
                                <div
                                    v-if="method.description"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ method.description }}
                                </div>
                            </td>
                            <td class="py-3 pr-3">
                                {{
                                    method.requiresReference ? 'Required' : 'No'
                                }}
                            </td>
                            <td class="py-3 pr-3">{{ method.sortOrder }}</td>
                            <td class="py-3 pr-3">
                                <StatusBadge
                                    :status="
                                        method.isActive ? 'Active' : 'Inactive'
                                    "
                                />
                            </td>
                            <td class="py-3">
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
                                        :variant="
                                            method.isActive
                                                ? 'destructive'
                                                : 'default'
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
                    </tbody>
                </table>
            </div>
        </section>

        <div
            v-if="editingId !== null"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            role="presentation"
            @keydown.esc="cancelEdit"
        >
            <section
                class="w-full max-w-lg rounded-xl border bg-card p-5 shadow-xl"
                role="dialog"
                aria-modal="true"
                aria-labelledby="payment-method-edit-title"
            >
                <h2
                    id="payment-method-edit-title"
                    class="text-sm font-semibold"
                >
                    Edit Payment Method
                </h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    Code is retained as the immutable transaction identifier.
                </p>
                <form class="mt-4 grid gap-3" @submit.prevent="saveEdit">
                    <label class="grid gap-1 text-xs">
                        Name
                        <Input v-model="editForm.name" maxlength="100" />
                        <InputError :message="editForm.errors.name" />
                    </label>
                    <label class="grid gap-1 text-xs">
                        Description (optional)
                        <Input v-model="editForm.description" maxlength="500" />
                        <InputError :message="editForm.errors.description" />
                    </label>
                    <label class="flex items-center gap-2 text-xs">
                        <Checkbox
                            v-model:checked="editForm.requires_reference"
                        />
                        Requires a transaction reference
                    </label>
                    <label class="grid gap-1 text-xs">
                        Display order
                        <Input
                            v-model.number="editForm.sort_order"
                            type="number"
                            min="0"
                            max="32767"
                        />
                        <InputError :message="editForm.errors.sort_order" />
                    </label>
                    <div class="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="cancelEdit"
                        >
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="editForm.processing">
                            Save changes
                        </Button>
                    </div>
                </form>
            </section>
        </div>
    </main>
</template>
