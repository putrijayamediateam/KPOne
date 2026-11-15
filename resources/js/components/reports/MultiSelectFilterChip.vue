<script setup lang="ts">
import {
    PopoverRoot,
    PopoverTrigger,
    PopoverPortal,
    PopoverContent,
} from 'reka-ui';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';

const props = defineProps<{
    label: string;
    modelValue: string[];
    options: { value: string; label: string }[];
    disabled?: boolean;
}>();
const emit = defineEmits<{
    'update:modelValue': [value: string[]];
    pending: [value: boolean];
}>();
const open = ref(false);
const search = ref('');
const draft = ref<string[]>([]);
const visible = computed(() =>
    props.options.filter((option) =>
        option.label
            .toLocaleLowerCase()
            .includes(search.value.toLocaleLowerCase()),
    ),
);
watch(open, (value) => {
    if (value) {
        draft.value = [...props.modelValue];
        search.value = '';
    } else {
        emit('pending', false);
    }
});
function toggle(value: string) {
    draft.value = draft.value.includes(value)
        ? draft.value.filter((item) => item !== value)
        : [...draft.value, value];
    emit(
        'pending',
        JSON.stringify([...draft.value].sort()) !==
            JSON.stringify([...props.modelValue].sort()),
    );
}
function apply() {
    emit('update:modelValue', [...draft.value].sort());
    open.value = false;
}
</script>
<template>
    <PopoverRoot v-model:open="open">
        <PopoverTrigger as-child
            ><Button
                variant="outline"
                size="sm"
                :disabled="disabled"
                class="rounded-full border-dashed"
                >{{ label }} · {{ modelValue.length || 'All' }}</Button
            ></PopoverTrigger
        >
        <PopoverPortal
            ><PopoverContent
                align="start"
                :side-offset="8"
                class="z-50 w-72 max-w-[calc(100vw-2rem)] rounded-lg border bg-popover p-3 text-popover-foreground shadow-lg"
            >
                <p class="mb-2 font-medium">{{ label }}</p>
                <Input
                    v-model="search"
                    :aria-label="`Search ${label}`"
                    placeholder="Search options"
                />
                <div class="my-3 max-h-60 space-y-2 overflow-y-auto">
                    <label
                        v-for="option in visible"
                        :key="option.value"
                        class="flex cursor-pointer items-start gap-2 text-sm"
                        ><Checkbox
                            :model-value="draft.includes(option.value)"
                            @update:model-value="toggle(option.value)"
                        /><span class="min-w-0 break-words">{{
                            option.label
                        }}</span></label
                    >
                    <p
                        v-if="!visible.length"
                        class="text-sm text-muted-foreground"
                    >
                        No options.
                    </p>
                </div>
                <div class="flex justify-between gap-2">
                    <Button variant="ghost" size="sm" @click="open = false"
                        >Cancel</Button
                    ><Button size="sm" @click="apply">Apply</Button>
                </div>
            </PopoverContent></PopoverPortal
        >
    </PopoverRoot>
</template>
