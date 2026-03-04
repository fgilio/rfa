<?php

function initTestRepo(string $dir): void
{
    exec(implode(' && ', [
        'cd '.escapeshellarg($dir),
        'git init -b main',
        "git config user.email 'test@rfa.test'",
        "git config user.name 'RFA Test'",
        'git config commit.gpgsign false',
    ]));
}

function commitTestRepo(string $dir, string $message = 'commit'): void
{
    exec(implode(' && ', [
        'cd '.escapeshellarg($dir),
        'git add -A',
        'git commit -m '.escapeshellarg($message),
    ]));
}
