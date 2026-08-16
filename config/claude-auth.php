<?php

return [
    // Registry + per-account snapshot files live here, outside ~/.claude and
    // outside CLAUDE_CONFIG_DIR scope, so switching accounts never touches
    // memory, history, or settings.
    'home' => env('CLAUDE_AUTH_HOME', getenv('HOME').'/.claude-auth'),

    // The live files Claude Code CLI itself reads.
    'claude_credentials_file' => env('CLAUDE_CREDENTIALS_FILE', getenv('HOME').'/.claude/.credentials.json'),
    'claude_json_file' => env('CLAUDE_JSON_FILE', getenv('HOME').'/.claude.json'),

    'max_backups' => 5,
];
