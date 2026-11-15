import type { InjectionKey } from 'vue';

// V2 Dispensary returns: the check panel tells the section that a sheet was closed without a check (Hakim), so the
// section forgets the row it would otherwise focus after the next reload.
export const RETURN_SHEET_CLOSED: InjectionKey<(rowPublicId: string) => void> =
    Symbol('return-sheet-closed');
