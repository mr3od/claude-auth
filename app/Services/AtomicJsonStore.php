<?php

namespace App\Services;

final class AtomicJsonStore
{
    public function __construct(
        private readonly string $backupDir,
        private readonly int $maxBackups,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function readJson(string $path): array
    {
        $data = $this->readJsonOrNull($path);

        if ($data === null) {
            throw new \RuntimeException("File not found or unreadable: {$path}");
        }

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readJsonOrNull(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function writeJsonAtomic(string $path, array $data, int $permissions = 0600): void
    {
        $dir = dirname($path);
        $tempPath = $dir.'/.'.basename($path).'.tmp.'.bin2hex(random_bytes(6));

        file_put_contents($tempPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        chmod($tempPath, $permissions);
        rename($tempPath, $path);
    }

    public function backupIfChanged(string $path, string $baseName): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $latest = $this->newestBackup($baseName);

        if ($latest !== null && file_get_contents($latest) === file_get_contents($path)) {
            return null;
        }

        return $this->writeBackup($path, $baseName);
    }

    public function backupUnconditional(string $path, string $baseName): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        return $this->writeBackup($path, $baseName);
    }

    /**
     * @return string[]
     */
    public function pruneBackups(string $baseName): array
    {
        $backups = $this->backupsSortedOldestFirst($baseName);
        $toDelete = array_slice($backups, 0, max(0, count($backups) - $this->maxBackups));

        foreach ($toDelete as $path) {
            unlink($path);
        }

        return $toDelete;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function writeJsonPreservingPermissions(string $path, array $data): void
    {
        $permissions = is_file($path) ? fileperms($path) & 0777 : 0600;

        $this->writeJsonAtomic($path, $data, $permissions);
    }

    /**
     * Replaces exactly one top-level key in a JSON file, leaving every other
     * field byte-identical - including empty objects. `json_decode(...,
     * true)` can't tell an empty JSON object from an empty array, so a
     * decode-to-array-then-encode round trip silently turns every `{}` in
     * the file into `[]`. Decoding without `assoc` keeps that distinction.
     *
     * @param  array<string, mixed>  $value
     */
    public function mergeTopLevelKey(string $path, string $key, array $value): void
    {
        $decoded = is_file($path) ? json_decode(file_get_contents($path)) : new \stdClass;
        $decoded->{$key} = json_decode(json_encode($value));

        $permissions = is_file($path) ? fileperms($path) & 0777 : 0600;

        $dir = dirname($path);
        $tempPath = $dir.'/.'.basename($path).'.tmp.'.bin2hex(random_bytes(6));

        file_put_contents($tempPath, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        chmod($tempPath, $permissions);
        rename($tempPath, $path);
    }

    public function replacePreservingPermissions(string $sourcePath, string $destPath): void
    {
        $permissions = is_file($destPath) ? fileperms($destPath) & 0777 : 0600;

        copy($sourcePath, $destPath);
        chmod($destPath, $permissions);
    }

    private function writeBackup(string $path, string $baseName): string
    {
        $timestamp = date('Ymd-His');
        $backupPath = "{$this->backupDir}/{$baseName}.bak.{$timestamp}";

        $suffix = 1;
        while (is_file($backupPath)) {
            $backupPath = "{$this->backupDir}/{$baseName}.bak.{$timestamp}.{$suffix}";
            $suffix++;
        }

        copy($path, $backupPath);

        return $backupPath;
    }

    private function newestBackup(string $baseName): ?string
    {
        $backups = $this->backupsSortedOldestFirst($baseName);

        return $backups === [] ? null : end($backups);
    }

    /**
     * @return string[]
     */
    private function backupsSortedOldestFirst(string $baseName): array
    {
        $matches = glob("{$this->backupDir}/{$baseName}.bak.*") ?: [];

        usort($matches, fn (string $a, string $b) => filemtime($a) <=> filemtime($b));

        return $matches;
    }
}
