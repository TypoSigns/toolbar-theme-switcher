<?php

/**
 * Main plugin class.
 */
class Toolbar_Theme_Switcher {

	/** @var WP_Theme $theme */
	public static $theme = false;

	/**
	 * Hooks that need to be set up early.
	 */
	public static function on_load() {

		add_action( 'setup_theme', array( __CLASS__, 'setup_theme' ) );
		add_action( 'init', array( __CLASS__, 'init' ) );
	}

	/**
	 * Loads cookie and sets up theme filters.
	 */
	public static function setup_theme() {

		global $pagenow;

		if ( ( is_admin() && 'themes.php' == $pagenow ) || ! self::can_switch_themes() ) {
			return;
		}

		if ( isset( $_GET['tts_reset'] ) ) {
			setcookie( self::get_cookie_name(), '', 1 );
			nocache_headers();
			wp_safe_redirect( home_url() );
			die;
		}

		self::load_cookie();

		if ( empty( self::$theme ) ) {
			return;
		}

		add_filter( 'pre_option_template', array( self::$theme, 'get_template' ) );
		add_filter( 'pre_option_stylesheet', array( self::$theme, 'get_stylesheet' ) );
		add_filter( 'pre_option_stylesheet_root', array( self::$theme, 'get_theme_root' ) );
		$parent = self::$theme->parent();
		add_filter( 'pre_option_template_root', array( empty( $parent ) ? self::$theme : $parent, 'get_theme_root' ) );
		add_filter( 'pre_option_current_theme', '__return_false' );
	}

	/**
	 * If allowed to switch theme.
	 *
	 * @return boolean
	 */
	public static function can_switch_themes() {

		$capability = apply_filters( 'tts_capability', 'switch_themes' );

		return apply_filters( 'tts_can_switch_themes', current_user_can( $capability ) );
	}

	/**
	 * Sets if cookie is defined to non-default theme.
	 */
	public static function load_cookie() {

		$cookie_name = self::get_cookie_name();

		if ( empty( $_COOKIE[ $cookie_name ] ) ) {
			return;
		}

		$theme = wp_get_theme( $_COOKIE[ $cookie_name ] );

		if (
			$theme->exists()
			&& $theme->get( 'Name' ) != get_option( 'current_theme' )
			&& $theme->is_allowed()
		) {
			self::$theme = $theme;
		}
	}

	/**
	 * Returns cookie name, based on home URL so it differs for sites in multisite.
	 *
	 * @return string
	 */
	public static function get_cookie_name() {

		static $hash;

		if ( empty( $hash ) ) {
			$hash = 'wordpress_tts_theme_' . md5( home_url( '', 'http' ) );
		}

		return $hash;
	}

	/**
	 * If theme is in list of allowed to be switched to.
	 *
	 * @deprecated
	 *
	 * @param WP_Theme $theme
	 *
	 * @return bool
	 */
	public static function is_allowed( $theme ) {

		return array_key_exists( $theme->get( 'Name' ), self::get_allowed_themes() );
	}

	/**
	 * Template slug filter.
	 *
	 * @param string $template
	 *
	 * @deprecated
	 *
	 * @return string
	 */
	public static function template( $template ) {

		return self::get_theme_field( 'Template', $template );
	}

	/**
	 * Stylesheet slug filter.
	 *
	 * @param string $stylesheet
	 *
	 * @deprecated
	 *
	 * @return string
	 */
	public static function stylesheet( $stylesheet ) {

		return self::get_theme_field( 'Stylesheet', $stylesheet );
	}

	/**
	 * Returns field from theme data if cookie is set to valid theme.
	 *
	 * @param string $field_name
	 * @param mixed  $default
	 *
	 * @deprecated
	 *
	 * @return mixed
	 */
	public static function get_theme_field( $field_name, $default = false ) {

		if ( ! empty( self::$theme ) ) {
			return self::$theme->get( $field_name );
		}

		return $default;
	}

	/**
	 * Retrieves allowed themes.
	 *
	 * @return array
	 */
	public static function get_allowed_themes() {

		static $themes;

		if ( isset( $themes  ) ) {
			return $themes;
		}

		$wp_themes = wp_get_themes( array( 'allowed' => true ) );

		/** @var WP_Theme $theme */
		foreach ( $wp_themes as $theme ) {

			// make keys names (rather than slugs) for backwards compat
			$themes[ $theme->get( 'Name' ) ] = $theme;
		}

		$themes = apply_filters( 'tts_allowed_themes', $themes );

		return $themes;
	}

	/**
	 * Sets up hooks that doesn't need to happen early.
	 */
	public static function init() {

		if ( self::can_switch_themes() ) {
			add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_menu' ), 90 );
			add_action( 'wp_ajax_tts_set_theme', array( __CLASS__, 'set_theme' ) );
		}

		load_plugin_textdomain( 'toolbar-theme-switcher', false, dirname( plugin_basename( __FILE__ ) ) . '/lang' );
	}

	/**
	 * Creates menu in toolbar.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar
	 */
	public static function admin_bar_menu( $wp_admin_bar ) {
		$themes  = self::get_allowed_themes();
		$current = empty( self::$theme ) ? wp_get_theme() : self::$theme;
		$title   = apply_filters( 'tts_root_title', sprintf( __( 'Theme: %s', 'toolbar-theme-switcher' ), $current->display( 'Name' ) ) );

		$wp_admin_bar->add_menu( array(
			'id'    => 'toolbar_theme_switcher',
			'title' => $title,
			'href'  => admin_url( 'themes.php' ),
		) );

		/** @var WP_Theme $theme */
		foreach ( $themes as $theme ) {
			$wp_admin_bar->add_menu( array(
				'id'     => $theme['Stylesheet'],
				'title'  => $theme->display( 'Name' ),
				'href'   => $current == $theme ? null : add_query_arg( array( 'action' => 'tts_set_theme', 'theme' => urlencode( $theme->get_stylesheet() ) ), admin_url( 'admin-ajax.php' ) ),
				'parent' => 'toolbar_theme_switcher',
			) );
		}
	}

	/**
	 * Saves selected theme in cookie if valid.
	 */
	public static function set_theme() {

		$stylesheet = $_REQUEST['theme'];
		$theme      = wp_get_theme( $stylesheet );

		if ( $theme->exists() && $theme->is_allowed() ) {
			setcookie( self::get_cookie_name(), $theme->get_stylesheet(), strtotime( '+1 year' ), COOKIEPATH );
		}

		wp_safe_redirect( wp_get_referer() );
		die;
	}
}