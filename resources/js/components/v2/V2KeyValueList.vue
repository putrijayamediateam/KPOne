<script setup lang="ts">
/**
 * Version 2 key-value list: read-only details as a real description list (`dl`, `dt`, `dd`), so a screen reader hears
 * each label with its value. The label sits ABOVE the value on phones and BESIDE it from 640px (a 12rem label column).
 * Long values wrap (they never stretch the page). A value that is empty (null, undefined or blank) shows "Not set" in
 * muted text, which is words and not only colour; pass `emptyText` for another word ("None recorded"). A zero or "0" is a value,
 * not empty.
 *
 * It does no masking and holds nothing private: a masked value goes in through the `value` slot
 * (for example a V2MaskedValue), and the page decides what each role may see. The slot receives the item and replaces
 * only the value; the label stays.
 */
export type KeyValueItem = {
    label: string;
    value?: string | number | null;
};

const props = withDefaults(
    defineProps<{
        items: KeyValueItem[];
        emptyText?: string;
    }>(),
    { emptyText: 'Not set' },
);

const isEmpty = (item: KeyValueItem) =>
    item.value === null ||
    item.value === undefined ||
    String(item.value).trim() === '';

void props;
</script>

<template>
    <dl
        data-slot="v2-key-value-list"
        class="grid min-w-0 grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-[12rem_minmax(0,1fr)] sm:gap-y-3"
    >
        <template
            v-for="(item, index) in items"
            :key="`${index}-${item.label}`"
        >
            <dt
                class="text-[13px] font-medium text-muted-foreground sm:pt-0.5 sm:text-sm"
            >
                {{ item.label }}
            </dt>
            <dd
                class="min-w-0 text-[15px] break-words text-foreground max-sm:-mt-3"
            >
                <slot name="value" :item="item" :index="index">
                    <span v-if="isEmpty(item)" class="text-muted-foreground">{{
                        emptyText
                    }}</span>
                    <template v-else>{{ item.value }}</template>
                </slot>
            </dd>
        </template>
    </dl>
</template>
