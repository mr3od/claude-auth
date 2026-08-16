<?php

namespace App\Commands;

use App\DataTransferObjects\AccountRecord;
use App\DataTransferObjects\SelectorResolutionBatch;
use App\Services\Registry;
use LaravelZero\Framework\Commands\Command;

class RemoveCommand extends Command
{
    protected $signature = 'remove {selectors?* : Row numbers, aliases, or email substrings to remove}
                            {--all : Remove every stored account}
                            {--force : Skip the confirmation prompt}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Remove one or more stored accounts';

    public function handle(Registry $registry): int
    {
        $json = (bool) $this->option('json');
        $all = (bool) $this->option('all');
        $selectors = $this->argument('selectors');

        if ($all && $selectors !== []) {
            return $this->usageError('Cannot combine --all with explicit selectors.', $json);
        }

        if (! $all && $selectors === []) {
            return $this->usageError('Provide at least one selector, or use --all.', $json);
        }

        if ($all) {
            $accounts = $registry->listAccounts()->accounts;

            if ($accounts === []) {
                return $this->finish([], $json, 'No accounts to remove.');
            }

            $keys = array_map(fn (AccountRecord $a) => $a->accountKey, $accounts);
            $labels = array_map(fn (AccountRecord $a) => $this->label($a), $accounts);
        } else {
            $batch = $registry->resolveMany($selectors);

            if (! $batch->allResolved()) {
                return $this->reportUnresolved($batch, $json);
            }

            $keys = $batch->resolvedKeys();
            $labels = array_map(fn ($r) => $this->label($r->match), $batch->bySelector);
        }

        if (! $this->confirmRemoval($labels, $json)) {
            return $this->finish([], $json, 'Removal cancelled, nothing was removed.');
        }

        $result = $registry->remove($keys);

        return $this->finish($result->removedKeys, $json, null);
    }

    private function label(AccountRecord $account): string
    {
        return "{$account->displayLabel()} ({$account->email})";
    }

    /**
     * @param  string[]  $labels
     */
    private function confirmRemoval(array $labels, bool $json): bool
    {
        if ($this->option('force') || $json) {
            return true;
        }

        $this->line('About to remove:');
        foreach ($labels as $label) {
            $this->line("  - {$label}");
        }

        return $this->confirm('Remove these accounts?');
    }

    private function reportUnresolved(SelectorResolutionBatch $batch, bool $json): int
    {
        if ($json) {
            $report = [];
            foreach ($batch->bySelector as $selector => $resolution) {
                $report[$selector] = strtolower($resolution->status->name);
            }

            $this->line(json_encode([
                'error' => 'selector_resolution_failed',
                'selectors' => $report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->error('Not every selector resolved to exactly one account. Nothing was removed.');
        foreach ($batch->bySelector as $selector => $resolution) {
            $this->line("  {$selector}: ".strtolower($resolution->status->name));
        }

        return self::FAILURE;
    }

    /**
     * @param  string[]  $removedKeys
     */
    private function finish(array $removedKeys, bool $json, ?string $message): int
    {
        if ($json) {
            $this->line(json_encode(['removed' => $removedKeys], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info($message ?? 'Removed '.count($removedKeys).' account(s).');

        return self::SUCCESS;
    }

    private function usageError(string $message, bool $json): int
    {
        if ($json) {
            $this->line(json_encode(['error' => 'usage', 'message' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->error($message);
        }

        return self::INVALID;
    }
}
