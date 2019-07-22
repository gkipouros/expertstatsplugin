<?php
/**
 *
 * @package wpcable
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class wpcable_api_data {

	/**
	 * List of custom table names.
	 *
	 * @var array
	 */
	public $tables = [];

	/**
	 * An wpcable_api_calls object for communication with the API.
	 *
	 * @var wpcable_api_calls
	 */
	private $api_calls = null;

	/**
	 * Enable API debugging?
	 *
	 * @var bool
	 */
	private $debug = null;

	/**
	 * Initialize the object properties.
	 */
	public function __construct() {
		global $wpdb;

		$this->tables = [
			'transcactions' => $wpdb->prefix . 'codeable_transcactions',
			'clients'       => $wpdb->prefix . 'codeable_clients',
			'amounts'       => $wpdb->prefix . 'codeable_amounts',
			'tasks'         => $wpdb->prefix . 'codeable_tasks',
		];

		$this->api_calls = wpcable_api_calls::inst();

		$this->debug = defined( 'WP_DEBUG' ) ? WP_DEBUG : false;
	}

	/**
	 * Returns a list of API functions that should be called in sequence.
	 *
	 * @return array
	 */
	public function prepare_queue() {
		$queue = [];

		$queue[] = [
			'task'  => 'profile',
			'label' => 'User profile',
			'page'  => 0,
			'paged' => false,
		];
		$queue[] = [
			'task'  => 'transactions',
			'label' => 'Transactions',
			'page'  => 1,
			'paged' => true,
		];
		$queue[] = [
			'task'  => 'task:lost',
			'label' => 'Tasks (lost)',
			'page'  => 0,
			'paged' => false,
		];
		$queue[] = [
			'task'  => 'task:pending',
			'label' => 'Tasks (pending)',
			'page'  => 1,
			'paged' => true,
		];
		$queue[] = [
			'task'  => 'task:active',
			'label' => 'Tasks (active)',
			'page'  => 1,
			'paged' => true,
		];
		$queue[] = [
			'task'  => 'task:preferred',
			'label' => 'Tasks (preferred)',
			'page'  => 1,
			'paged' => true,
		];
		$queue[] = [
			'task'  => 'task:in-progress',
			'label' => 'Tasks (in progress)',
			'page'  => 1,
			'paged' => true,
		];
		$queue[] = [
			'task'  => 'task:favourites',
			'label' => 'Tasks (favourites)',
			'page'  => 1,
			'paged' => true,
		];
		$queue[] = [
			'task'  => 'task:promoted',
			'label' => 'Tasks (promoted)',
			'page'  => 1,
			'paged' => false,
		];
		$queue[] = [
			'task'  => 'task:hidden',
			'label' => 'Tasks (hidden)',
			'page'  => 1,
			'paged' => true,
		];
		$queue[] = [
			'task'  => 'task:archived',
			'label' => 'Tasks (archived)',
			'page'  => 1,
			'paged' => true,
		];

		return $queue;
	}


	/**
	 * Processes a single API task and returns the updated task details or false,
	 * when the task is completed.
	 *
	 * @param  array $item The task details (generated by prepare_queue above).
	 * @return array|false
	 */
	public function process_queue( $item ) {
		$curr_page = max( 1, (int) $item['page'] );
		$next_page = false;

		switch ( $item['task'] ) {
			case 'profile':
				$this->store_profile();
				break;

			case 'transactions':
				$next_page = $this->store_transactions( $curr_page );
				break;

			case 'task:lost':
				$this->mark_tasks_lost();
				break;

			case 'task:pending':
				$next_page = $this->store_tasks( 'pending', $curr_page );
				break;

			case 'task:active':
				$next_page = $this->store_tasks( 'active', $curr_page );
				break;

			case 'task:preferred':
				$next_page = $this->store_tasks( 'preferred', $curr_page );
				break;

			case 'task:in-progress':
				$next_page = $this->store_tasks( 'in-progress', $curr_page );
				break;

			case 'task:favourites':
				$next_page = $this->store_tasks( 'favourites', $curr_page );
				break;

			case 'task:promoted':
				$next_page = $this->store_tasks( 'promoted', $curr_page );
				break;

			case 'task:hidden':
				$next_page = $this->store_tasks( 'hidden_tasks', $curr_page );
				break;

			case 'task:archived':
				$next_page = $this->store_tasks( 'archived', $curr_page );
				break;
		}

		if ( $next_page && $item['paged'] ) {
			$item['page'] = $next_page;
		} else {
			$item = false;
		}

		wpcable_cache::flush();
		return $item;
	}

	/**
	 * Fetches the profile details AND THE AUTH_TOKEN from codeable.
	 *
	 * @return void
	 */
	private function store_profile() {
		codeable_page_requires_login( __( 'API Refresh', 'wpcable' ) );

		$account_details = $this->api_calls->self();

		update_option( 'wpcable_account_details', $account_details );
	}

	/**
	 * Fetch transactions from the API and store them in our custom tables.
	 *
	 * @param  int $page Which page to fetch.
	 * @return int Number of he next page, or false when no next page exists.
	 */
	private function store_transactions( $page ) {
		global $wpdb;

		codeable_page_requires_login( __( 'API Refresh', 'wpcable' ) );

		if ( $this->debug ) {
			$wpdb->show_errors();
		}

		$single_page = $this->api_calls->transactions_page( $page );

		if ( 2 === $page ) {
			update_option( 'wpcable_average', $single_page['average_task_size'] );
			update_option( 'wpcable_balance', $single_page['balance'] );
			update_option( 'wpcable_revenue', $single_page['revenue'] );
		}

		if ( empty( $single_page['transactions'] ) ) {
			return false;
		} else {

			// Get all data to the DB.
			foreach ( $single_page['transactions'] as $tr ) {

				// Check if transactions already exists.
				$check = $wpdb->get_results(
					"SELECT COUNT(1) AS totalrows
					FROM `{$this->tables['transcactions']}`
					WHERE id = '{$tr['id']}';
					"
				);

				$exists = $check[0]->totalrows > 0;

				$new_tr = [
					'id'             => $tr['id'],
					'description'    => $tr['description'],
					'dateadded'      => date( 'Y-m-d H:i:s', $tr['timestamp'] ),
					'fee_percentage' => $tr['fee_percentage'],
					'fee_amount'     => $tr['fee_amount'],
					'task_type'      => $tr['task']['kind'],
					'task_id'        => $tr['task']['id'],
					'task_title'     => $tr['task']['title'],
					'parent_task_id' => ( $tr['task']['parent_task_id'] > 0 ? $tr['task']['parent_task_id'] : 0 ),
					'preferred'      => $tr['task']['current_user_is_preferred_contractor'],
					'client_id'      => $tr['task_client']['id'],
					'last_sync'      => time(),
				];

				// the API is returning some blank rows, ensure we have a valid client_id.
				if ( $new_tr['id'] && is_int( $new_tr['id'] ) ) {
					if ( $exists ) {
						$db_res = $wpdb->update(
							$this->tables['transcactions'],
							$new_tr,
							[ 'id' => $tr['id'] ]
						);
					} else {
						$db_res = $wpdb->insert(
							$this->tables['transcactions'],
							$new_tr
						);
					}
				}

				if ( $db_res === false ) {
					wp_die(
						'Could not insert transactions ' .
						$tr['id'] . ':' .
						$wpdb->print_error()
					);
				}

				$this->store_client( $tr['task_client'] );
				$this->store_amount(
					$tr['task']['id'],
					$tr['task_client']['id'],
					$tr['credit_amounts'],
					$tr['debit_amounts']
				);
			}

			return $page + 1;
		}
	}

	/**
	 * Marks all pending tasks as "lost", since the `store_tasks()` method will only
	 * receive pending/won tasks. This way we know, that all tasks that were not
	 * fetched by the `store_tasks()` method are not available for us anymore.
	 *
	 * @return void
	 */
	private function mark_tasks_lost() {
		global $wpdb;

		$lost_state = [
			'state'      => 'lost',
			'estimate'   => false,
			'hidden'     => true,
			'promoted'   => false,
			'subscribed' => false,
			'favored'    => false,
			'preferred'  => false,
		];

		$wpdb->update(
			$this->tables['tasks'],
			$lost_state,
			[ 'state' => 'published' ]
		);

		$wpdb->update(
			$this->tables['tasks'],
			$lost_state,
			[ 'state' => 'estimated' ]
		);

		$wpdb->update(
			$this->tables['tasks'],
			$lost_state,
			[ 'state' => 'hired' ]
		);
	}

	/**
	 * Fetch tasks from the API and store them in our custom tables.
	 *
	 * @param  string $filter The task-filter to apply.
	 * @param  int    $page   The page to load from API.
	 * @return int    Number of he next page, or false when no next page exists.
	 */
	private function store_tasks( $filter, $page ) {
		global $wpdb;

		codeable_page_requires_login( __( 'API Refresh', 'wpcable' ) );

		if ( $this->debug ) {
			$wpdb->show_errors();
		}

		$single_page        = $this->api_calls->tasks_page( $filter, $page );
		$cancel_after_hours = 24 * (int) get_option( 'wpcable_cancel_after_days', 180 );

		if ( empty( $single_page ) ) {
			return false;
		} else {

			// Get all data to the DB.
			foreach ( $single_page as $task ) {

				// Check if the task already exists.
				$check = $wpdb->get_results(
					"SELECT COUNT(1) AS totalrows
					FROM `{$this->tables['tasks']}`
					WHERE task_id = '{$task['id']}';
					"
				);

				// If the record exists then continue with next filter.
				$exists = $check[0]->totalrows > 0;

				$new_task = [
					'task_id'      => $task['id'],
					'client_id'    => $task['client']['id'],
					'title'        => $task['title'],
					'estimate'     => ! empty( $task['estimatable'] ),
					'hidden'       => ! empty( $task['hidden_by_current_user'] ),
					'promoted'     => ! empty( $task['promoted_task'] ),
					'subscribed'   => ! empty( $task['subscribed_by_current_user'] ),
					'favored'      => ! empty( $task['favored_by_current_user'] ),
					'preferred'    => ! empty( $task['current_user_is_preferred_contractor'] ),
					'client_fee'   => (float) $task['prices']['client_fee_percentage'],
					'state'        => $task['state'],
					'kind'         => $task['kind'],
					'value'        => (float) $task['prices']['contractor_earnings'],
					'value_client' => (float) $task['prices']['client_price_after_discounts'],
					'last_sync'    => time(),
				];

				if ( ! empty( $task['last_event']['object']['timestamp'] ) ) {
					$new_task['last_activity'] = (int) $task['last_event']['object']['timestamp'];
					$new_task['last_activity_by'] = '';
				} elseif ( ! empty( $task['last_event']['object']['published_at'] ) ) {
					$new_task['last_activity'] = (int) $task['last_event']['object']['published_at'];
					$new_task['last_activity_by'] = '';
				}

				if ( ! empty( $task['last_event']['user']['full_name'] ) ) {
					$new_task['last_activity_by'] = $task['last_event']['user']['full_name'];
				}

				// Some simple rules to automatically detect the correct flag for tasks.
				if ( 'canceled' === $task['state'] ) {
					// Tasks that were canceled by the client obviously are lost.
					$new_task['flag'] = 'lost';
				} elseif ( $new_task['hidden'] ) {
					// Tasks that I hide from my Codeable list are "lost for us".
					$new_task['flag'] = 'lost';
				} elseif ( ! empty( $new_task['last_activity'] ) ) {
					// This means that the workroom is public or private for me.
					if ( 'completed' === $task['state'] ) {
						$new_task['flag'] = 'completed';
					}
					if ( 'paid' === $task['state'] ) {
						$new_task['flag'] = 'won';
					}
					if ( 'hired' === $task['state'] ) {
						$new_task['flag'] = 'estimated';
					}
				} elseif ( empty( $new_task['last_activity'] ) ) {
					// This workroom is private for another expert = possibly lost.
					if ( in_array( $task['state'], [ 'hired', 'completed', 'refunded' ], true ) ) {
						$new_task['flag'] = 'lost';
					}
				}

				// Flag open tasks as "canceled" after a given number of stale days.
				if ( in_array( $task['state'], [ 'published', 'estimated', 'hired' ], true ) ) {
					if ( ! empty( $new_task['last_activity'] ) ) {
						$stale_hours = floor(
							( time() - $new_task['last_activity'] ) / HOUR_IN_SECONDS
						);

						if ( $stale_hours > $cancel_after_hours ) {
							$new_task['flag'] = 'lost';
						}
					}
				}

				// The API is returning some blank rows, ensure we have a valid id.
				if ( $new_task['task_id'] && is_int( $new_task['task_id'] ) ) {
					if ( $exists ) {
						$db_res = $wpdb->update(
							$this->tables['tasks'],
							$new_task,
							[ 'task_id' => $task['id'] ]
						);
					} else {
						$db_res = $wpdb->insert(
							$this->tables['tasks'],
							$new_task
						);
					}
				}

				if ( $db_res === false ) {
					wp_die(
						'Could not insert task ' .
						$task['id'] . ':' .
						$wpdb->print_error()
					);
				}

				$this->store_client( $task['client'] );
			}

			return $page + 1;
		}
	}

	/**
	 * Insert new clients to the clients-table.
	 *
	 * @param  array $client Client details.
	 * @return void
	 */
	private function store_client( $client ) {
		global $wpdb;

		// The API is returning some blank rows, ensure we have a valid client_id.
		if ( ! $client || ! is_int( $client['id'] ) ) {
			return;
		}

		// Check, if the client already exists.
		$check_client = $wpdb->get_results(
			"SELECT COUNT(1) AS totalrows
			FROM `{$this->tables['clients']}`
			WHERE client_id = '{$client['id']}';"
		);

		// When the client already exists, stop here.
		$exists = $check_client[0]->totalrows > 0;

		$new_client = [
			'client_id'       => $client['id'],
			'full_name'       => $client['full_name'],
			'role'            => $client['role'],
			'last_sign_in_at' => date( 'Y-m-d H:i:s', strtotime( $client['last_sign_in_at'] ) ),
			'pro'             => $client['pro'],
			'timezone_offset' => $client['timezone_offset'],
			'tiny'            => $client['avatar']['tiny_url'],
			'small'           => $client['avatar']['small_url'],
			'medium'          => $client['avatar']['medium_url'],
			'large'           => $client['avatar']['large_url'],
			'last_sync'       => time(),
		];

		if ( $exists ) {
			$wpdb->update(
				$this->tables['clients'],
				$new_client,
				[ 'client_id' => $client['id'] ]
			);
		} else {
			$wpdb->insert( $this->tables['clients'], $new_client );
		}
	}

	/**
	 * Insert pricing details into the amounts table.
	 *
	 * @param  array $client Client details.
	 * @return void
	 */
	private function store_amount( $task_id, $client_id, $credit, $debit ) {
		global $wpdb;

		// The API is returning some blank rows, ensure we have a valid client_id.
		if ( ! $task_id || ! is_int( $task_id ) ) {
			return;
		}

		$new_amount = [
			'task_id'               => $task_id,
			'client_id'             => $client_id,
			'credit_revenue_id'     => $credit[0]['id'],
			'credit_revenue_amount' => $credit[0]['amount'],
			'credit_fee_id'         => $credit[1]['id'],
			'credit_fee_amount'     => $credit[1]['amount'],
			'credit_user_id'        => $credit[2]['id'],
			'credit_user_amount'    => $credit[2]['amount'],
			'debit_cost_id'         => $debit[0]['id'],
			'debit_cost_amount'     => $debit[0]['amount'],
			'debit_user_id'         => $debit[1]['id'],
			'debit_user_amount'     => $debit[1]['amount'],
		];

		$wpdb->replace( $this->tables['amounts'], $new_amount );
	}
}
