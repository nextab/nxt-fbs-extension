<?php

defined('ABSPATH') || exit;

use FluentCrm\App\Modules\MCP\Helpers\MCPHelper;
use FluentCrm\App\Services\ContactsQuery;
use FluentCrm\App\Services\PermissionManager;

/**
 * Fixes fluent-crm/list-contacts pagination.
 *
 * FluentCRM MCPHelper::paginationFromInput() writes page/per_page to $_REQUEST,
 * but the ORM paginator resolves page from FluentCrm Request (empty on MCP)
 * and ContactsQuery::paginate() never passes per_page into paginate().
 * Result: every call returns page 1 with default 15 rows.
 */
class NXT_FBS_ContactListPaginationFix
{
	public function init(): void
	{
		if (!function_exists('FluentCrmApi')) {
			return;
		}

		add_action('init', [$this, 'boot'], 4);
	}

	public function boot(): void
	{
		if (!function_exists('wp_register_ability')) {
			return;
		}

		if (function_exists('fluentcrm_get_option') && fluentcrm_get_option('mcp_enabled', 'yes') !== 'yes') {
			return;
		}

		add_action('fluent_crm/mcp_loaded', [$this, 'overrideListContactsAbility'], 20);
		add_action('fluent_crm/mcp_loaded', [$this, 'patchPaginatorResolver'], 5);
	}

	/**
	 * Make AbstractPaginator honour $_REQUEST['page'] set by MCPHelper.
	 * Helps campaigns/funnels/etc. that share the same bug.
	 */
	public function patchPaginatorResolver(): void
	{
		if (!class_exists('\FluentCrm\Framework\Pagination\AbstractPaginator')) {
			return;
		}

		\FluentCrm\Framework\Pagination\AbstractPaginator::currentPageResolver(function ($pageName = 'page') {
			$candidates = [];

			if (function_exists('FluentCrm')) {
				try {
					$app = FluentCrm('app');
					if ($app && isset($app['request'])) {
						$candidates[] = $app['request']->get($pageName);
					}
				} catch (\Throwable $e) {
					// ignore
				}
			}

			if (isset($_REQUEST[$pageName])) {
				$candidates[] = $_REQUEST[$pageName];
			}

			foreach ($candidates as $page) {
				if (filter_var($page, FILTER_VALIDATE_INT) !== false && (int) $page >= 1) {
					return (int) $page;
				}
			}

			return 1;
		});
	}

	public function overrideListContactsAbility(): void
	{
		if (!class_exists(MCPHelper::class) || !class_exists(ContactsQuery::class)) {
			return;
		}

		if (function_exists('wp_unregister_ability')) {
			wp_unregister_ability('fluent-crm/list-contacts');
		}

		wp_register_ability('fluent-crm/list-contacts', [
			'label'       => __('List Contacts', 'nxt-fbs-extension'),
			'description' => __('List/filter contacts with tags + lists inline. Pagination (page/per_page) is applied correctly. `search` matches name/email/custom field values.', 'nxt-fbs-extension'),
			'category'    => 'fluent-crm',
			'input_schema'=> [
				'type'       => 'object',
				'properties' => [
					'search'                => ['type' => 'string'],
					'tags'                  => ['type' => 'array', 'items' => ['type' => ['string', 'integer']]],
					'lists'                 => ['type' => 'array', 'items' => ['type' => ['string', 'integer']]],
					'statuses'              => ['type' => 'array', 'items' => ['type' => 'string']],
					'sms_statuses'          => ['type' => 'array', 'items' => ['type' => 'string']],
					'contact_type'          => ['type' => 'string', 'enum' => ['lead', 'customer']],
					'created_after'         => ['type' => 'string'],
					'created_before'        => ['type' => 'string'],
					'sort_by'               => ['type' => 'string', 'enum' => ['id', 'email', 'first_name', 'last_name', 'created_at', 'last_activity'], 'default' => 'id'],
					'sort_type'             => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'default' => 'DESC'],
					'page'                  => ['type' => 'integer', 'default' => 1],
					'per_page'              => ['type' => 'integer', 'default' => 15, 'description' => 'Max 100.'],
					'include_custom_fields' => ['type' => 'boolean', 'default' => false],
				],
			],
			'execute_callback'    => [$this, 'listContacts'],
			'permission_callback' => function () {
				return PermissionManager::currentUserCan('fcrm_read_contacts');
			},
			'meta' => [
				'show_in_rest' => true,
				'mcp'          => ['public' => true],
				'annotations'  => ['readonly' => true],
			],
		]);
	}

	/**
	 * @param array|object $params
	 * @return array|\WP_Error
	 */
	public function listContacts($params)
	{
		$params = (array) $params;

		try {
			$validation = MCPHelper::validateUniversalFilter($params);
			if (is_wp_error($validation)) {
				return $validation;
			}

			$page    = max(1, (int) ($params['page'] ?? 1));
			$perPage = (int) ($params['per_page'] ?? 15);
			if ($perPage < 1) {
				$perPage = 15;
			}
			$perPage = min(100, $perPage);

			$_REQUEST['page']     = $page;
			$_REQUEST['per_page'] = $perPage;

			if (function_exists('FluentCrm')) {
				try {
					$app = FluentCrm('app');
					if ($app && isset($app['request']) && method_exists($app['request'], 'merge')) {
						$app['request']->merge([
							'page'     => $page,
							'per_page' => $perPage,
						]);
					}
				} catch (\Throwable $e) {
					// ignore
				}
			}

			$args         = MCPHelper::buildContactsQueryArgs($params);
			$args['with'] = ['tags', 'lists'];

			if (!empty($params['include_custom_fields'])) {
				$args['custom_fields'] = true;
			}

			$cq = new ContactsQuery($args);
			MCPHelper::applyDateFilters($cq, $params);

			$model = $cq->getModel();
			if (method_exists($model, 'setPerPage')) {
				$model->setPerPage($perPage);
			}

			$paginated = $model->paginate($perPage, ['*'], 'page', $page);

			if (!empty($params['include_custom_fields'])) {
				foreach ($paginated->items() as $subscriber) {
					$subscriber->custom_fields = $subscriber->custom_fields();
				}
			}

			return MCPHelper::formatContactList($paginated, !empty($params['include_custom_fields']));
		} catch (\Throwable $e) {
			do_action('fluent_crm/mcp_tool_exception', $e, 'fluent-crm/list-contacts', $params);

			return new \WP_Error('failed', $e->getMessage(), [
				'tool'      => 'fluent-crm/list-contacts',
				'exception' => get_class($e),
			]);
		}
	}
}
