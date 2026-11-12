<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import { usePageCrumbTrail } from '@/composables/usePageCrumbs';

/**
 * UX-20: the Version 2 breadcrumb above a page's h1 (Daniel, 2026-11-12). PageFrame places it inside the page's own
 * frame, so it starts at the content edge for every frame width; the shell draws it only for a page that has no
 * PageFrame. Hidden in focus mode, and renders nothing in Classic, where no V2 shell provides a trail.
 */
const props = defineProps<{ inFrame?: boolean }>();
const { show, trail } = usePageCrumbTrail(props.inFrame === true);

// On phones a trail longer than two items shortens to "… › last" instead of wrapping (Daniel, 2026-11-12). The "…" is a
// link to the nearest earlier page; a two-item trail is unchanged.
const collapses = computed(() => trail.value.length > 2);
const parent = computed(() =>
    trail.value
        .slice(0, -1)
        .reverse()
        .find((crumb) => crumb.href),
);
// A visible keyboard focus ring of 3:1 or better, set here rather than on the shared Button (DD-74).
const LINK =
    'inline-flex min-w-0 items-center rounded-sm text-muted-foreground outline-none hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring max-sm:min-h-11';
</script>

<template>
    <nav v-if="show" aria-label="Breadcrumb" data-test="v2-page-crumbs">
        <ol
            class="flex flex-wrap items-center gap-x-1 text-xs font-medium max-sm:flex-nowrap"
        >
            <li
                v-if="collapses && parent"
                class="flex shrink-0 items-center sm:hidden"
                data-test="v2-page-crumbs-collapsed"
            >
                <Link
                    :href="parent.href!"
                    :class="LINK"
                    class="justify-center max-sm:min-w-11"
                    :aria-label="`Back to ${parent.title}`"
                    >…</Link
                >
            </li>
            <li
                v-for="(crumb, index) in trail"
                :key="`${index}|${crumb.title}`"
                class="flex min-w-0 items-center gap-x-1"
                :class="{
                    'max-sm:hidden':
                        collapses && parent && index < trail.length - 1,
                }"
            >
                <ChevronRight
                    v-if="index > 0"
                    class="size-3.5 shrink-0 text-muted-foreground/70"
                    aria-hidden="true"
                />
                <Link v-if="crumb.href" :href="crumb.href" :class="LINK"
                    ><span class="truncate">{{ crumb.title }}</span></Link
                >
                <span
                    v-else
                    class="inline-flex min-w-0 items-center text-foreground max-sm:min-h-11"
                    aria-current="page"
                    ><span class="truncate">{{ crumb.title }}</span></span
                >
            </li>
        </ol>
    </nav>
</template>
