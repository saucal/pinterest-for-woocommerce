<?php //phpcs:disable WordPress.WP.AlternativeFunctions --- Uses FS read/write in order to reliable append to an existing file.
/**
 * Pinterest for WooCommerce Catalog Syncing
 *
 * @package     Pinterest_For_WooCommerce/Classes/
 * @version     1.0.0
 */

namespace Automattic\WooCommerce\Pinterest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Handling registration & generation of the XML product feed.
 */
class ProductSync {

	const ACTION_HANDLE_SYNC     = PINTEREST_FOR_WOOCOMMERCE_PREFIX . '-handle-sync';
	const ACTION_FEED_GENERATION = PINTEREST_FOR_WOOCOMMERCE_PREFIX . '-feed-generation';

	/**
	 * The number of products to process on each Iteration of the scheduled task.
	 * A high number increases the time it takes to complete the iteration, risking a PHP timeout.
	 *
	 * @var integer
	 */
	private static $products_per_step = 500;

	/**
	 * The number of products to hold in a buffer variable before writing their XML output to a file.
	 * A high number increases memory usage while a low number increases file writes.
	 *
	 * @var integer
	 */
	private static $products_per_write = 100;

	/**
	 * The current index of the loop iterating through feed creation steps.
	 *
	 * @var integer
	 */
	private static $current_index = 0;

	/**
	 * The buffer used to hold XML markup to be printed to the XML file.
	 *
	 * @var string
	 */
	private static $iteration_buffer = '';

	/**
	 * The number of products the iteration_buffer currently has
	 *
	 * @var integer
	 */
	private static $iteration_buffer_size = 0;

