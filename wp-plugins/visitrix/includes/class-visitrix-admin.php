<?php
/**
 * Admin screens: dashboard, settings, dashboard widget.
 *
 * @package Visitrix
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI.
 */
class Visitrix_Admin {

	/**
	 * Dashboard page hook suffix.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_content' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_visitrix_clear', array( __CLASS__, 'clear_data' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( VISITRIX_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Menu entries.
	 */
	public static function menu() {
		$cap        = Visitrix_Settings::view_capability();
		self::$hook = add_menu_page(
			__( 'Visitrix Statistics', 'visitrix' ),
			__( 'Statistics', 'visitrix' ),
			$cap,
			'visitrix',
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-chart-area',
			3
		);
		add_submenu_page( 'visitrix', __( 'Visitrix Statistics', 'visitrix' ), __( 'Summary', 'visitrix' ), $cap, 'visitrix', array( __CLASS__, 'render_dashboard' ) );
		add_submenu_page( 'visitrix', __( 'Visitrix Settings', 'visitrix' ), __( 'Settings', 'visitrix' ), 'manage_options', 'visitrix-settings', array( __CLASS__, 'render_settings' ) );
	}

	/**
	 * Plugins list links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=visitrix' ) ) . '">' . esc_html__( 'Statistics', 'visitrix' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=visitrix-settings' ) ) . '">' . esc_html__( 'Settings', 'visitrix' ) . '</a>'
		);
		return $links;
	}

	/**
	 * Enqueues dashboard assets only on the plugin page.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function assets( $hook ) {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'visitrix-admin', VISITRIX_URL . 'assets/css/admin.css', array(), VISITRIX_VERSION );
		wp_enqueue_script( 'visitrix-admin', VISITRIX_URL . 'assets/js/admin.js', array(), VISITRIX_VERSION, true );
		wp_localize_script(
			'visitrix-admin',
			'visitrixAdmin',
			array(
				'rest'   => esc_url_raw( rest_url( 'visitrix/v1/stats' ) ),
				'nonce'  => wp_create_nonce( 'wp_rest' ),
				'today'  => current_time( 'Y-m-d' ),
				'locale' => str_replace( '_', '-', get_user_locale() ),
				'home'   => esc_url_raw( home_url() ),
				'i18n'   => self::js_strings(),
			)
		);
	}

	/**
	 * Translatable strings used by admin.js.
	 *
	 * @return array
	 */
	private static function js_strings() {
		return array(
			'title'          => __( 'Summary', 'visitrix' ),
			'today'          => __( 'Today', 'visitrix' ),
			'yesterday'      => __( 'Yesterday', 'visitrix' ),
			'days3'          => __( '3 days', 'visitrix' ),
			'week'           => __( 'Week', 'visitrix' ),
			'month'          => __( 'Month', 'visitrix' ),
			'custom'         => __( 'Custom range', 'visitrix' ),
			'apply'          => __( 'Apply', 'visitrix' ),
			'refresh'        => __( 'Refresh', 'visitrix' ),
			'visits'         => __( 'Visits', 'visitrix' ),
			'visitors'       => __( 'Visitors', 'visitrix' ),
			'pageviews'      => __( 'Pageviews', 'visitrix' ),
			'bounce'         => __( 'Bounce rate', 'visitrix' ),
			'depth'          => __( 'Page depth', 'visitrix' ),
			'duration'       => __( 'Time on site', 'visitrix' ),
			'newVisitors'    => __( 'New visitors', 'visitrix' ),
			'views'          => __( 'Views', 'visitrix' ),
			'newReturning'   => __( 'New and returning visitors', 'visitrix' ),
			'visitorType'    => __( 'Visitor type', 'visitrix' ),
			'returning'      => __( 'Returning visitors', 'visitrix' ),
			'share'          => __( 'Share', 'visitrix' ),
			'vsPrev'         => __( 'compared to the previous period', 'visitrix' ),
			'bounceHint'     => __( 'Share of visits with one pageview lasting less than 15 seconds', 'visitrix' ),
			'depthHint'      => __( 'Average number of pages viewed per visit', 'visitrix' ),
			'durationHint'   => __( 'Average visit duration', 'visitrix' ),
			'newHint'        => __( 'Share of visitors who came for the first time', 'visitrix' ),
			'traffic'        => __( 'Traffic', 'visitrix' ),
			'byHour'         => __( 'by hour', 'visitrix' ),
			'byDay'          => __( 'by day', 'visitrix' ),
			'sources'        => __( 'Traffic sources', 'visitrix' ),
			'sourceDetails'  => __( 'Sites, search engines and social networks', 'visitrix' ),
			'search'         => __( 'Search engines', 'visitrix' ),
			'devices'        => __( 'Device type', 'visitrix' ),
			'browsers'       => __( 'Browsers', 'visitrix' ),
			'os'             => __( 'Operating systems', 'visitrix' ),
			'pages'          => __( 'Popular pages', 'visitrix' ),
			'landing'        => __( 'Landing pages', 'visitrix' ),
			'utm'            => __( 'UTM campaigns', 'visitrix' ),
			'source'         => __( 'Source', 'visitrix' ),
			'page'           => __( 'Page', 'visitrix' ),
			'noData'         => __( 'No data for the selected period', 'visitrix' ),
			'loading'        => __( 'Loading…', 'visitrix' ),
			'error'          => __( 'Could not load statistics.', 'visitrix' ),
			'other'          => __( 'Other', 'visitrix' ),
			'total'          => __( 'Total', 'visitrix' ),
			'src_direct'     => __( 'Direct traffic', 'visitrix' ),
			'src_search'     => __( 'Search engine traffic', 'visitrix' ),
			'src_social'     => __( 'Social network traffic', 'visitrix' ),
			'src_messenger'  => __( 'Messenger traffic', 'visitrix' ),
			'src_ai'         => __( 'AI assistant traffic', 'visitrix' ),
			'src_referral'   => __( 'Link traffic', 'visitrix' ),
			'src_ad'         => __( 'Ad traffic', 'visitrix' ),
			'src_internal'   => __( 'Internal traffic', 'visitrix' ),
			'dev_desktop'    => __( 'PC', 'visitrix' ),
			'dev_mobile'     => __( 'Smartphones', 'visitrix' ),
			'dev_tablet'     => __( 'Tablets', 'visitrix' ),
			'updated'        => __( 'Updated', 'visitrix' ),
		);
	}

