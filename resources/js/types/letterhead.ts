export type PrintLetterhead = {
    branchName: string;
    details: {
        clinic_name: string;
        address_line_1: string;
        address_line_2: string | null;
        postcode: string;
        city: string;
        state: string;
        country: string;
        phone: string | null;
        // CAT-9: optional second clinic phone (BranchLetterhead details.phone_secondary).
        phone_secondary: string | null;
        email: string | null;
    };
    logo: { url: string; mime: string; width: number; height: number } | null;
    footerText: string | null;
};
