<?php

use Livewire\Attributes\Layout;
use Livewire\Component;
use Native\Desktop\Facades\Shell;

new #[Layout('layouts.app')] class extends Component
{
    public function mount(): void
    {
        if (config('nativephp-internal.running')) {
            \Native\Desktop\Facades\Window::get('main')->title('rfa');
        }
    }

    private const ALLOWED_EXTERNAL_URLS = [
        'https://x.com/fgili0',
        'https://github.com/fgilio/rfa',
    ];

    public function openExternal(string $url): void
    {
        if (in_array($url, self::ALLOWED_EXTERNAL_URLS, true)) {
            Shell::openExternal($url);
        }
    }
};
?>

<div class="min-h-screen flex flex-col">
    @native
        <livewire:update-banner />
    @endnative

    <header class="sticky top-0 z-50 bg-gh-bg/80 backdrop-blur-sm border-b border-gh-border px-6 py-4 flex items-center justify-between">
        <div class="flex items-baseline gap-2">
            <span class="rfa-logo text-2xl">rfa</span>
            @native
                <span class="font-mono text-xs text-gh-muted">v{{ config('nativephp.version') }}</span>
            @endnative
        </div>
        <div class="flex items-center gap-3">
            <livewire:theme-switcher />
        </div>
    </header>

    <main class="flex-1 flex items-center justify-center px-6">
        <div class="text-center">
            <p class="rfa-logo text-5xl text-gh-muted/30 mb-6">rfa</p>
            <flux:heading class="mb-3">No projects yet</flux:heading>
            @native
                <flux:text variant="subtle" size="sm" class="mb-6">Open a git repository or scan a folder to get started</flux:text>
                <livewire:add-project-menu variant="expanded" />
            @else
                <flux:text variant="subtle" size="sm">Run <code class="font-mono bg-gh-border/50 px-1.5 py-0.5 rounded text-xs">rfa</code> from a git repository to get started</flux:text>
            @endnative
        </div>
    </main>

    <footer class="py-2 flex items-center justify-center gap-1.5 font-mono text-[11px] text-gh-muted/40">
        <x-external-link href="https://x.com/fgili0" class="hover:text-gh-muted transition-colors">Franco Gilio</x-external-link>
        <span>&middot;</span>
        <x-external-link href="https://github.com/fgilio/rfa" class="hover:text-gh-muted transition-colors">PRs welcome</x-external-link>
    </footer>
</div>
