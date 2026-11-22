<?php
/**
 * Plugin Name: MBR Performance Object Cache (Memcached)
 * Plugin URI:  https://littlewebshack.com/mbr-performance/
 * Description: Persistent object cache backed by Memcached. Installed and managed by MBR Performance from its Cache tab — switch it off there rather than deleting this file.
 * Author:      Robert Palmer
 * Version:     2.2.0
 * License:     GPLv2 or later
 *
 * This file is copied to wp-content/object-cache.php by MBR Performance, with
 * the connection settings written into the block marked below. It must stay
 * entirely self-contained: WordPress loads it long before any plugin, so it
 * cannot call into MBR Performance, read the options table or use hooks.
 *
 * Failure model. If the Memcached extension is missing, the server cannot be
 * reached, or it stops answering part-way through a request, the cache steps
 * aside and keeps working from memory for the rest of that request — exactly
 * as WordPress behaves with no object cache at all. It never fatals and never
 * serves a value it could not confirm. Any write that could not reach the
 * server leaves a marker file behind, and the first request to reconnect
 * flushes the cache, because WordPress will have changed the database in the
 * meantime without Memcached hearing about it.
 *
 * Overrides, all optional, in wp-config.php:
 *
 *   define( 'MBRPE_MEMCACHED_HOST', '127.0.0.1' );  // or a /path/to/socket
 *   define( 'MBRPE_MEMCACHED_PORT', 11211 );        // ignored for a socket
 *   define( 'WP_CACHE_KEY_SALT', 'unique-per-site' );
 *   define( 'MBRPE_OBJECT_CACHE_DISABLED', true );  // emergency off switch
 *
 * @package MBRPE
 * @since   2.2.0
 */

defined( 'ABSPATH' ) || exit;

/*
 * Contract version, read by MBR Performance to tell a current copy of this
 * file from a stale one. Bump it whenever this template changes in a way the
 * installed copy should pick up.
 */
define( 'MBRPE_OBJECT_CACHE_VERSION', '2.2.0' );

// @mbrpe-config-start
$GLOBALS['mbrpe_object_cache_config'] = array(
	'host'       => '127.0.0.1',
	'port'       => 11211,
	'salt'       => '',
	'epoch'      => '0',
	'stale_flag' => '',
);
// @mbrpe-config-end

/**
 * Object cache backed by Memcached, with an in-memory copy of everything the
 * current request has read or written.
 */
class WP_Object_Cache {

	/**
	 * Values held for this request, keyed [group][key]. Public because some
	 * debugging tools read it directly, as they do with the core cache.
	 *
	 * @var array
	 */
	public $cache = array();

	/**
	 * @var int
	 */
	public $cache_hits = 0;

	/**
	 * @var int
	 */
	public $cache_misses = 0;

	/**
	 * Groups shared by every site on a network.
	 *
	 * @var array group => true
	 */
	public $global_groups = array();

	/**
	 * Groups never sent to Memcached.
	 *
	 * @var array group => true
	 */
	public $non_persistent_groups = array();

	/**
	 * @var string Blog ID and colon on multisite, otherwise empty.
	 */
	public $blog_prefix = '';

	/**
	 * @var bool
	 */
	public $multisite = false;

	/**
	 * @var Memcached|null
	 */
	private $mc = null;

	/**
	 * Is Memcached usable for the rest of this request?
	 *
	 * @var bool
	 */
	private $connected = false;

	/**
	 * Why the cache is not connected, for the admin screen.
	 *
	 * @var string connected|disabled|no-extension|unreachable|lost
	 */
	private $status = 'unreachable';

	/**
	 * Cache-wide and per-group generations. Bumping a generation is how a
	 * flush works: every key embeds the generation it was written under, so
	 * raising it makes the old entries unreachable at once without touching
	 * any other site sharing the same Memcached server.
	 *
	 * @var array{gen:int,groups:array}
	 */
	private $meta = array( 'gen' => 0, 'groups' => array() );

	/**
	 * @var string
	 */
	private $salt = '';

	/**
	 * @var string
	 */
	private $epoch = '0';

	/**
	 * @var string
	 */
	private $host = '127.0.0.1';

	/**
	 * @var int
	 */
	private $port = 11211;

	/**
	 * @var string Absolute path of the "flush on reconnect" marker.
	 */
	private $stale_flag = '';

	/**
	 * Has this request already recorded that the server missed a write?
	 *
	 * @var bool
	 */
	private $marked_stale = false;

	/**
	 * Server round trips made this request.
	 *
	 * @var int
	 */
	private $calls = 0;

	/**
	 * Result codes that describe the data, not the connection. Anything else
	 * means the server could not be reached and the request goes memory-only.
	 *
	 * @var int[]
	 */
	private $benign_codes = array();

	/**
	 * Memcached treats an expiry above this as a Unix timestamp.
	 */
	const MAX_RELATIVE_TTL = 2592000;

