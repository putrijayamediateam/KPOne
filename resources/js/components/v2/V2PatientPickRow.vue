<script setup lang="ts">
/**
 * Version 2 patient pick row (the recent-patients list): name, KP number, masked identifier and date of birth.
 * The selected row has a success tint and a check. A 56px row; the whole row is the button.
 */
defineProps<{
    name: string;
    kpNumber: string;
    maskedIdentifier?: string;
    dateOfBirth?: string;
    selected: boolean;
}>();

const emit = defineEmits<{ select: [] }>();
</script>

<template>
    <button
        data-slot="v2-patient-pick-row"
        type="button"
        role="radio"
        :aria-checked="selected"
        class="flex min-h-14 w-full items-center gap-3 rounded-[10px] border px-3 py-2 text-left outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
        :class="
            selected
                ? 'border-success bg-success-soft'
                : 'border-transparent hover:bg-muted'
        "
        @click="emit('select')"
    >
        <span class="min-w-0 flex-1">
            <span class="block truncate text-[15px] font-medium">{{
                name
            }}</span>
            <span
                class="block text-[13px] text-muted-foreground tabular-nums"
                >{{ kpNumber }}</span
            >
        </span>
        <span
            class="shrink-0 text-right text-[13px] text-muted-foreground tabular-nums"
        >
            <span v-if="dateOfBirth" class="block">{{ dateOfBirth }}</span>
            <span v-if="maskedIdentifier" class="block">{{
                maskedIdentifier
            }}</span>
        </span>
        <svg
            v-if="selected"
            class="size-5 shrink-0 text-success"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2.25"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <path d="m5 12 5 5 9-10" />
        </svg>
    </button>
</template>
