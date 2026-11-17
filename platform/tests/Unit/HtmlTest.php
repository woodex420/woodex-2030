<?php

use App\Support\Html;

it('turns typed text into paragraphs and lists, and back', function () {
    $text = "Complete renovation of the ground floor.\nWork starts after the advance.\n\n- Civil work\n- Electrical and plumbing\n- Wood work\n\n1. Design approval\n2. Execution";

    $html = Html::fromPlainText($text);

    expect($html)->toBe("<p>Complete renovation of the ground floor.<br>Work starts after the advance.</p>\n<ul><li>Civil work</li><li>Electrical and plumbing</li><li>Wood work</li></ul>\n<ol><li>Design approval</li><li>Execution</li></ol>")
        ->and(Html::toPlainText($html))->toBe($text)
        ->and(Html::fromPlainText('   '))->toBeNull();
});

it('escapes typed text', function () {
    expect(Html::fromPlainText('Kitchen <script>alert(1)</script> & lounge'))
        ->toBe('<p>Kitchen &lt;script&gt;alert(1)&lt;/script&gt; &amp; lounge</p>');
});

it('keeps only allowed tags and safe links when sanitising HTML', function () {
    $dirty = '<div onclick="x()"><p style="color:red">Scope <strong>A</strong> <script>alert(1)</script><span>kept</span></p>'
        .'<a href="javascript:alert(1)">bad</a> <a href="https://woodex.com.pk" target="_blank">site</a><img src=x onerror=alert(1)></div>';

    expect(Html::sanitize($dirty))
        ->toBe('<p>Scope <strong>A</strong> kept</p><a>bad</a> <a href="https://woodex.com.pk" rel="noopener nofollow">site</a>')
        ->and(Html::sanitize(''))->toBeNull();
});