	/**
	 * Dashboard page (rendered by admin.js).
	 */
	public static function render_dashboard() {
		echo '<div class="wrap visitrix-wrap"><h1 class="screen-reader-text">' . esc_html__( 'Visitrix Statistics', 'visitrix' ) . '</h1><div id="visitrix-app"></div>';
		echo '<noscript><p>' . esc_html__( 'Please enable JavaScript to view statistics.', 'visitrix' ) . '</p></noscript></div>';
	}

	/**
	 * Settings registration.
	 */
	public static function register_settings() {
		register_setting(
			'visitrix_settings_group',
			Visitrix_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Visitrix_Settings', 'sanitize' ),
				'default'           => Visitrix_Settings::defaults(),
			)
		);
	}

	/**
	 * Settings page.
	 */
	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = Visitrix_Settings::get();
		$name  = Visitrix_Settings::OPTION;
		$roles = wp_roles()->get_names();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag after redirect.
		$cleared = isset( $_GET['visitrix_cleared'] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Visitrix Settings', 'visitrix' ); ?></h1>
			<?php if ( $cleared ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'All statistics have been deleted.', 'visitrix' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'visitrix_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Tracking', 'visitrix' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( $s['enabled'] ); ?>> <?php esc_html_e( 'Collect visit statistics', 'visitrix' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Do not track these roles', 'visitrix' ); ?></th>
						<td>
							<fieldset>
							<?php foreach ( $roles as $role => $label ) : ?>
								<label style="display:inline-block;min-width:180px;margin:0 12px 6px 0">
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[exclude_roles][]" value="<?php echo esc_attr( $role ); ?>" <?php checked( in_array( $role, (array) $s['exclude_roles'], true ) ); ?>>
									<?php echo esc_html( translate_user_role( $label ) ); ?>
								</label>
							<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Do Not Track', 'visitrix' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[respect_dnt]" value="1" <?php checked( $s['respect_dnt'] ); ?>> <?php esc_html_e( 'Do not count visitors whose browser sends the Do Not Track signal', 'visitrix' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="visitrix-retention"><?php esc_html_e( 'Keep data for', 'visitrix' ); ?></label></th>
						<td>
							<input id="visitrix-retention" type="number" min="30" max="3650" class="small-text" name="<?php echo esc_attr( $name ); ?>[retention_days]" value="<?php echo esc_attr( $s['retention_days'] ); ?>">
							<?php esc_html_e( 'days', 'visitrix' ); ?>
							<p class="description"><?php esc_html_e( 'Older records are deleted automatically once a day.', 'visitrix' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall', 'visitrix' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete_on_uninstall]" value="1" <?php checked( $s['delete_on_uninstall'] ); ?>> <?php esc_html_e( 'Delete all statistics and settings when the plugin is deleted', 'visitrix' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Reset statistics', 'visitrix' ); ?></h2>
			<p><?php esc_html_e( 'Permanently deletes all collected visits and pageviews. Settings are kept.', 'visitrix' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete all statistics? This cannot be undone.', 'visitrix' ) ); ?>');">
				<input type="hidden" name="action" value="visitrix_clear">
				<?php wp_nonce_field( 'visitrix_clear' ); ?>
				<?php submit_button( __( 'Delete all statistics', 'visitrix' ), 'delete', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handles the "Delete all statistics" form.
	 */
	public static function clear_data() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'visitrix' ), 403 );
		}
		check_admin_referer( 'visitrix_clear' );
		Visitrix_DB::truncate();
		wp_safe_redirect( admin_url( 'admin.php?page=visitrix-settings&visitrix_cleared=1' ) );
		exit;
	}

	/**
	 * Small widget on the WordPress dashboard.
	 */
	public static function dashboard_widget() {
		if ( ! current_user_can( Visitrix_Settings::view_capability() ) ) {
			return;
		}
		wp_add_dashboard_widget( 'visitrix_widget', __( 'Visitrix: today', 'visitrix' ), array( __CLASS__, 'render_widget' ) );
	}

	/**
	 * Widget body.
	 */
	public static function render_widget() {
		$today = current_time( 'Y-m-d' );
		$t     = Visitrix_Stats::totals( $today . ' 00:00:00', $today . ' 23:59:59' );
		$items = array(
			__( 'Visits', 'visitrix' )      => number_format_i18n( $t['visits'] ),
			__( 'Visitors', 'visitrix' )    => number_format_i18n( $t['visitors'] ),
			__( 'Pageviews', 'visitrix' )   => number_format_i18n( $t['pageviews'] ),
			__( 'Bounce rate', 'visitrix' ) => number_format_i18n( $t['bounce'], 1 ) . '%',
		);
		echo '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;text-align:center">';
		foreach ( $items as $label => $value ) {
			echo '<div><div style="font-size:22px;font-weight:600;line-height:1.3">' . esc_html( $value ) . '</div><div style="color:#646970">' . esc_html( $label ) . '</div></div>';
		}
		echo '</div><p style="margin:12px 0 0;text-align:right"><a href="' . esc_url( admin_url( 'admin.php?page=visitrix' ) ) . '">' . esc_html__( 'Open full report →', 'visitrix' ) . '</a></p>';
	}

	/**
	 * Suggested privacy policy text.
	 */
	public static function privacy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . esc_html__( 'This site uses a self-hosted statistics tool (Visitrix). When you visit pages, we record the page address, the referring site, UTM tags, your browser, operating system and device type, as well as the time spent on the site. A random anonymous identifier is stored in your browser’s localStorage to distinguish new and returning visitors. IP addresses are not stored. The data is kept on our server only and is not shared with third parties.', 'visitrix' ) . '</p>';
		wp_add_privacy_policy_content( 'Visitrix', wp_kses_post( $text ) );
	}
}
