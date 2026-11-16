<script setup lang="ts">
/**
 * An empty layout for a Version 2 print page (owner, 2026-11-16: the V2 drug label page). app.ts gives the Classic print
 * pages no layout and every page under /V2/ the V2 shell; a page layout of `null` falls back to that default, so the V2
 * label page names this one to stay bare like Classic: no sidebar or top bar on screen, and nothing extra in print.
 */
</script>

<template>
    <slot />
</template>
