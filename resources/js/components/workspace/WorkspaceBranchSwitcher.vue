<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Building2, ChevronDown } from '@lucide/vue';
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
        class="group flex h-9 items-center gap-1.5 rounded-lg border border-border/80 bg-background px-2 text-xs transition-colors focus-within:border-ring focus-within:ring-2 focus-within:ring-ring/25 hover:border-foreground/25 hover:bg-muted/20"
    >
        <Building2 class="size-3.5 text-muted-foreground" aria-hidden="true" />
        <span class="sr-only">Active branch</span>
        <select
            :value="String(context.active.id)"
            class="max-w-36 cursor-pointer appearance-none bg-transparent pr-4 font-normal text-foreground outline-none"
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
        <ChevronDown
            class="pointer-events-none -ml-4 size-3.5 text-muted-foreground transition-colors group-hover:text-foreground"
            aria-hidden="true"
        />
    </label>
</template>
