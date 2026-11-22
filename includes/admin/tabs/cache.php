<?php
/**
 * Cache tab — full page caching.
 *
 * @package MBRPE
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cache  = isset( $options['cache'] ) ? $options['cache'] : array();
$health = MBRPE_Page_Cache_Dropin::health();
$stats  = MBRPE_Page_Cache::stats();
$last   = get_option( 'mbrpe_cache_last_purge', array() );

$rules_installed = MBRPE_Page_Cache_Rules::are_installed();
$rules_possible  = MBRPE_Page_Cache_Rules::server_supports_htaccess() && '' !== MBRPE_Page_Cache_Rules::relative_cache_path();

// Surface the result of any repair action taken on the previous request.
if ( isset( $_GET['mbrpe_cache_result'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$error = get_transient( 'mbrpe_cache_action_error' );
	if ( $error ) {
		delete_transient( 'mbrpe_cache_action_error' );
		echo '<div class="notice notice-error"><p>' . wp_kses_post( $error ) . '</p></div>';
	} else {
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Done.', 'mbr-performance' ) . '</p></div>';
	}
}
?>
<div class="mbr-performance-tab-content">

	<?php if ( $health['competing'] ) : ?>
		<div class="notice notice-error inline" style="margin:0 0 1.5em;">
			<p>
				<strong><?php esc_html_e( 'Page caching is switched off.', 'mbr-performance' ); ?></strong><br>
				<?php
				printf(
					/* translators: %s: name of the competing caching plugin */
					esc_html__( '%s is active and already caches pages. Two page caches on one site produce stale and inconsistent content that neither plugin can explain, so MBR Performance will not start caching until one of them is disabled. Every other optimisation on the other tabs continues to work normally.', 'mbr-performance' ),
					'<strong>' . esc_html( $health['competing'] ) . '</strong>'
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<!-- ================= STATUS ================= -->
	<div class="mbr-performance-section">
		<h2><?php esc_html_e( 'Cache Status', 'mbr-performance' ); ?></h2>

		<table class="widefat striped" style="max-width:820px;">
			<tbody>
			<tr>
				<td style="width:230px;"><strong><?php esc_html_e( 'Serving method', 'mbr-performance' ); ?></strong></td>
				<td>
					<?php if ( $rules_installed ) : ?>
						<span style="color:#1a7f37;">&#9679;</span>
						<?php esc_html_e( 'Static — the web server answers hits from disk without starting PHP. This is as fast as it gets.', 'mbr-performance' ); ?>
					<?php elseif ( $health['dropin_active'] ) : ?>
						<span style="color:#1a7f37;">&#9679;</span>
						<?php esc_html_e( 'Drop-in — PHP starts, but WordPress does not. Typically a few milliseconds per hit.', 'mbr-performance' ); ?>
					<?php elseif ( MBRPE_Page_Cache::is_enabled() ) : ?>
						<span style="color:#bd8600;">&#9679;</span>
						<?php esc_html_e( 'In-WordPress fallback — pages are cached and served, but WordPress loads first. Still a large improvement; see the checks below to get the faster path working.', 'mbr-performance' ); ?>
					<?php else : ?>
						<span style="color:#8c8f94;">&#9679;</span>
						<?php esc_html_e( 'Not caching.', 'mbr-performance' ); ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Cached pages', 'mbr-performance' ); ?></strong></td>
				<td>
					<?php
					printf(
						/* translators: 1: page count, 2: human-readable size */
						esc_html__( '%1$s pages, %2$s on disk', 'mbr-performance' ),
						esc_html( number_format_i18n( $stats['files'] ) ),
						esc_html( size_format( $stats['bytes'], 1 ) )
					);
					?>
				</td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Last purged', 'mbr-performance' ); ?></strong></td>
				<td>
					<?php
					if ( ! empty( $last['time'] ) ) {
						printf(
							/* translators: 1: human time diff, 2: reason */
							esc_html__( '%1$s ago (%2$s)', 'mbr-performance' ),
							esc_html( human_time_diff( (int) $last['time'] ) ),
							esc_html( ! empty( $last['reason'] ) ? $last['reason'] : __( 'manual', 'mbr-performance' ) )
						);
					} else {
						esc_html_e( 'Never', 'mbr-performance' );
					}
					?>
				</td>
			</tr>
			<tr>
				<td><strong>WP_CACHE</strong></td>
				<td>
					<?php if ( $health['wp_cache_defined'] ) : ?>
						<span style="color:#1a7f37;">&#10003;</span> <?php esc_html_e( 'Defined in wp-config.php', 'mbr-performance' ); ?>
					<?php else : ?>
						<span style="color:#bd8600;">&#9888;</span>
						<?php esc_html_e( 'Not defined. Without it WordPress never loads the drop-in. Add this line just below the opening PHP tag in wp-config.php:', 'mbr-performance' ); ?>
						<br><code>define( 'WP_CACHE', true );</code>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<td><strong>advanced-cache.php</strong></td>
				<td>
					<?php
					switch ( $health['dropin_state'] ) {
						case 'ours-current':
							echo '<span style="color:#1a7f37;">&#10003;</span> ' . esc_html__( 'Installed and current', 'mbr-performance' );
							break;
						case 'ours-stale':
							echo '<span style="color:#bd8600;">&#9888;</span> ' . esc_html__( 'Installed but from an older version — it should be refreshed.', 'mbr-performance' );
							echo ' <a class="button button-small" href="' . esc_url( MBRPE_Page_Cache_Dropin::action_url( 'install_dropin' ) ) . '">' . esc_html__( 'Refresh', 'mbr-performance' ) . '</a>';
							break;
						case 'foreign':
							echo '<span style="color:#d63638;">&#10007;</span> ' . esc_html__( 'A different plugin owns this file. MBR Performance will not overwrite it without being asked.', 'mbr-performance' );
							echo ' <a class="button button-small" href="' . esc_url( MBRPE_Page_Cache_Dropin::action_url( 'replace_dropin' ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Replace the existing advanced-cache.php? Do this only if the plugin that installed it has been removed.', 'mbr-performance' ) ) . '\');">' . esc_html__( 'Replace', 'mbr-performance' ) . '</a>';
							break;
						default:
							echo '<span style="color:#8c8f94;">&#9679;</span> ' . esc_html__( 'Not installed', 'mbr-performance' );
							echo ' <a class="button button-small" href="' . esc_url( MBRPE_Page_Cache_Dropin::action_url( 'install_dropin' ) ) . '">' . esc_html__( 'Install', 'mbr-performance' ) . '</a>';
					}
					?>
				</td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Cache directory', 'mbr-performance' ); ?></strong></td>
				<td>
					<?php if ( $health['cache_writable'] ) : ?>
						<span style="color:#1a7f37;">&#10003;</span> <?php esc_html_e( 'Writable', 'mbr-performance' ); ?>
					<?php else : ?>
						<span style="color:#d63638;">&#10007;</span> <?php esc_html_e( 'Not writable — nothing can be cached until this is fixed.', 'mbr-performance' ); ?>
					<?php endif; ?>
					<br><code style="font-size:11px;"><?php echo esc_html( MBRPE_Page_Cache::cache_dir() ); ?></code>
				</td>
			</tr>
			</tbody>
		</table>

		<p style="margin-top:1em;">
			<button type="button" class="button button-secondary" id="mbrpe-purge-cache">
				<?php esc_html_e( 'Purge Entire Cache', 'mbr-performance' ); ?>
			</button>
			<span id="mbrpe-purge-cache-result" style="margin-left:10px;"></span>
		</p>
	</div>

	<!-- ================= CORE SETTINGS ================= -->
	<div class="mbr-performance-section">
		<h2><?php esc_html_e( 'Page Caching', 'mbr-performance' ); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="cache_enabled">
						<?php esc_html_e( 'Enable Page Caching', 'mbr-performance' ); ?>
						<span class="mbr-tooltip" data-tip="<?php esc_attr_e( 'Saves the finished HTML of each page and serves that file to subsequent visitors instead of rebuilding the page.', 'mbr-performance' ); ?>">?</span>
					</label>
				</th>
				<td>
					<input type="checkbox" name="mbrpe_options[cache][enabled]" id="cache_enabled" value="1" <?php checked( ! empty( $cache['enabled'] ) ); ?> <?php disabled( (bool) $health['competing'] ); ?>>
					<p class="description">
						<?php esc_html_e( 'Logged-in visitors are never served from cache, and nor is any page that sets a cookie. Saving this tab installs the drop-in and clears anything already stored.', 'mbr-performance' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="cache_ttl"><?php esc_html_e( 'Cache Lifetime', 'mbr-performance' ); ?></label>
				</th>
				<td>
					<select name="mbrpe_options[cache][ttl]" id="cache_ttl">
						<?php
						$ttl_choices = array(
							0      => __( 'Until purged (recommended)', 'mbr-performance' ),
							3600   => __( '1 hour', 'mbr-performance' ),
							21600  => __( '6 hours', 'mbr-performance' ),
							43200  => __( '12 hours', 'mbr-performance' ),
							86400  => __( '24 hours', 'mbr-performance' ),
							604800 => __( '7 days', 'mbr-performance' ),
						);
						$current_ttl = isset( $cache['ttl'] ) ? (int) $cache['ttl'] : 0;
						foreach ( $ttl_choices as $seconds => $label ) {
							printf(
								'<option value="%d" %s>%s</option>',
								(int) $seconds,
								selected( $current_ttl, $seconds, false ),
								esc_html( $label )
							);
						}
						?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Publishing, editing, commenting and dozens of other events already purge the pages they affect, so an expiry time is usually unnecessary. Set one only if something on your site changes without WordPress knowing about it — a feed widget, an external stock count.', 'mbr-performance' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="cache_gzip"><?php esc_html_e( 'Pre-compress Pages', 'mbr-performance' ); ?></label>
				</th>
				<td>
					<input type="checkbox" name="mbrpe_options[cache][gzip]" id="cache_gzip" value="1" <?php checked( ! isset( $cache['gzip'] ) || ! empty( $cache['gzip'] ) ); ?>>
					<p class="description"><?php esc_html_e( 'Writes a gzipped copy alongside each page, so the server never compresses the same bytes twice. Costs a little disk, saves CPU on every single hit.', 'mbr-performance' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="cache_vary_mobile"><?php esc_html_e( 'Separate Mobile Cache', 'mbr-performance' ); ?></label>
				</th>
				<td>
					<input type="checkbox" name="mbrpe_options[cache][vary_mobile]" id="cache_vary_mobile" value="1" <?php checked( ! empty( $cache['vary_mobile'] ) ); ?>>
					<p class="description"><?php esc_html_e( 'Only needed if your theme serves genuinely different HTML to phones. A responsive theme does not — leave this off and halve the number of entries.', 'mbr-performance' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="cache_404"><?php esc_html_e( 'Cache 404 Pages', 'mbr-performance' ); ?></label>
				</th>
				<td>
					<input type="checkbox" name="mbrpe_options[cache][cache_404]" id="cache_404" value="1" <?php checked( ! empty( $cache['cache_404'] ) ); ?>>
					<p class="description"><?php esc_html_e( 'Useful when bots hammer dead URLs. Leave off while you are still fixing broken links, or you will cache the broken state.', 'mbr-performance' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="cache_feeds"><?php esc_html_e( 'Cache Feeds', 'mbr-performance' ); ?></label>
				</th>
				<td>
					<input type="checkbox" name="mbrpe_options[cache][cache_feeds]" id="cache_feeds" value="1" <?php checked( ! empty( $cache['cache_feeds'] ) ); ?>>
					<p class="description"><?php esc_html_e( 'Caches RSS and Atom output as well as HTML.', 'mbr-performance' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="cache_purge_all_on_edit"><?php esc_html_e( 'Purge Everything on Edit', 'mbr-performance' ); ?></label>
				</th>
				<td>
					<input type="checkbox" name="mbrpe_options[cache][purge_all_on_edit]" id="cache_purge_all_on_edit" value="1" <?php checked( ! empty( $cache['purge_all_on_edit'] ) ); ?>>
					<p class="description"><?php esc_html_e( 'By default, editing a post clears that post plus the archives, terms and author pages it appears on. Switch this on if your theme cross-links so heavily that targeted purging misses things — at the cost of rebuilding the whole cache after every save.', 'mbr-performance' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="cache_debug_header"><?php esc_html_e( 'Send Debug Headers', 'mbr-performance' ); ?></label>
				</th>
				<td>
					<input type="checkbox" name="mbrpe_options[cache][debug_header]" id="cache_debug_header" value="1" <?php checked( ! isset( $cache['debug_header'] ) || ! empty( $cache['debug_header'] ) ); ?>>
					<p class="description">
						<?php esc_html_e( 'Adds X-MBR-Cache to every response: HIT, MISS or BYPASS, with a reason. The single most useful thing to have on while you are working out why a page is or is not being cached.', 'mbr-performance' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="cache_signature"><?php esc_html_e( 'Add HTML Signature', 'mbr-performance' ); ?></label>
				</th>
				<td>
					<input type="checkbox" name="mbrpe_options[cache][signature]" id="cache_signature" value="1" <?php checked( ! empty( $cache['signature'] ) ); ?>>
					<p class="description"><?php esc_html_e( 'Appends an HTML comment recording when the page was cached. Visible in view-source only.', 'mbr-performance' ); ?></p>
				</td>
			</tr>
		</table>
	</div>

	<!-- ================= STATIC SERVING ================= -->
	<div class="mbr-performance-section">
		<h2><?php esc_html_e( 'Static Serving', 'mbr-performance' ); ?></h2>

		<?php if ( $rules_possible ) : ?>
			<p class="description" style="max-width:820px;">
				<?php esc_html_e( 'Adds rewrite rules to .htaccess so Apache or LiteSpeed serves cached pages straight from disk, without starting PHP at all. Requests that do not obviously qualify — anything with a query string, a login cookie, or a non-GET method — fall through to PHP as usual, so the behaviour is identical, just faster.', 'mbr-performance' ); ?>
			</p>
			<p>
				<?php if ( $rules_installed ) : ?>
					<span style="color:#1a7f37;">&#10003;</span> <?php esc_html_e( 'Rules are installed.', 'mbr-performance' ); ?>
					<a class="button button-secondary" href="<?php echo esc_url( MBRPE_Page_Cache_Dropin::action_url( 'install_rules' ) ); ?>"><?php esc_html_e( 'Rewrite Rules', 'mbr-performance' ); ?></a>
					<a class="button button-secondary" href="<?php echo esc_url( MBRPE_Page_Cache_Dropin::action_url( 'remove_rules' ) ); ?>"><?php esc_html_e( 'Remove Rules', 'mbr-performance' ); ?></a>
				<?php else : ?>
					<a class="button button-primary" href="<?php echo esc_url( MBRPE_Page_Cache_Dropin::action_url( 'install_rules' ) ); ?>"><?php esc_html_e( 'Install .htaccess Rules', 'mbr-performance' ); ?></a>
				<?php endif; ?>
			</p>
			<p class="description">
				<?php esc_html_e( 'Your existing .htaccess is backed up to .htaccess.mbrpe-backup the first time these rules are written. Removing the rules leaves the rest of the file untouched.', 'mbr-performance' ); ?>
			</p>
		<?php elseif ( 'nginx' === $health['server'] ) : ?>
			<p class="description"><?php esc_html_e( 'This server runs Nginx, which does not read .htaccess. The drop-in already handles caching well; if you have access to the server block, the snippet below removes PHP from the hot path entirely.', 'mbr-performance' ); ?></p>
			<textarea readonly rows="22" style="width:100%;font-family:monospace;font-size:12px;"><?php echo esc_textarea( MBRPE_Page_Cache_Rules::nginx_snippet() ); ?></textarea>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Static serving is not available on this server or layout. The drop-in handles caching instead, which is still a substantial improvement.', 'mbr-performance' ); ?></p>
		<?php endif; ?>
	</div>

	<!-- ================= EXCLUSIONS ================= -->
	<div class="mbr-performance-section">
		<h2><?php esc_html_e( 'Never Cache', 'mbr-performance' ); ?></h2>
		<p class="description" style="max-width:820px;">
			<?php esc_html_e( 'Sensible defaults are already applied: wp-admin, the login and REST endpoints, and the WooCommerce cart, checkout and account pages, plus every request carrying a login, comment, password or cart cookie. Use these boxes only for things specific to your site. One entry per line.', 'mbr-performance' ); ?>
		</p>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="cache_exclude_uris"><?php esc_html_e( 'URLs', 'mbr-performance' ); ?></label></th>
				<td>
					<textarea name="mbrpe_options[cache][exclude_uris]" id="cache_exclude_uris" rows="5" class="large-text code" placeholder="/members(*)&#10;/live-scores/"><?php echo esc_textarea( isset( $cache['exclude_uris'] ) ? $cache['exclude_uris'] : '' ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Paths, not full URLs. End with (*) to match everything beneath a path.', 'mbr-performance' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cache_exclude_cookies"><?php esc_html_e( 'Cookies', 'mbr-performance' ); ?></label></th>
				<td>
					<textarea name="mbrpe_options[cache][exclude_cookies]" id="cache_exclude_cookies" rows="4" class="large-text code"><?php echo esc_textarea( isset( $cache['exclude_cookies'] ) ? $cache['exclude_cookies'] : '' ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Cookie names, matched as a prefix. Any request carrying one of these is served live.', 'mbr-performance' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cache_exclude_agents"><?php esc_html_e( 'User Agents', 'mbr-performance' ); ?></label></th>
				<td>
					<textarea name="mbrpe_options[cache][exclude_agents]" id="cache_exclude_agents" rows="3" class="large-text code"><?php echo esc_textarea( isset( $cache['exclude_agents'] ) ? $cache['exclude_agents'] : '' ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Substrings matched against the User-Agent header. Note that excluding search engine crawlers is almost always counterproductive — they are the visitors a cache serves most cheaply, and Google wants to see the same page a human gets.', 'mbr-performance' ); ?></p>
				</td>
			</tr>
		</table>
	</div>

	<!-- ================= QUERY STRINGS ================= -->
	<div class="mbr-performance-section">
		<h2><?php esc_html_e( 'Query Strings', 'mbr-performance' ); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="cache_ignored_qs"><?php esc_html_e( 'Ignored Parameters', 'mbr-performance' ); ?></label></th>
				<td>
					<textarea name="mbrpe_options[cache][ignored_qs]" id="cache_ignored_qs" rows="4" class="large-text code"><?php echo esc_textarea( isset( $cache['ignored_qs'] ) ? $cache['ignored_qs'] : '' ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Parameters stripped before looking up the cache, so a link shared with ?utm_source=newsletter serves the same entry as the clean URL. The usual analytics and ad-click parameters are already handled — utm_*, fbclid, gclid, mc_cid and around twenty others. Add your own here, one per line, trailing * allowed.', 'mbr-performance' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cache_query_strings"><?php esc_html_e( 'Cache Other Query Strings', 'mbr-performance' ); ?></label></th>
				<td>
					<input type="checkbox" name="mbrpe_options[cache][cache_query_strings]" id="cache_query_strings" value="1" <?php checked( ! empty( $cache['cache_query_strings'] ) ); ?>>
					<p class="description"><?php esc_html_e( 'Off by default: a URL with an unrecognised parameter is served live. Switch on only if you have filtered archives or similar, and list the safe parameters below.', 'mbr-performance' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cache_allowed_qs"><?php esc_html_e( 'Cacheable Parameters', 'mbr-performance' ); ?></label></th>
				<td>
					<textarea name="mbrpe_options[cache][allowed_qs]" id="cache_allowed_qs" rows="3" class="large-text code" placeholder="orderby&#10;filter_colour"><?php echo esc_textarea( isset( $cache['allowed_qs'] ) ? $cache['allowed_qs'] : '' ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Each distinct combination gets its own cache entry. Leave empty to allow any parameter through when the option above is on — which can generate a great many entries, so list them explicitly where you can.', 'mbr-performance' ); ?></p>
				</td>
			</tr>
		</table>
	</div>

	<?php
	// ================= OBJECT CACHE (MEMCACHED) — v2.2.0 =================
	$oc        = MBRPE_Object_Cache::health();
	$oc_set    = $oc['settings'];
	$oc_info   = $oc['info'];
	$oc_stats  = MBRPE_Object_Cache::summarise_stats( $oc['stats'] );
	$oc_label  = MBRPE_Object_Cache::is_socket( $oc_set['host'] ) ? $oc_set['host'] : $oc_set['host'] . ':' . $oc_set['port'];
	$oc_ours   = in_array( $oc['dropin_state'], array( 'ours-current', 'ours-stale' ), true );
	?>
	<div class="mbr-performance-section" id="mbrpe-object-cache">
		<h2><?php esc_html_e( 'Object Cache (Memcached)', 'mbr-performance' ); ?></h2>
		<p class="description" style="max-width:820px;">
			<?php esc_html_e( 'Page caching saves finished pages. An object cache saves the database results WordPress builds pages from, in Memcached\'s memory, so they are not fetched again on every request. It speeds up everything a page cache cannot serve: logged-in visitors, wp-admin, WooCommerce carts and checkout, search, AJAX and REST requests.', 'mbr-performance' ); ?>
		</p>

		<?php if ( ! $oc['extension'] ) : ?>
			<div class="notice notice-warning inline" style="margin:1em 0;">
				<p><?php esc_html_e( 'The PHP Memcached extension is not installed on this server, so the object cache cannot be switched on. Many hosts let you enable it from the control panel (on SiteGround: Speed → Caching → Memcached); otherwise ask your host for the "memcached" PHP extension — not the older "memcache".', 'mbr-performance' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( 'foreign' === $oc['dropin_state'] ) : ?>
			<div class="notice notice-warning inline" style="margin:1em 0;">
				<p>
					<?php
					if ( '' !== $oc['foreign_name'] ) {
						printf(
							/* translators: %s: plugin that owns object-cache.php */
							esc_html__( 'Another object cache is already installed: %s. MBR Performance will not replace it. If you would rather use this one, switch the other off first.', 'mbr-performance' ),
							'<strong>' . esc_html( $oc['foreign_name'] ) . '</strong>'
						);
					} else {
						esc_html_e( 'Another object cache is already installed in wp-content/object-cache.php, from another plugin or your host. MBR Performance will not replace it.', 'mbr-performance' );
					}
					?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( $oc_set['enabled'] && $oc['loaded'] && empty( $oc_info['connected'] ) ) : ?>
			<div class="notice notice-error inline" style="margin:1em 0;">
				<p>
					<strong><?php esc_html_e( 'Memcached is not answering.', 'mbr-performance' ); ?></strong>
					<?php
					if ( isset( $oc_info['status'] ) && 'no-extension' === $oc_info['status'] ) {
						esc_html_e( 'The PHP Memcached extension has gone missing, so the object cache is running from memory only — the same as having none. Nothing is broken, but nothing is being cached between requests either.', 'mbr-performance' );
					} elseif ( isset( $oc_info['status'] ) && 'disabled' === $oc_info['status'] ) {
						esc_html_e( 'MBRPE_OBJECT_CACHE_DISABLED is set in wp-config.php, so the object cache is running from memory only.', 'mbr-performance' );
					} else {
						printf(
							/* translators: %s: host:port */
							esc_html__( 'Nothing answered at %s, so the object cache is running from memory only — the same as having none. Nothing is broken, but nothing is being cached between requests either. Check Memcached is still switched on in your hosting control panel, or switch the object cache off below. When the server comes back the cache is flushed once automatically, so nothing out of date is served.', 'mbr-performance' ),
							'<code>' . esc_html( $oc_label ) . '</code>'
						);
					}
					?>
				</p>
			</div>
		<?php endif; ?>

		<table class="widefat striped" style="max-width:820px;">
			<tbody>
			<tr>
				<td style="width:230px;"><strong><?php esc_html_e( 'PHP extension', 'mbr-performance' ); ?></strong></td>
				<td>
					<?php if ( $oc['extension'] ) : ?>
						<span style="color:#1a7f37;">&#10003;</span>
						<?php
						printf(
							/* translators: %s: extension version */
							esc_html__( 'Memcached %s loaded', 'mbr-performance' ),
							esc_html( $oc['ext_version'] )
						);
						?>
					<?php else : ?>
						<span style="color:#d63638;">&#10007;</span> <?php esc_html_e( 'Not installed', 'mbr-performance' ); ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<td><strong>object-cache.php</strong></td>
				<td>
					<?php
					switch ( $oc['dropin_state'] ) {
						case 'ours-current':
							echo '<span style="color:#1a7f37;">&#10003;</span> ' . esc_html__( 'Installed and current', 'mbr-performance' );
							break;
						case 'ours-stale':
							echo '<span style="color:#bd8600;">&#9888;</span> ' . esc_html__( 'Installed but from an older version — it should be refreshed.', 'mbr-performance' );
							if ( $oc['can_manage'] ) {
								echo ' <a class="button button-small" href="' . esc_url( MBRPE_Page_Cache_Dropin::action_url( 'install_object_cache' ) ) . '">' . esc_html__( 'Refresh', 'mbr-performance' ) . '</a>';
							}
							break;
						case 'foreign':
							echo '<span style="color:#d63638;">&#10007;</span> ' . esc_html__( 'Owned by another plugin or your host.', 'mbr-performance' );
							if ( $oc['can_manage'] && $oc['extension'] ) {
								echo ' <a class="button button-small" href="' . esc_url( MBRPE_Page_Cache_Dropin::action_url( 'replace_object_cache' ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Replace the existing object-cache.php with the MBR Performance one? Do this only if the plugin that installed it has been removed — if it is still active, it may simply put its own back.', 'mbr-performance' ) ) . '\');">' . esc_html__( 'Replace', 'mbr-performance' ) . '</a>';
							}
							break;
						default:
							echo '<span style="color:#8c8f94;">&#9679;</span> ' . esc_html__( 'Not installed', 'mbr-performance' );
							if ( $oc_set['enabled'] && $oc['extension'] && $oc['can_manage'] ) {
								echo ' <a class="button button-small" href="' . esc_url( MBRPE_Page_Cache_Dropin::action_url( 'install_object_cache' ) ) . '">' . esc_html__( 'Install', 'mbr-performance' ) . '</a>';
							}
					}
					?>
				</td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'This page load', 'mbr-performance' ); ?></strong></td>
				<td>
					<?php if ( $oc['loaded'] && ! empty( $oc_info['connected'] ) ) : ?>
						<span style="color:#1a7f37;">&#9679;</span>
						<?php
						printf(
							/* translators: 1: host:port, 2: cache hits, 3: cache misses */
							esc_html__( 'Connected to %1$s — %2$s hits and %3$s misses building this screen.', 'mbr-performance' ),
							'<code>' . esc_html( MBRPE_Object_Cache::is_socket( $oc_info['host'] ) ? $oc_info['host'] : $oc_info['host'] . ':' . $oc_info['port'] ) . '</code>',
							esc_html( number_format_i18n( (int) $oc_info['hits'] ) ),
							esc_html( number_format_i18n( (int) $oc_info['misses'] ) )
						);
						?>
					<?php elseif ( $oc['loaded'] ) : ?>
						<span style="color:#d63638;">&#9679;</span> <?php esc_html_e( 'Not connected — running from memory only.', 'mbr-performance' ); ?>
					<?php elseif ( $oc['ext_cache_in_use'] ) : ?>
						<span style="color:#8c8f94;">&#9679;</span> <?php esc_html_e( 'Another persistent object cache is serving this site.', 'mbr-performance' ); ?>
					<?php else : ?>
						<span style="color:#8c8f94;">&#9679;</span> <?php esc_html_e( 'No persistent object cache — WordPress is using its built-in, per-request cache.', 'mbr-performance' ); ?>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( '' !== $oc_stats['version'] ) : ?>
			<tr>
				<td><strong><?php esc_html_e( 'Memcached server', 'mbr-performance' ); ?></strong></td>
				<td>
					<?php
					$oc_parts   = array();
					$oc_parts[] = sprintf(
						/* translators: %s: Memcached version */
						__( 'version %s', 'mbr-performance' ),
						$oc_stats['version']
					);
					if ( $oc_stats['uptime'] > 0 ) {
						$oc_parts[] = sprintf(
							/* translators: %s: human time, e.g. "3 days" */
							__( 'up %s', 'mbr-performance' ),
							human_time_diff( time() - $oc_stats['uptime'] )
						);
					}
					if ( $oc_stats['limit'] > 0 ) {
						$oc_parts[] = sprintf(
							/* translators: 1: memory used, 2: memory limit */
							__( '%1$s of %2$s used', 'mbr-performance' ),
							size_format( $oc_stats['used'], 1 ),
							size_format( $oc_stats['limit'], 0 )
						);
					}
					$oc_parts[] = sprintf(
						/* translators: %s: number of items */
						__( '%s items', 'mbr-performance' ),
						number_format_i18n( $oc_stats['items'] )
					);
					if ( null !== $oc_stats['hit_rate'] ) {
						$oc_parts[] = sprintf(
							/* translators: %s: percentage */
							__( '%s%% hit rate since it started', 'mbr-performance' ),
							number_format_i18n( $oc_stats['hit_rate'], 1 )
						);
					}
					if ( $oc_stats['evictions'] > 0 ) {
						$oc_parts[] = sprintf(
							/* translators: %s: number of evictions */
							__( '%s evictions — the server is short of memory', 'mbr-performance' ),
							number_format_i18n( $oc_stats['evictions'] )
						);
					}
					echo esc_html( implode( ' · ', $oc_parts ) );
					?>
					<br><span class="description"><?php esc_html_e( 'Figures cover the whole Memcached server, which may be shared with other sites.', 'mbr-performance' ); ?></span>
				</td>
			</tr>
			<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $oc['can_manage'] ) : ?>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="cache_memcached_enabled">
						<?php esc_html_e( 'Enable Object Cache', 'mbr-performance' ); ?>
						<span class="mbr-tooltip" data-tip="<?php esc_attr_e( 'Installs wp-content/object-cache.php, which keeps WordPress\'s internal cache in Memcached between requests instead of rebuilding it every time.', 'mbr-performance' ); ?>">?</span>
					</label>
				</th>
				<td>
					<input type="checkbox" name="mbrpe_options[cache][memcached_enabled]" id="cache_memcached_enabled" value="1" <?php checked( $oc_set['enabled'] ); ?> <?php disabled( ! $oc['extension'] || 'foreign' === $oc['dropin_state'] ); ?>>
					<p class="description">
						<?php esc_html_e( 'The server is tested before the cache is switched on, and it stays off if nothing answers. If Memcached later stops responding, the site carries on without it rather than failing.', 'mbr-performance' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="cache_memcached_host"><?php esc_html_e( 'IP Address', 'mbr-performance' ); ?></label>
				</th>
				<td>
					<input type="text" name="mbrpe_options[cache][memcached_host]" id="cache_memcached_host" class="regular-text code" value="<?php echo esc_attr( $oc_set['host'] ); ?>" placeholder="127.0.0.1" autocomplete="off" spellcheck="false" <?php echo $oc_set['host_locked'] ? 'readonly' : ''; ?>>
					<p class="description">
						<?php if ( $oc_set['host_locked'] ) : ?>
							<?php esc_html_e( 'Set by MBRPE_MEMCACHED_HOST in wp-config.php, which takes precedence over this field.', 'mbr-performance' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Usually 127.0.0.1, shown in your hosting control panel alongside the port. A hostname or the full path to a Unix socket also works.', 'mbr-performance' ); ?>
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="cache_memcached_port"><?php esc_html_e( 'Port', 'mbr-performance' ); ?></label>
				</th>
				<td>
					<input type="number" name="mbrpe_options[cache][memcached_port]" id="cache_memcached_port" class="small-text" min="1" max="65535" step="1" value="<?php echo esc_attr( $oc_set['port'] > 0 ? $oc_set['port'] : MBRPE_Object_Cache::DEFAULT_PORT ); ?>" <?php echo $oc_set['port_locked'] ? 'readonly' : ''; ?>>
					<p class="description">
						<?php if ( $oc_set['port_locked'] ) : ?>
							<?php esc_html_e( 'Set by MBRPE_MEMCACHED_PORT in wp-config.php.', 'mbr-performance' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Memcached\'s standard port is 11211. Some hosts give each site its own port instead — copy whatever the control panel shows. Ignored for a Unix socket.', 'mbr-performance' ); ?>
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Tools', 'mbr-performance' ); ?></th>
				<td>
					<button type="button" class="button button-secondary" id="mbrpe-memcached-test" <?php disabled( ! $oc['extension'] ); ?>><?php esc_html_e( 'Test Connection', 'mbr-performance' ); ?></button>
					<button type="button" class="button button-secondary" id="mbrpe-object-cache-flush" <?php disabled( ! ( $oc['loaded'] && ! empty( $oc_info['connected'] ) ) ); ?>><?php esc_html_e( 'Flush Object Cache', 'mbr-performance' ); ?></button>
					<p id="mbrpe-memcached-result" class="description" style="margin-top:8px;" aria-live="polite"></p>
					<p class="description">
						<?php esc_html_e( 'Test Connection checks the IP address and port typed above, without saving them. Flushing empties only this site\'s entries, so other sites sharing the same Memcached server are unaffected.', 'mbr-performance' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php else : ?>
			<p class="description" style="max-width:820px;margin-top:1em;">
				<?php esc_html_e( 'The object cache serves every site on this network, so it is set up from the main site by a network administrator.', 'mbr-performance' ); ?>
			</p>
		<?php endif; ?>
	</div>

	<script>
	( function () {
		var test   = document.getElementById( 'mbrpe-memcached-test' );
		var flush  = document.getElementById( 'mbrpe-object-cache-flush' );
		var result = document.getElementById( 'mbrpe-memcached-result' );
		var nonce  = <?php echo wp_json_encode( wp_create_nonce( 'mbrpe_nonce' ) ); ?>;
		if ( ! result ) { return; }

		function say( text, ok ) {
			result.textContent = text;
			result.style.color = ( true === ok ) ? '#1a7f37' : ( false === ok ? '#d63638' : '' );
		}

		function post( fields, button, busy ) {
			var data = new FormData();
			Object.keys( fields ).forEach( function ( k ) { data.append( k, fields[ k ] ); } );
			data.append( 'nonce', nonce );
			button.disabled = true;
			say( busy, null );
			return fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: data } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( json ) {
					button.disabled = false;
					var msg = ( json && json.data && json.data.message ) ? json.data.message : '';
					if ( json && json.success && json.data && json.data.summary && json.data.summary.limit ) {
						var s    = json.data.summary;
						var size = function ( b ) { return b >= 1048576 ? ( b / 1048576 ).toFixed( 1 ) + ' MB' : Math.max( 1, Math.round( b / 1024 ) ) + ' KB'; };
						msg += ' ' + size( s.used ) + ' of ' + size( s.limit ) + ' in use.';
					}
					say( msg, !! ( json && json.success ) );
				} )
				.catch( function () {
					button.disabled = false;
					say( <?php echo wp_json_encode( __( 'The request failed. Check you are still logged in and try again.', 'mbr-performance' ) ); ?>, false );
				} );
		}

		if ( test ) {
			test.addEventListener( 'click', function () {
				var host = document.getElementById( 'cache_memcached_host' );
				var port = document.getElementById( 'cache_memcached_port' );
				post(
					{ action: 'mbrpe_memcached_test', host: host ? host.value : '', port: port ? port.value : '' },
					test,
					<?php echo wp_json_encode( __( 'Testing…', 'mbr-performance' ) ); ?>
				);
			} );
		}
		if ( flush ) {
			flush.addEventListener( 'click', function () {
				post( { action: 'mbrpe_object_cache_flush' }, flush, <?php echo wp_json_encode( __( 'Flushing…', 'mbr-performance' ) ); ?> );
			} );
		}
	} )();
	</script>

	<script>
	( function () {
		var button = document.getElementById( 'mbrpe-purge-cache' );
		if ( ! button ) { return; }
		button.addEventListener( 'click', function () {
			var out = document.getElementById( 'mbrpe-purge-cache-result' );
			button.disabled = true;
			out.textContent = <?php echo wp_json_encode( __( 'Purging…', 'mbr-performance' ) ); ?>;

			var data = new FormData();
			data.append( 'action', 'mbrpe_purge_cache' );
			data.append( 'nonce', <?php echo wp_json_encode( wp_create_nonce( 'mbrpe_nonce' ) ); ?> );

			fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: data } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( json ) {
					out.textContent = ( json && json.data && json.data.message ) ? json.data.message : '';
					button.disabled = false;
				} )
				.catch( function () {
					out.textContent = <?php echo wp_json_encode( __( 'Purge failed.', 'mbr-performance' ) ); ?>;
					button.disabled = false;
				} );
		} );
	} )();
	</script>

</div>
