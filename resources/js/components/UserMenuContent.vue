<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Building2,
    Clock,
    LayoutGrid,
    LogOut,
    Settings,
    ShieldCheck,
} from '@lucide/vue';
import { computed, onMounted } from 'vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { useClockShortcut } from '@/composables/useClockShortcut';
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
// DS-12: the person's own pages, built on the server from the permissions they hold, and the phone clock row.
const accountMenu = computed(() => page.props.workspace?.accountMenu);
// Classic only (CO-1, 19 Nov 2026): the Clock in row is the same on every page, the spaces included.
const {
    load: loadClock,
    press: pressClock,
    label: clockLabel,
    visible: clockVisible,
} = useClockShortcut();
// The row is gone once the menu closes, so pass the menu's own button (still open while the row is selected); focus goes
// back to it after the photo dialog closes, instead of to the page.
const pressClockAfterMenu = () =>
    pressClock(
        document.querySelector<HTMLElement>(
            '[aria-haspopup="menu"][aria-expanded="true"]',
        ),
    );
onMounted(() => {
    if (accountMenu.value?.clock) {
        void loadClock();
    }
});
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup v-if="accountMenu?.links.length || accountMenu?.clock">
        <DropdownMenuItem
            v-if="accountMenu?.clock && clockVisible"
            class="cursor-pointer max-lg:min-h-11"
            data-test="clock-shortcut-row"
            @select="pressClockAfterMenu"
        >
            <Clock class="mr-2 h-4 w-4" />{{ clockLabel }}
        </DropdownMenuItem>
        <DropdownMenuItem
            v-for="link in accountMenu?.links ?? []"
            :key="link.title"
            :as-child="true"
            class="pl-10 max-lg:min-h-11"
        >
            <Link class="block w-full cursor-pointer" :href="link.href">{{
                link.title
            }}</Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator
        v-if="accountMenu?.links.length || accountMenu?.clock"
    />
    <DropdownMenuGroup>
        <DropdownMenuItem
            v-if="page.props.workspace?.navigation.clinicManagement"
            :as-child="true"
        >
            <Link class="block w-full cursor-pointer" href="/clinic-management">
                <Building2 class="mr-2 h-4 w-4" />Clinic management
            </Link>
        </DropdownMenuItem>
        <!-- Classic only (CO-1): the department spaces, for everyone who can enter one (built on the server). -->
        <DropdownMenuItem
            v-if="accountMenu?.spaces"
            :as-child="true"
            class="max-lg:min-h-11"
            data-test="spaces-row"
        >
            <Link class="block w-full cursor-pointer" href="/spaces">
                <LayoutGrid class="mr-2 h-4 w-4" />Spaces
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true" class="max-lg:min-h-11">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <Settings class="mr-2 h-4 w-4" />
                My profile
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem :as-child="true" class="max-lg:min-h-11">
            <Link class="block w-full cursor-pointer" href="/settings/security">
                <ShieldCheck class="mr-2 h-4 w-4" />
                Security
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
