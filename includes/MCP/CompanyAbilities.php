<?php

defined('ABSPATH') || exit;

/**
 * FluentCRM company MCP ability definitions for nxt-fbs-extension.
 */
class NXT_FBS_CompanyAbilities
{
	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function getDefinitions(): array
	{
		return [
			'fluent-crm/list-companies' => [
				'label'       => __('List Companies', 'nxt-fbs-extension'),
				'description' => __('List/filter FluentCRM companies. Supports search, type, industry, sort, and pagination.', 'nxt-fbs-extension'),
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'search'    => ['type' => 'string'],
						'type'      => ['type' => 'string'],
						'industry'  => ['type' => 'string'],
						'sort_by'   => ['type' => 'string', 'enum' => ['id', 'name', 'email', 'created_at', 'updated_at'], 'default' => 'id'],
						'sort_type' => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'default' => 'DESC'],
						'page'      => ['type' => 'integer', 'default' => 1],
						'per_page'  => ['type' => 'integer', 'default' => 15, 'description' => 'Max 100.'],
					],
				],
				'execute_callback'    => [NXT_FBS_CompanyTools::class, 'listCompanies'],
				'permission_callback' => static function () {
					return NXT_FBS_CompanyMCPHelper::currentUserCanManageCompanies();
				},
				'annotations' => ['readonly' => true],
			],

			'fluent-crm/get-company' => [
				'label'       => __('Get Company', 'nxt-fbs-extension'),
				'description' => __('Get one company by company_id, email, or name. Optional include: owner, subscribers, notes, custom_values.', 'nxt-fbs-extension'),
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'company_id' => ['type' => 'integer'],
						'email'      => ['type' => 'string'],
						'name'       => ['type' => 'string'],
						'include'    => [
							'type'  => 'array',
							'items' => ['type' => 'string', 'enum' => ['owner', 'subscribers', 'notes', 'custom_values']],
						],
					],
				],
				'execute_callback'    => [NXT_FBS_CompanyTools::class, 'getCompany'],
				'permission_callback' => static function () {
					return NXT_FBS_CompanyMCPHelper::currentUserCanManageCompanies();
				},
				'annotations' => ['readonly' => true],
			],

			'fluent-crm/upsert-company' => [
				'label'       => __('Upsert Company', 'nxt-fbs-extension'),
				'description' => __('Create or update a company. Provide id to update, otherwise name is required. Supports owner_id and custom_values.', 'nxt-fbs-extension'),
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'id'               => ['type' => 'integer'],
						'name'             => ['type' => 'string'],
						'email'            => ['type' => 'string'],
						'phone'            => ['type' => 'string'],
						'industry'         => ['type' => 'string'],
						'type'             => ['type' => 'string'],
						'description'      => ['type' => 'string'],
						'website'          => ['type' => 'string'],
						'owner_id'         => ['type' => 'integer'],
						'address_line_1'   => ['type' => 'string'],
						'address_line_2'   => ['type' => 'string'],
						'postal_code'      => ['type' => 'string'],
						'city'             => ['type' => 'string'],
						'state'            => ['type' => 'string'],
						'country'          => ['type' => 'string'],
						'timezone'         => ['type' => 'string'],
						'employees_number' => ['type' => 'string'],
						'logo'             => ['type' => 'string'],
						'linkedin_url'     => ['type' => 'string'],
						'facebook_url'     => ['type' => 'string'],
						'twitter_url'      => ['type' => 'string'],
						'date_of_start'    => ['type' => 'string'],
						'custom_values'    => ['type' => 'object'],
					],
				],
				'execute_callback'    => [NXT_FBS_CompanyTools::class, 'upsertCompany'],
				'permission_callback' => static function () {
					return NXT_FBS_CompanyMCPHelper::currentUserCanManageCompanies();
				},
			],

			'fluent-crm/delete-company' => [
				'label'       => __('Delete Company', 'nxt-fbs-extension'),
				'description' => __('Permanently delete a company by company_id, email, or name.', 'nxt-fbs-extension'),
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'company_id' => ['type' => 'integer'],
						'email'      => ['type' => 'string'],
						'name'       => ['type' => 'string'],
					],
				],
				'execute_callback'    => [NXT_FBS_CompanyTools::class, 'deleteCompany'],
				'permission_callback' => static function () {
					return NXT_FBS_CompanyMCPHelper::currentUserCanDeleteCompanies();
				},
				'annotations' => ['destructive' => true],
			],

			'fluent-crm/attach-contacts-to-company' => [
				'label'       => __('Attach Contacts to Company', 'nxt-fbs-extension'),
				'description' => __('Link contacts to one or more companies. Requires contact_ids and company_ids (or company_id).', 'nxt-fbs-extension'),
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'contact_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
						'company_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
						'company_id'  => ['type' => 'integer'],
					],
					'required' => ['contact_ids'],
				],
				'execute_callback'    => [NXT_FBS_CompanyTools::class, 'attachContactsToCompany'],
				'permission_callback' => static function () {
					return NXT_FBS_CompanyMCPHelper::currentUserCanManageCompanies();
				},
			],

			'fluent-crm/detach-contacts-from-company' => [
				'label'       => __('Detach Contacts from Company', 'nxt-fbs-extension'),
				'description' => __('Remove contact-company links. Requires contact_ids and company_ids (or company_id).', 'nxt-fbs-extension'),
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'contact_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
						'company_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
						'company_id'  => ['type' => 'integer'],
					],
					'required' => ['contact_ids'],
				],
				'execute_callback'    => [NXT_FBS_CompanyTools::class, 'detachContactsFromCompany'],
				'permission_callback' => static function () {
					return NXT_FBS_CompanyMCPHelper::currentUserCanManageCompanies();
				},
			],

			'fluent-crm/add-company-note' => [
				'label'       => __('Add Company Note', 'nxt-fbs-extension'),
				'description' => __('Add a note to a company. Provide company_id, email, or name plus title and description.', 'nxt-fbs-extension'),
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'company_id'  => ['type' => 'integer'],
						'email'       => ['type' => 'string'],
						'name'        => ['type' => 'string'],
						'type'        => ['type' => 'string', 'enum' => ['note', 'call', 'email', 'meeting', 'quote'], 'default' => 'note'],
						'title'       => ['type' => 'string'],
						'description' => ['type' => 'string'],
						'created_at'  => ['type' => 'string'],
					],
					'required' => ['title', 'description'],
				],
				'execute_callback'    => [NXT_FBS_CompanyTools::class, 'addCompanyNote'],
				'permission_callback' => static function () {
					return NXT_FBS_CompanyMCPHelper::currentUserCanManageCompanies();
				},
			],

			'fluent-crm/delete-company-note' => [
				'label'       => __('Delete Company Note', 'nxt-fbs-extension'),
				'description' => __('Delete a company note by note_id. Find note ids via get-company include notes.', 'nxt-fbs-extension'),
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'note_id' => ['type' => 'integer'],
					],
					'required' => ['note_id'],
				],
				'execute_callback'    => [NXT_FBS_CompanyTools::class, 'deleteCompanyNote'],
				'permission_callback' => static function () {
					return NXT_FBS_CompanyMCPHelper::currentUserCanManageCompanies();
				},
				'annotations' => ['destructive' => true],
			],
		];
	}

	/**
	 * @return array<int, string>
	 */
	public static function names(): array
	{
		return array_keys(self::getDefinitions());
	}

	public static function register(): void
	{
		foreach (self::getDefinitions() as $name => $definition) {
			$args = [
				'label'               => $definition['label'],
				'description'         => $definition['description'],
				'category'            => 'fluent-crm',
				'execute_callback'    => self::wrapExecuteCallback($name, $definition['execute_callback']),
				'permission_callback' => $definition['permission_callback'],
				'meta'                => [
					'show_in_rest' => true,
					'mcp'          => [
						'public' => true,
					],
				],
			];

			if (!empty($definition['input_schema'])) {
				$args['input_schema'] = $definition['input_schema'];
			}

			if (!empty($definition['annotations'])) {
				$args['meta']['annotations'] = $definition['annotations'];
			}

			wp_register_ability($name, $args);
		}
	}

	/**
	 * @param callable $callback
	 * @return callable
	 */
	private static function wrapExecuteCallback(string $toolName, $callback)
	{
		return static function ($params) use ($toolName, $callback) {
			try {
				return call_user_func($callback, $params);
			} catch (\Throwable $e) {
				do_action('fluent_crm/mcp_tool_exception', $e, $toolName, $params);

				$details = [
					'tool'      => $toolName,
					'exception' => get_class($e),
				];

				if (defined('WP_DEBUG') && WP_DEBUG) {
					$details['file']  = $e->getFile() . ':' . $e->getLine();
					$details['trace'] = array_slice(explode("\n", $e->getTraceAsString()), 0, 5);
				}

				return NXT_FBS_CompanyMCPHelper::error(
					'tool_exception',
					$e->getMessage(),
					$details
				);
			}
		};
	}
}
