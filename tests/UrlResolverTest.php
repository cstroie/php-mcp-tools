<?php
declare(strict_types=1);

use Mcp\Http\UrlResolver;

check(
    'resolves an absolute href unchanged',
    UrlResolver::resolve('https://example.com/a/', 'https://other.example/x') === 'https://other.example/x'
);
check(
    'resolves a protocol-relative href',
    UrlResolver::resolve('https://example.com/a/', '//cdn.example/feed.xml') === 'https://cdn.example/feed.xml'
);
check(
    'resolves a root-relative href',
    UrlResolver::resolve('https://example.com/a/b', '/c') === 'https://example.com/c'
);
check(
    'resolves a path-relative href against the page directory',
    UrlResolver::resolve('https://example.com/a/b', 'c') === 'https://example.com/a/c'
);
