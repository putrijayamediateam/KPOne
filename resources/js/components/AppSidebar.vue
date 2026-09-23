<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Building2,
    FileText,
    LayoutDashboard,
    ListOrdered,
    ClipboardPlus,
    QrCode,
    ShieldCheck,
    Users,
    UserRoundSearch,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import BranchSwitcher from '@/components/BranchSwitcher.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import type { NavItem } from '@/types';

const page = usePage();
const can = (permission: string) =>
    page.props.auth.permissions.includes(permission);

const mainNavItems = computed<NavItem[]>(() => [
    { title: 'Dashboard', href: '/dashboard', icon: LayoutDashboard },
    ...(can('visits.view.branch')
        ? [
              {
                  title: 'Registration',
                  href: '/registration',
                  icon: ClipboardPlus,
              },
          ]
        : []),
    ...(can('public_checkin_links.manage.organisation') ||
    can('public_checkin_links.manage.branch')
        ? [
              {
                  title: 'QR Cawangan',
                  href: '/public-checkin-links',
                  icon: QrCode,
              },
          ]
        : []),
    ...(can('queue.view.branch') || can('queue.view.own')
        ? [{ title: 'Queue', href: '/queue', icon: ListOrdered }]
        : []),
    ...(can('patients.search.organisation')
        ? [{ title: 'Patients', href: '/patients', icon: UserRoundSearch }]
        : []),
    ...(can('staff.view.own') ||
    can('staff.view.branch') ||
    can('staff.view.organisation')
        ? [{ title: 'Staff', href: '/staff', icon: Users }]
        : []),
    ...(can('branches.view.branch') || can('branches.view.organisation')
        ? [{ title: 'Branches', href: '/branches', icon: Building2 }]
        : []),
    ...(can('access.view.organisation')
        ? [
              {
                  title: 'Access Control',
                  href: '/access-control',
                  icon: ShieldCheck,
              },
          ]
        : []),
    ...(can('audit.view.organisation')
        ? [{ title: 'Audit Logs', href: '/audit-logs', icon: FileText }]
        : []),
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset" class="border-r-0">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link href="/dashboard">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <BranchSwitcher />
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <div
                class="px-3 pb-2 text-[11px] font-medium tracking-wide text-sidebar-foreground/40 group-data-[collapsible=icon]:hidden"
            >
                Phase 2A · Clinical Encounter
            </div>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
