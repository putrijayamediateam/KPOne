export type PanelClaimTone = 'neutral' | 'success' | 'warning' | 'danger';

// UI-RESTYLE: the tone of a Panel claim status badge, shared by the Panel pages. Only the tone is set here; the
// words always come from the server. Prepared, Submitted and "No claim" stay neutral.
const tones: Record<string, PanelClaimTone> = {
    accepted: 'success',
    paid: 'success',
    partially_accepted: 'warning',
    partially_paid: 'warning',
    queried: 'warning',
    rejected: 'danger',
};

export const panelClaimTone = (status: string): PanelClaimTone =>
    tones[status.trim().toLowerCase().replaceAll(' ', '_')] ?? 'neutral';
