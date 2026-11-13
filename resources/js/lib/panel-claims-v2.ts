import type { RowMenuGroup } from '@/components/v2/V2RowMenu.vue';

/**
 * Version 2 Panel Claims list helpers: the chip tone of a claim or invoice status, and the row menus. No permission
 * logic here: the menu items are the ones the Classic list offers (it gates nothing beyond the route), and "Submit claim
 * (manual)" is left out, not disabled, when the claim can no longer be marked submitted (as Classic does).
 */
export type ChipTone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

// The words come from the server (`PanelClaimDirectoryService::statusLabel`): they are the keys here.
const claimTones: Record<string, ChipTone> = {
    Prepared: 'neutral',
    Submitted: 'info',
    'Panel query': 'warning',
    Accepted: 'success',
    'Partially accepted': 'warning',
    Rejected: 'danger',
    'Partially paid': 'warning',
    Paid: 'success',
};

export const claimChipTone = (status: string): ChipTone =>
    claimTones[status] ?? 'neutral';

export const invoiceChip = (
    overdue: boolean,
): { tone: ChipTone; label: string } =>
    overdue
        ? { tone: 'danger', label: 'Overdue' }
        : { tone: 'warning', label: 'Pending' };

export const invoiceMenuGroups = (invoiceUrl: string): RowMenuGroup[] => [
    {
        label: '',
        items: [
            {
                id: 'view-invoice',
                label: 'View invoice',
                allowed: true,
                href: invoiceUrl,
            },
        ],
    },
];

export const claimMenuGroups = (
    showUrl: string,
    canMarkSubmitted: boolean,
): RowMenuGroup[] => [
    {
        label: '',
        items: [
            {
                id: 'view-claim',
                label: 'View claim',
                allowed: true,
                href: showUrl,
            },
            ...(canMarkSubmitted
                ? [
                      {
                          id: 'submit-claim',
                          label: 'Submit claim (manual)',
                          allowed: true,
                          href: showUrl,
                      },
                  ]
                : []),
        ],
    },
];
