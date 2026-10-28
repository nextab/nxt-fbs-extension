<?php

defined('ABSPATH') || exit;

/**
 * Forces MCP endpoint URLs onto the plain rest_route query form.
 *
 * Some nginx setups return a hard 404 for /wp-json/* — WordPress never
 * sees those requests. PATHINFO URLs (index.php/wp-json/...) work, but many
 * MCP clients were configured with the pretty form. Plain
 * index.php?rest_route=/namespace/mcp always hits PHP.
 *
 * Applies to every MCP server registered as <namespace>/mcp, not just the
 * Fluent ones.
 */
class NXT_FBS_McpEndpointCompat
{
	/** @var list<string> Always listed in the health panel, even if not registered (yet). */
	private const KNOWN_MCP_PATHS = [
		'fluent-boards/mcp',
		'fluent-crm/mcp',
	];

	public function init(): void
	{
		add_filter('rest_url', [$this, 'forcePlainMcpUrl'], 20, 2);
		add_filter('fluent_kit/mcp_products', [$this, 'rewriteProductEndpoints'], 100, 2);
	}

	/**
	 * @param string $url
	 * @param string $path
	 */
	public function forcePlainMcpUrl($url, $path = ''): string
	{
		$normalized = $this->normalizeMcpPath((string) $path);
		if ($normalized === null) {
			$normalized = $this->detectMcpPathFromUrl((string) $url);
		}

		if ($normalized === null) {
			return (string) $url;
		}

		return $this->plainRestUrl($normalized);
	}

	/**
	 * @param array<int, mixed> $products
	 * @param array<string, mixed> $adapter
	 * @return array<int, mixed>
	 */
	public function rewriteProductEndpoints($products, $adapter = []): array
	{
		if (!is_array($products)) {
			return [];
		}

		foreach ($products as $i => $product) {
			if (!is_array($product) || empty($product['endpoint_url'])) {
				continue;
			}

			$path = $this->detectMcpPathFromUrl((string) $product['endpoint_url']);
			if ($path === null) {
				continue;
			}

			$products[$i]['endpoint_url'] = $this->plainRestUrl($path);
		}

		return $products;
	}

	public static function plainRestUrl(string $path): string
	{
		$path = '/' . ltrim($path, '/');

		return add_query_arg('rest_route', $path, home_url('/index.php'));
	}

	public static function prettyRestUrl(string $path): string
	{
		$path = ltrim($path, '/');

		return home_url('/wp-json/' . $path);
	}

	public static function pathInfoRestUrl(string $path): string
	{
		$path = ltrim($path, '/');

		return home_url('/index.php/wp-json/' . $path);
	}

	/**
	 * @return list<array{slug: string, path: string, plain: string, pathinfo: string, pretty: string}>
	 */
	public static function endpointCatalog(): array
	{
		$paths = self::KNOWN_MCP_PATHS;
		foreach (array_keys(rest_get_server()->get_routes()) as $route) {
			if (preg_match('#^/([a-z0-9_-]+)/mcp$#', (string) $route, $m)) {
				$paths[] = $m[1] . '/mcp';
			}
		}

		$items = [];

		foreach (array_unique($paths) as $path) {
			$slug = explode('/', $path)[0];
			$items[] = [
				'slug'     => $slug,
				'path'     => $path,
				'plain'    => self::plainRestUrl($path),
				'pathinfo' => self::pathInfoRestUrl($path),
				'pretty'   => self::prettyRestUrl($path),
			];
		}

		return $items;
	}

	/**
	 * Server-side probe: does pretty /wp-json hit WordPress?
	 */
	public static function prettyRestReachable(): bool
	{
		$response = wp_remote_get(home_url('/wp-json/'), [
			'timeout'     => 5,
			'redirection' => 0,
			'sslverify'   => apply_filters('https_local_ssl_verify', false),
		]);

		if (is_wp_error($response)) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$body = (string) wp_remote_retrieve_body($response);

		if ($code !== 200) {
			return false;
		}

		$data = json_decode($body, true);

		return is_array($data) && isset($data['namespaces']);
	}

	private function normalizeMcpPath(string $path): ?string
	{
		$path = strtolower(trim($path, "/ \t\n\r\0\x0B"));
		$path = preg_replace('#^wp-json/#', '', $path) ?? $path;

		if (preg_match('#^([a-z0-9_-]+)/mcp(?:/|$)#', $path, $m)) {
			return $m[1] . '/mcp';
		}

		return null;
	}

	private function detectMcpPathFromUrl(string $url): ?string
	{
		if ($url === '') {
			return null;
		}

		$query = [];
		$parts = wp_parse_url($url);
		if (!empty($parts['query'])) {
			parse_str($parts['query'], $query);
			if (!empty($query['rest_route'])) {
				return $this->normalizeMcpPath((string) $query['rest_route']);
			}
		}

		$path = isset($parts['path']) ? (string) $parts['path'] : '';
		if (preg_match('#/(?:index\.php/)?wp-json/([^?\s]+)#', $path, $m)) {
			return $this->normalizeMcpPath($m[1]);
		}

		return null;
	}
}
