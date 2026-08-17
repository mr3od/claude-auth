<?php

namespace App\Services\Exceptions;

final class UnsupportedPlatformException extends \RuntimeException
{
    public function __construct(string $osFamily)
    {
        parent::__construct(
            "claude-auth only supports Linux today (detected: {$osFamily}). On macOS, Claude Code ".
            'stores credentials in the encrypted Keychain, not a file, so this tool\'s file-swap '.
            'design can\'t manage it there. Windows is untested and not yet supported.'
        );
    }
}
