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
    <Select
        v-if="context?.active && context.canSwitch"
        :model-value="String(context.active.id)"
        @update:model-value="changeBranch"
    >
        <SelectTrigger
            class="h-9 w-auto max-w-44 gap-2 border-0 bg-muted/60 px-2.5 text-xs font-normal shadow-none hover:bg-muted focus-visible:border-transparent focus-visible:ring-2 focus-visible:ring-ring/35"
            aria-label="Active branch"
        >
            <Building2
                class="size-3.5 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <SelectValue class="truncate" />
        </SelectTrigger>
        <SelectContent
            align="end"
            class="rounded-xl border-border/70 p-1.5 shadow-xl shadow-black/5"
        >
            <SelectItem
                v-for="branch in context.available"
                :key="branch.id"
                :value="String(branch.id)"
                class="min-h-8 rounded-lg px-2.5 text-[13px] data-[state=checked]:bg-pink-50 data-[state=checked]:text-pink-800 dark:data-[state=checked]:bg-pink-950/30 dark:data-[state=checked]:text-pink-200"
            >
                {{ branch.name }}
            </SelectItem>
        </SelectContent>
    </Select>
    <div
        v-else-if="context?.active"
        class="flex h-9 max-w-44 items-center gap-2 rounded-lg bg-muted/60 px-2.5 text-xs text-foreground"
        aria-label="Active branch"
    >
        <Building2
            class="size-3.5 shrink-0 text-muted-foreground"
            aria-hidden="true"
        />
        <span class="truncate">{{ context.active.name }}</span>
    </div>
</template>
