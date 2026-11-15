<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { HTMLAttributes } from 'vue';
import { Button } from '@/components/ui/button';
import type { ButtonVariants } from '@/components/ui/button';

const props = withDefaults(
    defineProps<{
        href: string;
        variant?: ButtonVariants['variant'];
        size?: ButtonVariants['size'];
        class?: HTMLAttributes['class'];
    }>(),
    { variant: 'ghost', size: 'sm' },
);
</script>

<template>
    <Button as-child :variant="variant" :size="size" :class="props.class">
        <Link :href="href"><slot /></Link>
    </Button>
</template>
