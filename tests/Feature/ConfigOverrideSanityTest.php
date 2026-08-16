<?php

it('resolves a container singleton against a config value overridden inside the test', function () {
    $fakeHome = sys_get_temp_dir().'/claude-auth-canary-'.bin2hex(random_bytes(6));

    config(['claude-auth.home' => $fakeHome]);

    app()->singleton('claude-auth.canary-probe', fn () => config('claude-auth.home'));

    expect(app('claude-auth.canary-probe'))->toBe($fakeHome);
});
