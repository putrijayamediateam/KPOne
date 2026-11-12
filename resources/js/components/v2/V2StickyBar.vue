<script setup lang="ts">
/**
 * Version 2 sticky action bar: below 640px, a full-width 44px action bar at the foot of the main column, clear of the
 * home indicator. From 640px it is hidden (the summary rail carries the actions). Its gutter is `-mx-4` (16px), which
 * must match the 16px page gutter the shell gives V2WorkLayout: change one and you must change the other.
 */
</script>

<template>
    <div
        data-slot="v2-sticky-bar"
        class="sticky bottom-0 z-10 -mx-4 border-t bg-background px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:hidden [&>*]:h-11 [&>*]:w-full"
    >
        <slot />
    </div>
</template>
