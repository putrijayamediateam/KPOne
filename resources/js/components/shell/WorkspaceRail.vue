<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Building2,
    ChartColumn,
    ClipboardList,
    House,
    Menu,
    Package,
    Pill,
    Pin,
    PinOff,
    Receipt,
    Settings,
    Stethoscope,
    Users,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import UserMenuContent from '@/components/UserMenuContent.vue';
import WorkspaceBranchSwitcher from '@/components/workspace/WorkspaceBranchSwitcher.vue';
import { useApprovalCount } from '@/composables/useApprovalCount';
import { getInitials } from '@/composables/useInitials';

// Clean Look shell (docs/design/CLEAN-LOOK-SPEC.md section 6): a 72 px icon rail, a 248 px menu panel that opens on hover or
// focus, and a drawer below 1024 px. The menu comes from the server (ShellMenu, built from the permission-filtered Clinic
// management rows), so a link shows only for someone who may open its page.
const props = defineProps<{ context?: 'clinic' | 'admin' }>();

const page = usePage();
const user = computed(() => page.props.auth.user!);
const workspace = computed(() => page.props.workspace);
const areas = computed(() => workspace.value?.shellMenu ?? []);
const branch = computed(() => page.props.branchContext?.active ?? null);
const { count: approvalCount, label: approvalLabel } = useApprovalCount();

const ICONS: Record<string, unknown> = {
    home: House,
    clipboard: ClipboardList,
    stethoscope: Stethoscope,
    pill: Pill,
    receipt: Receipt,
    building: Building2,
    package: Package,
    users: Users,
    chart: ChartColumn,
    settings: Settings,
};

const path = computed(() => page.url.split('?')[0]);
const linkActive = (href: string) =>
    path.value === href || path.value.startsWith(`${href}/`);
// The current area is the one holding the longest link that matches this page; otherwise Today.
const currentKey = computed(() => {
    let best = { key: 'today', length: 0 };

    for (const area of areas.value) {
        for (const section of area.sections) {
            for (const link of section.links) {
                if (linkActive(link.href) && link.href.length > best.length) {
                    best = { key: area.key, length: link.href.length };
                }
            }
        }
    }

    return best.key;
});

// ---- panel: hover or focus opens it after 120 ms and closes it 300 ms after leaving; click opens it; pin keeps it open ----
const PIN_KEY = 'kpone.shell.pinned';
const pinned = ref(false);
const open = ref(false);
const shownKey = ref<string>('today');
let openTimer: ReturnType<typeof setTimeout> | undefined;
let closeTimer: ReturnType<typeof setTimeout> | undefined;

onMounted(() => {
    try {
        pinned.value = window.localStorage.getItem(PIN_KEY) === '1';
    } catch {
        pinned.value = false;
    }

    shownKey.value = currentKey.value;
});
watch(currentKey, (key) => {
    if (!open.value) {
        shownKey.value = key;
    }
});
watch(pinned, (value) => {
    try {
        window.localStorage.setItem(PIN_KEY, value ? '1' : '0');
    } catch {
        // the choice just is not remembered
    }

    emitOffset();
});

const panelVisible = computed(() => pinned.value || open.value);
const shownArea = computed(
    () => areas.value.find((a) => a.key === shownKey.value) ?? areas.value[0],
);

const clearTimers = () => {
    clearTimeout(openTimer);
    clearTimeout(closeTimer);
};
const cancelClose = () => clearTimeout(closeTimer);
// Escape closes an unpinned panel and gives focus back to the rail button; that focus must not open the panel again.
let ignoreFocusOpen = false;
const onRailFocus = (key: string) => {
    if (!ignoreFocusOpen) {
        scheduleOpen(key);
    }
};
const scheduleOpen = (key: string) => {
    clearTimeout(closeTimer);
    clearTimeout(openTimer);
    openTimer = setTimeout(() => {
        shownKey.value = key;
        open.value = true;
    }, 120);
};
const scheduleClose = () => {
    clearTimeout(openTimer);
    clearTimeout(closeTimer);
    closeTimer = setTimeout(() => {
        open.value = false;
        shownKey.value = currentKey.value;
    }, 300);
};
const choose = (key: string) => {
    clearTimers();
    shownKey.value = key;
    open.value = true;
};
const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape' && open.value && !pinned.value) {
        open.value = false;
        clearTimers();
        const key = shownKey.value;
        shownKey.value = currentKey.value;
        ignoreFocusOpen = true;
        document.querySelector<HTMLElement>(`[data-area="${key}"]`)?.focus();
        setTimeout(() => {
            ignoreFocusOpen = false;
        }, 0);
    }
};
onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    clearTimers();
});

