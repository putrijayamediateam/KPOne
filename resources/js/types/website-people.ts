export type PeopleContent = Record<
    string,
    string | string[] | number | boolean
>;
export type PeopleRevision = {
    id: number | null;
    number: number;
    language: 'en' | 'bm';
    content: PeopleContent;
    portraitPublicId: string | null;
    branchIds?: number[];
    missing: string[];
    createdAt: string | null;
};
export type PeopleItem = {
    publicId: string;
    slug: string;
    title: string;
    lockVersion: number;
    archived: boolean;
    branchIds: number[];
};
export type PeoplePage = {
    type: 'doctor_profile' | 'career_posting';
    sharedReferencesDiverge: boolean;
    danglingServiceLink: boolean;
    branchId: number;
    branches: { id: number; name: string }[];
    canManage: boolean;
    editing: boolean;
    item: PeopleItem | null;
    items: PeopleItem[];
    filters: { search?: string; branch_id?: number; archived?: boolean };
    page: number;
    pages: number;
    drafts: Record<'en' | 'bm', PeopleRevision>;
    assets: { public_id: string; alt_en: string; alt_bm: string }[];
    history: PeopleRevision[];
    historyPage: number;
    historyPages: number;
    textLimits: Record<string, number>;
    listLimits: Record<string, [number, number]>;
    employmentTypes: string[];
};
