import type { ToasterProps } from 'vue-sonner';

/**
 * UX-5: the consultation page (focus mode) has no staff header; its own toolbar stays pinned at the
 * top on desktop, so a top toast always covered Panggil semula, the waiting count, Start treatment step
 * or On Hold. From 1024 px the toast shows at the bottom left instead, which was measured clear at
 * 1280 to 1920 px however far the page is scrolled; Complete Consultation sits on the right (owner
 * decision 2026-11-05). Below 1024 px, and on every other page, STAFF_TOAST_PLACEMENT still applies.
 */
export const FOCUS_DESKTOP_TOAST_MEDIA = '(min-width: 1024px)';

export const FOCUS_DESKTOP_TOAST_PLACEMENT: ToasterProps = {
    position: 'bottom-left',
};
