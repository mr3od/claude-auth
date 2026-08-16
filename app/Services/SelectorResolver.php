<?php

namespace App\Services;

use App\DataTransferObjects\AccountRecord;
use App\DataTransferObjects\SelectorResolution;
use App\DataTransferObjects\SelectorResolutionStatus;

final class SelectorResolver
{
    /**
     * @param  AccountRecord[]  $accounts  in canonical row order, row number = 1-based index
     */
    public function resolve(array $accounts, string $query): SelectorResolution
    {
        foreach ($accounts as $account) {
            if ($account->accountKey === $query) {
                return new SelectorResolution(SelectorResolutionStatus::Found, $account);
            }
        }

        if (ctype_digit($query)) {
            $account = $accounts[((int) $query) - 1] ?? null;

            return $account !== null
                ? new SelectorResolution(SelectorResolutionStatus::Found, $account)
                : new SelectorResolution(SelectorResolutionStatus::NotFound);
        }

        $candidates = $this->matchBySubstring($accounts, $query);

        return match (count($candidates)) {
            0 => new SelectorResolution(SelectorResolutionStatus::NotFound),
            1 => new SelectorResolution(SelectorResolutionStatus::Found, $candidates[0]),
            default => new SelectorResolution(SelectorResolutionStatus::Ambiguous, candidates: $candidates),
        };
    }

    /**
     * @param  AccountRecord[]  $accounts
     * @return AccountRecord[]
     */
    private function matchBySubstring(array $accounts, string $query): array
    {
        $needle = mb_strtolower($query);

        return array_values(array_filter(
            $accounts,
            function (AccountRecord $account) use ($needle) {
                foreach ([$account->email, $account->alias, $account->displayName, $account->organizationName] as $field) {
                    if ($field !== null && str_contains(mb_strtolower($field), $needle)) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }
}
