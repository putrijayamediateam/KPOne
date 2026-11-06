<script setup lang="ts">
import { ref } from 'vue';
import { Button } from '@/components/ui/button';

/**
 * CAT-10 (Daniel): a file picker that shows a real button and puts the chosen file's state on its own line, so a
 * phone never cuts "No file chosen". The native input stays in the page, hidden and out of the tab order; the
 * button opens it, so keyboard and screen-reader users reach the same picker. Attributes such as id and
 * aria-describedby go to the button. Built small so Staff Experience can adopt it as the shared FileInput.
 */
defineOptions({ inheritAttrs: false });

defineProps<{
    accept: string;
    label: string;
    status: string;
    statusId: string;
    disabled?: boolean;
}>();

const emit = defineEmits<{ change: [event: Event] }>();
const input = ref<HTMLInputElement | null>(null);

defineExpose({
    clear: () => {
        if (input.value) {
            input.value.value = '';
        }
    },
});
</script>

<template>
    <div class="grid min-w-0 gap-1.5">
        <input
            ref="input"
            type="file"
            :accept="accept"
            class="sr-only"
            tabindex="-1"
            aria-hidden="true"
            :disabled="disabled"
            @change="emit('change', $event)"
        />
        <Button
            v-bind="$attrs"
            type="button"
            variant="outline"
            class="min-h-11 w-fit sm:min-h-9"
            :disabled="disabled"
            @click="input?.click()"
            >{{ label }}</Button
        >
        <p
            :id="statusId"
            aria-live="polite"
            class="text-xs [overflow-wrap:anywhere] break-words text-muted-foreground"
        >
            {{ status }}
        </p>
        <slot />
    </div>
</template>
