<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Boxes,
    ChartColumn,
    ChevronsLeft,
    House,
    IdCard,
    Pill,
    Receipt,
    Settings,
    ShieldCheck,
    Stethoscope,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { getInitials } from '@/composables/useInitials';
import type { V2Shell } from '@/types';

/**
 * "New look (preview)", step C: the two-part sidebar (docs/design/NEWLOOK-PILOT.md, sections 3 and 5b): a 72 px icon rail
 * and an area panel. Built from `workspace.shell`, which the server already filters by the person's permissions and
 * branch, so every area and every row is one they can open; nothing is added here. The rail is always the owner's ten
 * areas in a fixed order, each with one icon, and an area with nothing the person may open has no icon. No counts and
 * no "+" actions yet (a later step). Desktop only (from 1024 px); below that the existing phone sheet is used.
 */
const props = defineProps<{ shell: V2Shell; userName: string }>();

type Row = { title: string; href: string };
type Section = { title: string | null; rows: Row[] };
type Area = {
    key: string;
    title: string;
    icon: Component;
    /** The area's own page (a space home), listed first as "Overview". */
    href: string | null;
    sections: Section[];
};

// The ten areas, in order, one unique icon each.
const AREAS: Array<{ key: string; title: string; icon: Component }> = [
    { key: 'today', title: 'Today', icon: House },
    { key: 'front_desk', title: 'Front desk', icon: Users },
    { key: 'consultation', title: 'Consultation', icon: Stethoscope },
    { key: 'pharmacy', title: 'Pharmacy', icon: Pill },
    { key: 'billing', title: 'Billing', icon: Receipt },
    { key: 'panel', title: 'Panel', icon: ShieldCheck },
    { key: 'stock', title: 'Stock', icon: Boxes },
    { key: 'hr', title: 'HR', icon: IdCard },
    { key: 'reports', title: 'Reports', icon: ChartColumn },
    { key: 'settings', title: 'Settings', icon: Settings },
];

// A space becomes the area of the same name; the Management space is Settings.
const SPACE_AREA: Record<string, string> = { management: 'settings' };
// Clinic management groups that are not under a space follow the group's name; anything else is Settings.
const groupArea = (title: string): string =>
    /^ai agents/i.test(title)
        ? 'today'
        : /^patients/i.test(title)
          ? 'front_desk'
          : /^documents/i.test(title)
            ? 'consultation'
            : /^panel/i.test(title)
              ? 'panel'
              : /^inventory/i.test(title)
                ? 'stock'
                : /^reports/i.test(title)
                  ? 'reports'
                  : 'settings';

// Busy areas open with the rail only until the person chooses otherwise (docs 5b, rule 5).
const BUSY = ['consultation', 'billing', 'hr'];

const areas = computed<Area[]>(() => {
    const built = new Map<string, Area>(
        AREAS.map((a) => [a.key, { ...a, href: null, sections: [] }]),
    );
    built.get('today')!.href = '/spaces/me';

    for (const group of props.shell.groups) {
        for (const item of group.items) {
            const area = built.get(SPACE_AREA[item.key] ?? item.key);

            if (area) {
                area.href = item.href;
                area.sections.unshift({ title: null, rows: item.links });
            }
        }
    }

    for (const group of props.shell.more) {
        built
            .get(groupArea(group.title))!
            .sections.push({ title: group.title, rows: group.links });
    }

    // A person only sees an area that has something to open.
    return AREAS.map((a) => built.get(a.key)!).filter(
        (a) => a.href !== null || a.sections.some((s) => s.rows.length > 0),
    );
});

const page = usePage();
const path = computed(() => page.url.split('?')[0]);
const matches = (href: string) =>
    path.value === href || path.value.startsWith(`${href}/`);
