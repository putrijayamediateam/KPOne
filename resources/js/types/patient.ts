export type PatientIdentifier = {
    id: number;
    type: 'nric' | 'passport';
    issuingCountryCode: string;
    displayValue: string;
    retiredAt: string | null;
    isCurrent: boolean;
};

export type PatientDetail = {
    patientNumber: string;
    identity: {
        fullName: string;
        dateOfBirth: string | null;
        sex: 'female' | 'male' | 'indeterminate' | 'unknown';
        nationalityCode: string | null;
    };
    contact: { mobilePhone: string | null; email: string | null };
    address: {
        line1: string | null;
        line2: string | null;
        postcode: string | null;
        city: string | null;
        state: string | null;
        countryCode: string | null;
    };
    identifiers: PatientIdentifier[];
    administrative: {
        lockVersion: number;
        createdAt: string;
        updatedAt: string;
    };
    activity: Array<{
        event: string;
        actor: string;
        occurredAt: string;
        changedFields: string[];
        identifierType: string | null;
    }>;
    can: { update: boolean; manageIdentifiers: boolean };
};

export type PatientFormValues = {
    full_name: string;
    date_of_birth: string;
    sex: 'female' | 'male' | 'indeterminate' | 'unknown';
    nationality_code: string;
    mobile_phone: string;
    email: string;
    address_line_1: string;
    address_line_2: string;
    postcode: string;
    city: string;
    state: string;
    country_code: string;
};
