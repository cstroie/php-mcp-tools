<?php
declare(strict_types=1);

use Mcp\Auth;
use Mcp\Config;

$auth = new Auth();
$config = new Config(['token' => 'secret-token']);

check(
    'tokenPart strips optional @id suffix',
    invokePrivate($auth, 'tokenPart', ['secret-token@client-1']) === 'secret-token'
);
check(
    'tokenPart returns whole value when no @id present',
    invokePrivate($auth, 'tokenPart', ['secret-token']) === 'secret-token'
);

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret-token@client-1';
check('check() accepts token with @id suffix', Auth::check($config));
check('clientId() extracts the id after @', Auth::clientId() === 'client-1');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret-token';
check('check() accepts bare token with no @id', Auth::check($config));
check('clientId() returns null when no @id present', Auth::clientId() === null);

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wrong-token@client-1';
check('check() rejects a wrong random-string part', !Auth::check($config));

unset($_SERVER['HTTP_AUTHORIZATION']);
check('check() rejects a missing Authorization header', !Auth::check($config));
check('clientId() returns null with no Authorization header', Auth::clientId() === null);

$openConfig = new Config(['token' => '']);
unset($_SERVER['HTTP_AUTHORIZATION']);
check('check() allows requests when no token is configured', Auth::check($openConfig));
