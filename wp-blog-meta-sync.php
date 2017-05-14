<?php
/**
 * Sync key options with blog meta
 *
 * @package   Wp_Blog_Meta_Sync
 * @author    Jonathan Harris <jon@spacedmonkey.co.uk>
 * @license   GPL-2.0+
 * @link      http://www.spacedmonkey.com/
 * @copyright 2017 Spacedmonkey
 *
 * @wordpress-plugin
 * Plugin Name:        Blog Meta option syncing
 * Plugin URI:         https://www.github.com/spacedmonkey/wp-blog-meta-sync
 * Description:        Sync key options with blog meta
 * Version:            1.0.0
 * Author:             Jonathan Harris
 * Author URI:         http://www.spacedmonkey.com/
 * License:            GPL-2.0+
 * License URI:        http://www.gnu.org/licenses/gpl-2.0.txt
 * Network:            true
 * GitHub Plugin URI:  https://www.github.com/spacedmonkey/wp-blog-meta-sync
 */
// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}


class Wp_Blog_Meta_Sync {
	/**
	 * List of filters to run this string replace on
	 *
	 * @since     1.0.0
	 *
	 * @var array
	 */
	protected $filters = array(
		'stylesheet',
		'blog_charset',
		'template',
		'WPLANG',
		'blogname',
		'siteurl',
		'post_count',
		'home',
		'allowedthemes',
		'blog_public',
		'WPLANG',
		'blogdescription',
		'db_version',
		'db_upgraded',
		'active_plugins',
		'users_can_register',
		'admin_email',
		'wp_user_roles',
	);

	/**
	 * Instance of this class.
	 *
	 * @since    1.0.0
	 *
	 * @var      object
	 */
	protected static $instance = null;

	/**
	 * Initialize the plugin by setting localization and loading public scripts
	 * and styles.
	 *
	 * @since     1.0.0
	 */
	private function __construct() {
		// If blog meta not loaded. Quit
		if( !function_exists('add_blog_meta') ){
			return;
		}
		foreach ( $this->get_filters() as $filter ) {
			add_filter( 'pre_option_' . $filter, array( $this, 'pre_get_option' ), 1, 2 );
		}
		add_action( 'updated_option', array( $this, 'updated_option' ), 10, 3 );
		add_action( 'added_option', array( $this, 'added_option' ), 10, 2 );
		add_action( 'delete_option', array( $this, 'delete_option' ), 10, 1 );
		add_action( 'wp_upgrade', array( $this, 'wp_upgrade' ), 10, 1 );
		add_action( 'pre_get_sites', array( $this, 'pre_get_sites' ), 15, 1 );

		add_action( 'wpmu_new_blog', array( $this, 'wpmu_new_blog' ), 10, 1 );
		add_action( 'switch_blog', array( $this, 'switch_blog' ), 10, 1 );

		add_filter( 'the_sites', array( $this, 'the_sites' ), 10, 1 );

		add_filter( 'sites_clauses', array( $this, 'sites_clauses' ), 10, 2 );


	}

