<script setup lang="ts">
// An amount field with a fixed, visible "RM" prefix (Daniel's HR-6 nit), right-aligned with tabular figures.
import { Input } from '@/components/ui/input';

defineProps<{
    id: string;
    invalid?: boolean;
    describedby?: string;
    label?: string;
}>();
const model = defineModel<string>({ required: true });
</script>

<template>
    <div
        class="flex w-44 items-stretch overflow-hidden rounded-md border bg-transparent focus-within:ring-2 focus-within:ring-ring dark:bg-input/30"
        :class="invalid ? 'border-destructive' : 'border-input'"
    >
        <span
            class="flex items-center self-stretch rounded-l-md border-r bg-muted px-3 text-sm font-medium text-foreground"
            aria-hidden="true"
            >RM</span
        >
        <Input
            :id="id"
            v-model="model"
            inputmode="decimal"
            autocomplete="off"
            class="border-0 bg-transparent text-right tabular-nums shadow-none focus-visible:ring-0 max-lg:h-11 dark:bg-transparent"
            :aria-invalid="invalid"
            :aria-describedby="describedby"
            :aria-label="label"
        />
    </div>
</template>