	/**
	 * Initiate class.
	 */
	public static function maybe_init() {

		if ( ! self::is_product_sync_enabled() && ! self::get_registered_feed_id() ) {
			return;
		}

		// Hook the Scheduled actions.
		add_action( self::ACTION_HANDLE_SYNC, array( __CLASS__, 'handle_feed_registration' ) );
		add_action( self::ACTION_FEED_GENERATION, array( __CLASS__, 'handle_feed_generation' ) );

		// Schedule the main feed control task.
		if ( false === as_next_scheduled_action( self::ACTION_HANDLE_SYNC, array(), PINTEREST_FOR_WOOCOMMERCE_PREFIX ) ) {
			$interval = 10 * MINUTE_IN_SECONDS;
			as_schedule_recurring_action( time() + 10, $interval, self::ACTION_HANDLE_SYNC, array(), PINTEREST_FOR_WOOCOMMERCE_PREFIX );
		}

		if ( self::is_product_sync_enabled() ) {
			$state = self::feed_job_status();

			if ( $state ) {
				// If local is not generated, or needs to be regenerated, schedule regeneration.
				if ( 'starting' === $state['status'] || 'in_progress' === $state['status'] ) {
					self::trigger_async_feed_generation();
				} elseif ( 'scheduled_for_generation' === $state['status'] || 'pending_config' === $state['status'] ) {
					self::feed_reschedule();
				}
			}

			/**
			 * Mark feed as needing re-generation whenever a product is edited or changed.
			 */
			add_action( 'edit_post', array( __CLASS__, 'mark_feed_dirty' ), 10, 1 );

			if ( 'yes' === get_option( 'woocommerce_manage_stock' ) ) {
				add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'mark_feed_dirty' ), 10, 1 );
				add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'mark_feed_dirty' ), 10, 1 );
			}

			// If feed is dirty on completion of feed generation, reschedule it.
			add_action( 'pinterest_for_woocommerce_feed_generated', array( __CLASS__, 'reschedule_if_dirty' ) );

			// If feed is generated, but not yet registered, register it as soon as possible using an async task.
			add_action( 'pinterest_for_woocommerce_feed_generated', array( __CLASS__, 'trigger_async_feed_registration_asap' ) );
		}
	}


	/**
	 * Deletes the XML file that is configured in the settings and deletes the feed_job option.
	 *
	 * @return void
	 */
	public static function feed_reset() {

		$state = Pinterest_For_Woocommerce()::get_data( 'feed_job' ) ?? array();

		if ( isset( $state['feed_file'] ) && file_exists( $state['feed_file'] ) ) {
			unlink( $state['feed_file'] );
		}

		if ( isset( $state['tmp_file'] ) && file_exists( $state['tmp_file'] ) ) {
			unlink( $state['tmp_file'] );
		}

		Pinterest_For_Woocommerce()::save_data( 'feed_job', false );
		Pinterest_For_Woocommerce()::save_data( 'feed_data_cache', false );

		self::log( 'Product feed reset and file deleted.' );
	}


	/**
	 * Checks if the feed file for the configured (In $state var) feed exists.
	 * This could be true as the feed is being generated, if its not the 1st time
	 * its been generated.
	 *
	 * @return bool
	 */
	public static function feed_file_exists() {

		$state = Pinterest_For_Woocommerce()::get_data( 'feed_job' ) ?? array();

		return isset( $state['feed_file'] ) && file_exists( $state['feed_file'] );
	}


	/**
	 * Schedules the regeneration process of the XML feed.
	 *
	 * @param boolean $force Forces rescheduling even when the status indicates that its not needed.
	 *
	 * @return void
	 */
	public static function feed_reschedule( $force = false ) {

		$feed_job = Pinterest_For_Woocommerce()::get_data( 'feed_job' ) ?? array();

		if ( ! $force && isset( $feed_job['status'] ) && in_array( $feed_job['status'], array( 'in_progress', 'starting' ), true ) ) {
			return;
		}

		$feed_job['status'] = 'scheduled_for_generation';
		if ( isset( $feed_job['finished'] ) ) {
			unset( $feed_job['finished'] );
		}

		Pinterest_For_Woocommerce()::save_data( 'feed_job', $feed_job );
		self::trigger_async_feed_generation( $force );

		self::log( 'Feed generation (re)scheduled.' );
	}


	/**
	 * Logs Sync related messages separately.
	 *
	 * @param string $message The message to be logged.
	 * @param string $level   The level of the message.
	 *
	 * @return void
	 */
	private static function log( $message, $level = 'debug' ) {
		Logger::log( $message, $level, 'product-sync' );
	}


	/**
	 * Checks if the feature is enabled, and all requirements are met.
	 *
	 * @return boolean
	 */
	public static function is_product_sync_enabled() {

		$domain_verified  = Pinterest_For_Woocommerce()::is_domain_verified();
		$tracking_enabled = $domain_verified && Pinterest_For_Woocommerce()::is_tracking_configured();

		return (bool) $domain_verified && $tracking_enabled && Pinterest_For_Woocommerce()::get_setting( 'product_sync_enabled' );
	}


	/**
	 * Returns the feed profile ID if it's registered. Returns `false` otherwise.
	 *
	 * @return string|boolean
	 */
	public static function get_registered_feed_id() {
		return Pinterest_For_Woocommerce()::get_data( 'feed_registered' ) ?? false;
	}


	/**
	 * Check if the feed is registered based on the plugin's settings.
	 * If not, try to register it,
	 * Log issues.
	 * Potentially report issues.
	 *
	 * Should be run on demand when settings change,
	 * and on a scheduled basis.
	 *
	 * @return mixed
	 *
	 * @throws \Exception PHP Exception.
	 */
	public static function handle_feed_registration() {

		$state = self::feed_job_status( 'check_registration' );

		if ( ! self::feed_file_exists() ) {
			self::log( 'Feed didn\'t fully generate yet. Retrying later.', 'debug' );
			// Feed is not generated yet, lets wait a bit longer.
			return true;
		}

		$feed_args = array(
			'feed_location'             => $state['feed_url'],
			'feed_format'               => 'XML',
			'feed_default_currency'     => get_woocommerce_currency(),
			'default_availability_type' => 'IN_STOCK',
			'country'                   => Pinterest_For_Woocommerce()::get_base_country() ?? 'US',
			'locale'                    => str_replace( '_', '-', determine_locale() ),
		);

		$registered = self::get_registered_feed_id();

		if ( ! self::is_product_sync_enabled() ) {
			// Handle feed deregistration.
			if ( $registered ) {
				self::handle_feed_deregistration();
			}

			return false;
		}

		try {
			$registered = self::register_feed( $feed_args );

			if ( $registered ) {
				return true;
			}

			throw new \Exception( esc_html__( 'Could not register feed.', 'pinterest-for-woocommerce' ) );

		} catch ( \Throwable $th ) {
			self::log( $th->getMessage(), 'error' );
			return false;
		}

	}


	/**
	 * Handles de-registration of the feed.
	 * $feed_args are needed so that they are passed to update_merchant_feed() in order to perform the update.
	 * Running this, sets the feed to 'DISABLED' in Pinterest, deletes the local XML file and the option holding the feed
	 * status of the feed generation job.
	 *
	 * @return void
	 */
	private static function handle_feed_deregistration() {
		Pinterest_For_Woocommerce()::save_data( 'feed_registered', false );

		self::feed_reset();
	}


	/**
	 * Handles the feed's generation,
	 * Using AS scheduled tasks prints $products_per_step number of products
	 * on each iteration and writes to a file every $products_per_write.
	 *
	 * @return void
	 *
	 * @throws \Exception PHP Exception.
	 */
	public static function handle_feed_generation() {

		$state = self::feed_job_status();
		$start = microtime( true );

		if ( $state && 'generated' === $state['status'] || ! self::is_product_sync_enabled() ) {
			return; // No need to perform any action.
		}

		if ( ! $state || ( $state && 'scheduled_for_generation' === $state['status'] ) ) {
			// We need to start a generation from scratch.
			$product_ids = self::get_product_ids_for_feed();

			if ( empty( $product_ids ) ) {
				self::log( 'No products found for feed generation.' );
				return; // No need to perform any action.
			}

			$state = self::feed_job_status(
				'starting',
				array(
					'dataset'       => $product_ids,
					'current_index' => 0,
				)
			);

			self::$current_index = 0;
		}

		try {

			if ( 'in_progress' === $state['status'] ) {
				$product_ids         = get_transient( PINTEREST_FOR_WOOCOMMERCE_PREFIX . '_feed_dataset_' . $state['job_id'] );
				self::$current_index = get_transient( PINTEREST_FOR_WOOCOMMERCE_PREFIX . '_feed_current_index_' . $state['job_id'] );
				self::$current_index = false === self::$current_index ? self::$current_index : ( (int) self::$current_index ) + 1; // Start on the next item.
			}

			if ( false === self::$current_index || empty( $product_ids ) ) {
				throw new \Exception( esc_html__( 'Something went wrong while attempting to generate the feed.', 'pinterest-for-woocommerce' ), 400 );
			}

			$target_file = $state['tmp_file'];
			$xml_file    = fopen( $target_file, ( 'in_progress' === $state['status'] ? 'a' : 'w' ) );

			if ( ! $xml_file ) {
				/* Translators: the path of the file */
				throw new \Exception( sprintf( esc_html__( 'Could not open file: %s.', 'pinterest-for-woocommerce' ), $target_file ), 400 );
			}

			self::log( 'Generating feed for ' . count( $product_ids ) . ' products' );

			if ( 0 === self::$current_index ) {
				// Write header.
				fwrite( $xml_file, ProductsXmlFeed::get_xml_header() );
			}

			self::$iteration_buffer      = '';
			self::$iteration_buffer_size = 0;
			$step_index                  = 0;
			$products_count              = count( $product_ids );
			$state['products_count']     = $products_count;

			for ( self::$current_index; ( self::$current_index < $products_count ); self::$current_index++ ) {

				$product_id = $product_ids[ self::$current_index ];

				self::$iteration_buffer .= ProductsXmlFeed::get_xml_item( wc_get_product( $product_id ) );
				self::$iteration_buffer_size++;

				if ( self::$iteration_buffer_size >= self::$products_per_write || self::$current_index >= $products_count ) {
					self::write_iteration_buffer( $xml_file, $state );
				}

				$step_index++;

				if ( $step_index >= self::$products_per_step ) {
					break;
				}
			}

			if ( ! empty( self::$iteration_buffer ) ) {
				self::write_iteration_buffer( $xml_file, $state );
			}

			if ( self::$current_index >= $products_count ) {
				// Write footer.
				fwrite( $xml_file, ProductsXmlFeed::get_xml_footer() );
				fclose( $xml_file );

				self::feed_job_status( 'generated' );

				if ( ! rename( $state['tmp_file'], $state['feed_file'] ) ) {
					/* Translators: the path of the file */
					throw new \Exception( sprintf( esc_html__( 'Could not write feed to file: %s.', 'pinterest-for-woocommerce' ), $state['feed_file'] ), 400 );
				}

				$target_file = $state['feed_file'];

				do_action( 'pinterest_for_woocommerce_feed_generated', $state );
			} else {
				// We got more products left. Schedule next iteration.
				fclose( $xml_file );
				self::trigger_async_feed_generation( true );
			}

			$end = microtime( true );
			self::log( 'Feed step generation completed in ' . round( ( $end - $start ) * 1000 ) . 'ms. Current Index: ' . self::$current_index . ' / ' . $products_count );
			self::log( 'Wrote ' . $step_index . ' products to file: ' . $target_file );

		} catch ( \Throwable $th ) {

			if ( 'error' === $state['status'] ) {
				// Already errored at once. Restart job.
				self::feed_reschedule( true );
				self::log( $th->getMessage(), 'error' );
				self::log( 'Restarting Feed generation.', 'error' );
				return;
			}

			self::feed_job_status( 'error', array( 'progress' => $th->getMessage() ) );
			self::log( $th->getMessage(), 'error' );
		}
	}


	/**
	 * Writes the iteration_buffer to the given file.
	 *
	 * @param resource $xml_file The file handle.
	 * @param array    $state    The array holding the feed_state values.
	 *
	 * @return void
	 *
	 * @throws \Exception PHP Exception.
	 */
	private static function write_iteration_buffer( $xml_file, $state ) {

		if ( false !== fwrite( $xml_file, self::$iteration_buffer ) ) {
			self::$iteration_buffer      = '';
			self::$iteration_buffer_size = 0;

			self::feed_job_status(
				'in_progress',
				array(
					'current_index' => self::$current_index,
					'progress'      => sprintf(
						/* Translators: %1$s number of products written, %2$s total number of products, %3$s hyperlink open tag, %4$s hyperlink close tag */
						esc_html__( 'Wrote %1$s out of %2$s products to %3$sfeed file%4$s.', 'pinterest-for-woocommerce' ),
						self::$current_index,
						$state['products_count'],
						'<a href="' . $state['feed_url'] . '" target="_blank">',
						'</a>'
					),
				)
			);

		} else {
			/* Translators: the path of the file */
			throw new \Exception( sprintf( esc_html__( 'Could not write to file: %s.', 'pinterest-for-woocommerce' ), $state['tmp_file'] ), 400 );
		}
	}


	/**
	 * Schedules an async action - if not already scheduled - to generate the feed.
	 *
	 * @param boolean $force When true, overrides the check for already scheduled task.
	 *
	 * @return void
	 */
	private static function trigger_async_feed_generation( $force = false ) {

		if ( $force || false === as_next_scheduled_action( self::ACTION_FEED_GENERATION, array(), PINTEREST_FOR_WOOCOMMERCE_PREFIX ) ) {
			as_enqueue_async_action( self::ACTION_FEED_GENERATION, array(), PINTEREST_FOR_WOOCOMMERCE_PREFIX );
		}
	}


	/**
	 * If the feed is not already registered, schedules an async action to registrer it asap.
	 *
	 * @return void
	 */
	public static function trigger_async_feed_registration_asap() {

		if ( self::get_registered_feed_id() ) {
			return;
		}

		self::log( 'running trigger_async_feed_registration_asap' );

		as_unschedule_all_actions( self::ACTION_HANDLE_SYNC, array(), PINTEREST_FOR_WOOCOMMERCE_PREFIX );
		as_enqueue_async_action( self::ACTION_HANDLE_SYNC, array(), PINTEREST_FOR_WOOCOMMERCE_PREFIX );
	}


	/**
	 * Make API request to add_merchant_feed.
	 *
	 * @param string $merchant_id The merchant ID the feed belongs to.
	 * @param array  $feed_args   The arguments used to create the feed.
	 *
	 * @return string|bool
	 */
	private static function do_add_merchant_feed( $merchant_id, $feed_args ) {
		$feed = API\Base::add_merchant_feed( $merchant_id, $feed_args );

		if ( $feed && 'success' === $feed['status'] && isset( $feed['data']->location_config->full_feed_fetch_location ) ) {
			self::log( 'Added merchant feed: ' . $feed_args['feed_location'] );
			return $feed['data']->id;
		}

		return false;
	}


	/**
	 * Handles feed registration using the given arguments.
	 * Will try to create a merchant if none exists.
	 * Also if a different feed is registered, it will update using the URL in the
	 * $feed_args.
	 *
	 * @param array $feed_args The arguments used to create the feed.
	 *
	 * @return boolean|string
	 *
	 * @throws \Exception PHP Exception.
	 */
	private static function register_feed( $feed_args ) {

		// Get merchant object.
		$merchant   = Merchants::get_merchant( $feed_args );
		$registered = false;

		if ( ! empty( $merchant['data']->id ) && 'declined' === $merchant['data']->product_pin_approval_status ) {
			$registered = false;
			self::log( 'Pinterest returned a Declined status for product_pin_approval_status' );
		} elseif ( ! empty( $merchant['data']->id ) && ! isset( $merchant['data']->product_pin_feed_profile ) ) {
			// No feed registered, but we got a merchant.
			$registered = self::do_add_merchant_feed( $merchant['data']->id, $feed_args );
		} elseif ( $feed_args['feed_location'] === $merchant['data']->product_pin_feed_profile->location_config->full_feed_fetch_location ) {
			// Feed registered.
			$registered = $merchant['data']->product_pin_feed_profile->id;
			self::log( 'Feed registered for merchant: ' . $feed_args['feed_location'] );
		} else {
			$product_pin_feed_profile    = $merchant['data']->product_pin_feed_profile;
			$product_pin_feed_profile_id = false;
			$prev_registered             = self::get_registered_feed_id();
			if ( false !== $prev_registered ) {
				try {
					$feed                        = API\Base::get_merchant_feed( $merchant['data']->id, $prev_registered );
					$product_pin_feed_profile_id = $feed['data']->feed_profile_id;
				} catch ( \Throwable $e ) {
					$product_pin_feed_profile_id = false;
				}
			}

			if ( false === $product_pin_feed_profile_id ) {
				$configured_path = dirname( $product_pin_feed_profile->location_config->full_feed_fetch_location );
				$local_path      = dirname( $feed_args['feed_location'] );

				if ( $configured_path === $local_path && $feed_args['country'] === $product_pin_feed_profile->country && $feed_args['locale'] === $product_pin_feed_profile->locale ) {
					// We can assume we're on the same site.

					$product_pin_feed_profile_id = $product_pin_feed_profile->id;
				}
			}

			if ( false !== $product_pin_feed_profile_id ) { // We update a feed, if we have one matching our site.
				// We cannot change the country or locale, so we remove that from the parameters to send.
				$update_feed_args = $feed_args;
				unset( $update_feed_args['country'] );
				unset( $update_feed_args['locale'] );

				// Actually do the update.
				$feed = API\Base::update_merchant_feed( $product_pin_feed_profile->merchant_id, $product_pin_feed_profile_id, $update_feed_args );

				if ( $feed && 'success' === $feed['status'] && isset( $feed['data']->location_config->full_feed_fetch_location ) ) {
					$registered = $feed['data']->id;
					self::log( 'Merchant\'s feed updated to current location: ' . $feed_args['feed_location'] );
				}
			} else {
				// We cannot infer that a feed exists, therefore we create a new one.
				$registered = self::do_add_merchant_feed( $merchant['data']->id, $feed_args );
			}
		}

		Pinterest_For_Woocommerce()::save_data( 'feed_registered', $registered );

		return $registered;
	}


	/**
	 * Gets the Array of IDs for the products that are to be printed to the
	 * XML feed, based on the current settings.
	 *
	 * @return array
	 */
	private static function get_product_ids_for_feed() {

		$excluded_product_types = apply_filters(
			'pinterest_for_woocommerce_excluded_product_types',
			array(
				'grouped',
			)
		);

		$products_query_args = array(
			'limit'        => -1,
			'return'       => 'ids',
			'status'       => 'publish',
			'type'         => array_diff( array_merge( array_keys( wc_get_product_types() ) ), $excluded_product_types ),
		);

		// Do not sync out of stock products if woocommerce_hide_out_of_stock_items is set
		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items') ) {
			$products_query_args['stock_status'] = 'instock';
		}

		$product_ids = wc_get_products( $products_query_args );

		if ( empty( $product_ids ) ) {
			return array();
		}

		// Do not sync products with hidden visibility
		$visible_product_ids = array();
		foreach ( $product_ids as $index => $product_id ) {
			$product = wc_get_product( $product_id );
			if ( 'hidden' !== $product->get_catalog_visibility() ) {
				$visible_product_ids[] = $product_id;
			}
		}
		$product_ids = $visible_product_ids;

		if ( empty( $product_ids ) ) {
			return array();
		}

		$products_count = count( $product_ids );

		// Get Variations of the current product and inject them into our array.
		for ( $i = 0; $i < $products_count; $i++ ) {

			$type = \WC_Product_Factory::get_product_type( $product_ids[ $i ] );

			if ( in_array( $type, array( 'variable' ), true ) ) {

				$product  = wc_get_product( $product_ids[ $i ] );
				$children = $product->get_children();

				array_splice( $product_ids, $i + 1, 0, $children );

				$i = $i + count( $children );

				$products_count = $products_count + count( $children );
			}
		}

		return $product_ids;
	}


	/**
	 * Saves or returns the Current state of the Feed generation job.
	 * Status can be one of the following:
	 *
	 * - starting                 The feed job is being initialized. A new JobID will be assigned if none exists.
	 * - check_registration       If a JobID already exists, it is returned, otherwise a new one will be assigned.
	 * - in_progress              Signifies that we are between iterations and generating the feed.
	 * - generated                The feed is generated, no further action will be taken.
	 * - scheduled_for_generation The feed needs to be (re)generated. If this status is set, the next run of __CLASS__::handle_feed_generation() will start the generation process.
	 * - pending_config           The feed was reset or was never configured.
	 * - error                    The generation process returned an error.
	 *
	 * @param string $status The status of the feed's generation process. See above.
	 * @param array  $args   The arguments that go along with the given status.
	 *
	 * @return array
	 */
	public static function feed_job_status( $status = null, $args = null ) {

		$state_data = Pinterest_For_Woocommerce()::get_data( 'feed_job' );

		if ( ! is_null( $state_data ) ) {
			$expired = ( 'generated' === $state_data['status'] && $state_data['finished'] < ( time() - DAY_IN_SECONDS ) );

			if ( $expired ) {
				$state_data['status'] = 'scheduled_for_generation';
				if ( isset( $state_data['finished'] ) ) {
					unset( $state_data['finished'] );
				}
				Pinterest_For_Woocommerce()::save_data( 'feed_job', $state_data );
			}
		}

		if ( is_null( $status ) || ( ! is_null( $status ) && 'check_registration' === $status && ! empty( $state_data['job_id'] ) ) ) {
			return $state_data;
		}

		if ( ! isset( $state_data['status'] ) ) {
			$state_data['status'] = 'pending_config';
		}

		$state_data     = empty( $state_data ) ? array() : $state_data;
		$initial_status = $state_data['status'];

		if ( 'starting' === $status || 'check_registration' === $status ) {

			if ( 'check_registration' !== $status ) {
				$state_data['status']  = $status;
				$state_data['started'] = time();
			}

			if ( empty( $state_data['job_id'] ) ) {
				$state_data['job_id'] = wp_generate_password( 6, false, false );
			}

			$upload_dir = wp_get_upload_dir();

			$state_data['feed_file'] = trailingslashit( $upload_dir['basedir'] ) . PINTEREST_FOR_WOOCOMMERCE_LOG_PREFIX . '-' . $state_data['job_id'] . '.xml';
			$state_data['tmp_file']  = trailingslashit( $upload_dir['basedir'] ) . PINTEREST_FOR_WOOCOMMERCE_LOG_PREFIX . '-' . $state_data['job_id'] . '-tmp.xml';
			$state_data['feed_url']  = trailingslashit( $upload_dir['baseurl'] ) . PINTEREST_FOR_WOOCOMMERCE_LOG_PREFIX . '-' . $state_data['job_id'] . '.xml';

			if ( isset( $args, $args['dataset'] ) ) {
				set_transient( PINTEREST_FOR_WOOCOMMERCE_PREFIX . '_feed_dataset_' . $state_data['job_id'], $args['dataset'], WEEK_IN_SECONDS );
			}
		} elseif ( 'in_progress' === $status ) {
			$state_data['status']        = 'in_progress';
			$state_data['finished']      = 0;
			$state_data['last_activity'] = time();
			$state_data['progress']      = $args['progress'];

			if ( isset( $args, $args['current_index'] ) ) {
				set_transient( PINTEREST_FOR_WOOCOMMERCE_PREFIX . '_feed_current_index_' . $state_data['job_id'], $args['current_index'], WEEK_IN_SECONDS );
			}
		} elseif ( 'generated' === $status ) {
			$state_data['status']   = 'generated';
			$state_data['finished'] = time();

			// Cleanup.
			delete_transient( PINTEREST_FOR_WOOCOMMERCE_PREFIX . '_feed_dataset_' . $state_data['job_id'] );
			delete_transient( PINTEREST_FOR_WOOCOMMERCE_PREFIX . '_feed_current_index_' . $state_data['job_id'] );

		} elseif ( 'error' === $status ) {
			$state_data['status']        = 'error';
			$state_data['last_activity'] = time();
			$state_data['progress']      = $args['progress'];

			// Cleanup.
			delete_transient( PINTEREST_FOR_WOOCOMMERCE_PREFIX . '_feed_dataset_' . $state_data['job_id'] );
			delete_transient( PINTEREST_FOR_WOOCOMMERCE_PREFIX . '_feed_current_index_' . $state_data['job_id'] );
		}

		Pinterest_For_Woocommerce()::save_data( 'feed_job', $state_data );

		if ( $initial_status !== $state_data['status'] ) {
			self::log( 'Feed status set to: ' . $state_data['status'] );
		}

		return $state_data;

	}


	/**
	 * Check if Given ID is of a product and if yes, mark feed as dirty.
	 *
	 * @param integer $product_id The product ID.
	 *
	 * @return void
	 */
	public static function mark_feed_dirty( $product_id ) {
		if ( ! wc_get_product( $product_id ) ) {
			return;
		}

		Pinterest_For_Woocommerce()::save_data( 'feed_dirty', true );
	}


	/**
	 * Check if feed is marked and dirty, and reschedule feed generation.
	 *
	 * @return void
	 */
	public static function reschedule_if_dirty() {

		if ( Pinterest_For_Woocommerce()::get_data( 'feed_dirty' ) ) {
			Pinterest_For_Woocommerce()::save_data( 'feed_dirty', false );
			self::log( 'Feed is dirty.' );
			self::feed_reschedule();
		}
	}


	/**
	 * Cancels the scheduled product sync jobs.
	 *
	 * @return void
	 */
	public static function cancel_jobs() {
		as_unschedule_all_actions( self::ACTION_HANDLE_SYNC, array(), PINTEREST_FOR_WOOCOMMERCE_PREFIX );
		as_unschedule_all_actions( self::ACTION_FEED_GENERATION, array(), PINTEREST_FOR_WOOCOMMERCE_PREFIX );
	}
}
