import { useMediaQuery } from '@vueuse/core';
import { computed } from 'vue';
import type { ToasterProps } from 'vue-sonner';

/**
 * UX-1 (S1-015): staff screens keep their main actions in sticky bars at the bottom
 * (for example Complete Consultation), and a bottom toast sat on top of them. Below
 * the `lg` breakpoint (phones and tablets) toasts show at the top, just under the
 * 52 px sticky header; from `lg` up the library default (bottom-right) is unchanged.
 */
export const STAFF_TOAST_DESKTOP_QUERY = '(min-width: 1024px)';
export const STAFF_TOAST_TOP_OFFSET = '64px';

export function staffToastPlacement(desktop: boolean): ToasterProps {
    if (desktop) {
        return {};
    }

    return {
        position: 'top-center',
        offset: { top: STAFF_TOAST_TOP_OFFSET },
        mobileOffset: { top: STAFF_TOAST_TOP_OFFSET },
    };
}

export function useStaffToastPlacement() {
    const desktop = useMediaQuery(STAFF_TOAST_DESKTOP_QUERY);

    return computed(() => staffToastPlacement(desktop.value));
}
