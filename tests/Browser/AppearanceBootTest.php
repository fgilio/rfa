<?php

beforeEach(function () {
    $this->setUpTestRepo();
});

test('restores the selected appearance before Flux paints a cold page', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->script(<<<'JS'
        document.cookie = 'rfa_appearance=dark;path=/;max-age=31536000;SameSite=Lax';
        document.cookie = 'rfa_theme=light;path=/;max-age=31536000;SameSite=Lax';
        localStorage.removeItem('flux.appearance');
    JS);

    $page->refresh();
    $page->page()->waitForFunction(<<<'JS'
        document.documentElement.classList.contains('dark')
            && localStorage.getItem('flux.appearance') === 'dark'
            && document.cookie.includes('rfa_appearance=dark')
            && !document.cookie.includes('rfa_theme=')
    JS);

    $page->assertNoJavaScriptErrors();
});
