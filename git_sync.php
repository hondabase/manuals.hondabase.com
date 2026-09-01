<?php

define('MANUALS_ARCHIVE_DIR', '/var/www/manuals.hondabase.com/cars');
define('MANUALS_PUSH_FLAG', '/var/www/manuals.hondabase.com/push_failed');

/** Run git inside the manuals archive working tree. Returns [exitCode, output]. */
function manuals_git_run(array $args): array {
    $cmd = 'git -C ' . escapeshellarg(MANUALS_ARCHIVE_DIR);
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg($a);
    exec($cmd . ' 2>&1', $output, $code);
    return [$code, implode("\n", $output)];
}

/** Stage everything, commit with message, attempt push. Returns true if commit succeeded. */
function manuals_git_commit_all(string $message): bool {
    manuals_git_run(['add', '-A']);
    [$code, $out] = manuals_git_run(['commit', '-m', $message]);
    if ($code !== 0) {
        if (!str_contains($out, 'nothing to commit')) error_log("manuals git commit failed: $out");
        return false;
    }
    manuals_git_try_push();
    return true;
}

function manuals_git_try_push(): bool {
    [$code, $out] = manuals_git_run(['push', 'origin', 'main']);
    if ($code !== 0) {
        error_log("manuals git push failed: $out");
        file_put_contents(MANUALS_PUSH_FLAG, date('c') . "\n" . $out . "\n");
        return false;
    }
    if (is_file(MANUALS_PUSH_FLAG)) unlink(MANUALS_PUSH_FLAG);
    return true;
}
