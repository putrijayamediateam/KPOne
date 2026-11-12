<script setup lang="ts">
/**
 * Version 2 form field: a visible label, the control (a slot that receives the ids to wire up), a hint line and an
 * error line that is announced. Wire the control with `:id="id"` and `:aria-describedby="describedBy"`.
 */
const props = defineProps<{
    label: string;
    hint?: string;
    error?: string;
    required?: boolean;
}>();

const base = `v2-field-${Math.random().toString(36).slice(2, 8)}`;
const id = base;
const hintId = `${base}-hint`;
const errorId = `${base}-error`;

const describedBy = () =>
    [props.hint ? hintId : null, props.error ? errorId : null]
        .filter(Boolean)
        .join(' ') || undefined;
</script>

<template>
    <div data-slot="v2-field" class="flex min-w-0 flex-col gap-1.5">
        <label :for="id" class="text-sm font-medium text-foreground">
            {{ label
            }}<span v-if="required" class="text-destructive" aria-hidden="true">
                *</span
            >
        </label>
        <slot :id="id" :described-by="describedBy()" :invalid="!!error" />
        <p v-if="hint" :id="hintId" class="text-[13px] text-muted-foreground">
            {{ hint }}
        </p>
        <p
            v-if="error"
            :id="errorId"
            role="alert"
            class="text-sm text-destructive"
        >
            {{ error }}
        </p>
    </div>
</template>
