<?php

namespace App\Domain\Organisation\Website;

final class WebsiteReleaseProjection
{
    public const FIELDS = [
        'branch_public_profile' => ['display_name', 'address_line_1', 'address_line_2', 'postcode', 'city', 'state', 'public_phone', 'whatsapp_number', 'public_email', 'map_url', 'hours_note', 'parking_notes', 'accessibility_notes'],
        'doctor_profile' => ['display_name', 'professional_title', 'biography', 'public_registration_text', 'qualifications', 'areas_of_practice', 'languages_spoken', 'sort_order'],
        'career_posting' => ['title', 'summary', 'location_note', 'apply_url', 'apply_email', 'responsibilities', 'requirements', 'employment_type', 'closing_date', 'sort_order'],
        'service_category' => ['name', 'short_description', 'sort_order'],
        'public_service' => ['name', 'short_summary', 'seo_title', 'seo_description', 'price_text', 'what_to_expect', 'suitable_for', 'sort_order'],
        'promotion' => ['title', 'short_summary', 'offer_text', 'valid_from', 'valid_until', 'terms', 'sort_order'],
        'activity' => ['title', 'short_summary', 'location_note', 'starts_on', 'ends_on', 'sort_order'],
        'faq' => ['question', 'answer', 'sort_order'],
        'little_shield' => ['title', 'tagline', 'introduction', 'overview_paragraphs', 'medical_safeguards', 'partnership_introduction', 'how_to_take_part'],
    ];

    /** @param array<string,mixed> $content
     * @return array<string,mixed>
     */
    public static function content(string $type, array $content): array
    {
        $out = array_intersect_key($content, array_flip(self::FIELDS[$type]));
        foreach (['description_sections' => ['heading', 'body'], 'programme_pillars' => ['title', 'description'], 'programme_flow_steps' => ['title', 'description'], 'partner_types' => ['title', 'description'], 'contact_blocks' => ['label', 'text']] as $key => $fields) {
            $allowed = $key === 'description_sections' ? in_array($type, ['public_service', 'promotion', 'activity'], true) : ($key === 'contact_blocks' ? $type === 'branch_public_profile' : $type === 'little_shield');
            if ($allowed) {
                $out[$key] = array_map(fn ($v) => array_intersect_key($v, array_flip($fields)), $content[$key] ?? []);
            }
        }
        if ($type === 'branch_public_profile') {
            $out['opening_hours'] = [];
            foreach (WebsiteProfileFields::DAYS as $day) {
                $out['opening_hours'][$day] = array_intersect_key($content['opening_hours'][$day] ?? [], array_flip(['closed', 'open', 'close']));
            }
        }

        return $out;
    }
}
