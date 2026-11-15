<?php

namespace Tests\Unit;

use App\Domain\Organisation\Website\WebsiteOutputPlanFields;
use PHPUnit\Framework\TestCase;

class WebsiteOutputPlanAliasesTest extends TestCase
{
    public function test_unknown_alias_keys_keep_the_key_and_fall_back_without_a_lookup(): void
    {
        $this->assertSame([
            ['key' => 'item-frozen', 'kind' => 'service', 'alias' => 'frozen-service', 'name' => 'Frozen English name'],
            ['key' => 'item-missing', 'kind' => 'branch', 'alias' => 'missing-branch', 'name' => 'Unknown item'],
        ], WebsiteOutputPlanFields::aliasRows([
            'item-frozen' => ['kind' => 'service', 'alias' => 'frozen-service'],
            'item-missing' => ['kind' => 'branch', 'alias' => 'missing-branch'],
        ], [['key' => 'item-frozen', 'kind' => 'service', 'name' => 'Frozen English name']]));
    }
}
