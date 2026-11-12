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
        // CAT-9: optional second clinic phone (BranchLetterhead details.phone_secondary); copies frozen before CAT-9
        // do not carry the key.
        phone_secondary?: string | null;
        email: string | null;
        // Drug label short address (BranchLetterhead details.label_address): used only by the drug label.
        label_address?: string | null;
    };
    logo: { url: string; mime: string; width: number; height: number } | null;
    footerText: string | null;
};
