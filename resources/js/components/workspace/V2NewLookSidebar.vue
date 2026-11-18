<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Boxes,
    Building2,
    ChartColumn,
    ClipboardList,
    ChevronsLeft,
    House,
    IdCard,
    LayoutList,
    Pill,
    Receipt,
    Settings,
    ShieldCheck,
    Stethoscope,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, onMounted, ref, watch } from 'vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { getInitials } from '@/composables/useInitials';
import type { V2Shell } from '@/types';

/**
 * "New look (preview)", step C: the two-part sidebar (docs/design/NEWLOOK-PILOT.md, sections 3 and 5b): a 72 px icon rail
 * and an area panel. Built from `workspace.shell`, which the server already filters by the person's permissions and
 * branch, so every area and every row is one they can open; nothing is added here. No counts and no "+" actions yet
 * (a later step). Desktop only (from 1024 px); below that the existing phone sheet is used.
 */
const props = defineProps<{ shell: V2Shell; userName: string }>();

type Row = { title: string; href: string };
type Area = {
    key: string;
    title: string;
    icon: Component;
    /** The area's own page (a space home), listed first as "Overview"; null for a group of Clinic management pages. */
    href: string | null;
    rows: Row[];
};

const SPACE_ICONS: Record<string, Component> = {
    front_desk: Users,
    pharmacy: Pill,
    consultation: Stethoscope,
    billing: Receipt,
    panel: ShieldCheck,
    stock: Boxes,
    hr: IdCard,
    management: Settings,
};

// Busy areas open with the rail only until the person chooses otherwise (docs 5b, rule 5).
const BUSY = ['consultation', 'billing', 'hr'];

// Clinic management groups have no key, so the icon comes from the group's name; anything else gets a plain list icon.
const moreIcon = (title: string): Component =>
    /report/i.test(title)
        ? ChartColumn
        : /inventory|stock|service/i.test(title)
          ? Boxes
          : /staff|branch/i.test(title)
            ? Building2
            : /clinic/i.test(title)
              ? ClipboardList
              : LayoutList;

const areas = computed<Area[]>(() => [
    {
        key: 'me',
        title: 'Me',
        icon: House,
        href: '/spaces/me',
        rows: [],
    },
    ...props.shell.groups.flatMap((group) =>
        group.items.map((item) => ({
            key: item.key,
            title: item.title,
            icon: SPACE_ICONS[item.key] ?? LayoutList,
            href: item.href,
            rows: item.links,
        })),
    ),
    ...props.shell.more.map((group) => ({
        key: `more:${group.title}`,
        title: group.title,
        icon: moreIcon(group.title),
        href: null,
        rows: group.links,
    })),
]);

const page = usePage();
const path = computed(() => page.url.split('?')[0]);
const matches = (href: string) =>
    path.value === href || path.value.startsWith(`${href}/`);
// The current page is the longest matching href; its area is the "current area".
const hereHref = computed(() => {
    const hrefs = areas.value
        .flatMap((area) => [
            ...(area.href ? [area.href] : []),
            ...area.rows.map((r) => r.href),
        ])
        .filter(matches);

    return hrefs.sort((a, b) => b.length - a.length)[0] ?? null;
});
const currentAreaKey = computed(() => {
    const here = hereHref.value;
    const byPage = areas.value.find(
        (area) => area.href === here || area.rows.some((r) => r.href === here),
    );

    return byPage?.key ?? props.shell.current?.key ?? 'me';
});

// The panel shows the current page's area until the person picks another icon; navigating resets it.
const picked = ref<string | null>(null);
watch(currentAreaKey, () => (picked.value = null));
const shownKey = computed(() => picked.value ?? currentAreaKey.value);
const shown = computed(
    () => areas.value.find((a) => a.key === shownKey.value) ?? areas.value[0],
);

// Open or closed: remembered in this browser only (a convenience, never needed for anything to work).
const STORAGE_KEY = 'kpone.newlook.sidebar';
const stored = ref<boolean | null>(null);
const open = computed(
    () => stored.value ?? !BUSY.includes(currentAreaKey.value),
);
const remember = (value: boolean) => {
    stored.value = value;

    try {
        window.localStorage.setItem(STORAGE_KEY, value ? 'open' : 'closed');
    } catch {
        // Private window or blocked storage: the choice just lasts until the page reloads.
    }
};
onMounted(() => {
    try {
        const value = window.localStorage.getItem(STORAGE_KEY);
        stored.value =
            value === 'open' ? true : value === 'closed' ? false : null;
    } catch {
        stored.value = null;
    }
});

