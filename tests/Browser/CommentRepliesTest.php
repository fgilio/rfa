<?php

use App\Models\Comment;
use App\Models\CommentReply;
use App\Models\Project;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->setUpTestRepo();
});

function addReplyTestRoot($page, string $body = 'Reply root'): void
{
    $file = $page->page()->locator('.group:has([data-testid="file-header"]:has-text("hello.php"))');
    $file->getByTestId('diff-line-number')->first()->click();
    $file->getByPlaceholder('Write a comment', false)->first()->fill($body);
    $file->getByRole('button', ['name' => 'Save'])->first()->click();
    $page->assertSee($body);
}

test('adds, edits, copies, and deletes an inline review reply', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    addReplyTestRoot($page);

    $thread = $page->page()->getByTestId('comment-thread')->first();
    $thread->getByRole('button', ['name' => 'Reply'])->click();
    $input = $thread->getByPlaceholder('Write a reply', false);
    $input->waitFor();

    expect($page->page()->evaluate(
        "document.activeElement === document.querySelector('[placeholder^=\"Write a reply\"]')",
    ))->toBeTrue();

    $input->fill('First reply');
    $input->press('Meta+Enter');
    $page->assertSee('First reply');
    expect($page->page()->evaluate(
        'document.activeElement?.matches(\'[data-testid="reply-to-comment"]\')',
    ))->toBeTrue();

    $thread->getByLabel('Edit reply')->click();
    $thread->getByPlaceholder('Write a reply', false)->fill('Edited reply');
    $thread->getByRole('button', ['name' => 'Save'])->click();
    $page->assertSee('Edited reply');
    $page->assertSee('(edited)');

    $thread->getByLabel('Copy reply')->click();
    $page->assertSee('Edited reply');

    $thread->getByLabel('Delete reply')->click();
    $page->assertDontSee('Edited reply');
    $page->assertSee('Reply deleted');

    $page->page()->getByTestId('undo-button')->click();
    $page->assertSee('Edited reply');
});

test('root deletion and undo restore its visible replies', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    addReplyTestRoot($page, 'Thread root');

    $thread = $page->page()->getByTestId('comment-thread')->first();
    $thread->getByRole('button', ['name' => 'Reply'])->click();
    $thread->getByPlaceholder('Write a reply', false)->fill('Keep this reply');
    $thread->getByRole('button', ['name' => 'Save'])->click();
    $page->assertSee('Keep this reply');

    $page->page()->getByLabel('Delete comment')->first()->click();
    $page->assertDontSee('Thread root');
    $page->page()->getByTestId('undo-button')->click();

    $page->assertSee('Thread root');
    $page->assertSee('Keep this reply');
});

test('reply and root undos remain stacked in LIFO order', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    addReplyTestRoot($page, 'Stack root');

    $thread = $page->page()->getByTestId('comment-thread')->first();
    $thread->getByRole('button', ['name' => 'Reply'])->click();
    $thread->getByPlaceholder('Write a reply', false)->fill('Stack reply');
    $thread->getByRole('button', ['name' => 'Save'])->click();

    $thread->getByLabel('Delete reply')->click();
    $page->assertSee('Reply deleted');
    $page->page()->getByLabel('Delete comment')->first()->click();
    $page->assertSee('Comment deleted');

    $page->page()->getByTestId('undo-button')->click();
    $page->assertSee('Stack root');
    $page->assertSee('Reply deleted');

    $page->page()->getByTestId('undo-button')->click();
    $page->assertSee('Stack reply');
});

test('adds a reply under a context comment', function () {
    File::put($this->testRepoPath.'/AGENTS.md', "# Instructions\nKeep this current.\n");

    $page = $this->visitAndLoad($this->projectUrl().'/context');
    $file = $page->page()->locator('.group:has([data-testid="file-header"]:has-text("AGENTS.md"))');
    $file->getByTestId('diff-line-number')->first()->click();
    $file->getByPlaceholder('Write a comment', false)->fill('Context root');
    $file->getByRole('button', ['name' => 'Save'])->click();

    $thread = $file->getByTestId('comment-thread')->first();
    $thread->getByRole('button', ['name' => 'Reply'])->click();
    $thread->getByPlaceholder('Write a reply', false)->fill('Context reply');
    $thread->getByRole('button', ['name' => 'Save'])->click();

    $page->assertSee('Context reply');
});

test('replies to a submitted thread in the drawer without closing it', function () {
    $project = Project::query()->where('slug', $this->testProjectSlug)->firstOrFail();
    $comment = Comment::factory()->for($project)->create([
        'repo_path' => $project->path,
        'file_path' => 'hello.php',
        'body' => 'Submitted thread',
        'submitted_at' => now(),
    ]);
    CommentReply::factory()->for($comment)->agent()->create([
        'body' => 'Existing agent answer',
    ]);

    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('All comments · ⌘J')->click();
    $panel = $page->page()->getByTestId('overlay-panel-comments-drawer');
    $panel->waitFor(['state' => 'visible']);
    $page->page()->evaluate(
        'document.querySelector(\'[aria-label="Show submitted comments"]\').click()',
    );
    $page->assertSee('Submitted thread');
    $panel->getByText('Submitted thread', true)->click();
    $page->assertSee('Codex');
    expect($panel->getByLabel('Edit reply')->count())->toBe(0);
    $panel->getByTestId('reply-to-comment')->click();
    $drawerThread = $panel->getByTestId('comment-thread');
    $drawerThread->getByPlaceholder('Write a reply', false)->fill('Human drawer reply');
    $drawerThread->getByRole('button', ['name' => 'Save'])->click();

    $panel->waitFor(['state' => 'visible']);
    $page->assertSee('Human drawer reply');

    $panel->getByPlaceholder('Filter comments...')->fill('codex-cli');
    $page->page()->waitForFunction(<<<'JS'
        document.querySelector('[data-testid="overlay-panel-comments-drawer"]')
            ?.textContent?.includes('Submitted thread')
    JS);
});
