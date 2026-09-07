<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Menu } from '@lucide/vue';
import { computed } from 'vue';
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
    SheetTrigger,
} from '@/components/ui/sheet';
import UserMenuContent from '@/components/UserMenuContent.vue';
import WorkspaceBranchSwitcher from '@/components/workspace/WorkspaceBranchSwitcher.vue';
import { getInitials } from '@/composables/useInitials';
import { headerDestinations } from '@/lib/workspace-navigation';

const props = defineProps<{ context: 'clinic' | 'admin' }>();
const page = usePage();
const user = computed(() => page.props.auth.user!);
const workspace = computed(() => page.props.workspace);
const items = computed(() =>
    workspace.value
        ? headerDestinations(workspace.value.navigation, props.context)
        : [],
);
const isActive = (href: string) =>
    page.url === href ||
    (href !== '/dashboard' && page.url.startsWith(`${href}/`));
</script>

<template>
    <header class="sticky top-0 z-40 border-b bg-background/95 backdrop-blur">
        <div
            class="mx-auto flex h-13 w-full max-w-[1600px] items-center gap-3 px-3 md:px-5 xl:grid xl:grid-cols-[minmax(180px,1fr)_auto_minmax(180px,1fr)]"
        >
            <Sheet>
                <SheetTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="xl:hidden"
                        aria-label="Open navigation"
                    >
                        <Menu class="size-5" />
                    </Button>
                </SheetTrigger>
                <SheetContent side="left" class="w-72 p-5">
                    <SheetHeader>
                        <SheetTitle>KPOne navigation</SheetTitle>
                    </SheetHeader>
                    <nav
                        class="mt-5 grid gap-1"
                        aria-label="Mobile primary navigation"
                    >
                        <Link
                            v-for="item in items"
                            :key="item.href"
                            :href="item.href"
                            class="cursor-pointer rounded-md border-l-2 border-transparent px-3 py-2 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :aria-current="
                                isActive(item.href) ? 'page' : undefined
                            "
                            :class="
                                isActive(item.href)
                                    ? 'border-pink-600 bg-muted text-foreground'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ item.label }}
                        </Link>
                    </nav>
                </SheetContent>
            </Sheet>

            <Link
                href="/dashboard"
                class="flex shrink-0 cursor-pointer items-center gap-2 rounded-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                aria-label="Open KPOne Main Menu"
            >
                <img
                    src="/kp-mark.png"
                    alt="Klinik Putrijaya"
                    class="size-8 object-contain"
                />
                <span
                    class="hidden text-sm font-semibold tracking-tight text-pink-700 sm:block"
                    >KPOne</span
                >
            </Link>

            <nav
                class="hidden min-w-0 items-stretch justify-self-center xl:flex"
                aria-label="Desktop primary navigation"
            >
                <Link
                    v-for="item in items"
                    :key="item.href"
                    :href="item.href"
                    class="relative flex h-13 cursor-pointer items-center px-2.5 text-[13px] font-normal text-muted-foreground transition-colors hover:bg-muted/35 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none focus-visible:ring-inset xl:px-3.5"
                    :aria-current="isActive(item.href) ? 'page' : undefined"
                    :class="
                        isActive(item.href)
                            ? 'font-medium text-foreground after:absolute after:inset-x-3 after:bottom-0 after:h-0.5 after:bg-pink-600'
                            : ''
                    "
                >
                    {{ item.label }}
                </Link>
            </nav>

            <div
                class="ml-auto flex shrink-0 items-center gap-2 xl:ml-0 xl:justify-self-end"
            >
                <Button
                    v-if="context === 'admin' && workspace?.canEnterClinic"
                    as-child
                    size="sm"
                    variant="outline"
                    class="hidden sm:inline-flex"
                >
                    <Link :href="workspace.defaultUrl"
                        >Return to workspace</Link
                    >
                </Button>
                <WorkspaceBranchSwitcher />
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-10 rounded-full hover:bg-muted/60"
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
        </div>
    </header>
</template>
