<?php

use App\DTOs\FileListEntry;
use App\Models\Project;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

const THRESHOLD_SMALL_LIFECYCLE = 500.0;
const THRESHOLD_LARGE_LIFECYCLE = 800.0;
const THRESHOLD_MULTI_HUNK_LIFECYCLE = 600.0;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/rfa_lifecycle_'.uniqid();
    mkdir($this->tempDir.'/src', 0755, true);

    initTestRepo($this->tempDir);

    // -- "before" state --
    file_put_contents($this->tempDir.'/src/Small.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Formatter;
use Illuminate\Support\Str;

class SmallService
{
    public function __construct(
        private readonly Formatter $formatter,
    ) {}

    public function format(string $input): string
    {
        $trimmed = trim($input);
        $lower = strtolower($trimmed);

        return $this->formatter->apply($lower);
    }

    public function slugify(string $value): string
    {
        return Str::slug($value);
    }

    public function isEmpty(string $value): bool
    {
        return $value === '';
    }

    public function reverse(string $value): string
    {
        return strrev($value);
    }

    public function length(string $value): int
    {
        return strlen($value);
    }
}
PHP);

    file_put_contents($this->tempDir.'/src/Large.php', generateLargePhpBefore());
    file_put_contents($this->tempDir.'/src/MultiHunk.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;
use Illuminate\Support\Collection;

class MultiHunkValidator
{
    /** @var array<string, callable> */
    private array $rules = [];

    private bool $strict = false;

    public function __construct(
        private readonly Collection $data,
    ) {}

    public function addRule(string $field, callable $rule): self
    {
        $this->rules[$field] = $rule;

        return $this;
    }

    public function strict(bool $enabled = true): self
    {
        $this->strict = $enabled;

        return $this;
    }

    public function validate(): array
    {
        $errors = [];

        foreach ($this->rules as $field => $rule) {
            $value = $this->data->get($field);
            $result = $rule($value);

            if ($result !== true) {
                $errors[$field] = $result;
            }
        }

        return $errors;
    }

    public function passes(): bool
    {
        return count($this->validate()) === 0;
    }

    public function fails(): bool
    {
        return ! $this->passes();
    }

    public function failOrReturn(): array
    {
        $errors = $this->validate();

        if (count($errors) > 0) {
            throw new ValidationException($errors);
        }

        return $this->data->all();
    }

    public function getFields(): array
    {
        return array_keys($this->rules);
    }

    public function hasRule(string $field): bool
    {
        return isset($this->rules[$field]);
    }

    public function removeRule(string $field): self
    {
        unset($this->rules[$field]);

        return $this;
    }
}
PHP);

    commitTestRepo($this->tempDir, 'initial commit');

    // -- "after" state (working-tree changes) --
    file_put_contents($this->tempDir.'/src/Small.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Formatter;
use App\Support\StringNormalizer;
use Illuminate\Support\Str;

class SmallService
{
    public function __construct(
        private readonly Formatter $formatter,
        private readonly StringNormalizer $normalizer,
    ) {}

    public function format(string $input): string
    {
        $normalized = $this->normalizer->normalize($input);

        return $this->formatter->apply($normalized);
    }

    public function slugify(string $value, string $separator = '-'): string
    {
        return Str::slug($value, $separator);
    }

    public function isEmpty(string $value): bool
    {
        return trim($value) === '';
    }

    public function reverse(string $value): string
    {
        return strrev($value);
    }

    public function length(string $value): int
    {
        return mb_strlen($value);
    }
}
PHP);

    file_put_contents($this->tempDir.'/src/Large.php', generateLargePhpAfter());
    file_put_contents($this->tempDir.'/src/MultiHunk.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class MultiHunkValidator
{
    /** @var array<string, callable> */
    private array $rules = [];

    private bool $strict = true;

    public function __construct(
        private readonly Collection $data,
        private readonly ?string $context = null,
    ) {}

    public function addRule(string $field, callable $rule): self
    {
        $this->rules[$field] = $rule;

        return $this;
    }

    public function strict(bool $enabled = true): self
    {
        $this->strict = $enabled;

        return $this;
    }

    public function validate(): array
    {
        $errors = [];

        foreach ($this->rules as $field => $rule) {
            $value = $this->data->get($field);
            $result = $rule($value);

            if ($result !== true) {
                $errors[$field] = $result;
            }
        }

        return $errors;
    }

    public function passes(): bool
    {
        return count($this->validate()) === 0;
    }

    public function fails(): bool
    {
        return ! $this->passes();
    }

    public function failOrReturn(): array
    {
        $errors = $this->validate();

        if (count($errors) > 0) {
            throw new ValidationException($errors);
        }

        return $this->data->all();
    }

    public function getFields(): array
    {
        return array_keys($this->rules);
    }

    public function hasRule(string $field): bool
    {
        return isset($this->rules[$field]);
    }

    public function removeRule(string $field): self
    {
        unset($this->rules[$field]);

        return $this;
    }

    public function ruleCount(): int
    {
        return count($this->rules);
    }

    public function log(): void
    {
        Log::info('Validator state', [
            'fields' => $this->getFields(),
            'strict' => $this->strict,
            'context' => $this->context,
        ]);
    }
}
PHP);

    $this->project = Project::create([
        'slug' => 'lifecycle-test',
        'name' => 'Lifecycle Test',
        'path' => $this->tempDir,
        'git_common_dir' => $this->tempDir.'/.git',
        'branch' => 'main',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->tempDir);
});

