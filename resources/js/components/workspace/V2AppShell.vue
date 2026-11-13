<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Menu, Search, Undo2 } from '@lucide/vue';
import { computed, onMounted, provide, ref, watchEffect } from 'vue';
import { toast } from 'vue-sonner';
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
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import UserMenuContent from '@/components/UserMenuContent.vue';
import V2JumpDialog from '@/components/workspace/V2JumpDialog.vue';
import V2PageCrumbs from '@/components/workspace/V2PageCrumbs.vue';
import V2ShellNav from '@/components/workspace/V2ShellNav.vue';
import WorkspaceBranchSwitcher from '@/components/workspace/WorkspaceBranchSwitcher.vue';
import {
    pageHasOwnClock,
    TOP_BAR_CLOCK,
    useClockShortcut,
} from '@/composables/useClockShortcut';
import { getInitials } from '@/composables/useInitials';
import { providePageCrumbs } from '@/composables/usePageCrumbs';
import type { V2Shell } from '@/types';

/**
 * UX-17: the Version 2 frame around a working page (GUIDE sections 18 and 19, Daniel's frame rules): the spaces'
 * grouped sidebar on the left (a full-height sheet below 1024 px, opened from a menu button; Daniel, 2026-11-12), and a top bar with
 * "Search or jump to…", the branch switcher, "Back to Classic" and the account button. A small crumb above the page
 * links to the space the page belongs to. Shown only when "Try the new layout" is on; Classic never renders it.
 */
const props = defineProps<{ shell: V2Shell; focusMode?: boolean }>();

const page = usePage();
const user = computed(() => page.props.auth.user!);
const sheetOpen = ref(false);

// UX-24 (Daniel): the clock shortcut as a small outline control from 1024 px, on every V2 page except those with their
// own Clock in (Me home, Roster, Attendance). Same server flag as before; hidden, never disabled, without it. A press
// opens the clock-in photo dialog (the confirm step) or the Attendance page; it never records a time by itself.
const accountMenu = computed(() => page.props.workspace?.accountMenu);
const {
    load: loadClock,
    press: pressClock,
    label: clockLabel,
    visible: clockVisible,
} = useClockShortcut();
onMounted(() => {
    if (accountMenu.value?.clock) {
        void loadClock();
    }
});
const showClock = computed(
    () =>
        accountMenu.value?.clock === true &&
        clockVisible.value &&
        !pageHasOwnClock(page.url),
);
// The top bar is hidden in focus mode, so the button is drawn only outside it.
provide(
    TOP_BAR_CLOCK,
    computed(() => showClock.value && !props.focusMode),
);
const jumpOpen = ref(false);

// The explicit way back to Classic: the same switch as the account menu row, through the existing endpoint.
const backToClassic = () =>
    router.put(
        '/spaces/preference',
        {
            enabled: false,
            lock_version: page.props.workspace?.spaces?.lockVersion ?? 0,
        },
        {
            onError: (errors) =>
                toast.error(
                    errors.enabled ??
                        'Could not switch back to Classic. Reload and try again.',
                ),
        },
    );

// Daniel (real-shell review, 2026-11-12): a quiet ghost control, not an outline page action, and the same 36 px
// height and small text as the branch switcher beside it. UX-20: a full-strength focus ring, because the shared Button's
// 40% ring measures under 3:1 against the top bar (Daniel, 2026-11-12).
const QUIET_BACK =
    'h-9 gap-1.5 rounded-lg px-2.5 text-xs font-normal text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring';

// UX-20: a page may set its own crumb trail (usePageCrumbs); the space this page belongs to is the fallback.
const { placed, hidden: crumbHidden } = providePageCrumbs(() =>
    props.shell.current
        ? { title: props.shell.current.title, href: props.shell.current.href }
        : null,
);
watchEffect(() => (crumbHidden.value = props.focusMode === true));
const crumbsInFrame = computed(() => placed.value.length);
</script>

