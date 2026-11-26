<?php

namespace Tests\Feature\Queue;

class QueueWorkloadValidationTest extends QueueTestCase
{
    public function test_malformed_search_values_are_validation_errors_and_short_numeric_search_remains_valid(): void
    {
        $doctor = $this->doctor();
        $this->selectBranch($doctor);
        foreach ([['x'], ['nested' => 'x'], 'ab'] as $query) {
            $this->getJson('/queue/workload?'.http_build_query([
                'expected_branch_id' => $this->branch->id, 'section' => 'held', 'query' => $query,
            ]))->assertUnprocessable()->assertJsonValidationErrors('query');
        }
        foreach (['1', 'Synthetic'] as $query) {
            $this->getJson('/queue/workload?'.http_build_query([
                'expected_branch_id' => $this->branch->id, 'section' => 'held', 'query' => $query,
            ]))->assertOk()->assertJsonPath('total', 0);
        }
    }
}
