<?php

namespace App\Domain\Agents\Proposals;

use Ramsey\Uuid\Uuid;

/** Finds the preparer for a proposal type. Domain teams tag their `PreparesWork` class with `ProposalPreparers::TAG`. */
final class ProposalPreparers
{
    public const TAG = 'agents.proposal_preparers';

    private const NAMESPACE = '6f1c7a52-3a44-5b0e-9c2d-1d7a4e0b8f31';

    public function for(string $type): ?PreparesWork
    {
        foreach (app()->tagged(self::TAG) as $preparer) {
            if ($preparer instanceof PreparesWork && in_array($type, $preparer->types(), true)) {
                return $preparer;
            }
        }

        return null;
    }

    /** The retry key a domain service uses for the record a proposal prepares: one proposal, one record. */
    public static function retryKey(int $proposalId): string
    {
        return Uuid::uuid5(self::NAMESPACE, 'ai-proposal:'.$proposalId)->toString();
    }
}
