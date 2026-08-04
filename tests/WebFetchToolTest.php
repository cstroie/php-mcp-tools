<?php
declare(strict_types=1);

use Mcp\Config;
use Mcp\Tools\WebFetchTool;

$config = new Config(['user_agent' => 'test-agent', 'fetch_max_redirects' => 3]);
$tool = new WebFetchTool($config);

$html = '<html><head><style>.x{}</style></head><body><h1>Hi &amp; welcome</h1><p>Text</p></body></html>';
$text = invokePrivate($tool, 'htmlToPlainText', [$html]);
check('htmlToPlainText strips tags', strpos($text, '<') === false);
check('htmlToPlainText decodes entities', strpos($text, 'Hi & welcome') !== false);

$plain = invokePrivate($tool, 'extractText', ['raw text', 'text/plain', 'https://example.com/']);
check('extractText passes through non-html content', $plain === 'raw text');

$paragraph = str_repeat('This is a sentence of real article body text used to pad the content out. ', 12);
$articleHtml = <<<HTML
<html><head><title>The Real Article Title</title></head>
<body>
  <nav><a href="/">Home</a><a href="/about">About</a></nav>
  <header><h1>Site Name</h1></header>
  <article>
    <h1>The Real Article Title</h1>
    <p>{$paragraph}</p>
    <p>{$paragraph}</p>
  </article>
  <aside>Related links: <a href="/x">x</a> <a href="/y">y</a></aside>
  <footer>Copyright 2026 Example Corp. All rights reserved.</footer>
</body></html>
HTML;

$article = invokePrivate($tool, 'extractArticle', [$articleHtml, 'https://example.com/article']);
check('extractArticle finds article content', $article !== null);
check('extractArticle renders the title as a markdown H1', strpos($article, '# The Real Article Title') === 0);
check('extractArticle includes the body text', strpos($article, 'real article body text') !== false);
check('extractArticle drops the nav boilerplate', strpos($article, 'About') === false);
check('extractArticle drops the footer boilerplate', strpos($article, 'Copyright 2026') === false);

// Readability does best-effort extraction even on thin pages (it only refuses
// to produce anything when there's no <body> content at all) — so the
// null-returning fallback path is for genuinely empty/broken HTML, not just
// "short" pages.
$emptyBody = '<html><head></head><body></body></html>';
$noArticle = invokePrivate($tool, 'extractArticle', [$emptyBody, 'https://example.com/']);
check('extractArticle returns null for HTML with no body content', $noArticle === null);

$fullText = invokePrivate($tool, 'extractText', [$articleHtml, 'text/html', 'https://example.com/article']);
check('extractText uses the article extraction path for real HTML pages', strpos($fullText, 'About') === false);

$fallbackText = invokePrivate($tool, 'extractText', [$emptyBody, 'text/html', 'https://example.com/']);
check('extractText falls back to whole-page text when no article is found', $fallbackText === '');
