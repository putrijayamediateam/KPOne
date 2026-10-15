<?php

namespace App\Domain\Organisation\BusinessDetails;

/** Current configuration only; issuance/version snapshots belong to Document Templates. */
final readonly class BranchLetterhead
{
    /** @param array{legal_name: string, registration_type: ?string, registration_number: ?string} $organisation
     * @param  array{clinic_name: string, address_line_1: string, address_line_2: ?string, postcode: string, city: string, state: string, country: string, phone: ?string, email: ?string}  $details
     * @param  array{url: string, mime: string, width: int, height: int}|null  $logo
     */
    public function __construct(
        public int $branchId,
        public string $branchName,
        public array $organisation,
        public array $details,
        public ?array $logo,
        public int $organisationVersion,
        public int $branchVersion,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['branchId' => $this->branchId, 'branchName' => $this->branchName, 'organisation' => $this->organisation,
            'details' => $this->details, 'logo' => $this->logo, 'organisationVersion' => $this->organisationVersion,
            'branchVersion' => $this->branchVersion, 'configured' => $this->organisationVersion > 0 && $this->branchVersion > 0];
    }
}
