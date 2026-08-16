<?php

use App\Services\AtomicJsonStore;
use Tests\Feature\Concerns\UsesFakeClaudeHome;

uses(UsesFakeClaudeHome::class);

beforeEach(function () {
    $this->useFakeClaudeHome();
    $this->backupDir = $this->fakeBaseDir.'/backups';
    mkdir($this->backupDir, recursive: true);
    $this->store = new AtomicJsonStore($this->backupDir, maxBackups: 3);
    $this->targetPath = $this->fakeHome.'/registry.json';
});

afterEach(fn () => $this->cleanupFakeClaudeHome());

it('writes json atomically and reads it back identical', function () {
    $data = ['schema_version' => 1, 'accounts' => []];

    $this->store->writeJsonAtomic($this->targetPath, $data);

    expect($this->store->readJson($this->targetPath))->toBe($data);
});

it('sets the given permissions on a newly written file', function () {
    $this->store->writeJsonAtomic($this->targetPath, ['a' => 1], permissions: 0600);

    expect(substr(sprintf('%o', fileperms($this->targetPath)), -4))->toBe('0600');
});

it('leaves no temp file behind after a successful atomic write', function () {
    $this->store->writeJsonAtomic($this->targetPath, ['a' => 1]);

    $entries = array_values(array_diff(scandir($this->fakeHome), ['.', '..']));

    expect($entries)->toBe(['registry.json']);
});

it('returns null from readJson when the file does not exist', function () {
    expect($this->store->readJsonOrNull($this->targetPath))->toBeNull();
});

it('backs up only when content differs via backupIfChanged', function () {
    file_put_contents($this->targetPath, json_encode(['v' => 1]));

    $first = $this->store->backupIfChanged($this->targetPath, 'registry.json');
    expect($first)->not->toBeNull();
    expect(glob($this->backupDir.'/registry.json.bak.*'))->toHaveCount(1);

    // Unchanged content on disk (we never rewrote $this->targetPath) -> no new backup.
    $second = $this->store->backupIfChanged($this->targetPath, 'registry.json');
    expect($second)->toBeNull();
    expect(glob($this->backupDir.'/registry.json.bak.*'))->toHaveCount(1);
});

it('backupIfChanged is a no-op when the source file does not exist', function () {
    expect($this->store->backupIfChanged($this->targetPath, 'registry.json'))->toBeNull();
    expect(glob($this->backupDir.'/registry.json.bak.*'))->toHaveCount(0);
});

it('backupUnconditional always writes a backup even when content is unchanged', function () {
    file_put_contents($this->targetPath, json_encode(['v' => 1]));

    $this->store->backupUnconditional($this->targetPath, 'registry.json');
    $this->store->backupUnconditional($this->targetPath, 'registry.json');

    expect(glob($this->backupDir.'/registry.json.bak.*'))->toHaveCount(2);
});

it('backupUnconditional is a no-op when the source file does not exist yet', function () {
    expect($this->store->backupUnconditional($this->targetPath, 'registry.json'))->toBeNull();
    expect(glob($this->backupDir.'/registry.json.bak.*'))->toHaveCount(0);
});

it('prunes backups to the newest maxBackups, oldest deleted first', function () {
    file_put_contents($this->targetPath, 'seed');

    for ($i = 0; $i < 5; $i++) {
        $this->store->backupUnconditional($this->targetPath, 'registry.json');
        usleep(1100000); // ensure distinct second-resolution timestamps
    }

    expect(glob($this->backupDir.'/registry.json.bak.*'))->toHaveCount(5);

    $deleted = $this->store->pruneBackups('registry.json');

    expect($deleted)->toHaveCount(2)
        ->and(glob($this->backupDir.'/registry.json.bak.*'))->toHaveCount(3);
})->group('slow');

it('replacePreservingPermissions copies content but keeps the destination file mode', function () {
    $src = $this->fakeBaseDir.'/source.json';
    $dest = $this->fakeCredentialsFile;

    file_put_contents($src, json_encode(['claudeAiOauth' => ['accessToken' => 'new']]));
    file_put_contents($dest, json_encode(['claudeAiOauth' => ['accessToken' => 'old']]));
    chmod($dest, 0644);

    $this->store->replacePreservingPermissions($src, $dest);

    expect(json_decode(file_get_contents($dest), true))
        ->toBe(['claudeAiOauth' => ['accessToken' => 'new']])
        ->and(substr(sprintf('%o', fileperms($dest)), -4))->toBe('0644');
});

it('replacePreservingPermissions defaults to 0600 when the destination did not exist yet', function () {
    $src = $this->fakeBaseDir.'/source.json';
    $dest = $this->fakeBaseDir.'/brand-new.json';

    file_put_contents($src, json_encode(['a' => 1]));

    $this->store->replacePreservingPermissions($src, $dest);

    expect(substr(sprintf('%o', fileperms($dest)), -4))->toBe('0600');
});
