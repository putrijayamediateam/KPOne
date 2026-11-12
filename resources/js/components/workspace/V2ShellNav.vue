<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { V2Shell } from '@/types';

/**
 * UX-17: the Version 2 sidebar list, used by the desktop sidebar and by the phone sheet. Every link comes from the
 * server (`workspace.shell`), which lists only spaces and Clinic management pages the person can already open.
 */
const props = defineProps<{ shell: V2Shell }>();
const emit = defineEmits<{ navigate: [] }>();

const page = usePage();
const path = computed(() => page.url.split('?')[0]);
const matches = (href: string) =>
    path.value === href || path.value.startsWith(`${href}/`);
// Only one link is the current page: the longest matching href (so /pricing/price-check is not also Pricing). Two
// rows can share an href (Checkout settings and Claims message template); only the first one is marked.
const hereHref = computed(() => {
    const hrefs = [
        ...props.shell.groups.flatMap((g) =>
            g.items.flatMap((i) => [i.href, ...i.links.map((l) => l.href)]),
        ),
        ...props.shell.more.flatMap((m) => m.links.map((l) => l.href)),
    ].filter(matches);

    return hrefs.sort((a, b) => b.length - a.length)[0] ?? null;
});
const firstTitleFor = computed(() => {
    const seen = new Map<string, string>();

    for (const link of [
        ...props.shell.groups.flatMap((g) => g.items.flatMap((i) => i.links)),
        ...props.shell.more.flatMap((m) => m.links),
    ]) {
        if (!seen.has(link.href)) {
            seen.set(link.href, link.title);
        }
    }

    return seen;
});
const isHere = (href: string) => href === hereHref.value;
const isHereLink = (link: { href: string; title: string }) =>
    isHere(link.href) && firstTitleFor.value.get(link.href) === link.title;
// A "More" section opens by itself when it holds the page the person is on.
const holdsHere = (links: Array<{ href: string }>) =>
    links.some((link) => isHere(link.href));
const currentKey = computed(() => props.shell.current?.key ?? null);
</script>

<template>
    <nav aria-label="Spaces and pages" class="flex flex-col gap-5 text-sm">
        <div v-for="group in shell.groups" :key="group.title">
            <p class="px-3 pb-1 text-xs font-medium text-sidebar-foreground/70">
                {{ group.title }}
            </p>
            <template v-for="item in group.items" :key="item.key">
                <Link
                    :href="item.href"
                    class="flex min-h-9 items-center rounded-lg px-3 text-sidebar-foreground hover:bg-sidebar-accent max-lg:min-h-11"
                    :class="{
                        'bg-sidebar-accent font-medium text-sidebar-primary':
                            isHere(item.href),
                        'font-medium text-sidebar-primary':
                            item.key === currentKey && !isHere(item.href),
                    }"
                    :aria-current="isHere(item.href) ? 'page' : undefined"
                    data-test="v2-shell-space"
                    @click="emit('navigate')"
                >
                    {{ item.title }}
                </Link>
                <Link
                    v-for="link in item.links"
                    :key="`${link.href}|${link.title}`"
                    :href="link.href"
                    class="ml-3 flex min-h-9 items-center rounded-lg px-3 text-sidebar-foreground/90 hover:bg-sidebar-accent max-lg:min-h-11"
                    :class="{
                        'bg-sidebar-accent font-medium text-sidebar-primary':
                            isHereLink(link),
                    }"
                    :aria-current="isHereLink(link) ? 'page' : undefined"
                    data-test="v2-shell-space-page"
                    @click="emit('navigate')"
                >
                    {{ link.title }}
                </Link>
            </template>
        </div>
        <div v-if="shell.more.length > 0" data-test="v2-shell-more">
            <p class="px-3 pb-1 text-xs font-medium text-sidebar-foreground/70">
                More
            </p>
            <details
                v-for="section in shell.more"
                :key="section.title"
                class="group"
                :open="holdsHere(section.links)"
            >
                <summary
                    class="flex min-h-9 cursor-pointer list-none items-center justify-between rounded-lg px-3 text-sidebar-foreground hover:bg-sidebar-accent max-lg:min-h-11"
                >
                    {{ section.title }}
                    <span
                        aria-hidden="true"
                        class="text-sidebar-foreground/60 transition-transform group-open:rotate-90 motion-reduce:transition-none"
                        >›</span
                    >
                </summary>
                <Link
                    v-for="link in section.links"
                    :key="`${link.href}|${link.title}`"
                    :href="link.href"
                    class="ml-3 flex min-h-9 items-center rounded-lg px-3 text-sidebar-foreground/90 hover:bg-sidebar-accent max-lg:min-h-11"
                    :class="{
                        'bg-sidebar-accent font-medium text-sidebar-primary':
                            isHereLink(link),
                    }"
                    :aria-current="isHereLink(link) ? 'page' : undefined"
                    @click="emit('navigate')"
                >
                    {{ link.title }}
                </Link>
            </details>
        </div>
    </nav>
</template>