// The current page is the longest matching href; its area is the "current area".
const hereHref = computed(() => {
    const hrefs = areas.value
        .flatMap((area) => [
            ...(area.href ? [area.href] : []),
            ...area.sections.flatMap((s) => s.rows.map((r) => r.href)),
        ])
        .filter(matches);

    return hrefs.sort((a, b) => b.length - a.length)[0] ?? null;
});
const currentAreaKey = computed(() => {
    const here = hereHref.value;
    const byPage = areas.value.find(
        (area) =>
            area.href === here ||
            area.sections.some((s) => s.rows.some((r) => r.href === here)),
    );

    return byPage?.key ?? 'today';
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

    measure();
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

// Keyboard: the rail is one Tab stop (roving tabindex); arrows, Home and End move between areas.
const buttons = ref<HTMLButtonElement[]>([]);
const focusIndex = ref(0);
const tabbable = (index: number) =>
    index === (focusIndex.value < areas.value.length ? focusIndex.value : 0);
watch(
    currentAreaKey,
    (key) =>
        (focusIndex.value = Math.max(
            0,
            areas.value.findIndex((a) => a.key === key),
        )),
    { immediate: true },
);
const moveFocus = (to: number) => {
    const total = areas.value.length;
    const index = (to + total) % total;
    focusIndex.value = index;
    void nextTick(() => buttons.value[index]?.focus());
};
const onRailKey = (event: KeyboardEvent, index: number) => {
    const keys: Record<string, number> = {
        ArrowDown: index + 1,
        ArrowUp: index - 1,
        Home: 0,
        End: areas.value.length - 1,
    };

    if (event.key in keys) {
        event.preventDefault();
        moveFocus(keys[event.key]);
    }
};
// Esc inside the panel closes it and puts focus back on the rail button of the area shown.
const onPanelKey = (event: KeyboardEvent) => {
    if (event.key === 'Escape' && open.value) {
        remember(false);
        const index = areas.value.findIndex((a) => a.key === shownKey.value);
        void nextTick(() => buttons.value[Math.max(0, index)]?.focus());
    }
};

// The name of the area, shown on hover and on keyboard focus, outside the rail's scroll box so it is never clipped.
const tip = ref<{ text: string; top: number; left: number } | null>(null);
const showTip = (event: Event, text: string) => {
    const box = (event.currentTarget as HTMLElement).getBoundingClientRect();
    tip.value = { text, top: box.top + box.height / 2, left: box.right + 10 };
};
const hideTip = () => (tip.value = null);

// The rail scrolls on short windows (hidden bar); a fade shows there is more above or below.
const scroller = ref<HTMLElement | null>(null);
const more = ref({ up: false, down: false });
const measure = () => {
    const el = scroller.value;

    if (el) {
        more.value = {
            up: el.scrollTop > 1,
            down: el.scrollTop + el.clientHeight < el.scrollHeight - 1,
        };
    }
};
const fade = computed(() => {
    const top = more.value.up ? 'transparent 0, #000 28px' : '#000 0';
    const bottom = more.value.down
        ? '#000 calc(100% - 28px), transparent 100%'
        : '#000 100%';

    return { maskImage: `linear-gradient(to bottom, ${top}, ${bottom})` };
});
</script>

<template>
    <aside
        class="sticky top-0 hidden h-screen shrink-0 p-3.5 lg:flex"
        data-test="v2-newlook-sidebar"
    >
        <a
            href="#v2-main"
            class="sr-only focus-visible:not-sr-only focus-visible:absolute focus-visible:top-5 focus-visible:left-5 focus-visible:z-[70] focus-visible:rounded-lg focus-visible:bg-foreground focus-visible:px-3 focus-visible:py-2 focus-visible:text-sm focus-visible:font-medium focus-visible:text-background focus-visible:ring-2 focus-visible:ring-ring"
            data-test="v2-newlook-skip"
        >
            Skip to content
        </a>
        <div
            class="flex h-full rounded-[26px] border border-border bg-card shadow-[0_8px_30px_rgba(17,17,26,0.10)] dark:shadow-[0_8px_30px_rgba(0,0,0,0.5)]"
        >
            <nav
                aria-label="Areas"
                class="flex w-[72px] shrink-0 flex-col items-center rounded-l-[26px] bg-muted/60 py-4"
            >
                <Link
                    href="/"
                    class="mb-3 flex size-10 shrink-0 items-center justify-center rounded-xl focus-visible:ring-2 focus-visible:ring-ring"
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
                <div
                    ref="scroller"
                    class="flex min-h-0 w-full flex-1 [scrollbar-width:none] flex-col items-center gap-1.5 overflow-y-auto py-1 [&::-webkit-scrollbar]:hidden"
                    :style="fade"
                    data-test="v2-newlook-rail-scroll"
                    @scroll.passive="measure"
                >
                    <button
                        v-for="(area, index) in areas"
                        :key="area.key"
                        :ref="
                            (el) => (buttons[index] = el as HTMLButtonElement)
                        "
                        type="button"
                        class="relative flex size-11 shrink-0 items-center justify-center rounded-[14px] text-muted-foreground hover:bg-card hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        :class="{
                            'bg-card text-foreground shadow-sm ring-1 ring-border dark:bg-foreground dark:text-background dark:ring-0':
                                area.key === shownKey && open,
                            'text-foreground': area.key === currentAreaKey,
                        }"
                        :aria-label="area.title"
                        :aria-current="
                            area.key === currentAreaKey ? 'true' : undefined
                        "
                        :aria-expanded="area.key === shownKey && open"
                        :tabindex="tabbable(index) ? 0 : -1"
                        data-test="v2-newlook-area"
                        @click="pick(area.key)"
                        @keydown="onRailKey($event, index)"
                        @focus="
                            focusIndex = index;
                            showTip($event, area.title);
                        "
                        @blur="hideTip"
                        @mouseenter="showTip($event, area.title)"
                        @mouseleave="hideTip"
                    >
                        <component
                            :is="area.icon"
                            class="size-5"
                            aria-hidden="true"
                        />
                    </button>
                </div>
                <Link
                    href="/spaces/me"
                    class="mt-2 shrink-0 rounded-full focus-visible:ring-2 focus-visible:ring-ring"
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
                @keydown="onPanelKey"
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
                    <nav
                        aria-label="Area pages"
                        class="flex min-h-0 flex-col gap-0.5 overflow-y-auto"
                    >
                        <Link
                            v-if="shown.href"
                            :href="shown.href"
                            class="flex min-h-10 items-center rounded-xl px-3 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                            :class="{ 'bg-muted': isHere(shown.href) }"
                            :aria-current="
                                isHere(shown.href) ? 'page' : undefined
                            "
                        >
                            Overview
                        </Link>
                        <template
                            v-for="(section, s) in shown.sections"
                            :key="`${s}|${section.title}`"
                        >
                            <p
                                v-if="section.title"
                                class="px-3 pt-3 pb-1 text-xs font-medium text-muted-foreground"
                            >
                                {{ section.title }}
                            </p>
                            <Link
                                v-for="row in section.rows"
                                :key="`${row.href}|${row.title}`"
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
                        </template>
                    </nav>
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
        <Teleport to="body">
            <div
                v-if="tip"
                aria-hidden="true"
                class="pointer-events-none fixed z-[60] -translate-y-1/2 rounded-lg bg-foreground px-2.5 py-1 text-xs font-medium whitespace-nowrap text-background"
                :style="{ top: `${tip.top}px`, left: `${tip.left}px` }"
                data-test="v2-newlook-tip"
            >
                {{ tip.text }}
            </div>
        </Teleport>
    </aside>
</template>
