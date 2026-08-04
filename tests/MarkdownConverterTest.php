<?php
declare(strict_types=1);

use Mcp\Text\MarkdownConverter;

check('converts headings', trim(MarkdownConverter::convert('<h2>Title</h2>')) === '## Title');

check(
    'converts a paragraph',
    trim(MarkdownConverter::convert('<p>Hello world</p>')) === 'Hello world'
);

check(
    'converts strong and em',
    trim(MarkdownConverter::convert('<p><strong>bold</strong> and <em>italic</em></p>')) === '**bold** and *italic*'
);

check(
    'converts a link',
    trim(MarkdownConverter::convert('<a href="https://example.com">Example</a>')) === '[Example](https://example.com)'
);

check(
    'link falls back to href as text when empty',
    trim(MarkdownConverter::convert('<a href="https://example.com"></a>')) === '[https://example.com](https://example.com)'
);

check(
    'converts an image',
    trim(MarkdownConverter::convert('<img src="https://example.com/x.png" alt="An image">'))
        === '![An image](https://example.com/x.png)'
);

check(
    'converts inline code',
    trim(MarkdownConverter::convert('<p>Run <code>ls -la</code> now</p>')) === 'Run `ls -la` now'
);

$preHtml = "<pre>function foo() {\n  return 1;\n}</pre>";
check(
    'converts a pre block to a fenced code block',
    trim(MarkdownConverter::convert($preHtml)) === "```\nfunction foo() {\n  return 1;\n}\n```"
);

check(
    'converts a blockquote',
    trim(MarkdownConverter::convert('<blockquote>quoted text</blockquote>')) === '> quoted text'
);

check(
    'converts an unordered list',
    trim(MarkdownConverter::convert('<ul><li>one</li><li>two</li></ul>')) === "- one\n- two"
);

check(
    'converts an ordered list',
    trim(MarkdownConverter::convert('<ol><li>first</li><li>second</li></ol>')) === "1. first\n2. second"
);

$nestedList = '<ul><li>outer<ul><li>inner</li></ul></li></ul>';
check(
    'converts a nested list with indentation',
    trim(MarkdownConverter::convert($nestedList)) === "- outer\n  - inner"
);

$table = '<table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table>';
check(
    'converts a table',
    trim(MarkdownConverter::convert($table)) === "| A | B |\n| --- | --- |\n| 1 | 2 |"
);

check(
    'escapes markdown-significant characters in plain text',
    trim(MarkdownConverter::convert('<p>2 * 3 = 6, [not a link], back\\slash</p>'))
        === '2 \\* 3 = 6, \\[not a link\\], back\\\\slash'
);

check('handles empty input', MarkdownConverter::convert('') === '');
check('handles whitespace-only input', MarkdownConverter::convert('   ') === '');

$hrHtml = '<p>before</p><hr><p>after</p>';
check(
    'converts a horizontal rule',
    trim(MarkdownConverter::convert($hrHtml)) === "before\n\n---\n\nafter"
);
