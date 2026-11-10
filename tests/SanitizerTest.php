<?php
declare(strict_types=1);

namespace Bristlecone\Zfolio\Tests;

use Bristlecone\Zfolio\Content\Sanitizer;
use PHPUnit\Framework\TestCase;

final class SanitizerTest extends TestCase {

	private function fixture_html(): string {
		return json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/docs/zfolio-feed/v1/fixtures/page.json' ), true )['html'];
	}

	public function test_bundled_allowlist_matches_the_contract_copy(): void {
		$this->assertJsonFileEqualsJsonFile(
			dirname( __DIR__ ) . '/docs/zfolio-feed/v1/kses-allowlist.json',
			dirname( __DIR__ ) . '/data/kses-allowlist-v1.json'
		);
	}

	public function test_fixture_page_survives_unchanged(): void {
		$html = $this->fixture_html();
		$this->assertSame( $html, Sanitizer::clean( $html ) );
	}

	public function test_scripts_styles_frames_and_handlers_are_removed(): void {
		$out = Sanitizer::clean( '<p onclick="x()" style="color:red">a<script>alert(1)</script><iframe src="https://x"></iframe><svg><g></g></svg><style>p{}</style></p>' );

		$this->assertStringNotContainsString( '<script', $out );
		$this->assertStringNotContainsString( 'onclick', $out );
		$this->assertStringNotContainsString( 'style', $out );
		$this->assertStringNotContainsString( '<iframe', $out );
		$this->assertStringNotContainsString( '<svg', $out );
	}

	public function test_unsafe_protocols_are_removed(): void {
		$out = Sanitizer::clean( '<a href="javascript:alert(1)">x</a><img src="data:image/png;base64,AA" alt="">' );

		$this->assertStringNotContainsString( 'javascript:', $out );
		$this->assertStringNotContainsString( 'data:', $out );
	}

	public function test_class_tokens_are_checked_one_by_one(): void {
		$out = Sanitizer::clean( '<span class="hljs-title function_ evil">x</span><a href="#" class="wiki-link other">y</a>' );

		$this->assertStringContainsString( '<span class="hljs-title">', $out );
		$this->assertStringContainsString( 'class="wiki-link"', $out );
		$this->assertStringNotContainsString( 'evil', $out );
	}

	public function test_ids_need_the_user_content_prefix(): void {
		$out = Sanitizer::clean( '<h2 id="admin-bar">a</h2><h2 id="user-content-ok">b</h2>' );

		$this->assertStringNotContainsString( 'admin-bar', $out );
		$this->assertStringContainsString( 'id="user-content-ok"', $out );
	}

	public function test_attribute_values_and_patterns_are_enforced(): void {
		$out = Sanitizer::clean( '<annotation encoding="text/html">x</annotation><img src="https://i/x.png" alt="" width="100px"><a href="#" data-zfolio-url="http://insecure">z</a>' );

		$this->assertStringNotContainsString( 'encoding=', $out );
		$this->assertStringNotContainsString( 'width=', $out );
		$this->assertStringNotContainsString( 'data-zfolio-url', $out );
	}

	public function test_task_list_checkbox_is_always_disabled(): void {
		$out = Sanitizer::clean( '<input type="text">' );

		$this->assertStringContainsString( 'disabled', $out );
		$this->assertStringContainsString( 'type="checkbox"', $out );
	}

	public function test_srcset_keeps_only_https(): void {
		$out = Sanitizer::clean( '<picture><source srcset="https://a.example/1.png 1x, http://b.example/2.png 2x"></picture>' );

		$this->assertStringContainsString( 'srcset="https://a.example/1.png 1x"', $out );
		$this->assertStringNotContainsString( 'http://b.example', $out );
	}

	public function test_math_can_be_shown_as_tex(): void {
		$out = Sanitizer::strip_math( Sanitizer::clean( $this->fixture_html() ) );

		$this->assertStringNotContainsString( '<math', $out );
		$this->assertStringContainsString( '<code class="zfolio-tex">E = mc^2</code>', $out );
	}
}
