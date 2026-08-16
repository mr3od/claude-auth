<?php

namespace App\Services;

use App\DataTransferObjects\AccountSnapshot;

final class SnapshotCodec
{
    public function filename(string $accountKey): string
    {
        $fileKey = preg_match('/^[a-zA-Z0-9._-]+$/', $accountKey) === 1
            ? $accountKey
            : rtrim(strtr(base64_encode($accountKey), '+/', '-_'), '=');

        return "{$fileKey}.snapshot.json";
    }

    /**
     * @return array<string, mixed>
     */
    public function encode(AccountSnapshot $snapshot): array
    {
        return $snapshot->toSnapshotFile();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function decode(array $data): AccountSnapshot
    {
        return AccountSnapshot::fromSnapshotFile($data);
    }
}
