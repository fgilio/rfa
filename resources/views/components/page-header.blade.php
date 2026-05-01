{{--
    Sticky page-top chrome shared by every primary page (review, context,
    and any future surface). Owns three responsibilities:

    1. The frosted bar styling (bg-gh-bg/80 backdrop-blur).
    2. The sticky positioning + z-index above the diff content.
    3. Publishing its rendered height as the --header-h CSS variable so
       resizable-sidebar-shell and other sticky offsets can pin themselves
       below the bar without hard-coding a number.

    Slots:
    - $above   optional content rendered above the bar but still inside
               the sticky/observed wrapper (used for the update banner).
    - $slot    the bar contents (typically two flex children, left + right).
    - $below   optional content rendered below the bar inside the same
               wrapper. Used by review-page for the status-strip, which
               must contribute to the published --header-h height so
               the resizable shell pins below it.
--}}

<div
    class="sticky top-0 z-50"
    x-data
    x-init="
        const update = () => document.documentElement.style.setProperty('--header-h', $el.offsetHeight + 'px');
        update();
        new ResizeObserver(update).observe($el);
    "
>
    {{ $above ?? '' }}

    <header class="bg-gh-bg/80 backdrop-blur-sm border-b border-gh-border px-5 py-3.5 flex items-center justify-between">
        {{ $slot }}
    </header>

    {{ $below ?? '' }}
</div>
