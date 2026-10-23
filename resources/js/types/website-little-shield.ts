export type LittleText =
    | 'title'
    | 'tagline'
    | 'introduction'
    | 'partnership_introduction'
    | 'how_to_take_part';
export type LittleList = 'overview_paragraphs' | 'medical_safeguards';
export type LittleCards =
    'programme_pillars' | 'programme_flow_steps' | 'partner_types';
export type LittleCard = { title: string; description: string };
export type LittleContent = Record<LittleText, string> &
    Record<LittleList, string[]> &
    Record<LittleCards, LittleCard[]> & {
        cover_public_id: string | null;
        gallery_public_ids: string[];
        include_on_website: boolean;
    };
export type LittleRevision = {
    id: number | null;
    number: number;
    language: 'en' | 'bm';
    content: LittleContent;
    branchIds: number[];
    missing: string[];
    createdAt: string | null;
};
export type LittlePage = {
    branchId: number;
    item: { publicId: string; lockVersion: number } | null;
    drafts: Record<'en' | 'bm', LittleRevision>;
    history: LittleRevision[];
    historyPage: number;
    historyPages: number;
    branches: { id: number; name: string }[];
    assets: { public_id: string; alt_en: string; alt_bm: string }[];
    canManage: boolean;
    canSelectMedia: boolean;
    textLimits: Record<LittleText, number>;
    listLimits: Record<LittleList, [number, number]>;
    cardLimits: Record<LittleCards, [number, number, number]>;
};