	/**
	 * Keys longer than this are hashed. Memcached's own limit is 250.
	 */
	const MAX_KEY_LENGTH = 240;

	/**
	 * store() result for a value PHP could not serialise (a closure, a
	 * SimpleXMLElement). Outside the range of real result codes.
	 */
	const UNSERIALISABLE = -99999;

	/**
	 * @param array|null $config Settings written by MBR Performance at install.
	 *                           Defaults to the block at the top of this file,
	 *                           so `new WP_Object_Cache()` — which some code
	 *                           and WordPress's own tests do — connects to the
	 *                           same server with the same keys.
	 */
	public function __construct( $config = null ) {
		$this->multisite   = function_exists( 'is_multisite' ) && is_multisite();
		$this->blog_prefix = $this->multisite ? (int) get_current_blog_id() . ':' : '';

		if ( ! is_array( $config ) ) {
			$config = isset( $GLOBALS['mbrpe_object_cache_config'] ) && is_array( $GLOBALS['mbrpe_object_cache_config'] ) ? $GLOBALS['mbrpe_object_cache_config'] : array();
		}

		$this->host = defined( 'MBRPE_MEMCACHED_HOST' ) && '' !== (string) MBRPE_MEMCACHED_HOST
			? (string) MBRPE_MEMCACHED_HOST
			: ( isset( $config['host'] ) ? (string) $config['host'] : '127.0.0.1' );
		$this->port = defined( 'MBRPE_MEMCACHED_PORT' )
			? (int) MBRPE_MEMCACHED_PORT
			: ( isset( $config['port'] ) ? (int) $config['port'] : 11211 );
		if ( '' !== $this->host && '/' === $this->host[0] ) {
			$this->port = 0; // Unix socket.
		}

		$salt = ( defined( 'WP_CACHE_KEY_SALT' ) && '' !== (string) WP_CACHE_KEY_SALT )
			? (string) WP_CACHE_KEY_SALT
			: ( isset( $config['salt'] ) ? (string) $config['salt'] : '' );
		if ( '' === $salt ) {
			$salt = substr( md5( ABSPATH ), 0, 10 );
		}
		// Used as-is when short and plain; otherwise hashed. Stripping odd
		// characters instead could make two different salts identical, and a
		// 64-character WP_CACHE_KEY_SALT would eat most of the key length.
		$this->salt = preg_match( '/^[A-Za-z0-9_.\-]{1,32}$/', $salt ) ? $salt : 's' . substr( md5( $salt ), 0, 12 );

		$this->epoch      = isset( $config['epoch'] ) ? preg_replace( '/[^0-9]/', '', (string) $config['epoch'] ) : '0';
		$this->stale_flag = isset( $config['stale_flag'] ) ? (string) $config['stale_flag'] : '';

		foreach ( array( 'RES_SUCCESS', 'RES_NOTFOUND', 'RES_NOTSTORED', 'RES_DATA_EXISTS', 'RES_STORED', 'RES_DELETED', 'RES_END', 'RES_BUFFERED', 'RES_BAD_KEY_PROVIDED', 'RES_E2BIG', 'RES_CLIENT_ERROR', 'RES_SERVER_ERROR', 'RES_PAYLOAD_FAILURE' ) as $name ) {
			if ( class_exists( 'Memcached' ) && defined( 'Memcached::' . $name ) ) {
				$this->benign_codes[] = constant( 'Memcached::' . $name );
			}
		}

		$this->connect();
	}

	// =====================================================================
	// Connection
	// =====================================================================

