<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Services\OpcacheService;

/**
 * In-memory stand-in for the opcache extension, which the test CLI does not
 * enable. Records every compile request so tests can assert on it.
 */
final class FakeOpcacheService extends OpcacheService
{
    /** @var list<string> */
    public array $compiles = [];

    /**
     * @param  list<string>  $included  scripts reported as loaded by the request
     * @param  list<string>  $cached  scripts reported as already in shared memory
     * @param  list<string>  $failing  scripts whose compile is reported as failed
     */
    public function __construct(
        private readonly bool $enabled = true,
        private readonly array $included = [],
        private readonly array $cached = [],
        private readonly array $failing = [],
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function includedScripts(): array
    {
        return $this->included;
    }

    public function isCached(string $path): bool
    {
        return in_array($path, $this->cached, true);
    }

    public function compile(string $path): bool
    {
        $this->compiles[] = $path;

        return ! in_array($path, $this->failing, true);
    }
}
