<?php

namespace Tests\Feature\Catalogue;

use Illuminate\Support\Str;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * The staff toast reads only Inertia::flash('toast', ...), so a controller that ends with ->with('success', ...) shows
 * nothing (found by the Inventory team, owner: "semua pasukan"). The settings controllers of this team use the toast.
 */
class SettingsToastFlashTest extends ClinicalTestCase
{
    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), ...$extra];
    }

    public function test_adding_diagnoses_and_medicine_options_flashes_a_success_toast(): void
    {
        $this->selectBranch($this->actor('administrator'));
        $this->post(route('diagnosis-presets.store'), $this->payload(['names' => ['Synthetic toast diagnosis']]))
            ->assertRedirect()->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Diagnoses added.']);
        $this->post(route('medicine-details.store', 'precaution'), $this->payload(['labels' => ['Synthetic toast option']]))
            ->assertRedirect()->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Options added.']);
    }

    public function test_no_settings_controller_of_this_team_flashes_the_unread_success_key(): void
    {
        foreach (['BusinessDetails', 'MedicineOptionSettings', 'DiagnosisPresetSettings', 'SupplierSettings', 'RegistrationSettings', 'PrescribingPhraseSettings'] as $name) {
            $path = base_path("app/Http/Controllers/{$name}Controller.php");
            if (! is_file($path)) {
                continue;
            }
            $source = (string) file_get_contents($path);
            $this->assertStringNotContainsString("->with('success'", $source, "{$name}Controller must flash the toast, not 'success'");
            $this->assertStringContainsString("Inertia::flash('toast'", $source, "{$name}Controller must flash a toast");
        }
    }
}
