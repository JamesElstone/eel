<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

function eel_project_git_writeln(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function eel_project_git_error(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
}

function eel_project_git_usage(): void
{
    eel_project_git_writeln('Usage:');
    eel_project_git_writeln('  php tools/php/projectGit.php init <project-origin-url> [upstream-name] [branch]');
    eel_project_git_writeln('  php tools/php/projectGit.php import [upstream-name] [branch] [--rebase]');
    eel_project_git_writeln();
    eel_project_git_writeln('Examples:');
    eel_project_git_writeln('  php tools/php/projectGit.php init git@github.com:you/grocery.git');
    eel_project_git_writeln('  php tools/php/projectGit.php import');
    eel_project_git_writeln('  php tools/php/projectGit.php import upstream main --rebase');
}

function eel_project_git_run(array $arguments): int
{
    if (PHP_SAPI !== 'cli') {
        eel_project_git_error('This tool can only be run from the command line.');
        return 1;
    }

    chdir(dirname(__DIR__, 2));

    $command = (string)($arguments[1] ?? '');

    try {
        if ($command === 'init') {
            return eel_project_git_init($arguments);
        }

        if ($command === 'import') {
            return eel_project_git_import($arguments);
        }

        eel_project_git_usage();
        return $command === '' ? 0 : 1;
    } catch (Throwable $exception) {
        eel_project_git_error($exception->getMessage());
        return 1;
    }
}

function eel_project_git_init(array $arguments): int
{
    $projectOriginUrl = trim((string)($arguments[2] ?? ''));
    $upstreamName = trim((string)($arguments[3] ?? 'upstream'));
    $branch = trim((string)($arguments[4] ?? eel_project_git_current_branch()));

    if ($projectOriginUrl === '') {
        throw new InvalidArgumentException('Missing project origin URL.');
    }

    eel_project_git_assert_repository();
    eel_project_git_assert_clean_worktree();
    eel_project_git_assert_remote_name($upstreamName);

    if (!eel_project_git_remote_exists($upstreamName)) {
        if (!eel_project_git_remote_exists('origin')) {
            throw new RuntimeException('This repository does not have an origin remote to rename as upstream.');
        }

        eel_project_git_exec(['git', 'remote', 'rename', 'origin', $upstreamName]);
        eel_project_git_writeln('Renamed origin remote to ' . $upstreamName . '.');
    }

    if (eel_project_git_remote_exists('origin')) {
        throw new RuntimeException('An origin remote already exists. Project Git setup appears to have been run already.');
    }

    eel_project_git_exec(['git', 'remote', 'add', 'origin', $projectOriginUrl]);
    eel_project_git_writeln('Added project origin remote: ' . $projectOriginUrl);

    eel_project_git_exec(['git', 'push', '-u', 'origin', $branch]);
    eel_project_git_writeln('Pushed ' . $branch . ' to project origin.');
    eel_project_git_writeln('-EOL-');

    return 0;
}

function eel_project_git_import(array $arguments): int
{
    $useRebase = in_array('--rebase', $arguments, true);
    $positionals = array_values(array_filter(
        array_slice($arguments, 2),
        static fn(string $argument): bool => $argument !== '--rebase'
    ));

    $upstreamName = trim((string)($positionals[0] ?? 'upstream'));
    $branch = trim((string)($positionals[1] ?? eel_project_git_current_branch()));

    eel_project_git_assert_repository();
    eel_project_git_assert_clean_worktree();
    eel_project_git_assert_remote_name($upstreamName);

    if (!eel_project_git_remote_exists($upstreamName)) {
        throw new RuntimeException('Remote was not found: ' . $upstreamName);
    }

    eel_project_git_exec(['git', 'fetch', $upstreamName]);

    $upstreamRef = $upstreamName . '/' . $branch;
    if ($useRebase) {
        eel_project_git_exec(['git', 'rebase', $upstreamRef]);
        eel_project_git_writeln('Rebased current branch onto ' . $upstreamRef . '.');
    } else {
        eel_project_git_exec(['git', 'merge', $upstreamRef]);
        eel_project_git_writeln('Merged ' . $upstreamRef . ' into current branch.');
    }

    eel_project_git_writeln('-EOL-');

    return 0;
}

function eel_project_git_assert_repository(): void
{
    eel_project_git_exec(['git', 'rev-parse', '--is-inside-work-tree'], false);
}

function eel_project_git_assert_clean_worktree(): void
{
    $status = eel_project_git_exec(['git', 'status', '--porcelain'], false);

    if (trim($status) !== '') {
        throw new RuntimeException('Git worktree is not clean. Commit or stash local changes first.');
    }
}

function eel_project_git_current_branch(): string
{
    $branch = trim(eel_project_git_exec(['git', 'branch', '--show-current'], false));

    if ($branch === '') {
        throw new RuntimeException('Could not detect the current Git branch.');
    }

    return $branch;
}

function eel_project_git_remote_exists(string $name): bool
{
    $remotes = preg_split('/\R/', trim(eel_project_git_exec(['git', 'remote'], false))) ?: [];

    return in_array($name, $remotes, true);
}

function eel_project_git_assert_remote_name(string $name): void
{
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
        throw new InvalidArgumentException('Remote name contains unsupported characters: ' . $name);
    }
}

function eel_project_git_exec(array $command, bool $echoCommand = true): string
{
    $escaped = array_map('escapeshellarg', $command);
    $commandLine = implode(' ', $escaped) . ' 2>&1';

    if ($echoCommand) {
        eel_project_git_writeln('$ ' . implode(' ', $command));
    }

    exec($commandLine, $output, $exitCode);
    $text = implode(PHP_EOL, $output);

    if ($exitCode !== 0) {
        throw new RuntimeException($text !== '' ? $text : 'Command failed: ' . implode(' ', $command));
    }

    if ($echoCommand && $text !== '') {
        eel_project_git_writeln($text);
    }

    return $text;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(eel_project_git_run($argv));
}
