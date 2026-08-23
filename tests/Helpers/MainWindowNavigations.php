<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Mockery;
use Native\Desktop\Facades\Window as WindowFacade;
use Native\Desktop\Windows\Window;

/**
 * Every URL the app navigated the main window to.
 *
 * `Window::get('main')->url(...)` is how each entry point — deep link, native
 * menu item, terminal open request — lands on a page, so the mocking of that
 * pair lives here once and tests assert against what was collected.
 */
final class MainWindowNavigations
{
    /** @var list<string> */
    private array $urls = [];

    public static function capture(): self
    {
        $navigations = new self;

        $window = Mockery::mock(Window::class);
        $window->shouldReceive('url')->andReturnUsing(
            fn (string $url) => $navigations->record($url),
        );

        WindowFacade::shouldReceive('get')->with('main')->andReturn($window);

        return $navigations;
    }

    public function record(string $url): void
    {
        $this->urls[] = $url;
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->urls;
    }

    /** The page the app ended up on, or null when it never navigated. */
    public function latest(): ?string
    {
        return $this->urls === [] ? null : $this->urls[array_key_last($this->urls)];
    }
}
