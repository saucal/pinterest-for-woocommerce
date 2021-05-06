<?php
/**
 * Pinterest For WooCommerce Logger
 *
 * @version     1.0.0
 * @package     Pinterest_For_WooCommerce/API
 */

namespace Automattic\WooCommerce\Pinterest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class responsible for logging stuff
 */
class Logger {

	/**
	 * The single instance of the class.
	 *
	 * @var Pinterest_For_Woocommerce
	 * @since 1.0.0
	 */
	public static $logger;

	/**
	 * The log_file_name.
	 *
	 * @var string
	 */
	protected static $log_file_name = \PINTEREST_FOR_WOOCOMMERCE_LOG_PREFIX;

	/**
	 * Always log errors, Logging of debug messages can be disabled via a filter.
	 *
	 * @param string $message The message to be logged.
	 * @param string $level   The level/context of the message.
	 *
	 * @return string
	 */
	public static function log( $message, $level = 'debug' ) {

		$logging = 'error' === $level ? true : apply_filters( 'pinterest_for_woocommerce_debug_logging', true );

		if ( empty( $logging ) || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		if ( ! self::$logger ) {
			self::$logger = wc_get_logger();
		}

		$handler = array( 'source' => self::$log_file_name );

		self::$logger->log( $level, $message, $handler );
	}

	/**
	 * Helper for Logging API requests.
	 *
	 * @param string $url The URL of the request.
	 * @param string $args The Arguments of the request.
	 * @param string $level The default level/context of the message to be logged.
	 *
	 * @return void
	 */
	public static function log_request( $url, $args, $level = 'debug' ) {
		unset( $args['headers'] );
		$method = isset( $args['method'] ) ? $args['method'] : 'POST';
		$data   = ! empty( $args['body'] ) ? $args['body'] : '--- EMPTY STRING ---';
		self::log( "{$method} Request: " . $url . "\n\n" . $data . "\n", $level );
	}

	/**
	 *  Helper for Logging API responses.
	 *
	 * @param array|WP_Error $response The body of the response.
	 * @param string         $level The default level/context of the message to be logged.
	 *
	 * @return void
	 */
	public static function log_response( $response, $level = 'debug' ) {
		if ( is_wp_error( $response ) ) {
			$level = 'error';
			$data  = $response->get_error_code() . ': ' . $response->get_error_message();
		} else {
			$data = $response['http_response']->get_response_object()->raw;
		}

		self::log( 'Response: ' . "\n\n" . $data . "\n", $level );
	}
}

