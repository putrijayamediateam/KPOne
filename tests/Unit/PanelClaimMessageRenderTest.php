<?php

namespace Tests\Unit;

use App\Domain\Visit\Billing\Services\PanelClaimMessageService;
use PHPUnit\Framework\TestCase;

class PanelClaimMessageRenderTest extends TestCase
{
    public function test_known_fields_are_filled_and_unknown_text_is_kept(): void
    {
        $this->assertSame('Hi Acme, RM 5.00 {other} {Panel name}', PanelClaimMessageService::render('Hi {panel_name}, {claimed_amount} {other} {Panel name}', ['panel_name' => 'Acme', 'claimed_amount' => 'RM 5.00']));
    }

    public function test_an_empty_template_renders_no_message(): void
    {
        $this->assertNull(PanelClaimMessageService::render("  \n", ['panel_name' => 'Acme']));
    }

    public function test_every_offered_field_has_a_label(): void
    {
        $this->assertSame(['panel_name', 'patient_name', 'patient_nric', 'staff_id', 'claim_number', 'claimed_amount', 'submission_date', 'claim_link', 'clinic_name'], array_keys(PanelClaimMessageService::FIELDS));
    }
}
