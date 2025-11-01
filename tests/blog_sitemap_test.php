<?php
require __DIR__ . '/wp-stubs.php';

ini_set('assert.exception', '1');

require __DIR__ . '/../plugin/virtual-product-pages/virtual-product-pages.php';

$plugin = VPP_Plugin::instance();
$reflect = new ReflectionClass($plugin);

// Prepare WordPress posts for fallback collection
$now = time();
$past = gmdate('Y-m-d H:i:s', $now - 86400);
$future = gmdate('Y-m-d H:i:s', $now + 86400);

$published = (object) [
    'ID' => 1,
    'post_name' => 'published-post',
    'post_title' => 'Published Post',
    'post_status' => 'publish',
    'post_date_gmt' => $past,
    'post_modified_gmt' => gmdate('Y-m-d H:i:s', $now - 3600),
];
$scheduled = (object) [
    'ID' => 2,
    'post_name' => 'scheduled-post',
    'post_title' => 'Scheduled Post',
    'post_status' => 'future',
    'post_date_gmt' => $future,
    'post_modified_gmt' => $future,
];

$GLOBALS['vpp_test_posts'] = [$published, $scheduled];

$collect = $reflect->getMethod('collect_wp_blog_posts_for_sitemap');
$collect->setAccessible(true);
$fallbackEntries = $collect->invoke($plugin);
assert(count($fallbackEntries) === 1, 'Only published posts should be collected');
assert($fallbackEntries[0]['slug'] === 'published-post');
assert(strpos($fallbackEntries[0]['loc'], 'published-post') !== false);

// Ensure build_sitemap_xml uses TiDB default and WP fallback URLs
$build = $reflect->getMethod('build_sitemap_xml');
$build->setAccessible(true);
$tidbXml = $build->invoke($plugin, [
    ['slug' => 'tidb-entry', 'lastmod' => gmdate('c')],
], 'blog');
assert(strpos($tidbXml, '/b/tidb-entry') !== false, 'TiDB entries should use /b/ base');

$wpXml = $build->invoke($plugin, [
    ['slug' => 'published-post', 'lastmod' => gmdate('c'), 'loc' => $fallbackEntries[0]['loc']],
], 'blog');
assert(strpos($wpXml, $fallbackEntries[0]['loc']) !== false, 'Fallback entries should use supplied permalink');

// Verify refresh_sitemap_index lists blog files
$dir = sys_get_temp_dir() . '/vpp-sitemap-' . uniqid();
wp_mkdir_p($dir);
file_put_contents($dir . '/blog-1.xml', $wpXml);
file_put_contents($dir . '/products-1.xml', $tidbXml);

$GLOBALS['vpp_test_options'][VPP_Plugin::SITEMAP_META_OPTION] = [
    'locks' => [],
    'base_url' => 'https://example.com/sitemaps/',
];

$refresh = $reflect->getMethod('refresh_sitemap_index');
$refresh->setAccessible(true);
$storage = [
    'dir' => trailingslashit($dir),
    'url' => 'https://example.com/sitemaps/',
    'index_url' => 'https://example.com/sitemaps/sitemap_index.xml',
    'alias_index_url' => 'https://example.com/sitemaps/vpp-index.xml',
    'legacy_dir' => '',
    'legacy_url' => '',
];
$err = null;
$touched = [];
$result = $refresh->invokeArgs($plugin, [$storage, &$err, &$touched]);
assert($err === null, 'refresh_sitemap_index should not error');
assert($result === $storage['index_url']);
$indexContent = file_get_contents($dir . '/sitemap_index.xml');
assert(strpos($indexContent, 'blog-1.xml') !== false, 'Index should include blog sitemap file');

// Clean up
$files = glob($dir . '/*');
if (is_array($files)) {
    array_map('unlink', $files);
}
rmdir($dir);

echo "All tests passed\n";
