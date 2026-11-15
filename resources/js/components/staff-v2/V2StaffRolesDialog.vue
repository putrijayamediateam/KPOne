<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import { formatRoleLabel } from '@/lib/displayLabels';

/** Version 2 "Manage staff roles" dialog. Same request as Classic: PUT /staff/{id}/roles with the full role list. */
const props = defineProps<{
    open: boolean;
    staffId: number;
    currentRoles: string[];
    roles: string[];
}>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const form = useForm({ roles: [...props.currentRoles] });

watch(
    () => props.open,
    (open) => {
        if (open) {
            form.roles = [...props.currentRoles];
            form.clearErrors();
        }
    },
);

const toggle = (role: string, checked: boolean) => {
    form.roles = checked
        ? [...form.roles, role]
        : form.roles.filter((item) => item !== role);
};

const save = () =>
    form.put(`/staff/${props.staffId}/roles`, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
</script>

<template>
    <V2Dialog
        :open="open"
        title="Manage staff roles"
        description="Roles change effective application authority. At least one approved KPOne role must remain assigned."
        confirm-label="Save roles"
        :busy="form.processing"
        @update:open="emit('update:open', $event)"
        @confirm="save"
    >
        <fieldset class="flex min-w-0 flex-col gap-2">
            <legend class="sr-only">Roles</legend>
            <label
                v-for="role in roles"
                :key="role"
                class="flex min-h-11 cursor-pointer items-center gap-3 rounded-[10px] border border-border px-3 text-sm font-medium hover:bg-muted lg:min-h-9"
            >
                <input
                    type="checkbox"
                    class="size-5 shrink-0 accent-primary"
                    :checked="form.roles.includes(role)"
                    @change="
                        toggle(
                            role,
                            ($event.target as HTMLInputElement).checked,
                        )
                    "
                />
                {{ formatRoleLabel(role) }}
            </label>
        </fieldset>
        <p
            v-if="form.errors.roles"
            role="alert"
            class="text-sm text-destructive"
        >
            {{ form.errors.roles }}
        </p>
    </V2Dialog>
</template>
