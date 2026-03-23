<?php
/**
 * Rate limiter class file.
 *
 * @package GoldtWebMCP
 */

namespace GoldtWebMCP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles API rate limiting using Redis or WordPress transients.
 *
 * @package GoldtWebMCP
 */
class Rate_Limiter {

	/**
	 * Redis client instance.
	 *
	 * @var \Predis\Client|null
	 */
	private $redis = null;

	/**
	 * Whether Redis is available.
	 *
	 * @var bool
	 */
	private $use_redis = false;

	/**
	 * Requests allowed per minute.
	 *
	 * @var int
	 */
	private $requests_per_minute = 50;

	/**
	 * Requests allowed per hour.
	 *
	 * @var int
	 */
	private $requests_per_hour = 1000;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->init_redis();
		$this->requests_per_minute = apply_filters( 'goldtwmcp_rate_limit_per_minute', 50 );
		$this->requests_per_hour   = apply_filters( 'goldtwmcp_rate_limit_per_hour', 1000 );
	}

	/**
	 * Log error message only in debug mode.
	 *
	 * @param string $message Error message.
	 * @param string $context Context identifier.
	 * @return void
	 */
	private function log_error( $message, $context = 'general' ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'AI Connect: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		do_action( 'goldtwmcp_rate_limiter_error', $message, $context );
	}

	/**
	 * Initialize Redis connection if available.
	 *
	 * @return void
	 */
	private function init_redis() {
		if ( ! class_exists( 'Predis\Client' ) ) {
			return;
		}

		if ( ! extension_loaded( 'redis' ) && ! class_exists( 'Predis\Client' ) ) {
			return;
		}

		try {
			$redis_host = getenv( 'REDIS_HOST' );
			$redis_port = getenv( 'REDIS_PORT' );

			$redis_config = array(
				'scheme' => 'tcp',
				'host'   => $redis_host ? $redis_host : '127.0.0.1',
				'port'   => $redis_port ? $redis_port : 6379,
			);

			$password = getenv( 'REDIS_PASSWORD' );
			if ( $password ) {
				$redis_config['password'] = $password;
			}

			$this->redis = new \Predis\Client( $redis_config );
			$this->redis->ping();
			$this->use_redis = true;
		} catch ( \Exception $e ) {
			$this->log_error( 'Redis connection failed - ' . $e->getMessage(), 'redis_init' );
			$this->use_redis = false;
		}
	}

	/**
	 * Check if identifier is rate limited.
	 *
	 * @param string $identifier Unique identifier for the requester.
	 * @param string $action Action type for the key.
	 * @return array Rate limit result array.
	 */
	public function is_rate_limited( $identifier, $action = 'api_request' ) {
		$key_minute = $this->get_key( $identifier, $action, 'minute' );
		$key_hour   = $this->get_key( $identifier, $action, 'hour' );

		if ( $this->use_redis ) {
			return $this->check_rate_redis( $key_minute, $key_hour );
		} else {
			return $this->check_rate_transients( $key_minute, $key_hour );
		}
	}

	/**
	 * Record a request for rate limiting purposes.
	 *
	 * @param string $identifier Unique identifier for the requester.
	 * @param string $action Action type for the key.
	 * @return void
	 */
	public function record_request( $identifier, $action = 'api_request' ) {
		$key_minute = $this->get_key( $identifier, $action, 'minute' );
		$key_hour   = $this->get_key( $identifier, $action, 'hour' );

		if ( $this->use_redis ) {
			$this->increment_redis( $key_minute, 60 );
			$this->increment_redis( $key_hour, 3600 );
		} else {
			$this->increment_transient( $key_minute, 60 );
			$this->increment_transient( $key_hour, 3600 );
		}
	}

	/**
	 * Check rate limit using Redis.
	 *
	 * @param string $key_minute Minute-window key.
	 * @param string $key_hour Hour-window key.
	 * @return array Rate limit result array.
	 */
	private function check_rate_redis( $key_minute, $key_hour ) {
		try {
			$count_minute = (int) $this->redis->get( $key_minute );
			$count_hour   = (int) $this->redis->get( $key_hour );

			if ( $count_minute >= $this->requests_per_minute ) {
				return array(
					'limited'     => true,
					'reason'      => 'rate_limit_per_minute',
					'limit'       => $this->requests_per_minute,
					'current'     => $count_minute,
					'retry_after' => 60,
				);
			}

			if ( $count_hour >= $this->requests_per_hour ) {
				return array(
					'limited'     => true,
					'reason'      => 'rate_limit_per_hour',
					'limit'       => $this->requests_per_hour,
					'current'     => $count_hour,
					'retry_after' => 3600,
				);
			}

			return array( 'limited' => false );
		} catch ( \Exception $e ) {
			$this->log_error( 'Redis rate check failed - ' . $e->getMessage(), 'rate_check' );
			return $this->check_rate_transients( $key_minute, $key_hour );
		}
	}

	/**
	 * Check rate limit using WordPress transients.
	 *
	 * @param string $key_minute Minute-window key.
	 * @param string $key_hour Hour-window key.
	 * @return array Rate limit result array.
	 */
	private function check_rate_transients( $key_minute, $key_hour ) {
		$count_minute = (int) \get_transient( $key_minute );
		$count_hour   = (int) \get_transient( $key_hour );

		if ( $count_minute >= $this->requests_per_minute ) {
			return array(
				'limited'     => true,
				'reason'      => 'rate_limit_per_minute',
				'limit'       => $this->requests_per_minute,
				'current'     => $count_minute,
				'retry_after' => 60,
			);
		}

		if ( $count_hour >= $this->requests_per_hour ) {
			return array(
				'limited'     => true,
				'reason'      => 'rate_limit_per_hour',
				'limit'       => $this->requests_per_hour,
				'current'     => $count_hour,
				'retry_after' => 3600,
			);
		}

		return array( 'limited' => false );
	}

	/**
	 * Increment counter in Redis with expiry.
	 *
	 * @param string $key Redis key.
	 * @param int    $expiry Expiry time in seconds.
	 * @return void
	 */
	private function increment_redis( $key, $expiry ) {
		try {
			$current = $this->redis->incr( $key );

			if ( 1 === $current ) {
				$this->redis->expire( $key, $expiry );
			}
		} catch ( \Exception $e ) {
			$this->log_error( 'Redis increment failed - ' . $e->getMessage(), 'increment' );
			$this->increment_transient( $key, $expiry );
		}
	}

	/**
	 * Increment counter in WordPress transient with expiry.
	 *
	 * @param string $key Transient key.
	 * @param int    $expiry Expiry time in seconds.
	 * @return void
	 */
	private function increment_transient( $key, $expiry ) {
		$current = (int) \get_transient( $key );
		++$current;
		\set_transient( $key, $current, $expiry );
	}

	/**
	 * Generate rate limit key for a given window.
	 *
	 * @param string $identifier Unique identifier for the requester.
	 * @param string $action Action type.
	 * @param string $window Time window ('minute' or 'hour').
	 * @return string Cache key.
	 */
	private function get_key( $identifier, $action, $window ) {
		$timestamp = 'minute' === $window
			? floor( time() / 60 )
			: floor( time() / 3600 );

		return sprintf(
			'goldtwmcp_rate_%s_%s_%s_%d',
			$action,
			$identifier,
			$window,
			$timestamp
		);
	}

	/**
	 * Get remaining requests for an identifier.
	 *
	 * @param string $identifier Unique identifier for the requester.
	 * @param string $action Action type for the key.
	 * @return array Remaining requests data.
	 */
	public function get_remaining_requests( $identifier, $action = 'api_request' ) {
		$key_minute = $this->get_key( $identifier, $action, 'minute' );
		$key_hour   = $this->get_key( $identifier, $action, 'hour' );

		if ( $this->use_redis ) {
			$count_minute = (int) $this->redis->get( $key_minute );
			$count_hour   = (int) $this->redis->get( $key_hour );
		} else {
			$count_minute = (int) \get_transient( $key_minute );
			$count_hour   = (int) \get_transient( $key_hour );
		}

		return array(
			'remaining_per_minute' => max( 0, $this->requests_per_minute - $count_minute ),
			'remaining_per_hour'   => max( 0, $this->requests_per_hour - $count_hour ),
			'limit_per_minute'     => $this->requests_per_minute,
			'limit_per_hour'       => $this->requests_per_hour,
			'using_redis'          => $this->use_redis,
		);
	}

	/**
	 * Check whether Redis is being used.
	 *
	 * @return bool
	 */
	public function is_using_redis() {
		return $this->use_redis;
	}

	/**
	 * Reset rate limits for an identifier.
	 *
	 * @param string $identifier Unique identifier for the requester.
	 * @param string $action Action type for the key.
	 * @return void
	 */
	public function reset_limits( $identifier, $action = 'api_request' ) {
		$key_minute = $this->get_key( $identifier, $action, 'minute' );
		$key_hour   = $this->get_key( $identifier, $action, 'hour' );

		if ( $this->use_redis ) {
			$this->redis->del( $key_minute );
			$this->redis->del( $key_hour );
		} else {
			\delete_transient( $key_minute );
			\delete_transient( $key_hour );
		}
	}
}
