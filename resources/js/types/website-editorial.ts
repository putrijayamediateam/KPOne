export type EditorialSection = { heading: string; body: string };
export type EditorialContent = {
    linked_service_public_id?: string | null;
    cover_public_id?: string | null;
    gallery_public_ids?: string[];
    description_sections?: EditorialSection[];
    terms?: string[];
    sort_order: number;
    include_on_website: boolean;
    [key: string]:
        | string
        | number
        | boolean
        | null
        | string[]
        | EditorialSection[]
        | undefined;
};
export type EditorialItem = {
    publicId: string;
    title: string;
    slug: string;
    archived: boolean;
    lockVersion: number;
};
export type EditorialRevision = {
    id: number | null;
    number: number;
    language: 'en' | 'bm';
    content: EditorialContent;
    branchIds: number[];
    missing: string[];
    createdAt: string | null;
};
export type EditorialPage = {
    type: 'promotion' | 'activity' | 'faq';
    timezone: string;
    branchId: number;
    branches: { id: number; name: string }[];
    linkedServices: EditorialItem[];
    canManage: boolean;
    canSelectMedia: boolean;
    editing: boolean;
    item: EditorialItem | null;
    items: EditorialItem[];
    page: number;
    pages: number;
    filters: {
        search?: string;
        branch_id?: number;
        archived?: boolean;
    };
    drafts: Record<'en' | 'bm', EditorialRevision>;
    history: EditorialRevision[];
    historyPage: number;
    historyPages: number;
    assets: { public_id: string; alt_en: string; alt_bm: string }[];
    textLimits: Record<string, number>;
    dateFields: string[];
};