<template>
    <!-- --v2-topbar-h: the top bar's height (h-16), so a page's sticky header can sit right under it (Liyana's
         V2WeekGrid). 0 in focus mode, where the top bar is hidden. -->
    <div
        class="flex min-h-screen w-full bg-background"
        :style="{ '--v2-topbar-h': focusMode ? '0px' : '4rem' }"
        data-test="v2-shell"
    >
        <div
            v-if="!focusMode"
            class="sticky top-0 hidden h-screen w-60 shrink-0 flex-col gap-4 overflow-y-auto border-r border-sidebar-border bg-sidebar px-3 py-4 lg:flex"
            data-test="v2-shell-sidebar"
        >
            <Link
                href="/"
                class="flex items-center gap-2 px-3 text-sm font-semibold tracking-tight text-sidebar-foreground"
            >
                <!-- A 56 px copy of the mark (4 KB instead of 758 KB), so it is there at first paint (Daniel). -->
                <img
                    src="/kp-mark-56.png"
                    alt=""
                    width="28"
                    height="28"
                    class="size-7 object-contain"
                />
                KPOne
            </Link>
            <V2ShellNav :shell="shell" />
        </div>

        <Sheet v-model:open="sheetOpen">
            <SheetContent
                side="left"
                class="h-full w-72 max-w-[85vw] gap-3 overflow-y-auto bg-sidebar p-4 motion-reduce:transition-none motion-reduce:data-[state=closed]:animate-none motion-reduce:data-[state=open]:animate-none"
                data-test="v2-shell-sheet"
            >
                <SheetHeader class="p-0">
                    <SheetTitle>KPOne</SheetTitle>
                    <SheetDescription class="sr-only"
                        >Spaces and pages you can open</SheetDescription
                    >
                </SheetHeader>
                <V2ShellNav :shell="shell" @navigate="sheetOpen = false" />
                <div class="mt-auto border-t border-sidebar-border pt-3">
                    <Button
                        variant="outline"
                        class="h-11 w-full"
                        data-test="v2-sheet-back-to-classic"
                        @click="backToClassic"
                    >
                        Back to Classic layout
                    </Button>
                </div>
            </SheetContent>
        </Sheet>

        <div class="flex min-w-0 flex-1 flex-col">
            <header
                v-if="!focusMode"
                class="sticky top-0 z-40 flex h-16 items-center gap-2 border-b bg-card px-3 md:px-5"
                data-test="v2-shell-top"
            >
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-11 lg:hidden"
                    aria-label="Open menu"
                    @click="sheetOpen = true"
                >
                    <Menu class="size-5" aria-hidden="true" />
                </Button>
                <button
                    type="button"
                    class="flex h-10 min-w-0 items-center gap-2 rounded-lg border bg-background px-3 text-left text-sm text-muted-foreground hover:bg-muted max-sm:size-11 max-sm:justify-center max-sm:px-0 sm:max-w-sm sm:flex-1"
                    aria-haspopup="dialog"
                    aria-label="Search or jump to"
                    data-test="v2-jump-open"
                    @click="jumpOpen = true"
                >
                    <Search class="size-4 shrink-0" aria-hidden="true" />
                    <span class="truncate max-sm:hidden"
                        >Search or jump to…</span
                    >
                </button>
                <div class="ml-auto flex shrink-0 items-center gap-2">
                    <WorkspaceBranchSwitcher />
                    <Button
                        v-if="showClock"
                        variant="outline"
                        size="sm"
                        class="hidden h-9 lg:inline-flex"
                        data-test="clock-shortcut"
                        @click="pressClock()"
                    >
                        {{ clockLabel }}
                    </Button>
                    <!-- Below 640 px the way back lives in the menu sheet's footer and the account menu (Daniel). -->
                    <Button
                        variant="ghost"
                        size="sm"
                        :class="QUIET_BACK"
                        class="max-sm:hidden"
                        data-test="v2-back-to-classic"
                        @click="backToClassic"
                    >
                        <Undo2 class="size-3.5" aria-hidden="true" />
                        Back to Classic
                    </Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="size-10 rounded-full hover:bg-muted/60 max-sm:size-11"
                                aria-label="Open user menu"
                            >
                                <Avatar class="size-9.5">
                                    <AvatarFallback
                                        class="bg-muted text-xs font-semibold"
                                    >
                                        {{ getInitials(user.name) }}
                                    </AvatarFallback>
                                </Avatar>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="end"
                            class="w-60 rounded-xl border-border/70 p-1.5 shadow-xl shadow-black/5"
                        >
                            <UserMenuContent :user="user" />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </header>

            <!-- A page with PageFrame draws the crumb inside its own frame, at the content edge. For a page without one the
                 shell draws it here, at the side padding most pages use today (16 px, 24 px from 768 px; Sofia's review). -->
            <V2PageCrumbs
                v-if="!focusMode && crumbsInFrame === 0"
                class="px-4 pt-4 md:px-6"
                data-test="v2-shell-crumb"
            />

            <slot />
        </div>

        <V2JumpDialog v-model:open="jumpOpen" :shell="shell" />
    </div>
</template>
