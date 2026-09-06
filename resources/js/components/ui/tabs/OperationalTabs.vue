<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';

type OperationalTab = {
    value: string;
    label: string;
    count?: number;
    disabled?: boolean;
};

const props = defineProps<{
    modelValue: string;
    tabs: OperationalTab[];
    label: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const tabElements = ref<(HTMLButtonElement | null)[]>([]);

const enabledIndexes = computed(() =>
    props.tabs.flatMap((tab, index) => (tab.disabled ? [] : [index])),
);

const selectedIndex = computed(() => {
    const index = props.tabs.findIndex(
        (tab) => tab.value === props.modelValue && !tab.disabled,
    );

    return index >= 0 ? index : (enabledIndexes.value[0] ?? -1);
});

const setTabElement = (element: unknown, index: number) => {
    tabElements.value[index] =
        element !== null &&
        typeof element === 'object' &&
        'focus' in element &&
        typeof element.focus === 'function'
            ? (element as HTMLButtonElement)
            : null;
};

const activate = (index: number) => {
    const tab = props.tabs[index];

    if (!tab || tab.disabled) {
        return;
    }

    emit('update:modelValue', tab.value);
    void nextTick(() => tabElements.value[index]?.focus());
};

const move = (
    currentIndex: number,
    destination: 'next' | 'previous' | 'first' | 'last',
) => {
    const indexes = enabledIndexes.value;

    if (indexes.length === 0) {
        return;
    }

    if (destination === 'first') {
        activate(indexes[0]);
        return;
    }

    if (destination === 'last') {
        activate(indexes[indexes.length - 1]);
        return;
    }

    const position = indexes.indexOf(currentIndex);
    const offset = destination === 'next' ? 1 : -1;
    const nextPosition =
        position < 0
            ? 0
            : (position + offset + indexes.length) % indexes.length;

    activate(indexes[nextPosition]);
};

const handleKeydown = (event: KeyboardEvent, index: number) => {
    const destination = {
        ArrowRight: 'next',
        ArrowLeft: 'previous',
        Home: 'first',
        End: 'last',
    }[event.key] as 'next' | 'previous' | 'first' | 'last' | undefined;

    if (!destination) {
        return;
    }

    event.preventDefault();
    move(index, destination);
};
</script>

<template>
    <nav
        class="flex min-w-0 gap-1 overflow-x-auto border-b"
        :aria-label="label"
        role="tablist"
    >
        <button
            v-for="(tab, index) in tabs"
            :key="tab.value"
            :ref="(element) => setTabElement(element, index)"
            type="button"
            role="tab"
            class="relative flex h-10 shrink-0 cursor-pointer items-center gap-1.5 px-3 text-sm text-muted-foreground outline-none transition-colors hover:bg-muted/50 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset disabled:cursor-not-allowed disabled:opacity-50"
            :class="
                modelValue === tab.value
                    ? 'font-medium text-foreground after:absolute after:inset-x-3 after:bottom-0 after:h-0.5 after:bg-brand'
                    : ''
            "
            :aria-selected="modelValue === tab.value"
            :tabindex="selectedIndex === index ? 0 : -1"
            :disabled="tab.disabled"
            @click="emit('update:modelValue', tab.value)"
            @keydown="handleKeydown($event, index)"
        >
            {{ tab.label }}
            <span
                v-if="tab.count !== undefined"
                class="rounded-full bg-muted px-1.5 py-0.5 text-[11px] tabular-nums text-muted-foreground"
            >
                {{ tab.count }}
            </span>
        </button>
    </nav>
</template>
