<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
    title: string;
    description?: string;
    eyebrow?: string;
}>();

// Clean Look: with no top bar, the active branch always shows under the page title (a wrong-branch action is a safety risk).
const page = usePage();
const branchName = computed(
    () => page.props.branchContext?.active?.name ?? null,
);
</script>

<template>
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0 space-y-1">
            <p
                v-if="eyebrow"
                class="text-[12.5px] font-medium text-muted-foreground"
            >
                {{ eyebrow }}
            </p>
            <!-- UX-15: an optional badge (for example a status) sits beside the title. -->
            <div
                v-if="$slots['title-extra']"
                class="flex flex-wrap items-center gap-2"
            >
                <h1
                    class="text-[26px] font-semibold tracking-[-0.02em] text-foreground lg:text-[30px]"
                >
                    {{ title }}
                </h1>
                <slot name="title-extra" />
            </div>
            <h1
                v-else
                class="text-[26px] font-semibold tracking-[-0.02em] text-foreground lg:text-[30px]"
            >
                {{ title }}
            </h1>
            <!-- UX-15: the description slot is for a line that needs markup; plain text uses the prop. -->
            <p
                v-if="description || $slots.description"
                class="text-[13px] text-muted-foreground"
            >
                <slot name="description">{{ description }}</slot>
            </p>
            <p
                v-if="branchName"
                class="text-[12.5px] text-muted-foreground"
                data-test="page-branch"
            >
                {{ branchName }}
            </p>
        </div>
        <div v-if="$slots.actions" class="kp-control-group">
            <slot name="actions" />
        </div>
    </header>
</template>
