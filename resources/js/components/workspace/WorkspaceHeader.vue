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

const props = defineProps<{ context: 'clinic' | 'admin' }>();
const page = usePage();
const user = computed(() => page.props.auth.user!);
const permissions = computed(() => page.props.auth.permissions);
const workspace = computed(() => page.props.workspace);
const can = (permission: string) => permissions.value.includes(permission);

const clinicItems = computed(() => [
    ...(workspace.value?.navigation.panelWork &&
    !workspace.value?.navigation.placeholders
        ? [{ label: 'Panel responsibility', href: '/panel-claims' }]
        : []),
    ...(workspace.value?.navigation.financeWork &&
    !workspace.value?.canEnterClinic
        ? [{ label: 'Finance / Billing', href: '/financial-work' }]
        : []),
    ...(workspace.value?.navigation.registration
        ? [{ label: 'Registration', href: '/registration' }]
        : []),
    ...(workspace.value?.navigation.consultation
        ? [{ label: 'Consultation', href: '/queue' }]
        : []),
    ...(workspace.value?.navigation.placeholders
        ? [
              { label: 'Reviews', href: '/reviews' },
              { label: 'Panel Claims', href: '/panel-claims' },
              { label: 'Insight', href: '/insight' },
              { label: 'Purchase', href: '/purchase' },
          ]
        : []),
]);

const adminItems = computed(() => [
    { label: 'Main Menu', href: '/dashboard' },
    ...(workspace.value?.navigation.panelWork
        ? [{ label: 'Panel responsibility', href: '/panel-claims' }]
        : []),
    ...(workspace.value?.navigation.financeWork
        ? [{ label: 'Finance / Billing', href: '/financial-work' }]
        : []),
    ...(can('staff.view.own') ||
    can('staff.view.branch') ||
    can('staff.view.organisation')
        ? [{ label: 'Staff', href: '/staff' }]
        : []),
    ...(can('branches.view.branch') || can('branches.view.organisation')
        ? [{ label: 'Branches', href: '/branches' }]
        : []),
    ...(can('access.view.organisation')
        ? [{ label: 'Access Control', href: '/access-control' }]
        : []),
    ...(can('audit.view.organisation')
        ? [{ label: 'Audit Logs', href: '/audit-logs' }]
        : []),
]);

const items = computed(() =>
    props.context === 'clinic' ? clinicItems.value : adminItems.value,
);
const isActive = (href: string) =>
    page.url === href ||
    (href !== '/dashboard' && page.url.startsWith(`${href}/`));
</script>

<template>
    <header class="sticky top-0 z-40 border-b bg-background/95 backdrop-blur">
        <div
            class="mx-auto flex h-13 w-full max-w-[1600px] items-center gap-3 px-3 md:px-5 lg:grid lg:grid-cols-[minmax(180px,1fr)_auto_minmax(180px,1fr)]"
        >
            <Sheet>
                <SheetTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="lg:hidden"
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
                        aria-label="Primary navigation"
                    >
                        <Link
                            v-for="item in items"
                            :key="item.href"
                            :href="item.href"
                            class="rounded-md px-3 py-2 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :class="
                                isActive(item.href)
                                    ? 'bg-muted text-foreground'
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
                class="flex shrink-0 items-center gap-2 rounded-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
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
                class="hidden min-w-0 items-stretch justify-self-center lg:flex"
                aria-label="Primary navigation"
            >
                <Link
                    v-for="item in items"
                    :key="item.href"
                    :href="item.href"
                    class="relative flex h-13 items-center px-2.5 text-[13px] font-normal text-muted-foreground transition-colors hover:bg-muted/35 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none focus-visible:ring-inset xl:px-3.5"
                    :class="
                        isActive(item.href)
                            ? 'font-medium text-foreground after:absolute after:inset-x-3 after:bottom-0 after:h-px after:bg-foreground/60'
                            : ''
                    "
                >
                    {{ item.label }}
                </Link>
            </nav>

            <div
                class="ml-auto flex shrink-0 items-center gap-2 lg:ml-0 lg:justify-self-end"
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
