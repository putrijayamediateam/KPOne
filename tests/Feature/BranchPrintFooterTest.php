<?php

namespace Tests\Feature;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Organisation\BusinessDetails\BranchDocumentFooter;
use App\Domain\Organisation\BusinessDetails\BranchLetterheadService;
use App\Domain\Organisation\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;

class BranchPrintFooterTest extends VisitTestCase
{
    private function command(array $overrides = []): array
    {
        return [...['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'footer_text' => "Synthetic print footer\nContact clinic@footer.test"], ...$overrides];
    }

    public function test_only_settings_holders_can_edit_footer_without_identity_edit_permission(): void
    {
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $command = $this->command(['lock_version' => BranchDocumentFooter::query()->value('lock_version') ?? 0]);
            $response = $this->post('/business-details/footer', $command);
            if (in_array($role, ['director', 'administrator', 'finance_officer', 'panel_officer'], true)) {
                $response->assertRedirect(route('business-details.index'))->assertSessionDoesntHaveErrors();
                $this->assertSame($command['footer_text'], app(BranchLetterheadService::class)->forActiveBranch($actor)->footerText);
            } else {
                $response->assertForbidden();
            }
        }
        $this->assertDatabaseCount('branch_document_footers', 1);
        $this->assertDatabaseCount('branch_business_profiles', 0);
        $this->assertDatabaseCount('organisation_business_profiles', 0);
    }

    public function test_retries_conflicts_stale_versions_and_values_free_audit(): void
    {
        $this->selectBranch($this->actor('finance_officer'));
        $command = $this->command();
        $this->post('/business-details/footer', $command)->assertSessionDoesntHaveErrors();
        $this->post('/business-details/footer', $command)->assertSessionDoesntHaveErrors();
        $this->assertDatabaseCount('business_detail_commands', 1);
        $this->post('/business-details/footer', [...$command, 'footer_text' => 'Changed'])->assertSessionHasErrors('idempotency_key');
        $this->post('/business-details/footer', $this->command())->assertSessionHasErrors('lock_version');
        $audit = DB::table('audit_logs')->where('event', 'business_details.print_footer_saved')->sole();
        $this->assertStringNotContainsString('clinic@footer.test', $audit->metadata);
        $this->assertStringNotContainsString('Synthetic print footer', $audit->metadata);
        $this->assertEqualsCanonicalizing(['changed_fields', 'record_version'], array_keys(json_decode($audit->metadata, true)));
        $this->post('/business-details/footer', $this->command(['lock_version' => 1, 'footer_text' => '']))->assertSessionDoesntHaveErrors();
        $this->assertNull(BranchDocumentFooter::query()->sole()->text);
        $this->assertSame(2, BranchDocumentFooter::query()->sole()->lock_version);
    }

    public function test_plain_text_limits_branch_isolation_and_revoked_permissions(): void
    {
        $actor = $this->actor('panel_officer');
        $this->selectBranch($actor);
        foreach ([str_repeat('x', 1001), '<script>alert(1)</script>', "bad\0text"] as $text) {
            $this->post('/business-details/footer', $this->command(['footer_text' => $text]))->assertSessionHasErrors('footer_text');
        }
        $other = Branch::query()->where('id', '!=', $this->branch->id)->firstOrFail();
        $this->post('/business-details/footer', $this->command(['expected_branch_id' => $other->id]))->assertSessionHasErrors('expected_branch_id');
        $this->assertDatabaseCount('branch_document_footers', 0);
        $this->post('/business-details/footer', $this->command(['footer_text' => 'Terms: A & B < 10']))->assertSessionDoesntHaveErrors();
        $actor->roles()->detach();
        $actor->unsetRelation('roles');
        $this->post('/business-details/footer', $this->command(['lock_version' => 1]))->assertForbidden();
    }
}
