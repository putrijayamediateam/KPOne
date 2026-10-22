export type Language = 'en' | 'bm';
export const weekdays = [
    'mon',
    'tue',
    'wed',
    'thu',
    'fri',
    'sat',
    'sun',
] as const;
export type Day = (typeof weekdays)[number];
export const profileFields = [
    ['display_name', 'Public display name'],
    ['address_line_1', 'Address line 1'],
    ['address_line_2', 'Address line 2'],
    ['postcode', 'Postcode'],
    ['city', 'City'],
    ['state', 'State'],
    ['public_phone', 'Public phone'],
    ['whatsapp_number', 'Public WhatsApp number (optional)'],
    ['public_email', 'Public email'],
    ['map_url', 'Map link (HTTPS)'],
    ['hours_note', 'Opening hours note'],
    ['parking_notes', 'Parking notes'],
    ['accessibility_notes', 'Accessibility notes'],
] as const;
export type TextField = (typeof profileFields)[number][0];
export type ProfileContent = Record<TextField, string> & {
    opening_hours: Record<
        Day,
        { closed: boolean; open: string; close: string }
    >;
    contact_blocks: { label: string; text: string }[];
};
export type Revision = {
    id: number | null;
    language: Language;
    number: number;
    content: ProfileContent;
    heroAssetPublicId: string | null;
    isSeedCopy: boolean;
    createdAt: string | null;
    missing: string[];
};
export type Profile = {
    branch: { id: number; name: string };
    lockVersion: number;
    slug: string | null;
    drafts: Record<Language, Revision>;
    history: { data: Revision[]; page: number; pages: number };
    assets: { public_id: string; alt_en: string; alt_bm: string }[];
    limits: Record<TextField, number>;
    canManage: boolean;
};
export type Asset = {
    publicId: string;
    altEn: string;
    altBm: string;
    rightsReference: string;
    identifiablePerson: boolean;
    consentReference: string;
    retired: boolean;
    lockVersion: number;
    mime: string;
    bytes: number;
    width: number;
    height: number;
    sha256: string;
};
