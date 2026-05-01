{{-- Small bordered mono-typed label. Used for path/hash chips in the
     submit bar, the commit-context bar, and elsewhere chrome wants to
     surface a technical token without making it look like a button.

     Pass content via the default slot. The component sets the layout
     defaults; consumers can extend via class attribute (e.g. truncate,
     shrink-0). --}}
<span {{ $attributes->class(['font-mono text-xs text-gh-muted px-2 py-0.5 rounded border border-gh-border']) }}>
    {{ $slot }}
</span>
