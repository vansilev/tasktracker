<?php

namespace Tests\Unit;

use App\Rules\PlainTextLength;
use App\Services\HtmlContentService;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class HtmlContentServiceTest extends TestCase
{
    private HtmlContentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(HtmlContentService::class);
    }

    public function test_sanitize_removes_script_tags(): void
    {
        $result = $this->service->sanitize('<script>alert(1)</script><p>safe</p>');

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('alert(1)', $result);
        $this->assertStringContainsString('<p>safe</p>', $result);
    }

    public function test_sanitize_keeps_attachment_view_img(): void
    {
        $html = '<p><a href="/tasks/attachments/12/view"><img src="/tasks/attachments/12/view" alt="shot.png"></a></p>';
        $result = $this->service->sanitize($html);

        $this->assertStringContainsString('<img', $result);
        $this->assertStringContainsString('src="/tasks/attachments/12/view"', $result);
        $this->assertStringContainsString('alt="shot.png"', $result);
        $this->assertStringNotContainsString('onerror', $result);
    }

    public function test_sanitize_keeps_absolute_same_host_attachment_img(): void
    {
        $src = rtrim((string) config('app.url'), '/').'/tasks/attachments/99/view';
        $result = $this->service->sanitize('<img src="'.$src.'" alt="a.png">');

        $this->assertStringContainsString('<img', $result);
        $this->assertStringContainsString('/tasks/attachments/99/view', $result);
        $this->assertTrue($this->service->isAllowedAttachmentImageSrc($src));
    }

    public function test_sanitize_strips_hostile_img_onerror_and_junk_src(): void
    {
        $result = $this->service->sanitize('<img src=x onerror=alert(1)>');

        $this->assertStringNotContainsString('<img', $result);
        $this->assertStringNotContainsString('onerror', $result);
        $this->assertStringNotContainsString('alert(1)', $result);
    }

    public function test_sanitize_strips_data_uri_img(): void
    {
        $result = $this->service->sanitize('<img src="data:image/png;base64,abc" alt="x">');

        $this->assertStringNotContainsString('<img', $result);
        $this->assertStringNotContainsString('data:', $result);
    }

    public function test_sanitize_strips_external_https_img(): void
    {
        $result = $this->service->sanitize('<img src="https://evil.example/track.png" alt="x">');

        $this->assertStringNotContainsString('<img', $result);
        $this->assertStringNotContainsString('evil.example', $result);
    }

    public function test_sanitize_strips_same_origin_non_attachment_img(): void
    {
        $result = $this->service->sanitize('<img src="/evil.png" alt="x"><p>ok</p>');

        $this->assertStringNotContainsString('<img', $result);
        $this->assertStringNotContainsString('/evil.png', $result);
        $this->assertStringContainsString('<p>ok</p>', $result);
    }

    public function test_sanitize_strips_prefixed_path_that_ends_like_attachment_view(): void
    {
        $evil = '/evil/tasks/attachments/1/view';

        $this->assertFalse($this->service->isAllowedAttachmentImageSrc($evil));

        $result = $this->service->sanitize('<img src="'.$evil.'" alt="x">');

        $this->assertStringNotContainsString('<img', $result);
        $this->assertStringNotContainsString('/evil/', $result);
    }

    public function test_sanitize_rejects_attachment_img_src_with_query_or_hash(): void
    {
        $this->assertFalse(
            $this->service->isAllowedAttachmentImageSrc('/tasks/attachments/1/view?x=1')
        );
        $this->assertFalse(
            $this->service->isAllowedAttachmentImageSrc('/tasks/attachments/1/view#frag')
        );

        $withQuery = $this->service->sanitize('<img src="/tasks/attachments/1/view?x=1" alt="x">');
        $withHash = $this->service->sanitize('<img src="/tasks/attachments/1/view#frag" alt="x">');

        $this->assertStringNotContainsString('<img', $withQuery);
        $this->assertStringNotContainsString('<img', $withHash);
    }

    public function test_sanitize_keeps_absolute_attachment_img_under_subdir_app_url(): void
    {
        // Keep the same host Purifier was configured with (URI.Host from APP_URL);
        // only add a subdirectory path prefix for the allowlist check.
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'http';
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        config(['app.url' => $scheme.'://'.$host.'/tasktracker/public']);

        $src = $scheme.'://'.$host.'/tasktracker/public/tasks/attachments/1/view';

        $this->assertTrue($this->service->isAllowedAttachmentImageSrc($src));
        $this->assertFalse(
            $this->service->isAllowedAttachmentImageSrc('/evil/tasks/attachments/1/view')
        );
        $this->assertTrue(
            $this->service->isAllowedAttachmentImageSrc('/tasks/attachments/1/view')
        );

        $result = $this->service->sanitize('<img src="'.$src.'" alt="a.png">');

        $this->assertStringContainsString('<img', $result);
        $this->assertStringContainsString('/tasks/attachments/1/view', $result);
    }

    public function test_is_empty_is_false_for_attachment_only_markup(): void
    {
        $html = '<p><img src="/tasks/attachments/3/view" alt="paste.png"></p>';

        $this->assertTrue($this->service->hasAttachmentEmbed($html));
        $this->assertFalse($this->service->isEmpty($html));
    }

    public function test_is_empty_is_false_for_attachment_download_link_only(): void
    {
        $html = '<p><a href="/tasks/attachments/4/download"></a></p>';

        $this->assertTrue($this->service->hasAttachmentEmbed($html));
        $this->assertFalse($this->service->isEmpty($html));
    }

    public function test_is_empty_is_true_for_fake_attachment_href_on_non_anchor(): void
    {
        $html = '<div href="/tasks/attachments/1/download"></div>';

        $this->assertTrue($this->service->isEmpty($html));
        $this->assertFalse($this->service->hasAttachmentEmbed($this->service->sanitize($html)));
    }

    public function test_sanitize_strips_javascript_href(): void
    {
        $result = $this->service->sanitize('<a href="javascript:alert(1)">x</a>');

        $this->assertStringNotContainsString('javascript:', $result);
        $this->assertStringNotContainsString('alert(1)', $result);
        $this->assertStringContainsString('x', $result);
    }

    public function test_sanitize_strips_data_uri_href(): void
    {
        $result = $this->service->sanitize('<a href="data:text/html,hi">x</a>');

        $this->assertStringNotContainsString('data:', $result);
        $this->assertStringContainsString('x', $result);
    }

    public function test_sanitize_keeps_safe_links_and_adds_rel_target(): void
    {
        $result = $this->service->sanitize('<a href="https://example.com">x</a>');

        $this->assertStringContainsString('href="https://example.com"', $result);
        $this->assertStringContainsString('target="_blank"', $result);
        $this->assertStringContainsString('nofollow', $result);
        $this->assertStringContainsString('noopener', $result);
        $this->assertStringContainsString('noreferrer', $result);
    }

    public function test_sanitize_preserves_legitimate_formatting(): void
    {
        $html = <<<'HTML'
<strong>bold</strong><em>italic</em><u>under</u><s>strike</s>
<h1>H1</h1><h2>H2</h2><h3>H3</h3><h4>H4</h4><h5>H5</h5><h6>H6</h6>
<ul><li>ul item</li></ul>
<ol><li>ol item</li></ol>
<blockquote>quote</blockquote>
<code>inline</code>
<pre>pre block</pre>
<table>
<thead><tr><th colspan="2">Header</th></tr></thead>
<tbody><tr><td rowspan="1">A</td><td>B</td></tr></tbody>
</table>
HTML;

        $result = $this->service->sanitize($html);

        $this->assertStringContainsString('<strong>bold</strong>', $result);
        $this->assertStringContainsString('<em>italic</em>', $result);
        $this->assertStringContainsString('<u>under</u>', $result);
        $this->assertStringContainsString('<s>strike</s>', $result);
        $this->assertStringContainsString('<h1>H1</h1>', $result);
        $this->assertStringContainsString('<h2>H2</h2>', $result);
        $this->assertStringContainsString('<h3>H3</h3>', $result);
        $this->assertStringContainsString('<h4>H4</h4>', $result);
        $this->assertStringContainsString('<h5>H5</h5>', $result);
        $this->assertStringContainsString('<h6>H6</h6>', $result);
        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('<ol>', $result);
        $this->assertStringContainsString('<li>ul item</li>', $result);
        $this->assertStringContainsString('<li>ol item</li>', $result);
        $this->assertStringContainsString('<blockquote>quote</blockquote>', $result);
        $this->assertStringContainsString('<code>inline</code>', $result);
        $this->assertStringContainsString('<pre>pre block</pre>', $result);
        $this->assertStringContainsString('<table>', $result);
        $this->assertStringContainsString('<thead>', $result);
        $this->assertStringContainsString('<tbody>', $result);
        $this->assertStringContainsString('<th colspan="2">Header</th>', $result);
        $this->assertStringContainsString('<td rowspan="1">A</td>', $result);
        $this->assertStringContainsString('<td>B</td>', $result);
    }

    public function test_sanitize_keeps_mention_chip_with_spaces(): void
    {
        $html = '<p>CC <span class="mention" data-type="mention" data-id="12" data-label="Максим Гольдт">@Максим Гольдт</span></p>';
        $result = $this->service->sanitize($html);

        $this->assertStringContainsString('data-type="mention"', $result);
        $this->assertStringContainsString('data-id="12"', $result);
        $this->assertStringContainsString('Максим Гольдт', $result);
        $this->assertStringContainsString('class="mention"', $result);
        $this->assertStringNotContainsString('<script', $result);
    }

    public function test_sanitize_keeps_comment_quote_blockquote(): void
    {
        $html = '<blockquote class="comment-quote" data-quoted-comment-id="42"><p><strong>CRM Manager</strong> — preview</p></blockquote>';
        $result = $this->service->sanitize($html);

        $this->assertStringContainsString('data-quoted-comment-id="42"', $result);
        $this->assertStringContainsString('class="comment-quote"', $result);
        $this->assertStringContainsString('CRM Manager', $result);
        $this->assertStringContainsString('preview', $result);
    }

    public function test_sanitize_keeps_stacked_comment_quote(): void
    {
        $html = '<blockquote class="comment-quote" data-quoted-comment-id="42"><p><strong>CRM Manager</strong></p><p>preview</p></blockquote>';
        $result = $this->service->sanitize($html);

        $this->assertStringContainsString('data-quoted-comment-id="42"', $result);
        $this->assertStringContainsString('CRM Manager', $result);
        $this->assertStringContainsString('preview', $result);
    }

    public function test_sanitize_keeps_chip_after_stripping_tiptap_extra_attrs(): void
    {
        $html = '<p>hello <span class="mention" data-type="mention" data-id="2" data-label="Максим Гольдт" data-mention-suggestion-char="@" contenteditable="false">@Максим Гольдт</span></p>';
        $result = $this->service->sanitize($html);

        $this->assertStringContainsString('data-type="mention"', $result);
        $this->assertStringContainsString('data-id="2"', $result);
        $this->assertStringContainsString('@Максим Гольдт', $result);
        $this->assertStringNotContainsString('contenteditable', $result);
        $this->assertStringNotContainsString('data-mention-suggestion-char', $result);
    }

    public function test_sanitize_strips_non_mention_data_type_on_span(): void
    {
        $result = $this->service->sanitize('<p><span data-type="evil" data-id="1">x</span></p>');

        $this->assertStringNotContainsString('data-type="evil"', $result);
        $this->assertStringContainsString('x', $result);
    }

    public function test_sanitize_strips_style_attributes(): void
    {
        $result = $this->service->sanitize('<p style="color:red">styled</p>');

        $this->assertStringNotContainsString('style=', $result);
        $this->assertStringContainsString('<p>styled</p>', $result);
    }

    public function test_sanitize_strips_event_handlers_iframe_and_style_tags(): void
    {
        $result = $this->service->sanitize(
            '<p onclick="alert(1)">click</p><iframe src="https://evil.test"></iframe><style>body{}</style>'
        );

        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringNotContainsString('<iframe', $result);
        $this->assertStringNotContainsString('<style', $result);
        $this->assertStringContainsString('<p>click</p>', $result);
    }

    public function test_sanitize_null_and_empty_return_empty_string(): void
    {
        $this->assertSame('', $this->service->sanitize(null));
        $this->assertSame('', $this->service->sanitize(''));
    }

    public function test_from_plain_text_escapes_and_preserves_breaks(): void
    {
        $result = $this->service->fromPlainText("Line one\nLine two\n\n<script>alert(1)</script>\nSecond para");

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $result);
        $this->assertStringContainsString('<p>Line one<br>Line two</p>', $result);
        $this->assertStringContainsString('<p>&lt;script&gt;alert(1)&lt;/script&gt;<br>Second para</p>', $result);
    }

    public function test_from_plain_text_null_and_empty_return_empty_string(): void
    {
        $this->assertSame('', $this->service->fromPlainText(null));
        $this->assertSame('', $this->service->fromPlainText(''));
    }

    public function test_to_plain_text_decodes_entities_and_collapses_whitespace(): void
    {
        $result = $this->service->toPlainText("<p>A&amp;B</p>\n<p>  foo   bar  </p>");

        $this->assertSame('A&B foo bar', $result);
    }

    public function test_to_plain_text_separates_block_elements_with_space(): void
    {
        $this->assertSame(
            'first second',
            $this->service->toPlainText('<p>first</p><p>second</p>'),
        );

        $this->assertSame(
            'A1 B1 A2 B2',
            $this->service->toPlainText(
                '<table><tr><td>A1</td><td>B1</td></tr><tr><td>A2</td><td>B2</td></tr></table>'
            ),
        );

        $this->assertSame(
            'one two',
            $this->service->toPlainText('<ul><li>one</li><li>two</li></ul>'),
        );

        $this->assertSame(
            'a b',
            $this->service->toPlainText('a<br>b'),
        );
    }

    public function test_to_plain_text_does_not_split_words_on_inline_tags(): void
    {
        $this->assertSame(
            'слово',
            $this->service->toPlainText('<p>сло<strong>во</strong></p>'),
        );
    }

    public function test_is_empty_for_wysiwyg_empty_payloads(): void
    {
        $this->assertTrue($this->service->isEmpty('<p></p>'));
        $this->assertTrue($this->service->isEmpty('<p><br></p>'));
        $this->assertTrue($this->service->isEmpty('<p>&nbsp;</p>'));
        $this->assertTrue($this->service->isEmpty('<p> </p>'));
        $this->assertTrue($this->service->isEmpty(''));
        $this->assertTrue($this->service->isEmpty(null));
        $this->assertTrue($this->service->isEmpty('   '));
        $this->assertTrue($this->service->isEmpty('<p><span> </span></p>'));
        $this->assertFalse($this->service->isEmpty('<p>real content</p>'));
    }

    public function test_sanitize_allows_https_and_mailto_but_drops_ftp(): void
    {
        $https = $this->service->sanitize('<a href="https://example.com">https</a>');
        $mailto = $this->service->sanitize('<a href="mailto:user@example.com">mail</a>');
        $ftp = $this->service->sanitize('<a href="ftp://files.example.com/a">ftp</a>');

        $this->assertStringContainsString('href="https://example.com"', $https);
        $this->assertStringContainsString('href="mailto:user@example.com"', $mailto);
        $this->assertStringNotContainsString('ftp:', $ftp);
        $this->assertStringNotContainsString('files.example.com', $ftp);
        $this->assertStringContainsString('ftp', $ftp);
    }

    public function test_plain_text_length_rule_rejects_empty_markup_under_min(): void
    {
        $validator = Validator::make(
            ['body' => '<p></p>'],
            ['body' => [new PlainTextLength(min: 3)]],
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('body', $validator->errors()->toArray());
    }

    public function test_plain_text_length_rule_accepts_real_text(): void
    {
        $validator = Validator::make(
            ['body' => '<p>hello world</p>'],
            ['body' => [new PlainTextLength(min: 3, max: 20000)]],
        );

        $this->assertFalse($validator->fails());
    }

    public function test_plain_text_length_rule_rejects_over_max(): void
    {
        $validator = Validator::make(
            ['body' => '<p>abcdef</p>'],
            ['body' => [new PlainTextLength(max: 3)]],
        );

        $this->assertTrue($validator->fails());
    }

    public function test_plain_text_length_rule_accepts_attachment_only_markup(): void
    {
        $validator = Validator::make(
            ['body' => '<p><img src="/tasks/attachments/7/view" alt="paste.png"></p>'],
            ['body' => [new PlainTextLength(min: 3, max: 20000)]],
        );

        $this->assertFalse($validator->fails());
    }

    public function test_plain_text_length_rule_accepts_attachment_download_link_only(): void
    {
        $validator = Validator::make(
            ['body' => '<p><a href="/tasks/attachments/8/download"></a></p>'],
            ['body' => [new PlainTextLength(min: 3, max: 20000)]],
        );

        $this->assertFalse($validator->fails());
    }

    public function test_plain_text_length_rule_rejects_fake_attachment_href_on_non_anchor(): void
    {
        $validator = Validator::make(
            ['body' => '<div href="/tasks/attachments/1/download"></div>'],
            ['body' => [new PlainTextLength(min: 3)]],
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('body', $validator->errors()->toArray());
    }
}
