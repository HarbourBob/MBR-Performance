<?php
/**
 * Persistent object cache (Memcached) — drop-in management.
 *
 * Owns wp-content/object-cache.php, the drop-in WordPress loads before any
 * plugin to replace its in-memory object cache with a persistent one. The
 * drop-in itself lives in includes/dropins/object-cache.php and carries its
 * connection settings inline, because it runs before the options table can be
 * read; this class renders that template, installs and removes it, reports on
 * it, and answers the Cache tab's Test Connection and Flush buttons.
 *
 * Like the page cache, everything here is best-effort and reports rather than
 * throws. A missing PHP extension, an unreachable server or a drop-in owned by
 * another plugin are all ordinary situations with a plain-English explanation,
 * not errors.
 *
 * @package MBRPE
 * @since   2.2.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MBRPE_Object_Cache {

	/**
	 * Contract version. Must match MBRPE_OBJECT_CACHE_VERSION in the template.
	 */
	const DROPIN_VERSION = '2.2.0';

	/**
	 * Option holding the fingerprint and epoch of the installed drop-in.
	 */
	const STATE_OPTION = 'mbrpe_object_cache_state';

	/**
	 * Option carrying settings notices across a save that switched the
	 * object cache on or off.
	 */
	const CARRY_OPTION = 'mbrpe_carried_settings_errors';

	/**
	 * Defaults for the connection fields.
	 */
	const DEFAULT_HOST = '127.0.0.1';
	const DEFAULT_PORT = 11211;

	/**
	 * @var MBRPE_Object_Cache|null
	 */
	private static $instance = null;

	/**
	 * @return MBRPE_Object_Cache
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hooks. Instantiated from the bootstrap on every request so the toolbar
	 * item and the admin routes exist whether or not the cache is in use.
	 */
	private function __construct() {
		add_action( 'admin_post_mbrpe_object_cache_flush', array( $this, 'handle_flush_request' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_flush_notice' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ), 101 );
		add_action( 'wp_ajax_mbrpe_memcached_test', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_mbrpe_object_cache_flush', array( $this, 'ajax_flush' ) );
	}

	// =====================================================================
	// Paths
	// =====================================================================

	/**
	 * @return string
	 */
	public static function dropin_path() {
		return WP_CONTENT_DIR . '/object-cache.php';
	}

	/**
	 * @return string
	 */
	public static function template_path() {
		return MBRPE_PLUGIN_DIR . 'includes/dropins/object-cache.php';
	}

	/**
	 * Marker the drop-in leaves when a write could not reach Memcached. The
	 * next request that connects flushes the cache and removes it.
	 *
	 * Kept in wp-content itself rather than wp-content/cache, which hosts and
	 * other plugins empty without warning — losing this file would mean
	 * serving data that went stale during an outage.
	 *
	 * @return string
	 */
	public static function stale_flag_path() {
		return WP_CONTENT_DIR . '/.mbrpe-object-cache-flush';
	}

	// =====================================================================
	// Permissions
	// =====================================================================

	/**
	 * May the current user change or flush the object cache?
	 *
	 * The drop-in serves the whole install, so on multisite this is a
	 * network-level decision: a super admin, on the main site.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage() {
		if ( is_multisite() ) {
			return is_super_admin() && is_main_site();
		}
		return current_user_can( 'manage_options' );
	}

	// =====================================================================
	// Settings
	// =====================================================================

	/**
	 * Effective connection settings: wp-config.php constants win over the
	 * Cache tab, as they do inside the drop-in.
	 *
	 * @return array{enabled:bool,host:string,port:int,host_locked:bool,port_locked:bool}
	 */
	public static function settings() {
		$opts = function_exists( 'mbrpe' ) ? mbrpe()->get_options( 'cache' ) : array();
		$opts = is_array( $opts ) ? $opts : array();

		$host = isset( $opts['memcached_host'] ) && '' !== $opts['memcached_host'] ? (string) $opts['memcached_host'] : self::DEFAULT_HOST;
		$port = isset( $opts['memcached_port'] ) ? (int) $opts['memcached_port'] : self::DEFAULT_PORT;

		$host_locked = defined( 'MBRPE_MEMCACHED_HOST' ) && '' !== (string) MBRPE_MEMCACHED_HOST;
		$port_locked = defined( 'MBRPE_MEMCACHED_PORT' );
		if ( $host_locked ) {
			$host = (string) MBRPE_MEMCACHED_HOST;
		}
		if ( $port_locked ) {
			$port = (int) MBRPE_MEMCACHED_PORT;
		}
		if ( self::is_socket( $host ) ) {
			$port = 0;
		}

		return array(
			'enabled'     => ! empty( $opts['memcached_enabled'] ),
			'host'        => $host,
			'port'        => $port,
			'host_locked' => $host_locked,
			'port_locked' => $port_locked,
		);
	}

	/**
	 * Is this "host" really the path to a Unix socket?
	 *
	 * @param string $host
	 * @return bool
	 */
	public static function is_socket( $host ) {
		return is_string( $host ) && '' !== $host && '/' === $host[0];
	}

	/**
	 * Validate a host typed into the Cache tab.
	 *
	 * Accepts an IPv4 or IPv6 address, a hostname, or the absolute path of a
	 * Unix socket. The value ends up inside a generated PHP file, so anything
	 * else is refused outright rather than cleaned up.
	 *
	 * @param string $host
	 * @return string|false Normalised host, or false if unusable.
	 */
	public static function validate_host( $host ) {
		$host = trim( (string) $host );
		if ( '' === $host || strlen( $host ) > 253 ) {
			return false;
		}

		if ( self::is_socket( $host ) ) {
			return ( preg_match( '#^/[A-Za-z0-9._/\-]+$#', $host ) && false === strpos( $host, '..' ) ) ? $host : false;
		}

		// [::1] style brackets are a URL convention; Memcached wants them off.
		$bare = trim( $host, '[]' );
		if ( filter_var( $bare, FILTER_VALIDATE_IP ) ) {
			return $bare;
		}

		if ( preg_match( '/^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9\-]{0,61}[A-Za-z0-9])?)(\.[A-Za-z0-9]([A-Za-z0-9\-]{0,61}[A-Za-z0-9])?)*$/', $host ) ) {
			return strtolower( $host );
		}

		return false;
	}

	/**
	 * Validate a port number.
	 *
	 * @param mixed $port
	 * @return int|false
	 */
	public static function validate_port( $port ) {
		if ( is_string( $port ) ) {
			$port = trim( $port );
			if ( '' === $port || ! ctype_digit( $port ) ) {
				return false;
			}
		}
		$port = (int) $port;
		return ( $port >= 1 && $port <= 65535 ) ? $port : false;
	}

	/**
	 * Key salt unique to this install, so several sites can share one
	 * Memcached server without reading each other's entries.
	 *
	 * @return string
	 */
	public static function salt() {
		global $table_prefix;
		$seed = ABSPATH . '|' . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . ( isset( $table_prefix ) ? $table_prefix : '' );
		return 'mbrpe_' . substr( md5( $seed ), 0, 10 );
	}

	// =====================================================================
	// Status
	// =====================================================================

	/**
	 * Is the PHP Memcached extension available?
	 *
	 * @return bool
	 */
	public static function extension_loaded() {
		return class_exists( 'Memcached' );
	}

	/**
	 * @return string Empty when not loaded.
	 */
	public static function extension_version() {
		if ( ! self::extension_loaded() ) {
			return '';
		}
		$v = phpversion( 'memcached' );
		return is_string( $v ) ? $v : '';
	}

	/**
	 * Classify whatever is at wp-content/object-cache.php.
	 *
	 * @return string missing|foreign|ours-stale|ours-current
	 */
	public static function dropin_state() {
		$path = self::dropin_path();
		if ( ! file_exists( $path ) ) {
			return 'missing';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the header of a local drop-in.
		$head = @file_get_contents( $path, false, null, 0, 8192 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $head || false === strpos( $head, 'MBRPE_OBJECT_CACHE_VERSION' ) ) {
			return 'foreign';
		}
		if ( preg_match( "/define\(\s*'MBRPE_OBJECT_CACHE_VERSION'\s*,\s*'([^']+)'/", $head, $m ) ) {
			return version_compare( $m[1], self::DROPIN_VERSION, '>=' ) ? 'ours-current' : 'ours-stale';
		}
		return 'ours-stale';
	}

	/**
	 * Name of the plugin that owns a foreign object-cache.php, if it says.
	 *
	 * @return string
	 */
	public static function foreign_name() {
		$path = self::dropin_path();
		if ( ! file_exists( $path ) ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the header of a local drop-in.
		$head = @file_get_contents( $path, false, null, 0, 8192 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_string( $head ) && preg_match( '/^[ \t\/*#@]*Plugin Name:\s*(.+)$/mi', $head, $m ) ) {
			return trim( wp_strip_all_tags( $m[1] ) );
		}
		return '';
	}

	/**
	 * The drop-in instance serving this request, if it is ours.
	 *
	 * @return object|null
	 */
	public static function runtime() {
		global $wp_object_cache;
		if ( is_object( $wp_object_cache ) && method_exists( $wp_object_cache, 'mbrpe_info' ) ) {
			return $wp_object_cache;
		}
		return null;
	}

	/**
	 * Is our drop-in loaded and connected right now?
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$rt = self::runtime();
		if ( ! $rt ) {
			return false;
		}
		$info = $rt->mbrpe_info();
		return ! empty( $info['connected'] );
	}

	/**
	 * Everything the Cache tab shows.
	 *
	 * @return array
	 */
	public static function health() {
		$settings = self::settings();
		$state    = self::dropin_state();
		$rt       = self::runtime();
		$info     = $rt ? $rt->mbrpe_info() : array();
		$stats    = ( $rt && ! empty( $info['connected'] ) ) ? $rt->mbrpe_server_stats() : array();

		return array(
			'settings'        => $settings,
			'extension'       => self::extension_loaded(),
			'ext_version'     => self::extension_version(),
			'dropin_state'    => $state,
			'foreign_name'    => 'foreign' === $state ? self::foreign_name() : '',
			'content_writable' => wp_is_writable( WP_CONTENT_DIR ),
			'loaded'          => (bool) $rt,
			'info'            => $info,
			'stats'           => $stats,
			'ext_cache_in_use' => wp_using_ext_object_cache(),
			'stale_pending'   => file_exists( self::stale_flag_path() ),
			'can_manage'      => self::current_user_can_manage(),
		);
	}

	// =====================================================================
	// Connection test
	// =====================================================================

	/**
	 * Try a server: connect, write, read back, delete, and collect stats.
	 *
	 * Uses its own short-lived connection, never the drop-in's, so it tests
	 * exactly the values given — typically ones typed but not yet saved.
	 *
	 * @param string $host
	 * @param int    $port
	 * @return array{ok:bool,message:string,version:string,stats:array}
	 */
	public static function test_connection( $host, $port ) {
		$out = array(
			'ok'      => false,
			'message' => '',
			'version' => '',
			'stats'   => array(),
		);

		if ( ! self::extension_loaded() ) {
			$out['message'] = __( 'The PHP Memcached extension is not installed on this server, so WordPress cannot talk to Memcached at all. Ask your host to enable the "memcached" PHP extension (not the older "memcache" one).', 'mbr-performance' );
			return $out;
		}

		$host = self::validate_host( $host );
		if ( false === $host ) {
			$out['message'] = __( 'That is not a valid IP address, hostname or socket path.', 'mbr-performance' );
			return $out;
		}
		if ( self::is_socket( $host ) ) {
			$port = 0;
		} else {
			$port = self::validate_port( $port );
			if ( false === $port ) {
				$out['message'] = __( 'The port must be a whole number between 1 and 65535.', 'mbr-performance' );
				return $out;
			}
		}

		$label = self::is_socket( $host ) ? $host : $host . ':' . $port;

		try {
			$mc = new Memcached();
			$mc->setOption( Memcached::OPT_CONNECT_TIMEOUT, 1000 );
			$mc->setOption( Memcached::OPT_POLL_TIMEOUT, 1000 );
			$mc->setOption( Memcached::OPT_SEND_TIMEOUT, 1000000 );
			$mc->setOption( Memcached::OPT_RECV_TIMEOUT, 1000000 );
			$mc->setOption( Memcached::OPT_BINARY_PROTOCOL, false );
			$mc->addServer( $host, $port );

			$key   = self::salt() . ':mbrpe:test:' . wp_generate_password( 8, false );
			$value = array( 'mbrpe' => wp_generate_password( 16, false ) );

			if ( ! $mc->set( $key, $value, 30 ) ) {
				$out['message'] = sprintf(
					/* translators: 1: host:port, 2: error from the Memcached library */
					__( 'Could not reach Memcached at %1$s (%2$s). Check the IP address and port, and that Memcached is switched on in your hosting control panel.', 'mbr-performance' ),
					$label,
					$mc->getResultMessage()
				);
				return $out;
			}

			$back = $mc->get( $key );
			$mc->delete( $key );

			if ( $back !== $value ) {
				$out['message'] = sprintf(
					/* translators: %s: host:port */
					__( 'Memcached at %s accepted a test value but returned something different. The server is answering but not storing reliably, so it should not be used as an object cache.', 'mbr-performance' ),
					$label
				);
				return $out;
			}

			$stats = $mc->getStats();
			$first = is_array( $stats ) ? reset( $stats ) : array();
			$out['stats']   = is_array( $first ) ? $first : array();
			$out['version'] = isset( $out['stats']['version'] ) ? (string) $out['stats']['version'] : '';
			$out['ok']      = true;
			$out['message'] = sprintf(
				/* translators: 1: host:port, 2: Memcached server version */
				__( 'Connected to Memcached %2$s at %1$s. A test value was written, read back and removed.', 'mbr-performance' ),
				$label,
				'' !== $out['version'] ? $out['version'] : '?'
			);
			$mc->quit();
		} catch ( \Throwable $e ) {
			$out['message'] = sprintf(
				/* translators: 1: host:port, 2: error message */
				__( 'Could not reach Memcached at %1$s (%2$s).', 'mbr-performance' ),
				$label,
				$e->getMessage()
			);
		}

		return $out;
	}

	/**
	 * Summarise raw Memcached stats for display.
	 *
	 * @param array $stats
	 * @return array{version:string,uptime:int,items:int,used:int,limit:int,hit_rate:float|null,evictions:int}
	 */
	public static function summarise_stats( $stats ) {
		$stats = is_array( $stats ) ? $stats : array();
		$hits  = isset( $stats['get_hits'] ) ? (int) $stats['get_hits'] : 0;
		$miss  = isset( $stats['get_misses'] ) ? (int) $stats['get_misses'] : 0;

		return array(
			'version'   => isset( $stats['version'] ) ? (string) $stats['version'] : '',
			'uptime'    => isset( $stats['uptime'] ) ? (int) $stats['uptime'] : 0,
			'items'     => isset( $stats['curr_items'] ) ? (int) $stats['curr_items'] : 0,
			'used'      => isset( $stats['bytes'] ) ? (int) $stats['bytes'] : 0,
			'limit'     => isset( $stats['limit_maxbytes'] ) ? (int) $stats['limit_maxbytes'] : 0,
			'hit_rate'  => ( $hits + $miss ) > 0 ? round( 100 * $hits / ( $hits + $miss ), 1 ) : null,
			'evictions' => isset( $stats['evictions'] ) ? (int) $stats['evictions'] : 0,
		);
	}

	// =====================================================================
	// Install / uninstall
	// =====================================================================

	/**
	 * Fingerprint of the settings a drop-in was rendered with.
	 *
	 * @param array $settings From settings().
	 * @return string
	 */
	private static function fingerprint( $settings ) {
		return md5( $settings['host'] . '|' . $settings['port'] . '|' . self::salt() . '|' . self::DROPIN_VERSION );
	}

	/**
	 * Render the template with the given settings written in.
	 *
	 * @param array $config
	 * @return string|WP_Error
	 */
	public static function render( $config ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the plugin's own bundled template.
		$template = @file_get_contents( self::template_path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $template || '' === $template ) {
			return new WP_Error( 'mbrpe_oc_template', __( 'The bundled object-cache.php template is missing from the plugin. Try reinstalling MBR Performance.', 'mbr-performance' ) );
		}

		$block = "// @mbrpe-config-start\n"
			. "\$GLOBALS['mbrpe_object_cache_config'] = " . var_export( $config, true ) . ";\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
			. '// @mbrpe-config-end';

		$count    = 0;
		$rendered = preg_replace_callback(
			'#// @mbrpe-config-start.*?// @mbrpe-config-end#s',
			static function () use ( $block ) {
				return $block;
			},
			$template,
			1,
			$count
		);

		if ( null === $rendered || 1 !== $count ) {
			return new WP_Error( 'mbrpe_oc_template', __( 'The object-cache.php template is damaged (its settings block is missing). Try reinstalling MBR Performance.', 'mbr-performance' ) );
		}

		// A broken object-cache.php takes the whole site down, admin included,
		// so nothing that fails a syntax check is ever written.
		if ( function_exists( 'token_get_all' ) ) {
			try {
				token_get_all( $rendered, TOKEN_PARSE );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'mbrpe_oc_syntax', __( 'The generated object-cache.php did not pass a syntax check, so it was not installed.', 'mbr-performance' ) );
			}
		}

		return $rendered;
	}

	/**
	 * Install (or refresh) the drop-in.
	 *
	 * @param bool $force Take over a foreign object-cache.php.
	 * @return true|WP_Error
	 */
	public static function install( $force = false ) {
		if ( ! self::extension_loaded() ) {
			return new WP_Error( 'mbrpe_oc_no_ext', __( 'The PHP Memcached extension is not installed, so the object cache was not switched on.', 'mbr-performance' ) );
		}

		$state = self::dropin_state();
		if ( 'foreign' === $state && ! $force ) {
			$name = self::foreign_name();
			return new WP_Error(
				'mbrpe_oc_foreign',
				$name
					/* translators: %s: name of the plugin that owns object-cache.php */
					? sprintf( __( 'wp-content/object-cache.php belongs to %s. MBR Performance will not overwrite another plugin\'s object cache — switch that one off first, or use Replace on the Cache tab once it has been removed.', 'mbr-performance' ), $name )
					: __( 'wp-content/object-cache.php belongs to another plugin or your host. MBR Performance will not overwrite it — remove it first, or use Replace on the Cache tab if you are sure it is no longer needed.', 'mbr-performance' )
			);
		}

		if ( ! wp_is_writable( WP_CONTENT_DIR ) ) {
			return new WP_Error( 'mbrpe_oc_readonly', __( 'wp-content is not writable, so object-cache.php could not be installed.', 'mbr-performance' ) );
		}

		$settings = self::settings();
		$fp       = self::fingerprint( $settings );
		$stored   = get_option( self::STATE_OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();

		// A new epoch means a fresh key space: nothing written before it can
		// be read. Used whenever the cache is switched on from off, or pointed
		// at a different server, because WordPress has been writing to the
		// database without Memcached seeing it. A plain refresh of an
		// installed copy keeps its epoch, so saving settings never empties a
		// warm cache for no reason.
		$fresh = ! in_array( $state, array( 'ours-current', 'ours-stale' ), true )
			|| empty( $stored['epoch'] )
			|| ! isset( $stored['fp'] ) || $stored['fp'] !== $fp;
		$epoch = $fresh ? (string) (int) floor( microtime( true ) * 1000 ) : (string) $stored['epoch'];

		$rendered = self::render(
			array(
				'host'       => $settings['host'],
				'port'       => (int) $settings['port'],
				'salt'       => self::salt(),
				'epoch'      => $epoch,
				'stale_flag' => self::stale_flag_path(),
			)
		);
		if ( is_wp_error( $rendered ) ) {
			return $rendered;
		}

		$path = self::dropin_path();
		$tmp  = $path . '.' . wp_generate_password( 6, false ) . '.tmp';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === @file_put_contents( $tmp, $rendered, LOCK_EX ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'mbrpe_oc_write', __( 'Could not write wp-content/object-cache.php.', 'mbr-performance' ) );
		}
		@chmod( $tmp, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		// Atomic: a request starting mid-write must see the old file or the
		// new one, never half of either.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return new WP_Error( 'mbrpe_oc_write', __( 'Could not put wp-content/object-cache.php into place.', 'mbr-performance' ) );
		}

		// Without this, a validate_timestamps=0 host would keep running the
		// previous copy — or the absence of one — until OPcache was flushed.
		MBRPE_OPcache::invalidate( $path );

		update_option(
			self::STATE_OPTION,
			array(
				'fp'        => $fp,
				'epoch'     => $epoch,
				'installed' => time(),
			),
			false
		);

		// A fresh key space needs no flush, so drop any marker left over.
		if ( $fresh && file_exists( self::stale_flag_path() ) ) {
			@unlink( self::stale_flag_path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		return true;
	}

	/**
	 * Remove the drop-in. Never touches one that is not ours.
	 *
	 * @return true
	 */
	public static function uninstall() {
		if ( in_array( self::dropin_state(), array( 'ours-current', 'ours-stale' ), true ) ) {
			// Invalidate before deleting: OPcache needs the file to resolve it.
			MBRPE_OPcache::invalidate( self::dropin_path() );
			@unlink( self::dropin_path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		if ( file_exists( self::stale_flag_path() ) ) {
			@unlink( self::stale_flag_path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		return true;
	}

	/**
	 * Bring the drop-in in line with the saved settings.
	 *
	 * Hooked to update_option_mbrpe_options from the bootstrap, after the
	 * page cache's own handler. Errors are reported on the settings screen.
	 *
	 * @return void
	 */
	public static function on_options_updated() {
		// One drop-in serves the whole network, so only the main site's
		// settings may switch it on or off. A sub-site saving its Cache tab —
		// where these fields are not even shown — must not remove it.
		if ( is_multisite() && ! is_main_site() ) {
			return;
		}

		$settings = self::settings();
		$state    = self::dropin_state();

		if ( $settings['enabled'] ) {
			if ( 'foreign' === $state ) {
				return; // Reported on the tab; never overwritten implicitly.
			}
			$stored = get_option( self::STATE_OPTION, array() );
			$fp     = self::fingerprint( $settings );
			if ( 'ours-current' !== $state || ! is_array( $stored ) || ! isset( $stored['fp'] ) || $stored['fp'] !== $fp ) {
				$was_installed = in_array( $state, array( 'ours-current', 'ours-stale' ), true );
				$result        = self::install();
				if ( is_wp_error( $result ) && function_exists( 'add_settings_error' ) ) {
					add_settings_error( 'mbrpe_options', 'mbrpe_object_cache', $result->get_error_message(), 'error' );
				} elseif ( ! $was_installed ) {
					self::carry_settings_errors();
				}
			}
		} elseif ( in_array( $state, array( 'ours-current', 'ours-stale' ), true ) ) {
			self::uninstall();
			// The rest of this request still runs on Memcached, but the next
			// one will not: send anything else written this request, such as
			// options.php's own notices, to the database where it will look.
			// (Switching on needs carry_settings_errors() instead, because the
			// store the next request reads from is not loaded in this one.)
			if ( function_exists( 'wp_using_ext_object_cache' ) && self::runtime() ) {
				wp_using_ext_object_cache( false );
			}
		}
	}

	/**
	 * Keep this save's settings notices for the next page load.
	 *
	 * options.php hands notices to the next request in a transient. When this
	 * save switched the object cache on or off, the transient is written to
	 * one store and read back from the other, and the notices — including the
	 * error saying why something failed — would be lost. They are copied to a
	 * plain option at the end of the request instead.
	 *
	 * @return void
	 */
	public static function carry_settings_errors() {
		static $hooked = false;
		if ( $hooked ) {
			return;
		}
		$hooked = true;
		add_action(
			'shutdown',
			static function () {
				if ( ! function_exists( 'get_settings_errors' ) ) {
					return;
				}
				$errors = get_settings_errors();
				if ( ! empty( $errors ) ) {
					update_option( self::CARRY_OPTION, $errors, false );
				}
			},
			0
		);
	}

	/**
	 * Re-register notices carried over by carry_settings_errors().
	 *
	 * @return void
	 */
	public static function restore_settings_errors() {
		$errors = get_option( self::CARRY_OPTION, array() );
		if ( empty( $errors ) || ! is_array( $errors ) ) {
			return;
		}
		delete_option( self::CARRY_OPTION );
		foreach ( $errors as $error ) {
			// Only this screen's own notices; options.php's generic
			// "Settings saved." is not shown here on any other save either.
			if ( ! is_array( $error ) || empty( $error['message'] ) || ! isset( $error['setting'] ) || 'mbrpe_options' !== $error['setting'] ) {
				continue;
			}
			add_settings_error(
				'mbrpe_options',
				isset( $error['code'] ) ? (string) $error['code'] : 'mbrpe_carried',
				(string) $error['message'],
				isset( $error['type'] ) ? (string) $error['type'] : 'info'
			);
		}
	}

	// =====================================================================
	// Flushing
	// =====================================================================

	/**
	 * Flush the object cache, if ours is serving this request.
	 *
	 * @return true|WP_Error
	 */
	public static function flush() {
		$rt = self::runtime();
		if ( ! $rt ) {
			return new WP_Error( 'mbrpe_oc_inactive', __( 'The MBR Performance object cache is not running, so there is nothing to flush.', 'mbr-performance' ) );
		}
		$info = $rt->mbrpe_info();
		if ( empty( $info['connected'] ) ) {
			// The drop-in has already recorded that it needs flushing when the
			// server returns; there is nothing more to do from here.
			return new WP_Error( 'mbrpe_oc_unreachable', __( 'Memcached is not reachable at the moment. The cache will be flushed automatically as soon as it is.', 'mbr-performance' ) );
		}
		if ( ! wp_cache_flush() ) {
			return new WP_Error( 'mbrpe_oc_flush', __( 'Memcached did not accept the flush. Try again in a moment.', 'mbr-performance' ) );
		}

		/**
		 * Fires after the MBR Performance object cache is flushed.
		 *
		 * @since 2.2.0
		 */
		do_action( 'mbrpe_object_cache_flushed' );

		return true;
	}

	/**
	 * URL that flushes the object cache, for the toolbar.
	 *
	 * @return string
	 */
	public static function flush_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=mbrpe_object_cache_flush' ), 'mbrpe_object_cache_flush' );
	}

	/**
	 * Toolbar flush, via admin-post.
	 *
	 * @return void
	 */
	public function handle_flush_request() {
		if ( ! self::current_user_can_manage() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mbr-performance' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'mbrpe_object_cache_flush' );

		$result = self::flush();

		$back = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'admin.php?page=mbr-performance&tab=cache' );
		}
		wp_safe_redirect( add_query_arg( 'mbrpe_object_cache', is_wp_error( $result ) ? 'failed' : 'flushed', $back ) );
		exit;
	}

	/**
	 * Confirm a toolbar flush after the redirect.
	 *
	 * @return void
	 */
	public function maybe_show_flush_notice() {
		if ( ! isset( $_GET['mbrpe_object_cache'] ) || ! self::current_user_can_manage() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$result = sanitize_key( wp_unslash( $_GET['mbrpe_object_cache'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'flushed' === $result ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Object cache flushed.', 'mbr-performance' ) );
		} elseif ( 'failed' === $result ) {
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html__( 'The object cache could not be flushed — Memcached may be unreachable. See the Cache tab for details.', 'mbr-performance' ) );
		}
	}

	/**
	 * "Flush Object Cache" in the MBR Performance toolbar menu, shown only
	 * while our drop-in is connected.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar
	 * @return void
	 */
	public function admin_bar_menu( $wp_admin_bar ) {
		if ( ! self::current_user_can_manage() || ! self::is_connected() ) {
			return;
		}
		$wp_admin_bar->add_node(
			array(
				'parent' => 'mbr-performance',
				'id'     => 'mbr-performance-object-cache-flush',
				'title'  => __( 'Flush Object Cache', 'mbr-performance' ),
				'href'   => self::flush_url(),
			)
		);
	}

	// =====================================================================
	// AJAX
	// =====================================================================

	/**
	 * Cache tab: test the host and port currently in the form.
	 *
	 * @return void
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'mbrpe_nonce', 'nonce' );
		if ( ! self::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mbr-performance' ) ) );
		}

		$host = isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( $_POST['host'] ) ) : '';
		$port = isset( $_POST['port'] ) ? sanitize_text_field( wp_unslash( $_POST['port'] ) ) : '';

		$result = self::test_connection( $host, $port );
		$data   = array(
			'message' => $result['message'],
			'summary' => $result['ok'] ? self::summarise_stats( $result['stats'] ) : array(),
		);

		if ( $result['ok'] ) {
			wp_send_json_success( $data );
		}
		wp_send_json_error( $data );
	}

	/**
	 * Cache tab: flush now.
	 *
	 * @return void
	 */
	public function ajax_flush() {
		check_ajax_referer( 'mbrpe_nonce', 'nonce' );
		if ( ! self::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mbr-performance' ) ) );
		}

		$result = self::flush();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'Object cache flushed.', 'mbr-performance' ) ) );
	}
}
