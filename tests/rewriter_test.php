<?php

// Plain-PHP checks for sievefolders_rewriter. Run: php tests/rewriter_test.php

require __DIR__ . '/../sievefolders_rewriter.php';

$failures = 0;

function check(string $name, $actual, $expected): void
{
    global $failures;

    if ($actual === $expected) {
        echo "ok   {$name}\n";
    } else {
        $failures++;
        echo "FAIL {$name}\n     expected " . var_export($expected, true) . "\n     got      " . var_export($actual, true) . "\n";
    }
}

$r = 'sievefolders_rewriter';

$script = <<<'SIEVE'
require ["fileinto","imap4flags"];
# rule:[Marketing]
if exists "list-unsubscribe"
{
	fileinto "Marketing";
	stop;
}
# rule:[Shops]
if header :contains "from" "shop"
{
	addflag "\\Seen";
	fileinto :copy "Marketing/Shops";
}
# rule:[Other]
if header :contains "subject" "Marketing"
{
	fileinto "MarketingArchive";
	fileinto :create "Marketing";
}
# rule:[Flags]
if true
{
	fileinto :flags "\\Seen" "Marketing";
}
SIEVE;

check('targets', $r::targets($script), ['Marketing', 'Marketing/Shops', 'MarketingArchive']);

[$out, $n] = $r::rename($script, 'Marketing', 'INBOX/Marketing', '/');
check('rename: count (folder, child, :create, :flags)', $n, 4);
check('rename: plain', str_contains($out, 'fileinto "INBOX/Marketing";'), true);
check('rename: child with :copy', str_contains($out, 'fileinto :copy "INBOX/Marketing/Shops";'), true);
check('rename: :create kept', str_contains($out, 'fileinto :create "INBOX/Marketing";'), true);
check('rename: :flags kept', str_contains($out, 'fileinto :flags "\\\\Seen" "INBOX/Marketing";'), true);
check('rename: prefix-only name untouched', str_contains($out, 'fileinto "MarketingArchive";'), true);
check('rename: header test value untouched', str_contains($out, '"subject" "Marketing"'), true);
check('rename: rule comment untouched', str_contains($out, '# rule:[Marketing]'), true);
check('rename: nothing else changed', str_replace('INBOX/Marketing', 'Marketing', $out), $script);

[$same, $n] = $r::rename($script, 'Nope', 'Other', '/');
check('rename: no match is a no-op', [$same, $n], [$script, 0]);

$quoted = 'fileinto "Odd \\"name\\" \\\\ here";';
[$out, $n] = $r::rename($quoted, 'Odd "name" \\ here', 'New "one"', '/');
check('rename: escaped quotes/backslashes round-trip', [$out, $n], ['fileinto "New \\"one\\"";', 1]);

check('references: folder and children', $r::references($script, 'Marketing', '/'), 2);
check('references: none', $r::references($script, 'Receipts', '/'), 0);

echo $failures ? "\n{$failures} failure(s)\n" : "\nall passed\n";
exit($failures ? 1 : 0);
