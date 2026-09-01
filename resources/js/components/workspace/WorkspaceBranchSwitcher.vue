<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Building2 } from '@lucide/vue';
import { computed } from 'vue';

const page = usePage();
const context = computed(() => page.props.branchContext);

const changeBranch = (event: Event) => {
    const branchId = Number((event.target as HTMLSelectElement).value);

    if (branchId) {
        router.post(
            '/branch-context',
            { branch_id: branchId },
            { preserveScroll: true },
        );
    }
};
</script>

<template>
    <label
        v-if="context?.active"
        class="flex h-9 items-center gap-1.5 rounded-md border bg-background px-2 text-xs"
    >
        <Building2 class="size-3.5 text-muted-foreground" aria-hidden="true" />
        <span class="sr-only">Active branch</span>
        <select
            :value="String(context.active.id)"
            class="max-w-36 bg-transparent font-medium outline-none"
            aria-label="Active branch"
            @change="changeBranch"
        >
            <option
                v-for="branch in context.available"
                :key="branch.id"
                :value="String(branch.id)"
            >
                {{ branch.name }}
            </option>
        </select>
    </label>
</template>
