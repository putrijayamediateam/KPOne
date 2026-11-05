import type { ToasterProps } from 'vue-sonner';

/**
 * UX-5: the consultation page (focus mode) has no staff header; its own toolbar stays pinned at the
 * top on desktop, so a top toast always covered Panggil semula, the waiting count, Start treatment step
 * or On Hold. From 1024 px the toast shows at the bottom left instead: measured clear of every control
 * at 1280 to 1920 px however far the page is scrolled, and at 1024 px it briefly covers "Choose preset"
 * only with the page at the very top. Complete Consultation sits on the right (owner decision
 * 2026-11-05). Below 1024 px, and on every other page, STAFF_TOAST_PLACEMENT still applies.
 */
export const FOCUS_DESKTOP_TOAST_MEDIA = '(min-width: 1024px)';

/**
 * Design review (Daniel, 2026-11-05): at 1024 px the default 356 px toast ran over the left end of
 * "Save clinical record". Capped so it ends before the left column's right-aligned buttons.
 */
export const FOCUS_DESKTOP_TOAST_WIDTH = 'min(20rem, calc(50vw - 2rem))';

export const FOCUS_DESKTOP_TOAST_PLACEMENT: ToasterProps = {
    position: 'bottom-left',
    style: { '--width': FOCUS_DESKTOP_TOAST_WIDTH },
};
