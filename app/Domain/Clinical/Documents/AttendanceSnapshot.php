<?php

namespace App\Domain\Clinical\Documents;

use App\Domain\Organisation\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AttendanceSnapshot
{
    public const RENDERER_VERSION = 'attendance-a4-v1';

    public const TEXT_LIMITS = ['recipient' => 200];

    /** @param array<string, mixed> $source
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(Branch $branch, array $source, array $input, string $number, string $at): array
    {
        $template = DB::table('attendance_template_revisions')->where('language', $input['language'])->where('revision', 1)->sole();
        $w = json_decode($template->wording, true, flags: JSON_THROW_ON_ERROR);
        $recipient = $input['recipient'] ?? '';
        $sections = [];
        if ($recipient !== '') {
            $sections[] = [$w['recipient'], $recipient];
        }
        $statement = strtr($w['statement'], [':patient' => $source['patient']['name'], ':clinic' => $source['letterhead']['details']['clinic_name'], ':date' => $source['examDate']]);
        $sections[] = ['', $statement];
        $sections[] = ['', $w['limitation']];
        if ($recipient !== '') {
            $sections[] = ['', $w['consent']];
        }
        $snapshot = ['schemaVersion' => 1, 'rendererVersion' => self::RENDERER_VERSION, 'templateId' => $template->id, 'templateRevision' => 1, 'templateWording' => $w, 'productionApproved' => false, 'language' => $input['language'], 'title' => $w['title'], 'number' => $number, 'issuedAtUtc' => $at, 'issueDate' => CarbonImmutable::parse($at)->setTimezone($branch->timezone)->format('d/m/Y'), 'timezone' => $branch->timezone, 'patient' => $source['patient'], 'visit' => $source['visit'], 'examDate' => $source['examDate'], 'doctor' => $source['doctor'], 'letterhead' => $source['letterhead'], 'recipient' => $recipient, 'consent' => $recipient !== '' && (bool) $input['consent'], 'sourceToken' => $source['token'], 'evidence' => 'pre-signature', 'pages' => app(ReferralPagination::class)->pages($sections)];
        $snapshot['html'] = view('attendance.content', ['snapshot' => $snapshot])->render();

        return $snapshot;
    }
}
