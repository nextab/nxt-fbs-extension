<?php

defined('ABSPATH') || exit;

/**
 * Keeps Fluent Boards + FluentCRM MCP servers registered when adapter is present.
 *
 * Core plugins gate MCPInit on init:5 + mcp_enabled. If the option was flipped
 * off in FluentKit, or init raced the adapter polyfill, Claude sees no tools.
 * This module force-enables the options (once) and re-runs MCPInit safely.
 */
class NXT_FBS_McpEnsure
{
	const FLAG_BOARDS = 'nxt_fbs_mcp_ensure_boards_enabled';
	const FLAG_CRM    = 'nxt_fbs_mcp_ensure_crm_enabled';

	public function init(): void
	{
		add_action('init', [$this, 'boot'], 6);
	}

	public function boot(): void
	{
		if (!function_exists('wp_register_ability')) {
			return;
		}

		$this->ensureBoardsMcp();
		$this->ensureCrmMcp();
	}

	private function ensureBoardsMcp(): void
	{
		if (!defined('FLUENT_BOARDS') || !class_exists('\FluentBoards\App\Modules\MCP\MCPInit')) {
			return;
		}

		if (function_exists('fluent_boards_get_option') && function_exists('fluent_boards_update_option')) {
			if (fluent_boards_get_option('mcp_enabled', 'yes') !== 'yes' && !get_option(self::FLAG_BOARDS)) {
				fluent_boards_update_option('mcp_enabled', 'yes');
				update_option(self::FLAG_BOARDS, '1', false);
			}
		}

		if (function_exists('fluent_boards_get_option') && fluent_boards_get_option('mcp_enabled', 'yes') !== 'yes') {
			return;
		}

		if (!$this->hasActionCallback('mcp_adapter_init', '\FluentBoards\App\Modules\MCP\MCPInit', 'registerCustomServer')) {
			(new \FluentBoards\App\Modules\MCP\MCPInit())->init();
		}
	}

	private function ensureCrmMcp(): void
	{
		if (!function_exists('FluentCrmApi') || !class_exists('\FluentCrm\App\Modules\MCP\MCPInit')) {
			return;
		}

		if (function_exists('fluentcrm_get_option') && function_exists('fluentcrm_update_option')) {
			if (fluentcrm_get_option('mcp_enabled', 'yes') !== 'yes' && !get_option(self::FLAG_CRM)) {
				fluentcrm_update_option('mcp_enabled', 'yes');
				update_option(self::FLAG_CRM, '1', false);
			}
		}

		if (function_exists('fluentcrm_get_option') && fluentcrm_get_option('mcp_enabled', 'yes') !== 'yes') {
			return;
		}

		if (!$this->hasActionCallback('mcp_adapter_init', '\FluentCrm\App\Modules\MCP\MCPInit', 'registerCustomServer')) {
			(new \FluentCrm\App\Modules\MCP\MCPInit())->init();
		}
	}

	/**
	 * @param class-string $class
	 */
	private function hasActionCallback(string $hook, string $class, string $method): bool
	{
		global $wp_filter;

		if (empty($wp_filter[$hook]) || !($wp_filter[$hook] instanceof \WP_Hook)) {
			return false;
		}

		foreach ($wp_filter[$hook]->callbacks as $callbacks) {
			foreach ($callbacks as $entry) {
				$cb = $entry['function'] ?? null;
				if (!is_array($cb) || count($cb) < 2) {
					continue;
				}

				$object = $cb[0];
				$name   = $cb[1] ?? '';

				if ($name !== $method) {
					continue;
				}

				if (is_object($object) && is_a($object, $class)) {
					return true;
				}

				if (is_string($object) && $object === $class) {
					return true;
				}
			}
		}

		return false;
	}
}
