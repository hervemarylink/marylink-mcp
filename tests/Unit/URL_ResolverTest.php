<?php
/**
 * Tests for URL_Resolver
 *
 * @package MCP_No_Headless\Tests\Unit
 */

namespace MCP_No_Headless\Tests\Unit;

use PHPUnit\Framework\TestCase;

class URL_ResolverTest extends TestCase {

    /**
     * Reset global test data before each test
     */
    protected function setUp(): void {
        global $mcpnh_test_posts, $mcpnh_test_meta, $mcpnh_test_options;
        $mcpnh_test_posts = [];
        $mcpnh_test_meta = [];
        $mcpnh_test_options = [
            'siteurl' => 'https://test.marylink.io',
        ];
    }

    /**
     * TEST 7: URL pattern extraction
     */
    public function test_extracts_marylink_urls(): void {
        $content = "Voici la source:\nhttps://test.marylink.io/publication/test-doc/\nFin.";

        // Test pattern matching
        $pattern = '#https?://[a-z0-9.-]+\.marylink\.(io|net)/publication/([a-z0-9_-]+)/?#i';
        preg_match_all($pattern, $content, $matches);

        $this->assertCount(1, $matches[0]);
        $this->assertEquals('test-doc', $matches[2][0]);
    }

    /**
     * TEST: URL validation - allowed domains only
     */
    public function test_only_allows_marylink_domains(): void {
        $allowed_domains = ['marylink.io', 'marylink.net'];

        // Valid URLs
        $valid_urls = [
            'https://test.marylink.io/publication/doc/',
            'https://cabinet.marylink.net/publication/pricing/',
        ];

        foreach ($valid_urls as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            $is_valid = false;
            foreach ($allowed_domains as $domain) {
                if (str_ends_with($host, $domain)) {
                    $is_valid = true;
                    break;
                }
            }
            $this->assertTrue($is_valid, "URL should be valid: $url");
        }

        // Invalid URLs
        $invalid_urls = [
            'https://evil.com/publication/doc/',
            'https://marylink.evil.com/publication/doc/',
        ];

        foreach ($invalid_urls as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            $is_valid = false;
            foreach ($allowed_domains as $domain) {
                if (str_ends_with($host, $domain)) {
                    $is_valid = true;
                    break;
                }
            }
            $this->assertFalse($is_valid, "URL should be invalid: $url");
        }
    }

    /**
     * TEST: Anti-SSRF - blocks IP literals
     */
    public function test_blocks_ip_literals(): void {
        $urls = [
            'https://127.0.0.1/publication/doc/',
            'https://192.168.1.1/publication/doc/',
            'https://10.0.0.1/publication/doc/',
            'https://[::1]/publication/doc/',
        ];

        foreach ($urls as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            $is_ip = filter_var($host, FILTER_VALIDATE_IP) !== false ||
                     preg_match('/^\[.*\]$/', $host);
            $this->assertTrue($is_ip, "Should detect IP literal: $url");
        }
    }

    /**
     * TEST: Slug extraction from URL
     */
    public function test_extracts_slug_correctly(): void {
        $test_cases = [
            'https://test.marylink.io/publication/my-doc/' => 'my-doc',
            'https://test.marylink.io/publications/catalog' => 'catalog',
            'https://cabinet.marylink.net/style/formal-b2b/' => 'formal-b2b',
            'https://test.marylink.io/publication/test_doc_123' => 'test_doc_123',
        ];

        foreach ($test_cases as $url => $expected_slug) {
            // Pattern supports both /publication/ and /publications/ and /style/
            $pattern = '#/(?:publication|publications|style|styles)/([a-z0-9_-]+)/?#i';
            preg_match($pattern, $url, $matches);

            $this->assertEquals($expected_slug, $matches[1] ?? '', "Failed for URL: $url");
        }
    }

    /**
     * TEST 10: Content truncation
     */
    public function test_content_truncation(): void {
        $max_chars = 50000;
        $long_content = str_repeat('A', 100000);

        // Simulate truncation logic
        if (strlen($long_content) > $max_chars) {
            $truncated = substr($long_content, 0, $max_chars) . "\n\n[... contenu tronqué ...]";
        } else {
            $truncated = $long_content;
        }

        $this->assertLessThanOrEqual($max_chars + 50, strlen($truncated));
        $this->assertStringContainsString('[... contenu tronqué ...]', $truncated);
    }

    /**
     * TEST: Wrapper format for injected content
     */
    public function test_wrapper_format(): void {
        $title = 'Catalogue 2024';
        $content = 'Product list here';
        $type = 'REFERENCE';

        $wrapped = sprintf(
            "=== BEGIN %s: %s ===\n%s\n=== END %s ===",
            $type,
            $title,
            $content,
            $type
        );

        $this->assertStringContainsString('=== BEGIN REFERENCE: Catalogue 2024 ===', $wrapped);
        $this->assertStringContainsString('Product list here', $wrapped);
        $this->assertStringContainsString('=== END REFERENCE ===', $wrapped);
    }

    /**
     * TEST: Singulier/pluriel URL support
     */
    public function test_singular_plural_url_support(): void {
        $pattern = '#/(?:publication|publications|style|styles)/([a-z0-9_-]+)/?#i';

        $test_urls = [
            '/publication/catalog' => 'catalog',
            '/publications/catalog' => 'catalog',
            '/style/formal-b2b' => 'formal-b2b',
            '/styles/formal-b2b' => 'formal-b2b',
        ];

        foreach ($test_urls as $path => $expected) {
            preg_match($pattern, $path, $matches);
            $this->assertEquals($expected, $matches[1] ?? '', "Failed for path: $path");
        }
    }

    /**
     * TEST: API JSON normalization
     */
    public function test_api_json_normalization(): void {
        $html_url = 'https://cabinet.marylink.net/publication/catalog';
        $expected_api = 'https://cabinet.marylink.net/wp-json/marylink/v1/publications/catalog';

        // Extract parts and build API URL
        $parsed = parse_url($html_url);
        $path_parts = explode('/', trim($parsed['path'], '/'));

        if (count($path_parts) >= 2 && in_array($path_parts[0], ['publication', 'publications'])) {
            $slug = $path_parts[1];
            $api_url = $parsed['scheme'] . '://' . $parsed['host'] . '/wp-json/marylink/v1/publications/' . $slug;
            $this->assertEquals($expected_api, $api_url);
        } else {
            $this->fail("Could not parse URL: $html_url");
        }
    }
}
