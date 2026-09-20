<?php
declare(strict_types=1);

/**
 * Superuser password hash generator.
 *
 * Produces a one-way bcrypt hash to paste into config.php
 * (superuser.password_hash). The plaintext is never stored.
 *
 * Usage:
 *   php portal/bin/hash.php                 # prompts securely for a password
 *   php portal/bin/hash.php 'MyPassw0rd!'   # hashes the given argument
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This utility runs from the command line only.\n");
}

$password = $argv[1] ?? null;

if ($password === null) {
    fwrite(STDOUT, "Enter superuser password: ");
    // Try to disable echo for hidden input; fall back to visible input.
    $hidden = @shell_exec('stty -echo 2>/dev/null');
    $password = trim((string)fgets(STDIN));
    @shell_exec('stty echo 2>/dev/null');
    fwrite(STDOUT, "\nConfirm password: ");
    @shell_exec('stty -echo 2>/dev/null');
    $confirm = trim((string)fgets(STDIN));
    @shell_exec('stty echo 2>/dev/null');
    fwrite(STDOUT, "\n");
    if ($password !== $confirm) {
        fwrite(STDERR, "Passwords did not match.\n");
        exit(1);
    }
}

if ($password === '') {
    fwrite(STDERR, "Password must not be empty.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_BCRYPT);

echo "\nOne-way password hash (paste into config.php superuser.password_hash):\n\n";
echo $hash . "\n\n";
