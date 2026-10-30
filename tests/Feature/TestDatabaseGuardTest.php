<?php

use Symfony\Component\Process\Process;

it('refuses a file-backed application database before a child test can touch it', function () {
    $path = tempnam(sys_get_temp_dir(), 'smart-market-guard-');
    $before = hash_file('sha256', $path);
    try {
        $process = new Process([PHP_BINARY, 'artisan', 'test', '--compact', 'tests/Feature/ExampleTest.php'], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $path, 'DB_URL' => '',
        ]);
        $process->setTimeout(30);
        $process->run();
        expect($process->getExitCode())->not->toBe(0);
        expect($process->getOutput().$process->getErrorOutput())->toContain('Refusing database refresh');
        expect(hash_file('sha256', $path))->toBe($before);
    } finally {
        unlink($path);
    }
});
