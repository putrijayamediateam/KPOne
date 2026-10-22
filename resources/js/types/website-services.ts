export type ServiceSection = { heading: string; body: string };
export type ServiceContent = {
    name: string;
    parent_public_id: string | null;
    cover_public_id: string | null;
    gallery_public_ids: string[];
    description_sections?: ServiceSection[];
    what_to_expect?: string[];
    suitable_for?: string[];
    sort_order: number;
    include_on_website: boolean;
    [key: string]:
        | string
        | number
        | boolean
        | null
        | string[]
        | ServiceSection[]
        | undefined;
};
export type ServiceItem = {
    publicId: string;
    title: string;
    slug: string;
    archived: boolean;
    lockVersion: number;
    parentIds: string[];
};
export type ServiceRevision = {
    id: number | null;
    number: number;
    language: 'en' | 'bm';
    content: ServiceContent;
    branchIds: number[];
    missing: string[];
    createdAt: string | null;
};
export type ServicesPage = {
    type: 'service_category' | 'public_service';
    branchId: number;
    branches: { id: number; name: string }[];
    categories: ServiceItem[];
    canManage: boolean;
    canSelectMedia: boolean;
    editing: boolean;
    item: ServiceItem | null;
    items: ServiceItem[];
    page: number;
    pages: number;
    filters: {
        search?: string;
        branch_id?: number;
        category_id?: string;
        archived?: boolean;
    };
    drafts: Record<'en' | 'bm', ServiceRevision>;
    history: ServiceRevision[];
    historyPage: number;
    historyPages: number;
    assets: { public_id: string; alt_en: string; alt_bm: string }[];
    textLimits: Record<string, number>;
    listLimits: Record<string, [number, number]>;
};