	/**
	 * Open (or reuse) the connection and load the generations.
	 *
	 * @return void
	 */
	private function connect() {
		if ( defined( 'MBRPE_OBJECT_CACHE_DISABLED' ) && MBRPE_OBJECT_CACHE_DISABLED ) {
			$this->status = 'disabled';
			return;
		}
		if ( ! class_exists( 'Memcached' ) ) {
			$this->status = 'no-extension';
			$this->mark_stale();
			return;
		}

		try {
			// A persistent ID keeps the connection open between requests in
			// the same PHP worker, which saves a TCP handshake every request.
			$mc = new Memcached( 'mbrpe_' . md5( $this->host . ':' . $this->port ) );

			// Options are only set on a fresh connection. A reused one already
			// carries them, and setting them again resets its state.
			if ( ! count( $mc->getServerList() ) ) {
				$mc->setOption( Memcached::OPT_CONNECT_TIMEOUT, 250 );   // ms.
				$mc->setOption( Memcached::OPT_POLL_TIMEOUT, 500 );      // ms.
				$mc->setOption( Memcached::OPT_SEND_TIMEOUT, 500000 );   // µs.
				$mc->setOption( Memcached::OPT_RECV_TIMEOUT, 500000 );   // µs.
				$mc->setOption( Memcached::OPT_RETRY_TIMEOUT, 2 );       // s.
				$mc->setOption( Memcached::OPT_TCP_NODELAY, true );
				// The ASCII protocol, deliberately. Some hosts' Memcached
				// builds silently drop values sent over the binary protocol.
				$mc->setOption( Memcached::OPT_BINARY_PROTOCOL, false );
				$mc->addServer( $this->host, $this->port );
			}
			$this->mc = $mc;
		} catch ( \Throwable $e ) {
			$this->status = 'unreachable';
			$this->mark_stale();
			return;
		}

		$this->connected = true;

		$meta = $this->mc->get( $this->meta_key() );
		$this->calls++;
		$code = $this->mc->getResultCode();

		if ( Memcached::RES_SUCCESS === $code ) {
			$this->meta = $this->normalise_meta( $meta );
		} elseif ( Memcached::RES_NOTFOUND === $code ) {
			// First request against this server, or it has restarted: either
			// way nothing of ours is stored, so start a generation from the
			// clock. A clock-based start can never land back on a generation
			// that was used before a restart.
			$this->update_meta(
				function ( $m ) {
					return $m;
				}
			);
		} else {
			$this->lost();
			$this->status = 'unreachable';
			$this->mark_stale();
			return;
		}

		if ( ! $this->connected ) {
			return;
		}

		$this->status = 'connected';

		// Something was written while the server was out of reach, so what
		// Memcached holds may be out of date: flush it once before trusting
		// any of it. (Switching the cache on needs no flush — each install
		// starts a fresh key space through its epoch.)
		if ( '' !== $this->stale_flag && @file_exists( $this->stale_flag ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $this->flush() ) {
				@unlink( $this->stale_flag ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
	}

	/**
	 * Stop using Memcached for the rest of this request.
	 *
	 * @return void
	 */
	private function lost() {
		$this->connected = false;
		if ( 'connected' === $this->status ) {
			$this->status = 'lost';
		}
	}

	/**
	 * Inspect the last result code and go memory-only if it was a
	 * connection failure rather than an answer about the data.
	 *
	 * @param bool $is_write Whether the failed call was a write.
	 * @return int The result code.
	 */
	private function check_result( $is_write = false ) {
		$code = $this->mc->getResultCode();
		if ( ! in_array( $code, $this->benign_codes, true ) ) {
			$this->lost();
			if ( $is_write ) {
				$this->mark_stale();
			}
		}
		return $code;
	}

	/**
	 * Record that the server missed a write and needs flushing when it
	 * comes back. Once per request.
	 *
	 * @return void
	 */
	private function mark_stale() {
		if ( $this->marked_stale || '' === $this->stale_flag ) {
			return;
		}
		$this->marked_stale = true;
		if ( ! @file_exists( $this->stale_flag ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@touch( $this->stale_flag ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Key holding the generations.
	 *
	 * @return string
	 */
	private function meta_key() {
		return $this->salt . ':mbrpe:meta';
	}

	/**
	 * Milliseconds since the epoch, as an integer generation.
	 *
	 * @return int
	 */
	private static function clock() {
		return (int) floor( microtime( true ) * 1000 );
	}

	/**
	 * @param mixed $meta
	 * @return array{gen:int,groups:array}
	 */
	private function normalise_meta( $meta ) {
		if ( ! is_array( $meta ) || empty( $meta['gen'] ) ) {
			return array( 'gen' => self::clock(), 'groups' => array() );
		}
		return array(
			'gen'    => (int) $meta['gen'],
			'groups' => isset( $meta['groups'] ) && is_array( $meta['groups'] ) ? $meta['groups'] : array(),
		);
	}

	/**
	 * Read-modify-write the generations atomically.
	 *
	 * Compare-and-swap, so two requests flushing at once can never write an
	 * older generation back over a newer one — which would quietly bring
	 * flushed data back to life.
	 *
	 * @param callable $change Receives the current meta, returns the new one.
	 * @return bool
	 */
	private function update_meta( $change ) {
		if ( ! $this->connected ) {
			return false;
		}
		$key = $this->meta_key();

		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			$current = $this->mc->get( $key, null, Memcached::GET_EXTENDED );
			$this->calls++;
			$code = $this->mc->getResultCode();

			if ( Memcached::RES_NOTFOUND === $code ) {
				$meta = $this->normalise_meta( call_user_func( $change, array( 'gen' => self::clock(), 'groups' => array() ) ) );
				$this->calls++;
				if ( $this->mc->add( $key, $meta, 0 ) ) {
					$this->meta = $meta;
					return true;
				}
				if ( Memcached::RES_NOTSTORED === $this->check_result( true ) ) {
					continue; // Another request created it first; go again.
				}
				return false;
			}

			if ( Memcached::RES_SUCCESS !== $code || ! is_array( $current ) || ! isset( $current['cas'] ) ) {
				$this->check_result( true );
				return false;
			}

			$meta = $this->normalise_meta( call_user_func( $change, $this->normalise_meta( $current['value'] ) ) );
			$this->calls++;
			if ( $this->mc->cas( $current['cas'], $key, $meta, 0 ) ) {
				$this->meta = $meta;
				return true;
			}
			$code = $this->check_result( true );
			if ( Memcached::RES_DATA_EXISTS !== $code && Memcached::RES_NOTFOUND !== $code ) {
				return false;
			}
		}
		return false;
	}

	// =====================================================================
	// Keys
	// =====================================================================

	/**
	 * Normalise a group name.
	 *
	 * @param string $group
	 * @return string
	 */
	private static function group( $group ) {
		$group = (string) $group;
		return '' === $group ? 'default' : $group;
	}

	/**
	 * Is the key usable at all? Mirrors the core cache's validation.
	 *
	 * @param mixed $key
	 * @return bool
	 */
	private function is_valid_key( $key ) {
		if ( is_int( $key ) ) {
			return true;
		}
		if ( is_string( $key ) && '' !== trim( $key ) ) {
			return true;
		}
		if ( function_exists( '_doing_it_wrong' ) ) {
			_doing_it_wrong(
				'WP_Object_Cache',
				is_string( $key )
					? 'Cache key must not be an empty string.'
					: sprintf( 'Cache key must be an integer or a non-empty string, %s given.', gettype( $key ) ),
				'6.1.0'
			);
		}
		return false;
	}

	/**
	 * The Memcached key for a cache key and group.
	 *
	 * salt:epoch.gen:blog:group:key, with the group carrying its own
	 * generation once it has been flushed individually. Anything too long or
	 * containing characters the ASCII protocol cannot carry is hashed.
	 *
	 * @param int|string $key
	 * @param string     $group
	 * @return string
	 */
	private function server_key( $key, $group ) {
		$prefix = isset( $this->global_groups[ $group ] ) ? '' : $this->blog_prefix;
		$g      = $group;
		if ( isset( $this->meta['groups'][ $group ] ) ) {
			$g .= '~' . (int) $this->meta['groups'][ $group ];
		}

		$full = $this->salt . ':' . $this->epoch . '.' . (int) $this->meta['gen'] . ':' . $prefix . $g . ':' . $key;

		if ( strlen( $full ) > self::MAX_KEY_LENGTH || preg_match( '/[^\x21-\x7e]/', $full ) ) {
			$full = $this->salt . ':' . $this->epoch . '.' . (int) $this->meta['gen'] . ':h:' . md5( $prefix . $g . ':' . $key );
		}
		return $full;
	}

	/**
	 * Memcached reads any expiry over 30 days as an absolute timestamp.
	 *
	 * @param int $expire Seconds.
	 * @return int
	 */
	private static function expiry( $expire ) {
		$expire = (int) $expire;
		if ( $expire <= 0 ) {
			return 0;
		}
		if ( $expire > self::MAX_RELATIVE_TTL ) {
			return time() + $expire;
		}
		return $expire;
	}

	/**
	 * Should this group go to Memcached at all?
	 *
	 * @param string $group
	 * @return bool
	 */
	private function persists( $group ) {
		return $this->connected && ! isset( $this->non_persistent_groups[ $group ] );
	}

	/**
	 * Store in the request's memory, copying objects so later changes to
	 * the caller's instance do not leak into the cache.
	 *
	 * @param int|string $key
	 * @param mixed      $data
	 * @param string     $group
	 * @return void
	 */
	private function remember( $key, $data, $group ) {
		if ( is_object( $data ) ) {
			$data = clone $data;
		}
		$this->cache[ $group ][ $this->local_key( $key, $group ) ] = $data;
	}

	/**
	 * Key for the in-memory copy. On multisite, per-site groups carry the
	 * blog ID, exactly as the core cache does — otherwise a value read for
	 * one site would be handed to another after switch_to_blog(). On a single
	 * site the prefix is empty and this is the key itself.
	 *
	 * @param int|string $key
	 * @param string     $group
	 * @return int|string
	 */
	private function local_key( $key, $group ) {
		if ( '' === $this->blog_prefix || isset( $this->global_groups[ $group ] ) ) {
			return $key;
		}
		return $this->blog_prefix . $key;
	}

	/**
	 * @param int|string $key
	 * @param string     $group
	 * @return bool
	 */
	private function in_memory( $key, $group ) {
		$key = $this->local_key( $key, $group );
		return isset( $this->cache[ $group ] ) && ( isset( $this->cache[ $group ][ $key ] ) || array_key_exists( $key, $this->cache[ $group ] ) );
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private static function copy( $value ) {
		return is_object( $value ) ? clone $value : $value;
	}

	/**
	 * Run one storage command.
	 *
	 * The core cache never serialises anything, so code written against it
	 * sometimes caches values that cannot be serialised. Memcached throws on
	 * those; catching it here turns a fatal error into a value that is simply
	 * kept for this request only.
	 *
	 * @param string $op     add, set or replace.
	 * @param string $sk     Server key.
	 * @param mixed  $data
	 * @param int    $expire Already converted with expiry().
	 * @return true|int True when stored, otherwise the result code.
	 */
	private function store( $op, $sk, $data, $expire ) {
		$this->calls++;
		try {
			// Silenced: the extension warns, rather than throws, when it cannot
			// serialise a value, and the failure is handled just below.
			$ok = @$this->mc->$op( $sk, $data, $expire ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} catch ( \Throwable $e ) {
			return self::UNSERIALISABLE;
		}
		if ( $ok ) {
			return true;
		}
		return $this->check_result( 'add' !== $op );
	}

	// =====================================================================
	// Public API — mirrors WP_Object_Cache
	// =====================================================================

	/**
	 * @param int|string $key
	 * @param mixed      $data
	 * @param string     $group
	 * @param int        $expire
	 * @return bool
	 */
	public function add( $key, $data, $group = 'default', $expire = 0 ) {
		if ( function_exists( 'wp_suspend_cache_addition' ) && wp_suspend_cache_addition() ) {
			return false;
		}
		if ( ! $this->is_valid_key( $key ) ) {
			return false;
		}
		$group = self::group( $group );

		if ( $this->in_memory( $key, $group ) ) {
			return false;
		}

		if ( $this->persists( $group ) ) {
			$result = $this->store( 'add', $this->server_key( $key, $group ), $data, self::expiry( $expire ) );
			if ( true !== $result ) {
				if ( Memcached::RES_NOTSTORED === $result ) {
					return false; // Already on the server.
				}
				if ( $this->connected ) {
					// Rejected for its size or content: keep it for this
					// request only, and say so.
					$this->remember( $key, $data, $group );
					return false;
				}
			}
		}

		$this->remember( $key, $data, $group );
		return true;
	}

	/**
	 * @param array  $data
	 * @param string $group
	 * @param int    $expire
	 * @return bool[]
	 */
	public function add_multiple( array $data, $group = '', $expire = 0 ) {
		$values = array();
		foreach ( $data as $key => $value ) {
			$values[ $key ] = $this->add( $key, $value, $group, $expire );
		}
		return $values;
	}

	/**
	 * @param int|string $key
	 * @param mixed      $data
	 * @param string     $group
	 * @param int        $expire
	 * @return bool
	 */
	public function replace( $key, $data, $group = 'default', $expire = 0 ) {
		if ( ! $this->is_valid_key( $key ) ) {
			return false;
		}
		$group = self::group( $group );

		if ( ! $this->in_memory( $key, $group ) ) {
			if ( ! $this->persists( $group ) ) {
				return false;
			}
			$result = $this->store( 'replace', $this->server_key( $key, $group ), $data, self::expiry( $expire ) );
			if ( true !== $result ) {
				if ( Memcached::RES_NOTSTORED === $result ) {
					return false; // Not on the server either.
				}
				if ( $this->connected ) {
					$this->delete_from_server( $key, $group );
					return false;
				}
			}
			$this->remember( $key, $data, $group );
			return true;
		}

		return $this->set( $key, $data, $group, $expire );
	}

	/**
	 * @param int|string $key
	 * @param mixed      $data
	 * @param string     $group
	 * @param int        $expire
	 * @return bool
	 */
	public function set( $key, $data, $group = 'default', $expire = 0 ) {
		if ( ! $this->is_valid_key( $key ) ) {
			return false;
		}
		$group = self::group( $group );

		$this->remember( $key, $data, $group );

		if ( isset( $this->non_persistent_groups[ $group ] ) ) {
			return true;
		}
		if ( ! $this->connected ) {
			$this->mark_stale();
			return true;
		}

		if ( true === $this->store( 'set', $this->server_key( $key, $group ), $data, self::expiry( $expire ) ) ) {
			return true;
		}

		if ( $this->connected ) {
			// The server is up but refused the value — usually because it is
			// larger than Memcached's item limit. Whatever it held for this
			// key before is now out of date, so remove it rather than leave a
			// stale copy for the next request to find.
			$this->delete_from_server( $key, $group );
			return false;
		}
		return true;
	}

	/**
	 * @param array  $data
	 * @param string $group
	 * @param int    $expire
	 * @return bool[]
	 */
	public function set_multiple( array $data, $group = '', $expire = 0 ) {
		$group  = self::group( $group );
		$values = array();

		if ( ! $this->persists( $group ) || count( $data ) < 2 ) {
			foreach ( $data as $key => $value ) {
				$values[ $key ] = $this->set( $key, $value, $group, $expire );
			}
			return $values;
		}

		$items = array();
		$map   = array();
		foreach ( $data as $key => $value ) {
			if ( ! $this->is_valid_key( $key ) ) {
				$values[ $key ] = false;
				continue;
			}
			$this->remember( $key, $value, $group );
			$sk           = $this->server_key( $key, $group );
			$items[ $sk ] = $value;
			$map[ $sk ]   = $key;
		}
		if ( empty( $items ) ) {
			return $values;
		}

		$this->calls++;
		try {
			$ok = @$this->mc->setMulti( $items, self::expiry( $expire ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} catch ( \Throwable $e ) {
			$ok = null;
		}
		if ( true === $ok ) {
			foreach ( $map as $key ) {
				$values[ $key ] = true;
			}
			return $values;
		}

		// Something in the batch was refused. Retry one at a time so each
		// result is accurate and a refused key is cleared, not left stale.
		if ( false === $ok ) {
			$this->check_result( true );
		}
		foreach ( $map as $key ) {
			$values[ $key ] = $this->set( $key, $data[ $key ], $group, $expire );
		}
		return $values;
	}

	/**
	 * @param int|string $key
	 * @param string     $group
	 * @param bool       $force Re-read from Memcached even if held in memory.
	 * @param bool|null  $found
	 * @return mixed|false
	 */
	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		if ( ! $this->is_valid_key( $key ) ) {
			$found = false;
			return false;
		}
		$group = self::group( $group );

		if ( ( ! $force || ! $this->persists( $group ) ) && $this->in_memory( $key, $group ) ) {
			$found = true;
			$this->cache_hits++;
			return self::copy( $this->cache[ $group ][ $this->local_key( $key, $group ) ] );
		}

		if ( ! $this->persists( $group ) ) {
			$found = false;
			$this->cache_misses++;
			return false;
		}

		$this->calls++;
		$value = $this->mc->get( $this->server_key( $key, $group ) );
		$code  = $this->check_result( false );

		if ( Memcached::RES_SUCCESS === $code ) {
			$found = true;
			$this->cache_hits++;
			$this->cache[ $group ][ $this->local_key( $key, $group ) ] = $value;
			return self::copy( $value );
		}

		$found = false;
		$this->cache_misses++;
		return false;
	}

	/**
	 * @param array  $keys
	 * @param string $group
	 * @param bool   $force
	 * @return array
	 */
	public function get_multiple( $keys, $group = 'default', $force = false ) {
		$group  = self::group( $group );
		$values = array();
		$fetch  = array();

		foreach ( $keys as $key ) {
			if ( ! $this->is_valid_key( $key ) ) {
				$values[ $key ] = false;
				continue;
			}
			if ( ( ! $force || ! $this->persists( $group ) ) && $this->in_memory( $key, $group ) ) {
				$this->cache_hits++;
				$values[ $key ] = self::copy( $this->cache[ $group ][ $this->local_key( $key, $group ) ] );
				continue;
			}
			$values[ $key ] = false;
			if ( $this->persists( $group ) ) {
				$fetch[ $this->server_key( $key, $group ) ] = $key;
			} else {
				$this->cache_misses++;
			}
		}

		if ( empty( $fetch ) ) {
			return $values;
		}

		$this->calls++;
		$found = $this->mc->getMulti( array_keys( $fetch ) );
		$this->check_result( false );

		foreach ( $fetch as $sk => $key ) {
			if ( is_array( $found ) && array_key_exists( $sk, $found ) ) {
				$this->cache_hits++;
				$this->cache[ $group ][ $this->local_key( $key, $group ) ] = $found[ $sk ];
				$values[ $key ]                = self::copy( $found[ $sk ] );
			} else {
				$this->cache_misses++;
			}
		}

		return $values;
	}

	/**
	 * Remove a key from the server only.
	 *
	 * @param int|string $key
	 * @param string     $group
	 * @return bool Whether the server held it.
	 */
	private function delete_from_server( $key, $group ) {
		if ( ! $this->persists( $group ) ) {
			return false;
		}
		$this->calls++;
		if ( $this->mc->delete( $this->server_key( $key, $group ) ) ) {
			return true;
		}
		$this->check_result( true );
		return false;
	}

	/**
	 * @param int|string $key
	 * @param string     $group
	 * @return bool
	 */
	public function delete( $key, $group = 'default' ) {
		if ( ! $this->is_valid_key( $key ) ) {
			return false;
		}
		$group = self::group( $group );

		$held = $this->in_memory( $key, $group );
		unset( $this->cache[ $group ][ $this->local_key( $key, $group ) ] );

		if ( isset( $this->non_persistent_groups[ $group ] ) ) {
			return $held;
		}
		if ( ! $this->connected ) {
			$this->mark_stale();
			return $held;
		}

		return $this->delete_from_server( $key, $group ) || $held;
	}

	/**
	 * @param array  $keys
	 * @param string $group
	 * @return bool[]
	 */
	public function delete_multiple( array $keys, $group = '' ) {
		$values = array();
		foreach ( $keys as $key ) {
			$values[ $key ] = $this->delete( $key, $group );
		}
		return $values;
	}

	/**
	 * Add to a numeric value, in the way the core cache does: a missing key
	 * fails, a non-numeric value is treated as zero, and the result never
	 * goes below zero.
	 *
	 * @param int|string $key
	 * @param int        $offset
	 * @param string     $group
	 * @return int|false
	 */
	public function incr( $key, $offset = 1, $group = 'default' ) {
		return $this->change_number( $key, (int) $offset, $group );
	}

	/**
	 * @param int|string $key
	 * @param int        $offset
	 * @param string     $group
	 * @return int|false
	 */
	public function decr( $key, $offset = 1, $group = 'default' ) {
		return $this->change_number( $key, -1 * (int) $offset, $group );
	}

	/**
	 * @param int|string $key
	 * @param int        $offset Positive or negative.
	 * @param string     $group
	 * @return int|false
	 */
	private function change_number( $key, $offset, $group ) {
		if ( ! $this->is_valid_key( $key ) ) {
			return false;
		}
		$group = self::group( $group );

		if ( $this->persists( $group ) ) {
			// Atomic on the server where the stored value allows it.
			$sk = $this->server_key( $key, $group );
			$this->calls++;
			$result = $offset >= 0
				? $this->mc->increment( $sk, $offset )
				: $this->mc->decrement( $sk, -1 * $offset );
			if ( false !== $result ) {
				$this->cache[ $group ][ $this->local_key( $key, $group ) ] = (int) $result;
				return (int) $result;
			}
			$code = $this->check_result( true );
			if ( Memcached::RES_NOTFOUND === $code ) {
				unset( $this->cache[ $group ][ $this->local_key( $key, $group ) ] );
				return false;
			}
		}

		// Memory-only groups, a lost connection, or a value Memcached will not
		// do arithmetic on (a float, a string): do it here, as core does.
		$found = false;
		$value = $this->get( $key, $group, false, $found );
		if ( ! $found ) {
			return false;
		}
		$value = is_numeric( $value ) ? (int) $value : 0;
		$value = max( 0, $value + $offset );
		$this->set( $key, $value, $group );
		return $value;
	}

	/**
	 * Flush everything: raise the generation so every stored key goes out of
	 * reach at once. Leaves other sites on a shared server untouched.
	 *
	 * @return bool
	 */
	public function flush() {
		$this->cache = array();

		if ( ! $this->connected ) {
			$this->mark_stale();
			return true;
		}

		$now = self::clock();
		return $this->update_meta(
			function ( $m ) use ( $now ) {
				return array(
					'gen'    => max( (int) $m['gen'] + 1, $now ),
					'groups' => array(),
				);
			}
		);
	}

	/**
	 * @return bool
	 */
	public function flush_runtime() {
		$this->cache = array();
		return true;
	}

	/**
	 * @param string $group
	 * @return bool
	 */
	public function flush_group( $group ) {
		$group = self::group( $group );
		unset( $this->cache[ $group ] );

		if ( isset( $this->non_persistent_groups[ $group ] ) ) {
			return true;
		}
		if ( ! $this->connected ) {
			$this->mark_stale();
			return true;
		}

		$now = self::clock();
		return $this->update_meta(
			function ( $m ) use ( $group, $now ) {
				$current              = isset( $m['groups'][ $group ] ) ? (int) $m['groups'][ $group ] : 0;
				$m['groups'][ $group ] = max( $current + 1, $now );
				return $m;
			}
		);
	}

	/**
	 * @param string|string[] $groups
	 * @return void
	 */
	public function add_global_groups( $groups ) {
		foreach ( (array) $groups as $group ) {
			$this->global_groups[ (string) $group ] = true;
		}
	}

	/**
	 * @param string|string[] $groups
	 * @return void
	 */
	public function add_non_persistent_groups( $groups ) {
		foreach ( (array) $groups as $group ) {
			$this->non_persistent_groups[ (string) $group ] = true;
		}
	}

	/**
	 * @param int $blog_id
	 * @return void
	 */
	public function switch_to_blog( $blog_id ) {
		$this->blog_prefix = $this->multisite ? (int) $blog_id . ':' : '';
	}

	/**
	 * Drop per-site groups from memory. Deprecated in core since 3.5.
	 *
	 * @return true
	 */
	public function reset() {
		foreach ( array_keys( $this->cache ) as $group ) {
			if ( ! isset( $this->global_groups[ $group ] ) ) {
				unset( $this->cache[ $group ] );
			}
		}
		return true;
	}

	/**
	 * @param string $feature
	 * @return bool
	 */
	public function supports( $feature ) {
		return in_array( $feature, array( 'add_multiple', 'set_multiple', 'get_multiple', 'delete_multiple', 'flush_runtime', 'flush_group' ), true );
	}

	/**
	 * Print hit and miss counts, as the core cache does for debugging tools.
	 *
	 * @return void
	 */
	public function stats() {
		echo '<p>';
		echo '<strong>Cache Hits:</strong> ' . (int) $this->cache_hits . '<br />';
		echo '<strong>Cache Misses:</strong> ' . (int) $this->cache_misses . '<br />';
		echo '<strong>Memcached:</strong> ' . htmlspecialchars( $this->status, ENT_QUOTES ) . ' (' . (int) $this->calls . ' calls)<br />';
		echo '</p>';
	}

	/**
	 * State for the MBR Performance Cache tab.
	 *
	 * @return array
	 */
	public function mbrpe_info() {
		return array(
			'version'   => MBRPE_OBJECT_CACHE_VERSION,
			'status'    => $this->status,
			'connected' => $this->connected,
			'host'      => $this->host,
			'port'      => $this->port,
			'salt'      => $this->salt,
			'hits'      => $this->cache_hits,
			'misses'    => $this->cache_misses,
			'calls'     => $this->calls,
		);
	}

	/**
	 * Server statistics for the Cache tab, or an empty array.
	 *
	 * @return array
	 */
	public function mbrpe_server_stats() {
		if ( ! $this->connected || ! $this->mc ) {
			return array();
		}
		$stats = $this->mc->getStats();
		if ( ! is_array( $stats ) || empty( $stats ) ) {
			return array();
		}
		$first = reset( $stats );
		return is_array( $first ) ? $first : array();
	}

	/**
	 * Release the connection. Persistent connections stay open on purpose.
	 *
	 * @return bool
	 */
	public function close() {
		return true;
	}
}

/*
 * The functions WordPress calls.
 *
 * WordPress requires this file from inside wp_start_object_cache(), so plain
 * variables at the top of the file would be local to that function — which is
 * why the settings above are written to $GLOBALS explicitly.
 */

if ( ! function_exists( 'wp_cache_init' ) ) {
	/**
	 * @return void
	 */
	function wp_cache_init() {
		$GLOBALS['wp_object_cache'] = new WP_Object_Cache();

		// On a single site with no working server, tell WordPress there is no
		// persistent cache after all, so transients go to the database rather
		// than evaporating at the end of every request. That matters: WP-Cron
		// keeps its lock in a transient, and without one every page load could
		// try to start cron. Multisite is left alone because ms-settings.php
		// restarts the object cache and would load core's cache.php over this
		// file if the flag were down.
		$info = $GLOBALS['wp_object_cache']->mbrpe_info();
		if ( ! $info['connected'] && function_exists( 'is_multisite' ) && ! is_multisite() && function_exists( 'wp_using_ext_object_cache' ) ) {
			wp_using_ext_object_cache( false );
		}
	}
}

/**
 * @return WP_Object_Cache
 */
function mbrpe_object_cache() {
	global $wp_object_cache;
	return $wp_object_cache;
}

function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
	return mbrpe_object_cache()->add( $key, $data, $group, (int) $expire );
}

function wp_cache_add_multiple( array $data, $group = '', $expire = 0 ) {
	return mbrpe_object_cache()->add_multiple( $data, $group, (int) $expire );
}

function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
	return mbrpe_object_cache()->replace( $key, $data, $group, (int) $expire );
}

function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
	return mbrpe_object_cache()->set( $key, $data, $group, (int) $expire );
}

function wp_cache_set_multiple( array $data, $group = '', $expire = 0 ) {
	return mbrpe_object_cache()->set_multiple( $data, $group, (int) $expire );
}

function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	return mbrpe_object_cache()->get( $key, $group, $force, $found );
}

function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
	return mbrpe_object_cache()->get_multiple( $keys, $group, $force );
}

function wp_cache_delete( $key, $group = '' ) {
	return mbrpe_object_cache()->delete( $key, $group );
}

function wp_cache_delete_multiple( array $keys, $group = '' ) {
	return mbrpe_object_cache()->delete_multiple( $keys, $group );
}

function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	return mbrpe_object_cache()->incr( $key, $offset, $group );
}

function wp_cache_decr( $key, $offset = 1, $group = '' ) {
	return mbrpe_object_cache()->decr( $key, $offset, $group );
}

function wp_cache_flush() {
	return mbrpe_object_cache()->flush();
}

function wp_cache_flush_runtime() {
	return mbrpe_object_cache()->flush_runtime();
}

function wp_cache_flush_group( $group ) {
	return mbrpe_object_cache()->flush_group( $group );
}

function wp_cache_supports( $feature ) {
	return mbrpe_object_cache()->supports( $feature );
}

function wp_cache_close() {
	return mbrpe_object_cache()->close();
}

function wp_cache_add_global_groups( $groups ) {
	mbrpe_object_cache()->add_global_groups( $groups );
}

function wp_cache_add_non_persistent_groups( $groups ) {
	mbrpe_object_cache()->add_non_persistent_groups( $groups );
}

function wp_cache_switch_to_blog( $blog_id ) {
	mbrpe_object_cache()->switch_to_blog( $blog_id );
}

function wp_cache_reset() {
	if ( function_exists( '_deprecated_function' ) ) {
		_deprecated_function( __FUNCTION__, '3.5.0', 'wp_cache_switch_to_blog()' );
	}
	return mbrpe_object_cache()->reset();
}
