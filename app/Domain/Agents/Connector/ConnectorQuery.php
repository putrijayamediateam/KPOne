<?php

namespace App\Domain\Agents\Connector;

use App\Domain\Organisation\Models\Branch;

/**
 * One connector question (docs/agents/MCP-CONNECTOR-DESIGN.md section 6). It reads only through ConnectorReads (the agent tool
 * gateway), keeps an explicit list of the keys it returns (never a pass-through of a data source it does not understand) and
 * never returns a person. The runner calls it once for each branch the Director works at, or once with no branch when it is
 * organisation-wide.
 */
interface ConnectorQuery
{
    /** The MCP tool name, e.g. `clinic_day_summary`. */
    public function name(): string;

    /** @return list<string> the agent data source codes it reads (the connector agent's allowlist) */
    public function sources(): array;

    /** @return array<string, mixed> Laravel validation rules for the input (the branch code is validated by the runner) */
    public function rules(): array;

    /** Plain words that say what was counted, so the answer is worded honestly. */
    public function definitions(): string;

    /**
     * Rules that need several fields together (such as a date range). Runs before anything is counted or recorded.
     *
     * @param  array<string, mixed>  $input  validated
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function check(array $input): void;

    public function organisationWide(): bool;

    /**
     * @param  array<string, mixed>  $input  validated
     * @return array<string, mixed>
     */
    public function run(ConnectorReads $reads, ?Branch $branch, array $input): array;
}
