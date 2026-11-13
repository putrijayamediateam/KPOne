<script setup lang="ts">
import { computed, inject } from 'vue';
import { V2_RAIL_FROM } from './railKey';
import type { RailFrom } from './railKey';

/**
 * Version 2 sticky action bar: a full-width 44px bar at the foot of the main column, clear of the home indicator, that
 * holds the page's one filled action. It is shown at every width below the page's rail breakpoint, and hidden from that
 * width up, because the summary rail carries the actions there.
 *
 * Which width: V2WorkLayout provides its `railFrom` (`lg`, `xl` or `2xl`) to everything inside it and the bar reads it, so
 * a page never repeats it. The `railFrom` prop is only an override. A page with no rail (or a bar outside any layout)
 * keeps the original behaviour: shown below 640px, hidden from 640px. `always` shows the bar at every width, for a long
 * form with no rail where the action must stay in reach.
 *
 * Its gutter is `-mx-4` (16px), which must match the 16px page gutter the shell gives V2WorkLayout: change one and you
 * must change the other. For a bar that also shows a selection summary and a reason, use V2SelectionBar.
 */
const props = withDefaults(
    defineProps<{ railFrom?: RailFrom; always?: boolean }>(),
    { railFrom: undefined, always: false },
);

const layoutRailFrom = inject(V2_RAIL_FROM, null);

const hideFrom = computed(() => {
    if (props.always) {
        return '';
    }

    const from = props.railFrom ?? layoutRailFrom?.value ?? null;

    return from === null
        ? 'sm:hidden'
        : { lg: 'lg:hidden', xl: 'xl:hidden', '2xl': '2xl:hidden' }[from];
});
</script>

<template>
    <div
        data-slot="v2-sticky-bar"
        class="sticky bottom-0 z-10 -mx-4 border-t bg-background px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] [&>*]:h-11 [&>*]:w-full"
        :class="hideFrom"
    >
        <slot />
    </div>
</template>
