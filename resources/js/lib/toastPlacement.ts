import type { ToasterProps } from 'vue-sonner';

/**
 * UX-1 (S1-015): staff screens keep their main actions in sticky bars at the bottom
 * (for example Complete Consultation), and a bottom toast sat on top of them. Staff
 * toasts therefore show at the top centre at every width, just under the 52 px sticky
 * header (owner decision 2026-11-05: desktop too). The public QR layout keeps its own.
 */
export const STAFF_TOAST_TOP_OFFSET = '64px';

export const STAFF_TOAST_PLACEMENT: ToasterProps = {
    position: 'top-center',
    offset: { top: STAFF_TOAST_TOP_OFFSET },
    mobileOffset: { top: STAFF_TOAST_TOP_OFFSET },
};