// Click an icon: show that area and open the panel; click the icon of the area already shown to close it.
const pick = (key: string) => {
    if (open.value && shownKey.value === key) {
        remember(false);

        return;
    }

    picked.value = key;
    remember(true);
};
const isHere = (href: string) => href === hereHref.value;
</script>

<template>
    <aside
        class="sticky top-0 hidden h-screen shrink-0 p-3.5 lg:flex"
        data-test="v2-newlook-sidebar"
    >
        <div
            class="flex h-full rounded-[26px] border border-border bg-card shadow-[0_8px_30px_rgba(17,17,26,0.10)] dark:shadow-[0_8px_30px_rgba(0,0,0,0.5)]"
        >
            <nav
                aria-label="Areas"
                class="flex w-[72px] shrink-0 flex-col items-center gap-1.5 rounded-l-[26px] bg-muted/60 py-4"
            >
                <Link
                    href="/"
                    class="mb-3 flex size-10 items-center justify-center rounded-xl focus-visible:ring-2 focus-visible:ring-ring"
                    aria-label="KPOne home"
                >
                    <img
                        src="/kp-mark-56.png"
                        alt=""
                        width="28"
                        height="28"
                        class="size-7 object-contain"
                    />
                </Link>
                <button
                    v-for="area in areas"
                    :key="area.key"
                    type="button"
                    class="group relative flex size-11 items-center justify-center rounded-[14px] text-muted-foreground hover:bg-card hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="{
                        'bg-card text-foreground shadow-sm':
                            area.key === shownKey && open,
                        'text-foreground': area.key === currentAreaKey,
                    }"
                    :aria-label="area.title"
                    :aria-current="
                        area.key === currentAreaKey ? 'true' : undefined
                    "
                    :aria-expanded="area.key === shownKey && open"
                    data-test="v2-newlook-area"
                    @click="pick(area.key)"
                >
                    <component
                        :is="area.icon"
                        class="size-5"
                        aria-hidden="true"
                    />
                    <span
                        aria-hidden="true"
                        class="pointer-events-none absolute left-full z-50 ml-2 rounded-lg bg-foreground px-2.5 py-1 text-xs font-medium whitespace-nowrap text-background opacity-0 transition-opacity duration-150 group-hover:opacity-100 group-focus-visible:opacity-100 motion-reduce:transition-none"
                    >
                        {{ area.title }}
                    </span>
                </button>
                <Link
                    href="/spaces/me"
                    class="mt-auto rounded-full focus-visible:ring-2 focus-visible:ring-ring"
                    :aria-label="`Me, ${userName}`"
                >
                    <Avatar class="size-10">
                        <AvatarFallback class="bg-card text-xs font-semibold">
                            {{ getInitials(userName) }}
                        </AvatarFallback>
                    </Avatar>
                </Link>
            </nav>

            <div
                class="overflow-hidden rounded-r-[26px] transition-[width] duration-200 motion-reduce:transition-none"
                :class="open ? 'w-[248px]' : 'w-0'"
                :inert="!open"
                data-test="v2-newlook-panel"
            >
                <section
                    class="flex h-full w-[248px] flex-col gap-1 p-3.5"
                    :aria-label="`${shown.title} pages`"
                >
                    <div class="flex items-center gap-2 px-1.5 pb-3">
                        <h2
                            class="flex-1 truncate text-[19px] font-semibold tracking-tight"
                        >
                            {{ shown.title }}
                        </h2>
                        <button
                            type="button"
                            class="flex size-9 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                            aria-label="Close panel"
                            @click="remember(false)"
                        >
                            <ChevronsLeft class="size-4" aria-hidden="true" />
                        </button>
                    </div>
                    <ul class="flex min-h-0 flex-col gap-0.5 overflow-y-auto">
                        <li v-if="shown.href">
                            <Link
                                :href="shown.href"
                                class="flex min-h-10 items-center rounded-xl px-3 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                :class="{ 'bg-muted': isHere(shown.href) }"
                                :aria-current="
                                    isHere(shown.href) ? 'page' : undefined
                                "
                            >
                                Overview
                            </Link>
                        </li>
                        <li
                            v-for="row in shown.rows"
                            :key="`${row.href}|${row.title}`"
                        >
                            <Link
                                :href="row.href"
                                class="flex min-h-10 items-center rounded-xl px-3 text-sm text-foreground/90 hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                :class="{
                                    'bg-muted font-medium text-foreground':
                                        isHere(row.href),
                                }"
                                :aria-current="
                                    isHere(row.href) ? 'page' : undefined
                                "
                            >
                                {{ row.title }}
                            </Link>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
        <button
            v-if="!open"
            type="button"
            class="sr-only"
            @click="remember(true)"
        >
            Open panel
        </button>
    </aside>
</template>
