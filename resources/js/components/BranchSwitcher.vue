<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Building2 } from '@lucide/vue';
import { computed } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

const page = usePage();
const context = computed(() => page.props.branchContext);

const changeBranch = (value: unknown) => {
    const branchId = Number(value);

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
    <div
        v-if="context?.active"
        class="px-2 pb-3 group-data-[collapsible=icon]:hidden"
    >
        <span
            class="mb-1.5 block px-1 text-[11px] font-medium tracking-[0.08em] text-sidebar-foreground/50 uppercase"
        >
            Branch
        </span>
        <Select
            :model-value="String(context.active.id)"
            @update:model-value="changeBranch"
        >
            <SelectTrigger
                id="branch-context"
                class="h-9 w-full gap-2 border-sidebar-border bg-sidebar-accent/50 px-3 text-sm font-medium shadow-none transition-colors duration-150 hover:bg-sidebar-accent focus-visible:ring-primary/20"
            >
                <Building2 class="size-4 shrink-0 text-primary" />
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="branch in context.available"
                    :key="branch.id"
                    :value="String(branch.id)"
                >
                    {{ branch.name }}
                </SelectItem>
            </SelectContent>
        </Select>
    </div>
</template>
