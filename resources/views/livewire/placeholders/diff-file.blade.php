<div data-rfa-render-blocker class="group">
    <div data-rfa-static-file-header class="rfa-lazy-header">
        <div class="rfa-lazy-main">
            <span class="rfa-lazy-chevron" aria-hidden="true">
                @if($isReviewed)
                    <span class="rfa-lazy-icon rfa-lazy-icon--chevron-right size-4" data-rfa-static-icon="chevron-right" aria-hidden="true"></span>
                @else
                    <span class="rfa-lazy-icon rfa-lazy-icon--chevron-down size-4" data-rfa-static-icon="chevron-down" aria-hidden="true"></span>
                @endif
            </span>

            <span class="rfa-lazy-path" title="{{ $title }}">
                @if($oldPath)
                    <span class="rfa-lazy-old-path">{{ $oldPath }}&nbsp;→&nbsp;</span>
                @endif
                <span class="rfa-lazy-directory">{{ $directory }}</span>{{ $basename }}
            </span>

            @if($isSymlink)
                <span class="rfa-lazy-icon rfa-lazy-icon--link" data-rfa-static-icon="link" aria-hidden="true"></span>
                <span class="rfa-lazy-link-label">→ {{ $symlinkTarget }}</span>
            @endif
        </div>

        <div class="rfa-lazy-meta">
            <div class="rfa-lazy-actions">
                <span class="rfa-lazy-button rfa-lazy-icon--copy-path" data-rfa-static-icon="copy-path" aria-hidden="true"></span>
                @if($showContentCopy)
                    <span class="rfa-lazy-button rfa-lazy-icon--copy-content" data-rfa-static-icon="copy-content" aria-hidden="true"></span>
                @endif
                @if($showDiscard)
                    <span class="rfa-lazy-button rfa-lazy-icon--discard" data-rfa-static-icon="discard" aria-hidden="true"></span>
                @endif
            </div>

            @if($additions > 0)
                <span class="text-gh-green">+{{ $additions }}</span>
            @endif
            @if($deletions > 0)
                <span class="text-gh-red">-{{ $deletions }}</span>
            @endif

            <div class="rfa-lazy-comments">
                <span class="rfa-lazy-button rfa-lazy-icon--comment" data-rfa-static-icon="comment" aria-hidden="true"></span>
                @if($commentsCount > 0)
                    <span class="rfa-lazy-comment-count">{{ $commentsCount }}</span>
                @endif
            </div>

            <span @class(['rfa-lazy-checkbox', 'rfa-lazy-checkbox--reviewed' => $isReviewed]) aria-hidden="true"></span>
        </div>
    </div>
</div>
