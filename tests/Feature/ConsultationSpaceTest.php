<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Queue\QueueTestCase;

/** DS-6: the Consultation space shows only the doctor's own queue, as the Queue board already shows it. */
class ConsultationSpaceTest extends QueueTestCase
{
    public function test_a_doctor_with_nobody_waiting_sees_empty_widgets_and_the_primary_action(): void
    {
        $doctor = $this->doctor();
        $this->selectBranch($doctor);

        $this->get('/spaces/consultation')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Spaces/Show')
            ->where('consultation.waiting.total', 0)
            ->where('consultation.waiting.rows', [])
            ->where('consultation.held.total', 0)
            ->where('consultation.primaryAction', ['title' => 'Open my queue', 'href' => '/queue']));
    }

    public function test_only_the_doctors_own_waiting_patients_show_with_queue_numbers_short_names_and_a_cap(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $other = $this->actor('resident_doctor', $this->branch);
        foreach (['Ahmad Ismail bin Ali', 'Nurul Huda binti Hassan', 'Tan Mei Ling', 'Siva Kumar a/l Raju', 'Aisyah Rahman', 'Lim Wei Jie'] as $i => $name) {
            $visit = $this->register($ca, $this->patient($ca, ['full_name' => $name]), ['visit_type' => 'consultation', 'assigned_doctor_user_id' => $doctor->id, 'visit_reason' => 'Synthetic reason '.$i]);
            $this->send($ca, $visit);
        }
        $this->send($ca, $this->consultationVisit($ca, $other));

        $this->selectBranch($doctor);
        $props = $this->get('/spaces/consultation')->assertOk()->viewData('page')['props']['consultation'];
        $this->assertSame(6, $props['waiting']['total']);
        $this->assertCount(5, $props['waiting']['rows']);
        foreach ($props['waiting']['rows'] as $row) {
            $this->assertMatchesRegularExpression('/^A-\d{3}$/', $row['queueNumber']);
            $this->assertStringStartsWith('/visits/', $row['href']);
        }
        $this->assertStringNotContainsString('Ismail', json_encode($props));
    }

    public function test_the_ask_answers_follow_the_same_permission_and_a_ca_is_refused_the_space(): void
    {
        $doctor = $this->doctor();
        $this->selectBranch($doctor);
        $this->get('/spaces/consultation')->assertInertia(fn (Assert $p) => $p->has('questions', 2));
        $this->postJson('/spaces/consultation/ask', ['question' => 'consultation.longest_waiting'])
            ->assertOk()->assertJsonPath('sentence', 'Nobody is waiting for you.')->assertJsonPath('href', '/queue');
        $this->postJson('/spaces/consultation/ask', ['question' => 'consultation.not_ready'])
            ->assertOk()->assertJsonPath('sentence', 'None of your waiting patients is marked not ready to call.');

        $this->selectBranch($this->actor('ca'));
        $this->get('/spaces/consultation')->assertForbidden();
        $this->postJson('/spaces/consultation/ask', ['question' => 'consultation.longest_waiting'])->assertForbidden();
    }
}