// The page content shifts right by the rail (and the panel when pinned).
const emitOffset = () => {
    document.documentElement.style.setProperty(
        '--shell-offset',
        pinned.value ? '320px' : '72px',
    );
};
onMounted(emitOffset);
onBeforeUnmount(() =>
    document.documentElement.style.removeProperty('--shell-offset'),
);

// ---- drawer below 1024 px ----
const drawerOpen = ref(false);
const drawerKey = ref<string | null>(null);
const drawerArea = computed(() =>
    areas.value.find((a) => a.key === drawerKey.value),
);
const closeDrawer = () => {
    drawerOpen.value = false;
    drawerKey.value = null;
};
</script>

<template>
    <!-- Desktop rail -->
    <TooltipProvider :delay-duration="150">
        <nav
            class="fixed inset-y-0 left-0 z-40 hidden w-[72px] flex-col items-center gap-1 border-r bg-sidebar py-3 lg:flex"
            aria-label="Areas"
            data-test="shell-rail"
            @mouseenter="cancelClose"
            @mouseleave="scheduleClose"
        >
            <Link
                href="/dashboard"
                class="mb-2 flex size-11 items-center justify-center rounded-[10px] focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                aria-label="Open KPOne Main Menu"
            >
                <img
                    src="/kp-mark.png"
                    alt="Klinik Putrijaya"
                    class="size-9 object-contain"
                />
            </Link>
            <template v-for="area in areas" :key="area.key">
                <div
                    v-if="
                        area.key === 'consultation' ||
                        area.key === 'billing' ||
                        area.key === 'inventory'
                    "
                    class="my-1 h-px w-8 bg-border"
                    aria-hidden="true"
                />
                <div v-if="area.key === 'settings'" class="flex-1" />
                <Tooltip>
                    <TooltipTrigger as-child>
                        <Link
                            :href="area.href"
                            class="relative flex size-11 items-center justify-center rounded-[10px] transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :class="
                                currentKey === area.key
                                    ? 'bg-primary/10 text-primary dark:bg-primary/20'
                                    : 'text-muted-foreground hover:text-foreground'
                            "
                            :aria-label="area.title"
                            :aria-current="
                                currentKey === area.key ? 'page' : undefined
                            "
                            :data-area="area.key"
                            @mouseenter="scheduleOpen(area.key)"
                            @focus="onRailFocus(area.key)"
                            @click.prevent="choose(area.key)"
                            @keydown.enter.prevent="router.visit(area.href)"
                        >
                            <component
                                :is="ICONS[area.icon] ?? Settings"
                                class="size-[21px]"
                                :stroke-width="1.7"
                                aria-hidden="true"
                            />
                            <span
                                v-if="area.key === 'today' && approvalCount > 0"
                                class="absolute top-1.5 right-1.5 size-2 rounded-full bg-destructive ring-2 ring-sidebar"
                                aria-hidden="true"
                            />
                        </Link>
                    </TooltipTrigger>
                    <TooltipContent side="right" class="text-[12.5px]">
                        {{ area.title }}
                    </TooltipContent>
                </Tooltip>
            </template>
            <Tooltip v-if="branch">
                <TooltipTrigger as-child>
                    <span
                        class="mt-1 inline-flex h-7 min-w-11 items-center justify-center rounded-lg bg-info-wash px-1.5 text-[12.5px] font-semibold tracking-wide text-info"
                        role="img"
                        :aria-label="`Active branch: ${branch.name}`"
                        data-test="rail-branch"
                        tabindex="0"
                        >{{ branch.code.slice(0, 3).toUpperCase() }}</span
                    >
                </TooltipTrigger>
                <TooltipContent side="right" class="text-[12.5px]">
                    {{ branch.name }}
                </TooltipContent>
            </Tooltip>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="relative mt-2 size-11 rounded-full"
                        :aria-label="
                            approvalCount > 0
                                ? `Open user menu, ${approvalLabel}`
                                : 'Open user menu'
                        "
                    >
                        <Avatar class="size-9">
                            <AvatarFallback
                                class="bg-muted text-xs font-semibold"
                            >
                                {{ getInitials(user.name) }}
                            </AvatarFallback>
                        </Avatar>
                        <span
                            v-if="approvalCount > 0"
                            data-test="approvals-pill"
                            class="absolute -top-0.5 -right-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-destructive px-1 text-xs leading-none font-semibold text-destructive-foreground ring-2 ring-sidebar"
                            aria-hidden="true"
                            >{{
                                approvalCount > 99 ? '99+' : approvalCount
                            }}</span
                        >
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="start"
                    side="right"
                    class="w-60 p-1.5"
                >
                    <UserMenuContent :user="user" />
                </DropdownMenuContent>
            </DropdownMenu>
        </nav>
    </TooltipProvider>

    <!-- Desktop panel -->
    <aside
        v-if="panelVisible && shownArea"
        class="fixed inset-y-0 left-[72px] z-30 hidden w-[248px] flex-col border-r bg-sidebar lg:flex"
        :aria-label="`${shownArea.title} menu`"
        data-test="shell-panel"
        @mouseenter="cancelClose"
        @mouseleave="scheduleClose"
    >
        <div class="flex items-center gap-1 px-4 pt-4 pb-2">
            <h2 class="flex-1 text-[17px] font-semibold text-foreground">
                {{ shownArea.title }}
            </h2>
            <Button
                variant="ghost"
                size="icon-sm"
                :aria-label="pinned ? 'Unpin the menu' : 'Pin the menu open'"
                :aria-pressed="pinned"
                @click="pinned = !pinned"
            >
                <component :is="pinned ? PinOff : Pin" class="size-4" />
            </Button>
            <Button
                v-if="!pinned"
                variant="ghost"
                size="icon-sm"
                aria-label="Close the menu"
                @click="
                    open = false;
                    shownKey = currentKey;
                "
            >
                <X class="size-4" />
            </Button>
        </div>
        <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-2 pb-3">
            <section v-for="section in shownArea.sections" :key="section.title">
                <p
                    v-if="shownArea.sections.length > 1"
                    class="px-2 pb-1 text-[13px] font-medium text-muted-foreground"
                >
                    {{ section.title }}
                </p>
                <Link
                    v-for="link in section.links"
                    :key="link.href"
                    :href="link.href"
                    class="flex min-h-8 items-center rounded-[10px] px-2 py-1.5 text-[13.5px] text-foreground transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="
                        linkActive(link.href) ? 'bg-accent font-semibold' : ''
                    "
                    :aria-current="linkActive(link.href) ? 'page' : undefined"
                >
                    {{ link.title }}
                </Link>
            </section>
        </div>
        <div class="space-y-2 border-t px-3 py-3">
            <Button
                v-if="props.context === 'admin' && workspace?.canEnterClinic"
                as-child
                size="sm"
                variant="outline"
                class="w-full"
            >
                <Link :href="workspace.defaultUrl">Return to workspace</Link>
            </Button>
            <WorkspaceBranchSwitcher />
        </div>
    </aside>

    <!-- Phone and tablet: header with the drawer -->
    <header
        class="sticky top-0 z-40 flex h-14 items-center gap-2 border-b bg-background/95 px-3 backdrop-blur lg:hidden"
    >
        <Button
            variant="ghost"
            size="icon"
            aria-label="Open navigation"
            @click="drawerOpen = true"
        >
            <Menu class="size-5" />
        </Button>
        <Link
            href="/dashboard"
            class="mx-auto flex items-center gap-2 rounded-[10px] focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            aria-label="Open KPOne Main Menu"
        >
            <img
                src="/kp-mark.png"
                alt="Klinik Putrijaya"
                class="size-8 object-contain"
            />
        </Link>
        <WorkspaceBranchSwitcher />
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button
                    variant="ghost"
                    size="icon"
                    class="relative rounded-full"
                    :aria-label="
                        approvalCount > 0
                            ? `Open user menu, ${approvalLabel}`
                            : 'Open user menu'
                    "
                >
                    <Avatar class="size-9">
                        <AvatarFallback class="bg-muted text-xs font-semibold">
                            {{ getInitials(user.name) }}
                        </AvatarFallback>
                    </Avatar>
                    <span
                        v-if="approvalCount > 0"
                        class="absolute -top-0.5 -right-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-destructive px-1 text-xs leading-none font-semibold text-destructive-foreground ring-2 ring-background"
                        aria-hidden="true"
                        >{{ approvalCount > 99 ? '99+' : approvalCount }}</span
                    >
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-60 p-1.5">
                <UserMenuContent :user="user" />
            </DropdownMenuContent>
        </DropdownMenu>
    </header>
    <Sheet
        :open="drawerOpen"
        @update:open="(v: boolean) => (v ? (drawerOpen = true) : closeDrawer())"
    >
        <SheetContent side="left" class="w-80 max-w-full p-5">
            <SheetHeader>
                <SheetTitle>{{
                    drawerArea ? drawerArea.title : 'KPOne navigation'
                }}</SheetTitle>
            </SheetHeader>
            <nav class="mt-4 grid gap-1" aria-label="Mobile navigation">
                <template v-if="!drawerArea">
                    <button
                        v-for="area in areas"
                        :key="area.key"
                        type="button"
                        class="flex min-h-11 items-center gap-3 rounded-[10px] px-3 text-left text-sm font-medium hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        :class="
                            currentKey === area.key
                                ? 'bg-accent text-primary'
                                : ''
                        "
                        @click="drawerKey = area.key"
                    >
                        <component
                            :is="ICONS[area.icon] ?? Settings"
                            class="size-5"
                            :stroke-width="1.7"
                            aria-hidden="true"
                        />
                        {{ area.title }}
                    </button>
                </template>
                <template v-else>
                    <button
                        type="button"
                        class="flex min-h-11 items-center rounded-[10px] px-3 text-left text-sm text-link hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        @click="drawerKey = null"
                    >
                        All areas
                    </button>
                    <section
                        v-for="section in drawerArea.sections"
                        :key="section.title"
                    >
                        <p
                            v-if="drawerArea.sections.length > 1"
                            class="px-3 pt-2 pb-1 text-[13px] font-medium text-muted-foreground"
                        >
                            {{ section.title }}
                        </p>
                        <Link
                            v-for="link in section.links"
                            :key="link.href"
                            :href="link.href"
                            class="flex min-h-11 items-center rounded-[10px] px-3 text-sm hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :class="
                                linkActive(link.href)
                                    ? 'bg-accent font-semibold'
                                    : ''
                            "
                            :aria-current="
                                linkActive(link.href) ? 'page' : undefined
                            "
                            @click="closeDrawer"
                        >
                            {{ link.title }}
                        </Link>
                    </section>
                </template>
                <Link
                    v-if="
                        props.context === 'admin' && workspace?.canEnterClinic
                    "
                    :href="workspace.defaultUrl"
                    class="mt-2 flex min-h-11 items-center rounded-[10px] px-3 text-sm text-link hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    @click="closeDrawer"
                    >Return to workspace</Link
                >
            </nav>
        </SheetContent>
    </Sheet>
</template>