function measureE2eLifecycle(array $file, string $repoPath, int $projectId): float
{
    $component = Livewire::test('diff-file', [
        'file' => $file,
        'repoPath' => $repoPath,
        'projectId' => $projectId,
        'fileComments' => [],
    ]);

    $start = hrtime(true);
    $component->call('loadFileDiff');

    return (hrtime(true) - $start) / 1_000_000;
}

function makeFileEntry(string $path, int $additions, int $deletions): array
{
    return (new FileListEntry(
        path: $path,
        status: 'modified',
        oldPath: null,
        additions: $additions,
        deletions: $deletions,
        isBinary: false,
        isUntracked: false,
    ))->toArray();
}

// -- End-to-end lifecycle benchmarks --

test('small diff full lifecycle', function () {
    $file = makeFileEntry('src/Small.php', 5, 3);
    $ms = measureE2eLifecycle($file, $this->tempDir, $this->project->id);

    expect($ms)->toRenderWithin(THRESHOLD_SMALL_LIFECYCLE);
})->group('perf');

test('large diff full lifecycle', function () {
    $file = makeFileEntry('src/Large.php', 60, 40);
    $ms = measureE2eLifecycle($file, $this->tempDir, $this->project->id);

    expect($ms)->toRenderWithin(THRESHOLD_LARGE_LIFECYCLE);
})->group('perf');

test('multi-hunk diff full lifecycle', function () {
    $file = makeFileEntry('src/MultiHunk.php', 12, 3);
    $ms = measureE2eLifecycle($file, $this->tempDir, $this->project->id);

    expect($ms)->toRenderWithin(THRESHOLD_MULTI_HUNK_LIFECYCLE);
})->group('perf');

// -- Large.php content generators --

function generateLargePhpBefore(): string
{
    $methods = '';
    for ($i = 1; $i <= 20; $i++) {
        $methods .= <<<PHP

    public function transform{$i}(mixed \$value): mixed
    {
        return match (true) {
            is_string(\$value) => strtolower(trim(\$value)),
            is_int(\$value) => \$value * {$i},
            is_array(\$value) => array_map(fn (\$v) => \$v, \$value),
            is_bool(\$value) => ! \$value,
            default => \$value,
        };
    }

PHP;
    }

    return <<<PHP
<?php

declare(strict_types=1);

namespace App\\Pipeline;

use App\\Contracts\\Transformer;
use Illuminate\\Support\\Collection;

class LargeProcessor implements Transformer
{
    private Collection \$items;

    private int \$batchSize = 100;

    public function __construct(
        private readonly string \$name,
        private readonly array \$config = [],
    ) {
        \$this->items = collect();
    }

    public function getName(): string
    {
        return \$this->name;
    }

    public function setBatchSize(int \$size): self
    {
        \$this->batchSize = \$size;

        return \$this;
    }

    public function addItem(mixed \$item): self
    {
        \$this->items->push(\$item);

        return \$this;
    }

    public function process(): Collection
    {
        return \$this->items
            ->chunk(\$this->batchSize)
            ->flatMap(fn (Collection \$chunk) => \$chunk->map(
                fn (mixed \$item) => \$this->transformItem(\$item)
            ));
    }

    private function transformItem(mixed \$item): mixed
    {
        if (is_null(\$item)) {
            return null;
        }

        return \$item;
    }
{$methods}
    public function toArray(): array
    {
        return [
            'name' => \$this->name,
            'config' => \$this->config,
            'batchSize' => \$this->batchSize,
            'itemCount' => \$this->items->count(),
        ];
    }
}
PHP;
}

