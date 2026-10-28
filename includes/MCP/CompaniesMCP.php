<?php

defined('ABSPATH') || exit;

/**
 * Registers FluentCRM company abilities on the core FluentCRM MCP server.
 *
 * Timing mirrors FluentCRM core: hard MCP gates run on `init` (priority 4,
 * before FluentCRM's MCPInit at priority 5). Checking `wp_register_ability`
 * on `plugins_loaded` is too early — the Abilities API / adapter polyfill
 * is often not loaded yet, which silently skipped all company tool hooks.
 */
class NXT_FBS_CompaniesMCP
{
	/** @var string */
	private $skipReason = '';

	public function init(): void
	{
		if (!function_exists('FluentCrmApi')) {
			return;
		}

		add_action('init', [$this, 'boot'], 4);
	}

	public function boot(): void
	{
		if (!$this->prerequisitesMet()) {
			add_action('admin_notices', [$this, 'renderPrerequisiteNotice']);
			return;
		}

		require_once NXT_FBS_EXT_PATH . 'includes/MCP/CompanyMCPHelper.php';
		require_once NXT_FBS_EXT_PATH . 'includes/MCP/CompanyTools.php';
		require_once NXT_FBS_EXT_PATH . 'includes/MCP/CompanyAbilities.php';

		add_action('fluent_crm/mcp_loaded', [$this, 'onMcpLoaded']);
		add_filter('fluent_crm/mcp_ability_names', [$this, 'appendAbilityNames']);
		add_filter('fluent_crm/mcp_safety_levels', [$this, 'filterSafetyLevels']);
		add_filter('fluent_crm/mcp_capabilities', [$this, 'filterCapabilities']);
		add_filter('fluent_crm/mcp_ai_guidelines', [$this, 'filterGuidelines']);
	}

	public function onMcpLoaded(): void
	{
		NXT_FBS_CompanyAbilities::register();

		// FluentCRM 3.2.6 hat den Cache entfernt. Ohne Guard wirft der Aufruf in
		// wp_abilities_api_init – das kippt jeden MCP-Server, der die Abilities
		// zuerst anfasst, auch den eines anderen Plugins.
		if (class_exists('\FluentCrm\App\Modules\MCP\Tools\ContextTools')
			&& method_exists('\FluentCrm\App\Modules\MCP\Tools\ContextTools', 'invalidateCache')
		) {
			\FluentCrm\App\Modules\MCP\Tools\ContextTools::invalidateCache();
		}
	}

	/**
	 * @param array<int, string> $names
	 * @return array<int, string>
	 */
	public function appendAbilityNames(array $names): array
	{
		return array_values(array_unique(array_merge($names, NXT_FBS_CompanyAbilities::names())));
	}

	/**
	 * @param array<string, string> $levels
	 * @return array<string, string>
	 */
	public function filterSafetyLevels(array $levels): array
	{
		$levels['fluent-crm/list-companies']               = 'readonly';
		$levels['fluent-crm/get-company']                  = 'readonly';
		$levels['fluent-crm/upsert-company']               = 'creates_or_mutates_draft';
		$levels['fluent-crm/add-company-note']             = 'creates_or_mutates_draft';
		$levels['fluent-crm/attach-contacts-to-company']   = 'creates_or_mutates_draft';
		$levels['fluent-crm/detach-contacts-from-company'] = 'creates_or_mutates_draft';
		$levels['fluent-crm/delete-company']               = 'destructive_irrecoverable';
		$levels['fluent-crm/delete-company-note']          = 'destructive_irrecoverable';

		return $levels;
	}

	/**
	 * @param array<string, mixed> $capabilities
	 * @return array<string, mixed>
	 */
	public function filterCapabilities(array $capabilities): array
	{
		$capabilities['version'] = '1.5.0-nxt-companies';

		$supports = isset($capabilities['supports']) && is_array($capabilities['supports'])
			? $capabilities['supports']
			: [];

		$supports[] = 'companies';
		$supports[] = 'list_companies';
		$supports[] = 'upsert_company';
		$supports[] = 'company_notes';
		$supports[] = 'attach_contacts_to_company';

		$capabilities['supports'] = array_values(array_unique($supports));

		return $capabilities;
	}

	/**
	 * @param string $guidelines
	 */
	public function filterGuidelines($guidelines): string
	{
		$extra = ' Companies: use fluent-crm/list-companies and fluent-crm/get-company'
			. ' (include owner/subscribers/notes/custom_values). Upsert via fluent-crm/upsert-company'
			. ' (match by id or name). Link contacts with attach/detach-contacts-to-company.'
			. ' Company custom field values live on get-company custom_values — not under custom_fields_schema.contact.';

		return rtrim((string) $guidelines) . $extra;
	}

	public function renderPrerequisiteNotice(): void
	{
		if (!current_user_can('manage_options') || $this->skipReason === '') {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>NXT FluentCRM Companies MCP:</strong> '
			. esc_html($this->skipReason)
			. '</p></div>';
	}

	private function prerequisitesMet(): bool
	{
		if (!function_exists('wp_register_ability')) {
			$this->skipReason = __('WordPress Abilities API / Fluent Toolkit fehlt — Company-MCP-Tools werden nicht registriert.', 'nxt-fbs-extension');
			return false;
		}

		if (!class_exists('\FluentCrm\App\Services\Helper')) {
			$this->skipReason = __('FluentCRM Helper nicht geladen.', 'nxt-fbs-extension');
			return false;
		}

		if (!\FluentCrm\App\Services\Helper::isCompanyEnabled()) {
			$this->skipReason = __('FluentCRM Companies-Modul ist deaktiviert. Bitte unter FluentCRM → Settings → Business aktivieren.', 'nxt-fbs-extension');
			return false;
		}

		if (function_exists('fluentcrm_get_option') && fluentcrm_get_option('mcp_enabled', 'yes') !== 'yes') {
			$this->skipReason = __('FluentCRM MCP ist deaktiviert. Bitte unter FluentCRM → Settings → AI / MCP einschalten.', 'nxt-fbs-extension');
			return false;
		}

		return true;
	}
}
