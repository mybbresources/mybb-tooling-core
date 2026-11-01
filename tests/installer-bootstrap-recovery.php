<?php

test('bootstrap installs journal transaction directories before package writes', function () use ($tmp): void {
    $target = $tmp . '/bootstrap-transaction-site';
    mkdir($target . '/inc', 0755, true);
    mkdir($target . '/install', 0755, true);
    file_put_contents($target . '/inc/config.default.php', '<?php');
    file_put_contents($target . '/inc/class_core.php', '<?php $version = "1.8.41";');
    file_put_contents($target . '/global.php', '<?php');
    file_put_contents($target . '/install/index.php', '<?php');
    [$archive, $manifest, $keys] = package();
    $transactions = [];
    $result = (new MyBB\Tooling\PackageManager($target, $target . '/inc/plugins/data/mybb-tooling', $keys))
        ->installBootstrap($archive, $manifest, null, static function (string $path) use (&$transactions): void {
            $transactions[] = $path;
        });
    unlink($archive);
    eq(count($transactions), 1);
    eq($transactions[0], $result['transaction']);
    eq(is_dir($transactions[0]), true);
});

test('installer recovery accepts prior ledger digests and removes only journaled bootstrap transactions', function () use ($tmp): void {
    $target = $tmp . '/bootstrap-recovery-site';
    $relative = 'inc/plugins/data/mybb-tooling/transactions/mybb-toolchain-20261028120000-a1b2c3';
    $transaction = $target . '/' . $relative;
    mkdir($transaction . '/stage', 0700, true);
    mkdir($transaction . '/backup', 0700, true);
    mkdir($target . '/inc/plugins/data/mybb-tooling/transactions/untracked-operator-data', 0700, true);
    file_put_contents($transaction . '/stage/file.tmp', 'partial stage');
    file_put_contents($transaction . '/backup/installed.json', 'prior backup');
    file_put_contents($target . '/inc/plugins/data/mybb-tooling/transactions/untracked-operator-data/keep.txt', 'keep');
    $ledger = $target . '/inc/plugins/data/mybb-tooling/installed.json';
    file_put_contents($ledger, 'first-package-ledger');
    $journalPath = $target . '.mybb-install-transaction.json';
    $journal = [
        'schema_version' => 1,
        'target' => realpath($target),
        'status' => 'bootstrap_applying',
        'files' => [[
            'path' => 'inc/plugins/data/mybb-tooling/installed.json',
            'sha256' => hash('sha256', 'second-package-ledger'),
            'sha256_variants' => [hash('sha256', 'first-package-ledger'), hash('sha256', 'second-package-ledger')],
            'status' => 'planned',
            'component' => 'bootstrap_state',
        ]],
        'directories' => [],
        'bootstrap_transactions' => [$relative],
    ];
    file_put_contents($journalPath, json_encode($journal, JSON_THROW_ON_ERROR));
    $entry = dirname(__DIR__) . '/mybb-installer-cli/bin/mybb-install';
    $pipes = [];
    $process = proc_open([PHP_BINARY, $entry, 'recover', '--path=' . $target, '--rollback', '--json'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start installer recovery');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    eq(proc_close($process), 0);
    eq($stderr, '');
    $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    eq($result['status'], 'rolled_back');
    eq($result['removed'], ['inc/plugins/data/mybb-tooling/installed.json']);
    eq($result['removed_transactions'], [$relative]);
    eq(is_dir($transaction), false);
    eq(file_get_contents($target . '/inc/plugins/data/mybb-tooling/transactions/untracked-operator-data/keep.txt'), 'keep');
});

test('installer rollback preserves a journaled package transaction containing a symlink', function () use ($tmp): void {
    $target = $tmp . '/bootstrap-symlink-recovery-site';
    $outside = $tmp . '/bootstrap-symlink-recovery-outside';
    $relative = 'inc/plugins/data/mybb-tooling/transactions/mybb-toolchain-20261028120001-b2c3d4';
    $transaction = $target . '/' . $relative;
    mkdir($transaction . '/stage', 0700, true);
    mkdir($outside, 0700, true);
    file_put_contents($outside . '/keep.txt', 'outside');
    symlink($outside, $transaction . '/stage/escape');
    $ledger = $target . '/inc/plugins/data/mybb-tooling/installed.json';
    file_put_contents($ledger, 'ledger');
    $journalPath = $target . '.mybb-install-transaction.json';
    file_put_contents($journalPath, json_encode([
        'schema_version' => 1,
        'target' => realpath($target),
        'status' => 'bootstrap_applying',
        'files' => [[
            'path' => 'inc/plugins/data/mybb-tooling/installed.json',
            'sha256' => hash('sha256', 'ledger'),
            'status' => 'applied',
            'component' => 'bootstrap_state',
        ]],
        'directories' => [],
        'bootstrap_transactions' => [$relative],
    ], JSON_THROW_ON_ERROR));
    $entry = dirname(__DIR__) . '/mybb-installer-cli/bin/mybb-install';
    $pipes = [];
    $process = proc_open([PHP_BINARY, $entry, 'recover', '--path=' . $target, '--rollback', '--json'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start installer recovery');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    eq(proc_close($process), 1);
    eq($stderr, '');
    $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    eq($result['status'], 'rollback_incomplete');
    eq($result['preserved_transactions'], [$relative]);
    eq(is_dir($transaction), true);
    eq(file_get_contents($outside . '/keep.txt'), 'outside');
});

test('legacy installer rollback reports but does not delete unjournaled package transactions', function () use ($tmp): void {
    $target = $tmp . '/legacy-bootstrap-recovery-site';
    $relative = 'inc/plugins/data/mybb-tooling/transactions/mybb-toolchain-20261028120002-c3d4e5';
    $transaction = $target . '/' . $relative;
    mkdir($transaction . '/backup', 0700, true);
    file_put_contents($transaction . '/backup/unknown.bin', 'preserve legacy recovery data');
    $ledger = $target . '/inc/plugins/data/mybb-tooling/installed.json';
    file_put_contents($ledger, 'ledger');
    $journalPath = $target . '.mybb-install-transaction.json';
    file_put_contents($journalPath, json_encode([
        'schema_version' => 1,
        'target' => realpath($target),
        'status' => 'bootstrap_applying',
        'files' => [[
            'path' => 'inc/plugins/data/mybb-tooling/installed.json',
            'sha256' => hash('sha256', 'ledger'),
            'status' => 'applied',
            'component' => 'bootstrap_state',
        ]],
        'directories' => [],
    ], JSON_THROW_ON_ERROR));
    $entry = dirname(__DIR__) . '/mybb-installer-cli/bin/mybb-install';
    $pipes = [];
    $process = proc_open([PHP_BINARY, $entry, 'recover', '--path=' . $target, '--rollback', '--json'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start installer recovery');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    eq(proc_close($process), 1);
    eq($stderr, '');
    $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    eq($result['status'], 'rollback_incomplete');
    eq($result['preserved_transactions'], [$relative]);
    eq(file_get_contents($transaction . '/backup/unknown.bin'), 'preserve legacy recovery data');
});

test('installer resume removes digest-matching bootstrap writes and only journaled transactions', function () use ($tmp): void {
    $target = $tmp . '/resume-bootstrap-site';
    $relative = 'inc/plugins/data/mybb-tooling/transactions/mybb-toolchain-20261028120003-d4e5f6';
    $untracked = 'inc/plugins/data/mybb-tooling/transactions/operator-keep';
    mkdir($target . '/inc/plugins/data/mybb-tooling/transactions/' . basename($relative) . '/backup', 0700, true);
    mkdir($target . '/' . $untracked, 0700, true);
    $ledgerPath = $target . '/inc/plugins/data/mybb-tooling/installed.json';
    file_put_contents($ledgerPath, 'prior-package-ledger');
    file_put_contents($target . '/' . $relative . '/backup/installed.json', 'previous ledger');
    file_put_contents($target . '/' . $untracked . '/keep.txt', 'operator data');
    $journal = [
        'status' => 'bootstrap_applying',
        'files' => [[
            'path' => 'inc/plugins/data/mybb-tooling/installed.json',
            'sha256' => hash('sha256', 'current-package-ledger'),
            'sha256_variants' => [hash('sha256', 'prior-package-ledger')],
            'component' => 'bootstrap_state',
        ], ['path' => 'inc/core.php', 'sha256' => hash('sha256', 'core'), 'component' => 'mybb_core']],
        'bootstrap_transactions' => [$relative],
    ];
    $journalPath = $target . '.mybb-install-transaction.json';
    $persist = static function (string $path, array $value): void { file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR)); };
    MyBB\Installer\InstallRecovery::removeBootstrapWritesForResume($target, $journalPath, $journal, $persist);
    eq(is_file($ledgerPath), false);
    eq(is_dir($target . '/' . $relative), false);
    eq(file_get_contents($target . '/' . $untracked . '/keep.txt'), 'operator data');
    eq($journal['bootstrap_transactions'], []);
    eq($journal['bootstrap_transactions_removed'], [$relative]);
    eq(count($journal['files']), 1);
    eq($journal['files'][0]['path'], 'inc/core.php');
    eq($journal['status'], 'applying');
    eq(json_decode(file_get_contents($journalPath), true, 512, JSON_THROW_ON_ERROR), $journal);
});

test('installer resume preserves modified bootstrap writes and transaction recovery data', function () use ($tmp): void {
    $target = $tmp . '/resume-bootstrap-modified-site';
    $relative = 'inc/plugins/data/mybb-tooling/transactions/mybb-toolchain-20261028120004-e5f6a7';
    mkdir($target . '/inc/plugins/data/mybb-tooling/transactions/' . basename($relative), 0700, true);
    $path = $target . '/inc/plugins/data/mybb-tooling/installed.json';
    $matchingPath = $target . '/inc/plugins/data/mybb-tooling/index.html';
    file_put_contents($path, 'operator edit');
    file_put_contents($matchingPath, 'installer-owned marker');
    file_put_contents($target . '/' . $relative . '/partial.bin', 'recovery data');
    $journal = ['status' => 'bootstrap_applying', 'files' => [[
        'path' => 'inc/plugins/data/mybb-tooling/installed.json',
        'sha256' => hash('sha256', 'installer ledger'),
        'component' => 'bootstrap_state',
    ], [
        'path' => 'inc/plugins/data/mybb-tooling/index.html',
        'sha256' => hash('sha256', 'installer-owned marker'),
        'component' => 'bootstrap_package',
    ]], 'bootstrap_transactions' => [$relative]];
    $persisted = false;
    $persist = static function () use (&$persisted): void { $persisted = true; };
    try {
        MyBB\Installer\InstallRecovery::removeBootstrapWritesForResume($target, $target . '.journal.json', $journal, $persist);
        throw new RuntimeException('Resume unexpectedly removed a modified file');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'changed or unsafe paths')) throw $error;
    }
    eq(file_get_contents($path), 'operator edit');
    eq(file_get_contents($matchingPath), 'installer-owned marker');
    eq(is_dir($target . '/' . $relative), true);
    eq($persisted, false);
});

test('installer resume preserves symlinked transaction data and can retry after operator review', function () use ($tmp): void {
    $target = $tmp . '/resume-bootstrap-symlink-site';
    $outside = $tmp . '/resume-bootstrap-symlink-outside';
    $relative = 'inc/plugins/data/mybb-tooling/transactions/mybb-toolchain-20261028120005-f6a7b8';
    mkdir($target . '/inc/plugins/data/mybb-tooling/transactions/' . basename($relative) . '/stage', 0700, true);
    mkdir($outside, 0700, true);
    $path = $target . '/inc/plugins/data/mybb-tooling/installed.json';
    file_put_contents($path, 'owned ledger');
    file_put_contents($outside . '/keep.txt', 'outside');
    symlink($outside, $target . '/' . $relative . '/stage/escape');
    $journal = ['status' => 'bootstrap_applying', 'files' => [[
        'path' => 'inc/plugins/data/mybb-tooling/installed.json',
        'sha256' => hash('sha256', 'owned ledger'),
        'component' => 'bootstrap_state',
    ]], 'bootstrap_transactions' => [$relative]];
    $persist = static function (string $path, array $value): void { file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR)); };
    try {
        MyBB\Installer\InstallRecovery::removeBootstrapWritesForResume($target, $target . '.journal.json', $journal, $persist);
        throw new RuntimeException('Resume unexpectedly removed a symlinked transaction tree');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'transaction recovery data was preserved')) throw $error;
    }
    eq(is_file($path), false);
    eq(is_link($target . '/' . $relative . '/stage/escape'), true);
    eq(file_get_contents($outside . '/keep.txt'), 'outside');
    unlink($target . '/' . $relative . '/stage/escape');
    MyBB\Installer\InstallRecovery::removeBootstrapWritesForResume($target, $target . '.journal.json', $journal, $persist);
    eq(is_dir($target . '/' . $relative), false);
    eq($journal['bootstrap_transactions'], []);
    eq(file_get_contents($outside . '/keep.txt'), 'outside');
});
