<?php

defined('ABSPATH') || exit;

/**
 * FluentBoards 2.2 lädt in der Listenansicht je Phase nur 20 Karten und lädt
 * nie nach (Scroll-Trigger hängt an einem Container, der dort nicht scrollt).
 * Das Skript lädt das Board über den Core komplett und zeigt alle Karten.
 */
class NXT_FBS_ListViewAllTasks
{
	public function init(): void
	{
		// Feuert in WP-Admin und im Frontend-Portal (FrontendRenderer ruft
		// AdminMenuHandler::enqueueAssets); die Portal-Freigabe steht in der
		// Hauptdatei (portal_asset_listed_slugs).
		add_action('fluent_boards/after_enqueue_assets', [$this, 'enqueue']);
	}

	public function enqueue(): void
	{
		wp_enqueue_script(
			'nxt-fbs-list-view-all-tasks',
			NXT_FBS_EXT_URL . 'assets/list-view-all-tasks.js',
			[],
			NXT_FBS_EXT_VERSION,
			true
		);
	}
}
