<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { usePageCrumbTrail } from '@/composables/usePageCrumbs';

/**
 * UX-20: the Version 2 breadcrumb above a page's h1 (Daniel, 2026-11-12). PageFrame places it inside the page's own
 * frame, so it starts at the content edge for every frame width; the shell draws it only for a page that has no
 * PageFrame. Hidden in focus mode, and renders nothing in Classic, where no V2 shell provides a trail.
 */
const props = defineProps<{ inFrame?: boolean }>();
const { show, trail } = usePageCrumbTrail(props.inFrame === true);
</script>

<template>
    <nav v-if="show" aria-label="Breadcrumb" data-test="v2-page-crumbs">
        <ol class="flex flex-wrap items-center gap-x-1 text-xs font-medium">
            <li
                v-for="(crumb, index) in trail"
                :key="`${index}|${crumb.title}`"
                class="flex min-w-0 items-center gap-x-1"
            >
                <ChevronRight
                    v-if="index > 0"
                    class="size-3.5 shrink-0 text-muted-foreground/70"
                    aria-hidden="true"
                />
                <Link
                    v-if="crumb.href"
                    :href="crumb.href"
                    class="inline-flex min-w-0 items-center text-muted-foreground hover:text-foreground hover:underline max-sm:min-h-11"
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
