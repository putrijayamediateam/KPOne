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
    public function test_existing_patient_and_visit_projections_do_not_gain_a_prop_or_click_audit(): void
    {
        $ca = $this->actor();
        $this->selectBranch($ca);
        $patient = $this->patient($ca);
        $visit = $this->register($ca, $patient);
        $before = DB::table('audit_logs')->orderBy('id')->get()->toJson();
        $detail = app(PatientDirectoryService::class)->detail($ca, $patient);
        $this->assertSame(['patientNumber', 'identity', 'contact', 'address', 'identifiers', 'administrative', 'activity', 'can'], array_keys($detail));
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

    public function test_link_only_adds_no_route_permission_migration_or_server_integration(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertDoesNotMatchRegularExpression('/whatsapp|wa[._-]01|wa\.me/i', $route->uri().' '.$route->getName());
        }
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
                    $this->assertDoesNotMatchRegularExpression('/whatsapp|wa\.me/i', file_get_contents($file->getPathname()));
                }
            }
        }
    }
}
