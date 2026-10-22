<?php

namespace Tests\Feature;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Patient\Services\PatientDirectoryService;
use App\Domain\Visit\Services\VisitDirectoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Visit\VisitTestCase;

class WhatsAppLinkBoundaryTest extends VisitTestCase
{
    public function test_patient_projection_adds_only_contact_eligibility_and_reads_do_not_audit_clicks(): void
    {
        $ca = $this->actor();
        $this->selectBranch($ca);
        $patient = $this->patient($ca);
        $visit = $this->register($ca, $patient);
        $before = DB::table('audit_logs')->orderBy('id')->get()->toJson();
        $detail = app(PatientDirectoryService::class)->detail($ca, $patient);
        $this->assertSame(['canWhatsapp', 'patientNumber', 'identity', 'contact', 'address', 'identifiers', 'administrative', 'activity', 'can'], array_keys($detail));
        $this->assertTrue($detail['canWhatsapp']);
        $this->assertSame(['mobilePhone' => $patient->mobile_phone, 'email' => $patient->email], $detail['contact']);
        $visitDetail = app(VisitDirectoryService::class)->detail($ca, $visit);
        $this->assertSame(['patientNumber', 'fullName', 'dateOfBirth', 'sex'], array_keys($visitDetail['patient']));
        $this->assertSame($before, DB::table('audit_logs')->orderBy('id')->get()->toJson());
        foreach (['administrator', 'technical_admin', 'marketing', 'business_development', 'finance_officer', 'hr_manager', 'queue_display'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->get(route('patients.show', $patient))->assertForbidden();
        }
    }

    public function test_one_authorised_redirect_adds_no_permission_migration_or_outbound_integration(): void
    {
        $contactRoutes = [];
        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'whatsapp')) {
                $contactRoutes[] = [$route->uri(), $route->getName(), $route->methods()];
            }
        }
        $this->assertSame([['patient-contact/{context}/{key}/whatsapp', 'patients.whatsapp', ['GET', 'HEAD']]], $contactRoutes);
        foreach (PermissionCatalogue::all() as $permission) {
            $this->assertDoesNotMatchRegularExpression('/whatsapp|wa[._-]01/i', $permission);
        }
        foreach (glob(database_path('migrations/*.php')) ?: [] as $migration) {
            $this->assertDoesNotMatchRegularExpression('/whatsapp|wa[._-]01|wa\.me/i', basename($migration).' '.file_get_contents($migration));
        }
        foreach (['app', 'routes'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($directory), \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() === 'php') {
                    $source = file_get_contents($file->getPathname());
                    $this->assertDoesNotMatchRegularExpression('/(?:Http::|curl_|file_get_contents\s*\(\s*[\'"]https:).*wa\.me/i', $source);
                }
            }
        }
    }
}
