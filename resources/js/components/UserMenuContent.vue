<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Building2, LayoutGrid, LogOut, Settings } from '@lucide/vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { retireAuthenticationHistoryForLogout } from '@/lib/inertia-auth-history-boundary';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

const handleLogout = () => {
    retireAuthenticationHistoryForLogout(router);
    router.flushAll();
};

defineProps<Props>();
const page = usePage();
const switchSpaces = (enabled: boolean) =>
    router.put('/spaces/preference', {
        enabled,
        lock_version: page.props.workspace?.spaces?.lockVersion ?? 0,
    });
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem
            v-if="page.props.workspace?.navigation.clinicManagement"
            :as-child="true"
        >
            <Link class="block w-full cursor-pointer" href="/clinic-management">
                <Building2 class="mr-2 h-4 w-4" />Clinic management
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem
            v-if="page.props.workspace?.spaces?.enabled"
            :as-child="true"
        >
            <Link class="block w-full cursor-pointer" href="/spaces">
                <LayoutGrid class="mr-2 h-4 w-4" />Spaces
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem
            v-if="page.props.workspace?.spaces?.offered"
            class="cursor-pointer"
            @select="switchSpaces(!page.props.workspace.spaces.enabled)"
        >
            <LayoutGrid class="mr-2 h-4 w-4" />{{
                page.props.workspace.spaces.enabled
                    ? 'Back to the standard layout'
                    : 'Try the new layout'
            }}
        </DropdownMenuItem>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <Settings class="mr-2 h-4 w-4" />
                Settings
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer"
            :href="logout()"
            @start="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <LogOut class="mr-2 h-4 w-4" />
            Log out
        </Link>
    </DropdownMenuItem>
</template>