function generateLargePhpAfter(): string
{
    $methods = '';
    for ($i = 1; $i <= 20; $i++) {
        $body = $i <= 12
            ? <<<PHP
        \$normalized = match (true) {
            is_string(\$value) => mb_strtolower(trim(\$value)),
            is_int(\$value) => intval(\$value * {$i} + 1),
            is_float(\$value) => round(\$value, {$i}),
            is_array(\$value) => array_values(array_filter(\$value)),
            is_bool(\$value) => ! \$value,
            default => \$value,
        };

        return \$this->applyMiddleware(\$normalized);
PHP
            : <<<PHP
        return match (true) {
            is_string(\$value) => strtolower(trim(\$value)),
            is_int(\$value) => \$value * {$i},
            is_array(\$value) => array_map(fn (\$v) => \$v, \$value),
            is_bool(\$value) => ! \$value,
            default => \$value,
        };
PHP;

        $methods .= <<<PHP

    public function transform{$i}(mixed \$value): mixed
    {
{$body}
    }

PHP;
    }

    return <<<PHP
<?php

declare(strict_types=1);

namespace App\\Pipeline;

use App\\Contracts\\Transformer;
use App\\Support\\Middleware;
use Illuminate\\Support\\Collection;
use Illuminate\\Support\\Facades\\Log;

class LargeProcessor implements Transformer
{
    private Collection \$items;

    private int \$batchSize = 50;

    /** @var list<callable> */
    private array \$middleware = [];

    public function __construct(
        private readonly string \$name,
        private readonly array \$config = [],
        private readonly ?Middleware \$middlewareStack = null,
    ) {
        \$this->items = collect();
    }

    public function getName(): string
    {
        return \$this->name;
    }

    public function getConfig(string \$key, mixed \$default = null): mixed
    {
        return \$this->config[\$key] ?? \$default;
    }

    public function setBatchSize(int \$size): self
    {
        \$this->batchSize = max(1, \$size);

        return \$this;
    }

    public function addMiddleware(callable \$fn): self
    {
        \$this->middleware[] = \$fn;

        return \$this;
    }

    public function addItem(mixed \$item): self
    {
        \$this->items->push(\$item);
        Log::debug('Item added', ['processor' => \$this->name, 'count' => \$this->items->count()]);

        return \$this;
    }

    public function addItems(iterable \$items): self
    {
        foreach (\$items as \$item) {
            \$this->addItem(\$item);
        }

        return \$this;
    }

    public function process(): Collection
    {
        return \$this->items
            ->chunk(\$this->batchSize)
            ->flatMap(fn (Collection \$chunk) => \$chunk->map(
                fn (mixed \$item) => \$this->transformItem(\$item)
            ));
    }

    private function transformItem(mixed \$item): mixed
    {
        if (is_null(\$item)) {
            return null;
        }

        return \$this->applyMiddleware(\$item);
    }

    private function applyMiddleware(mixed \$value): mixed
    {
        foreach (\$this->middleware as \$fn) {
            \$value = \$fn(\$value);
        }

        return \$value;
    }
{$methods}
    public function count(): int
    {
        return \$this->items->count();
    }

    public function toArray(): array
    {
        return [
            'name' => \$this->name,
            'config' => \$this->config,
            'batchSize' => \$this->batchSize,
            'itemCount' => \$this->items->count(),
            'middlewareCount' => count(\$this->middleware),
        ];
    }
}
PHP;
}
