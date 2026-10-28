<?php

defined('ABSPATH') || exit;

/**
 * Enqueues the Show-more fallback for Fluent Boards task descriptions.
 */
class NXT_FBS_ShowMoreFix
{
	public function init(): void
	{
		add_action('admin_enqueue_scripts', [$this, 'enqueue']);
		add_action('wp_enqueue_scripts', [$this, 'enqueue']);
	}

	public function enqueue(): void
	{
		wp_enqueue_script(
			'nxt-fbs-show-more-fix',
			NXT_FBS_EXT_URL . 'assets/show-more-fix.js',
			[],
			NXT_FBS_EXT_VERSION,
			true
		);
	}
}