	/**
	 * Return an instance of this class.
	 *
	 * @since     1.0.0
	 *
	 * @return    object    A single instance of this class.
	 */
	public static function get_instance() {
		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	/**
	 * @param $value
	 * @param $option
	 *
	 * @return mixed
	 */
	function pre_get_option( $value, $option ) {
		$blog_id = get_current_blog_id();
		$meta    = get_blog_meta( $blog_id, $option, true );

		if ( false !== $meta ) {
			$value = maybe_unserialize( $meta );
			if ( in_array( $option, array( 'siteurl', 'home', 'category_base', 'tag_base' ) ) ) {
				$value = untrailingslashit( $value );
			}
		} else {
			add_filter( 'option_' . $option, array( $this, 'get_option' ), 1, 2 );
		}

		return $value;
	}

	/**
	 * @param $value
	 * @param $option
	 */
	function get_option( $value, $option ) {
		if ( ! $this->check_option( $option ) || false == $value ) {
			return;
		}
		$blog_id = get_current_blog_id();
		update_blog_meta( $blog_id, $option, maybe_serialize( $value ) );
	}

	/**
	 * @param $option
	 * @param $old_value
	 * @param $value
	 */
	function updated_option( $option, $old_value, $value ) {
		if ( ! $this->check_option( $option ) ) {
			return;
		}
		$blog_id = get_current_blog_id();
		update_blog_meta( $blog_id, $option, $value, maybe_serialize( $old_value ) );
	}

	/**
	 * @param $option
	 * @param $value
	 */
	function added_option( $option, $value ) {
		if ( ! $this->check_option( $option ) ) {
			return;
		}
		$blog_id = get_current_blog_id();
		add_blog_meta( $blog_id, $option, $value, true );
	}

	/**
	 * @param $option
	 */
	function delete_option( $option ) {
		if ( ! $this->check_option( $option ) ) {
			return;
		}
		$blog_id = get_current_blog_id();
		delete_blog_meta( $blog_id, $option );
	}

	/**
	 * @param $wp_db_version
	 */
	function wp_upgrade( $wp_db_version ) {
		$blog_id = get_current_blog_id();
		update_blog_meta( $blog_id, 'wp_db_version', $wp_db_version );
	}

	/**
	 * @param $site_query
	 */
	function pre_get_sites( $site_query ) {
		if ( ! isset( $site_query->query_var_defaults['update_blog_meta_cache'] ) ) {
			$site_query->query_var_defaults['update_blog_meta_cache'] = true;
		}
	}

	/**
	 * @param $blog_id
	 */
	function wpmu_new_blog( $blog_id ) {
		switch_to_blog( $blog_id );
		$this->migrate_options();
		restore_current_blog();
	}

	/**
	 *
	 */
	function migrate_options() {
		$all_option = wp_load_alloptions();
		$blog_id    = get_current_blog_id();
		foreach ( $this->get_filters() as $filter ) {
			if ( ! empty( $all_option[ $filter ] ) ) {
				update_blog_meta( $blog_id, $filter, $all_option[ $filter ] );
			}
		}

	}

	/**
	 * @param bool $network_wide
	 */
	function activate( $network_wide = false ) {
		if ( $network_wide ) {
			return;
		}
		$site_ids = get_sites( array( 'fields' => 'ids', 'number' => false ) );

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			$this->migrate_options();
			restore_current_blog();
		}
	}

	/**
	 * @param $blog_id
	 */
	function switch_blog( $blog_id ) {
		global $wpdb;
		if ( $blog_id == 1 ) {
			return;
		}
		$filter = $wpdb->get_blog_prefix( $blog_id ) . 'user_roles';
		if ( ! in_array( $filter, $this->get_filters( ) ) ) {
			add_filter( 'pre_option_' . $filter, array( $this, 'pre_get_option' ), 1, 2 );
			$this->filters[] = $filter;
		}
	}

	/**
	 * @since 1.1.0
	 *
	 * @param $_sites
	 *
	 * @return $_sites
	 */
	public function the_sites( $_sites ) {
		$sites = wp_list_pluck( $_sites, 'id' );
		foreach ( $sites as $blog_id ) {
			$this->switch_blog( $blog_id );
		}

		return $_sites;
	}

	/**
	 * @param $clauses
	 * @param $wp_site
	 *
	 * @return $clauses array
	 */
	public function sites_clauses( $clauses, &$wp_site ) {
		global $wpdb;

		if ( strlen( $wp_site->query_vars['search'] ) ) {
			$clauses['join'] .= " LEFT JOIN {$wpdb->blogmeta} AS sq1 ON ( {$wpdb->blogs}.blog_id = sq1.blog_id AND sq1.meta_key = 'blogname' )";
			$clauses['groupby'] = "{$wpdb->blogs}.blog_id";
			$clauses['fields']  = "{$wpdb->blogs}.blog_id";
			$clauses['where']   = str_replace( "(domain LIKE", "(sq1.meta_value LIKE '%{$wp_site->query_vars['search']}%' OR domain LIKE", $clauses['where'] );
		}

		return $clauses;
	}

	protected function get_filters(){
		return apply_filters( 'Wp_Blog_Meta_Sync_filters', $this->filters );
	}

	/**
	 * @param $option
	 *
	 * @return bool
	 */
	protected function check_option( $option ) {
		return in_array( $option, $this->get_filters( ) );
	}

}

add_action( 'plugins_loaded', array( 'Wp_Blog_Meta_Sync', 'get_instance' ) );
register_activation_hook( __FILE__, function(){
	$blog_meta_sync = Wp_Blog_Meta_Sync::get_instance();
	$blog_meta_sync->activate();
} );
