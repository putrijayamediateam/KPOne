<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

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
const nav = ref<HTMLElement | null>(null);

/** DD-40: fade the right edge while more tabs are hidden there, so every tab strip hints at them once. */
const moreTabsRight = ref(false);
const updateFade = () => {
    const element = nav.value;

    moreTabsRight.value =
        element !== null &&
        element.scrollWidth > element.clientWidth + 1 &&
        element.scrollLeft + element.clientWidth < element.scrollWidth - 1;
};
let resizeObserver: ResizeObserver | undefined;
onMounted(() => {
    updateFade();
    nav.value?.addEventListener?.('scroll', updateFade, { passive: true });

    if (typeof ResizeObserver !== 'undefined' && nav.value) {
        resizeObserver = new ResizeObserver(updateFade);
        resizeObserver.observe(nav.value);
    }
});
onBeforeUnmount(() => {
    nav.value?.removeEventListener?.('scroll', updateFade);
    resizeObserver?.disconnect();
});
watch(
    () => props.tabs,
    () => void nextTick(updateFade),
    { deep: true },
);

const enabledIndexes = computed(() =>
    props.tabs.flatMap((tab, index) => (tab.disabled ? [] : [index])),
);

const selectedIndex = computed(() => {
    const index = props.tabs.findIndex(
        (tab) => tab.value === props.modelValue && !tab.disabled,
    );

    return index >= 0 ? index : (enabledIndexes.value[0] ?? -1);
});

/** A focused tab must sit clear of the fade at the right edge, not just inside the strip. */
const FADE_CLEARANCE = 40;
const revealFocused = (event: FocusEvent) => {
    const element = nav.value;
    const tab = event.currentTarget as HTMLElement | null;

    if (!element || !tab || typeof element.getBoundingClientRect !== 'function') {
        return;
    }

    const box = element.getBoundingClientRect();
    const tabBox = tab.getBoundingClientRect();

    if (tabBox.left < box.left) {
        element.scrollLeft += tabBox.left - box.left;
    } else if (tabBox.right > box.right - FADE_CLEARANCE) {
        element.scrollLeft += tabBox.right - (box.right - FADE_CLEARANCE);
    }
};

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
        ref="nav"
        class="flex min-w-0 gap-1 overflow-x-auto border-b"
        :class="
            moreTabsRight
                ? '[mask-image:linear-gradient(to_right,#000_calc(100%-2.5rem),transparent)]'
                : ''
        "
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
            @focus="revealFocused"
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
