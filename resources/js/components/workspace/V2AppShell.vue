<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Menu, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
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
import V2ShellNav from '@/components/workspace/V2ShellNav.vue';
import WorkspaceBranchSwitcher from '@/components/workspace/WorkspaceBranchSwitcher.vue';
import { getInitials } from '@/composables/useInitials';
import type { V2Shell } from '@/types';

/**
 * UX-17: the Version 2 frame around a working page (GUIDE sections 18 and 19, Daniel's frame rules): the spaces'
 * grouped sidebar on the left (a full-height sheet below 720 px, opened from a menu button), and a top bar with
 * "Search or jump to…", the branch switcher, "Back to Classic" and the account button. A small crumb above the page
 * links to the space the page belongs to. Shown only when "Try the new layout" is on; Classic never renders it.
 */
const props = defineProps<{ shell: V2Shell; focusMode?: boolean }>();

const page = usePage();
const user = computed(() => page.props.auth.user!);
const sheetOpen = ref(false);
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

const crumb = computed(() => props.shell.current);
</script>

<template>
    <div class="flex min-h-screen w-full bg-background" data-test="v2-shell">
        <div
            v-if="!focusMode"
            class="sticky top-0 hidden h-screen w-60 shrink-0 flex-col gap-4 overflow-y-auto border-r border-sidebar-border bg-sidebar px-3 py-4 min-[720px]:flex"
            data-test="v2-shell-sidebar"
        >
            <Link
                href="/"
                class="flex items-center gap-2 px-3 text-sm font-semibold tracking-tight text-sidebar-foreground"
            >
                <img src="/kp-mark.png" alt="" class="size-7 object-contain" />
                KPOne
            </Link>
            <V2ShellNav :shell="shell" />
        </div>

        <Sheet v-model:open="sheetOpen">
            <SheetContent
                side="left"
                class="h-full w-72 max-w-[85vw] gap-3 overflow-y-auto bg-sidebar p-4"
                data-test="v2-shell-sheet"
            >
                <SheetHeader class="p-0">
                    <SheetTitle>KPOne</SheetTitle>
                    <SheetDescription class="sr-only"
                        >Spaces and pages you can open</SheetDescription
                    >
                </SheetHeader>
                <V2ShellNav :shell="shell" @navigate="sheetOpen = false" />
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
                    class="size-11 min-[720px]:hidden"
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
                        variant="outline"
                        size="sm"
                        class="max-sm:h-11"
                        data-test="v2-back-to-classic"
                        @click="backToClassic"
                    >
                        <span class="max-sm:hidden">Back to Classic</span>
                        <span class="sm:hidden">Classic</span>
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

            <nav
                v-if="crumb && !focusMode"
                aria-label="Breadcrumb"
                class="px-4 pt-4 md:px-6"
                data-test="v2-shell-crumb"
            >
                <Link
                    :href="crumb.href"
                    class="inline-flex items-center text-xs font-medium text-muted-foreground hover:text-foreground hover:underline max-sm:min-h-11"
                    >{{ crumb.title }}</Link
                >
            </nav>

            <slot />
        </div>

        <V2JumpDialog v-model:open="jumpOpen" :shell="shell" />
    </div>
</template>
