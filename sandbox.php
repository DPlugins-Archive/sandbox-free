<?php

/**
 * dPlugins Sandbox - Isolated Environment for WordPress Visual Builder
 *
 * @wordpress-plugin
 * Plugin Name:         dPlugins Sandbox
 * Description:         Isolated Environment for WordPress Visual Builder.
 * Version:             2.1.1
 * Author:              dPlugins
 * Author URI:          https://dplugins.com
 * Requires at least:   5.6
 * Tested up to:        5.8
 * Requires PHP:        7.4
 * Text Domain:         oxyrealm-sandbox
 * Domain Path:         /languages
 *
 * @package             Sandbox
 * @author              oxyrealm <hello@oxyrealm.com>
 * @link                https://dplugins.com
 * @since               1.0.0
 * @copyright           2021 oxyrealm.com
 * @version             2.1.1
 */

namespace Oxyrealm\Modules\Sandbox;

defined( 'ABSPATH' ) || exit;

define( 'OXYREALM_SANDBOX_VERSION', '2.1.1' );
define( 'OXYREALM_SANDBOX_DB_VERSION', '001' );
define( 'OXYREALM_SANDBOX_AETHER_MINIMUM_VERSION', '1.1.14' );

define( 'OXYREALM_SANDBOX_FILE', __FILE__ );
define( 'OXYREALM_SANDBOX_PATH', dirname( OXYREALM_SANDBOX_FILE ) );
define( 'OXYREALM_SANDBOX_MIGRATION_PATH', OXYREALM_SANDBOX_PATH . '/database/migrations/' );
define( 'OXYREALM_SANDBOX_URL', plugins_url( '', OXYREALM_SANDBOX_FILE ) );
define( 'OXYREALM_SANDBOX_ASSETS', OXYREALM_SANDBOX_URL . '/public' );

require_once __DIR__ . '/vendor/autoload.php';

use Composer\Semver\Comparator;
use Oxyrealm\Aether\Assets;
use Oxyrealm\Aether\Utils;
use Oxyrealm\Aether\Utils\DB;
use Oxyrealm\Aether\Utils\Migration;
use Oxyrealm\Aether\Utils\Notice;
use Oxyrealm\Aether\Utils\Oxygen;
use Oxyrealm\Loader\Aether;
use Oxyrealm\Loader\Update;
use WP_Admin_Bar;
use WP_Error;
use function unlink;

class Sandbox extends Aether {

	/** @var Update */
	public $skynet;

	protected bool $active = false;

	protected $selected_session;

	protected $pattern = null;

	public function __construct( $module_id ) {
		parent::__construct( $module_id );

		if ( ! $this->are_requirements_met( OXYREALM_SANDBOX_FILE, OXYREALM_SANDBOX_AETHER_MINIMUM_VERSION ) ) {
			return;
		}

		add_filter( 'plugin_action_links_' . plugin_basename( OXYREALM_SANDBOX_FILE ), function ( $links ) {
			return Utils::plugin_action_links( $links, $this->module_id );
		} );

		register_activation_hook( OXYREALM_SANDBOX_FILE, [ $this, 'plugin_activate' ] );
		register_deactivation_hook( OXYREALM_SANDBOX_FILE, [ $this, 'plugin_deactivate' ] );

		add_action( 'plugins_loaded', [ $this, 'init_plugin' ] );
	}

	public static function run( $module_id ) {
		static $instance = false;

		if ( ! $instance ) {
			$instance = new Sandbox( $module_id );
		}

		return $instance;
	}

	public function init_plugin() {
		Assets::register_style( "{$this->module_id}-admin", OXYREALM_SANDBOX_URL . '/assets/css/admin.css' );
		Assets::register_script( "{$this->module_id}-admin", OXYREALM_SANDBOX_URL . '/assets/js/admin.js' );
		Assets::register_style( "{$this->module_id}-oygen-editor", OXYREALM_SANDBOX_URL . '/assets/css/oxygen-editor.css' );
		Assets::register_script( "{$this->module_id}-oygen-editor", OXYREALM_SANDBOX_URL . '/assets/js/oxygen-editor.js' );

		$this->pattern = $this->get_pattern();

		$this->selected_session = get_option( 'oxyrealm_sandbox_selected_session' );

		if ( ! $this->selected_session && ! $this->get_sandbox_sessions() ) {
			$this->selected_session = self::init_sessions();
		}

		$this->active = $this->is_active();

		add_action( 'init', [ $this, 'boot' ] );
	}

	public function get_sandbox_sessions() {
		return get_option( 'oxyrealm_sandbox_sessions' );
	}

	public function boot() {
		Assets::do_register();

		if ( Oxygen::can( true ) ) {

			if ( Utils::is_request( 'ajax' ) ) {
				add_action( "wp_ajax_{$this->module_id}_update_session", [ $this, 'ajax_update_session' ] );
				add_action( "wp_ajax_{$this->module_id}_rename_session", [ $this, 'ajax_rename_session' ] );
			}

			if ( Utils::is_request( 'admin' ) ) {
				new Admin( $this->module_id );
			}
		}

		$this->plugin_update();

		$this->actions();

		if ( ! $this->active ) {
			return;
		}

		if ( Utils::is_request( 'frontend' ) && Utils::is_oxygen_editor() ) {
			$available_sessions = $this->get_sandbox_sessions();

			add_action( 'wp_enqueue_scripts', function () use ( $available_sessions ) {
				wp_enqueue_style( "{$this->module_id}-oygen-editor" );
				wp_enqueue_script( "{$this->module_id}-oygen-editor" );
				wp_localize_script( "{$this->module_id}-oygen-editor", 'sandbox', [
					'session' => $available_sessions['sessions'][ $this->selected_session ],
				] );
			} );
		}

		foreach ( array_keys( wp_load_alloptions() ) as $option ) {
			if ( $this->match_pattern('options', $option) ) {
				add_filter( "pre_option_{$option}", [ $this, 'pre_get_option' ], 0, 3 );
				add_filter( "pre_update_option_{$option}", [ $this, 'pre_update_option' ], 0, 3 );
			}
		}

		add_filter( 'get_post_metadata', [ $this, 'get_post_metadata' ], 0, 4 );
		add_filter( 'update_post_metadata', [ $this, 'update_post_metadata' ], 0, 5 );
		add_filter( 'delete_post_metadata', [ $this, 'delete_post_metadata' ], 0, 5 );

		add_action( 'admin_bar_menu', [ $this, 'admin_bar_node' ], 100 );
		add_filter( 'body_class', function( $classes ) {
			return array_merge( $classes, [ "{$this->module_id}-{$this->selected_session}" ] );
		});
		add_filter( 'admin_body_class', function( $classes ) {
			return "{$classes} {$this->module_id}-{$this->selected_session}";
		});
	}

	private function plugin_update(): void {
		$payload = [
			'version'            => OXYREALM_SANDBOX_VERSION,
			'license'            => get_option( "{$this->module_id}_license_key" ),
			'beta'               => get_option( "{$this->module_id}_beta" ),
			'plugin_file'        => OXYREALM_SANDBOX_FILE,
			'item_id'            => 8654,
			'store_url'          => 'https://dplugins.com',
			'author'             => 'dPlugins',
			'is_require_license' => false,
		];

		$this->skynet = new Update( $this->module_id, $payload );

		if ( $this->skynet->isActivated() ) {
			$doing_cron = defined( 'DOING_CRON' ) && DOING_CRON;
			if ( ! ( current_user_can( 'manage_options' ) && $doing_cron ) ) {
				$this->skynet->ignite();
			}
		}
	}

	private function actions(): void {
		if ( ! Oxygen::can( true ) ) {
			return;
		}

		if (
			isset( $_REQUEST["{$this->module_id}_add_session"] )
			&& wp_verify_nonce( $_REQUEST["{$this->module_id}_add_session"], $this->module_id )
		) {
			$available_sessions = $this->get_sandbox_sessions();
			$random_number      = random_int( 10000, 99999 );

			$available_sessions['sessions'][ $random_number ] = [
				'id'     => $random_number,
				'name'   => "Sandbox #{$random_number}",
				'secret' => wp_generate_uuid4(),
			];

			update_option( 'oxyrealm_sandbox_sessions', $available_sessions );

			Notice::success( "New sandbox session created with id: #{$random_number}", 'Sandbox' );

			wp_redirect( add_query_arg( [ 'page' => $this->module_id, ], admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_REQUEST['session'] ) ) {
			$session            = sanitize_text_field( $_REQUEST['session'] );
			$available_sessions = $this->get_sandbox_sessions();

			if ( array_key_exists( $session, $available_sessions['sessions'] ) ) {
				$session_name = $available_sessions['sessions'][ $session ]['name'];
				if (
					isset( $_REQUEST["{$this->module_id}_publish"] )
					&& wp_verify_nonce( $_REQUEST["{$this->module_id}_publish"], $this->module_id )
				) {
					$this->publish_changes( $session );
					$this->delete_changes( $session );
					Notice::success( "Sandbox session (name: {$session_name}) published succesfuly.", 'Sandbox' );

					if ( wp_redirect( admin_url( 'admin.php?page=oxygen_vsb_settings&tab=cache&start_cache_generation=true' ) ) ) {
						exit;
					}
				} elseif (
					isset( $_REQUEST["{$this->module_id}_delete"] )
					&& wp_verify_nonce( $_REQUEST["{$this->module_id}_delete"], $this->module_id )
				) {
					$this->delete_changes( $session );
					Notice::success( "Sandbox session (name: {$session_name}) deleted succesfuly.", 'Sandbox' );
					wp_redirect( add_query_arg( [ 'page' => $this->module_id, ], admin_url( 'admin.php' ) ) );
					exit;
				}
			}
		}
	}

	private function is_active(): bool {
		if ( $this->selected_session && current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( $this->validate_cookie() ) {
			return true;
		}

		$session = isset( $_GET['session'] ) ? sanitize_text_field( $_GET['session'] ) : false;
		$secret  = isset( $_GET[ $this->module_id ] ) ? sanitize_text_field( $_GET[ $this->module_id ] ) : false;

		$available_sessions = $this->get_sandbox_sessions();

		if ( $session && $secret ) {
			if (
				array_key_exists( $session, $available_sessions['sessions'] )
				&& $secret === $available_sessions['sessions'][ $session ]['secret']
			) {
				$this->set_cookie( [ 'session' => $session, 'secret' => $secret ] );
			} else {
				$this->unset_cookie();
			}
		}

		return false;
	}

	public function ajax_update_session() {
		wp_verify_nonce( $_REQUEST['_wpnonce'], $this->module_id );

		$session            = sanitize_text_field( $_REQUEST['session'] );
		$available_sessions = $this->get_sandbox_sessions();

		if ( 'false' === $session ) {
			self::unset_session();
			wp_send_json_success( 'Sandbox disabled' );
		} elseif ( array_key_exists( $session, $available_sessions['sessions'] ) ) {
			self::set_session( $session );
			wp_send_json_success( 'Sandbox session changed to ' . $available_sessions['sessions'][ $session ]['name'] );
		} else {
			wp_send_json_error( 'Session not available', 422 );
		}
		exit;
	}

	public function ajax_rename_session() {
		wp_verify_nonce( $_REQUEST['_wpnonce'], $this->module_id );

		$session            = sanitize_text_field( $_REQUEST['session'] );
		$new_name           = sanitize_text_field( $_REQUEST['new_name'] );
		$available_sessions = $this->get_sandbox_sessions();

		if ( array_key_exists( $session, $available_sessions['sessions'] ) ) {
			$old_name = $available_sessions['sessions'][ $session ]['name'];

			$available_sessions['sessions'][ $session ]['name'] = $new_name;

			update_option( 'oxyrealm_sandbox_sessions', $available_sessions );

			wp_send_json_success( 'Sandbox session renamed from ' . $old_name . ' to ' . $new_name );
		} else {
			wp_send_json_error( 'Session not available, could not rename', 422 );
		}
		exit;
	}

	private function validate_cookie(): bool {
		if ( isset( $_GET[ $this->module_id ] ) && isset( $_GET['session'] ) ) {
			return false;
		}

		$cookie = isset( $_COOKIE[ $this->module_id ] ) ? json_decode( $_COOKIE[ $this->module_id ] ) : false;

		if ( $cookie ) {
			$available_sessions = $this->get_sandbox_sessions();

			if (
				array_key_exists( $cookie->session, $available_sessions['sessions'] )
				&& $cookie->secret === $available_sessions['sessions'][ $cookie->session ]['secret']
			) {
				$this->selected_session = $available_sessions['sessions'][ $cookie->session ]['id'];

				return true;
			}

			$this->unset_cookie();
		}

		return false;
	}

	private function set_cookie( $data ): void {
		setcookie( $this->module_id, json_encode( $data ), time() + DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );

		if ( wp_redirect( get_home_url() ) ) {
			exit;
		}
	}

	private function unset_cookie(): void {
		setcookie( $this->module_id, null, - 1, COOKIEPATH, COOKIE_DOMAIN );

		if ( wp_redirect( get_home_url() ) ) {
			exit;
		}
	}

	public static function init_sessions() {
		$random_number = random_int( 10000, 99999 );
		update_option( 'oxyrealm_sandbox_sessions', [
			'sessions' => [
				$random_number => [
					'id'     => $random_number,
					'secret' => wp_generate_uuid4(),
					'name'   => "Sandbox #{$random_number}"
				]
			]
		] );

		self::set_session( $random_number );

		return get_option( 'oxyrealm_sandbox_selected_session' );
	}

	public static function set_session( $session ): void {
		update_option( 'oxyrealm_sandbox_selected_session', $session );
	}

	public static function unset_session(): void {
		update_option( 'oxyrealm_sandbox_selected_session', false );
	}

	public function pre_get_option( $pre_option, string $option, $default ) {
		if ( $option === 'oxygen_vsb_universal_css_cache' ) {
			return 'false';
		}

		if ( DB::has( 'options', [ 'option_name' => "{$this->module_id}_{$this->selected_session}_{$option}", ] ) ) {
			$pre_option = get_option( "{$this->module_id}_{$this->selected_session}_{$option}", $default );
		}

		return $pre_option;
	}

	public function pre_update_option( $value, $old_value, string $option ) {
		if ( $option === 'oxygen_vsb_universal_css_cache' ) {
			return $old_value;
		}

		update_option( "{$this->module_id}_{$this->selected_session}_{$option}", $value );

		return $old_value;
	}

	public function update_post_metadata( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
		return $this->match_pattern('post_metadata', $meta_key)
			? update_metadata( 'post', $object_id, "{$this->module_id}_{$this->selected_session}_{$meta_key}", $meta_value, $prev_value )
			: $check;
	}

	public function delete_post_metadata( $delete, $object_id, $meta_key, $meta_value, $delete_all ) {
		return $this->match_pattern('post_metadata', $meta_key)
			? delete_metadata( 'post', $object_id, "{$this->module_id}_{$this->selected_session}_{$meta_key}", $meta_value, $delete_all )
			: $delete;
	}

	public function get_post_metadata( $value, $object_id, $meta_key, $single ) {
		if ( $this->match_pattern('post_metadata', $meta_key) && metadata_exists( 'post', $object_id, "{$this->module_id}_{$this->selected_session}_{$meta_key}" ) ) {
			$value = get_metadata( 'post', $object_id, "{$this->module_id}_{$this->selected_session}_{$meta_key}", $single );
			if ( $single && is_array( $value ) ) {
				$value = [ $value ];
			}
		}

		return $value;
	}

	public function admin_bar_node( WP_Admin_Bar $wp_admin_bar ) {
		$available_sessions = $this->get_sandbox_sessions();
		$session_name       = $available_sessions['sessions'][ $this->selected_session ]['name'];

		$wp_admin_bar->add_node( [
			'parent' => 'top-secondary',
			'id'    => 'oxyrealm-sandbox',
			'title' => "<span style=\"font-weight:700;\">Sandbox:</span> {$session_name} <span style=\"color:limegreen;\">●</span>",
			'meta'  => [
				'title' => 'Sandbox Mode - Aether'
			],
			'href'  => add_query_arg( [ 'page' => $this->module_id, ], admin_url( 'admin.php' ) )
		] );
	}

	public function publish_changes( $session ): void {
		$this->publish_sandbox_options( $session );
		$this->publish_sandbox_postmeta( $session );

		self::unset_session();
	}

	public function publish_sandbox_options( $session ): void {
		$options = DB::select( 'options', [
			'option_id',
			'option_name',
			'option_value',
		], [
			'option_name[~]' => "{$this->module_id}_{$session}_%"
		] );

		if ( $options ) {
			foreach ( $options as $option ) {
				$option       = (object) $option;
				$_option_name = Utils::ltrim( $option->option_name, "{$this->module_id}_{$session}_" );

				$exist_option = DB::get( 'options', 'option_id', [
					'option_name' => $_option_name
				] );

				if ( $exist_option ) {
					$backup = DB::get( 'options', 'option_id', [ 'option_name' => "aetherbackup_{$_option_name}", ] );

					if ( $backup ) {
						DB::delete( 'options', [
							'option_id' => $backup,
						] );
					}

					DB::update( 'options', [
						'option_name' => "aetherbackup_{$_option_name}",
					], [
						'option_id' => $exist_option,
					] );
				}

				DB::update( 'options', [
					'option_name' => "{$_option_name}",
				], [
					'option_id' => $option->option_id,
				] );
			}
		}
	}

	public function publish_sandbox_postmeta( $session ): void {
		$postmetas = DB::select( 'postmeta', [
			'meta_id',
			'post_id',
			'meta_key',
			'meta_value',
		], [
			'meta_key[~]' => "{$this->module_id}_{$session}_%"
		] );

		if ( $postmetas ) {
			foreach ( $postmetas as $postmeta ) {
				$postmeta      = (object) $postmeta;
				$_postmeta_key = Utils::ltrim( $postmeta->meta_key, "{$this->module_id}_{$session}_" );

				$exist_postmeta = DB::get( 'postmeta', 'meta_id', [
					'post_id'  => $postmeta->post_id,
					'meta_key' => $_postmeta_key,
				] );

				if ( $exist_postmeta ) {
					$backup = DB::get( 'postmeta', 'meta_id', [ 'meta_key' => "aetherbackup_{$_postmeta_key}", ] );

					if ( $backup ) {
						DB::delete( 'postmeta', [
							'meta_id' => $backup,
						] );
					}

					DB::update( 'postmeta', [
						'meta_key' => "aetherbackup_{$_postmeta_key}",
					], [
						'meta_id' => $exist_postmeta,
					] );
				}

				DB::update( 'postmeta', [
					'meta_key' => "{$_postmeta_key}",
				], [
					'meta_id' => $postmeta->meta_id,
				] );
			}
		}
	}

	public function delete_changes( $session ): void {
		$this->delete_sandbox_options( $session );
		$this->delete_sandbox_postmeta( $session );

		if ( $this->selected_session === $session ) {
			self::unset_session();
		}

		$available_sessions = $this->get_sandbox_sessions();

		$available_sessions['sessions'] = array_filter( $available_sessions['sessions'], function ( $v, $k ) use ( $session ) {
			return $k !== (int) $session;
		}, ARRAY_FILTER_USE_BOTH );

		update_option( 'oxyrealm_sandbox_sessions', $available_sessions );
	}

	public function delete_sandbox_options( $session ): void {
		$options = DB::delete( 'options', [
			'option_name[~]' => "{$this->module_id}_{$session}_%"
		] );
	}

	public function delete_sandbox_postmeta( $session ): void {
		$postmetas = DB::delete( 'postmeta', [
			'meta_key[~]' => "{$this->module_id}_{$session}_%"
		] );
	}

	public function plugin_activate(): void {
		if ( ! get_option( 'oxyrealm_sandbox_installed' ) ) {
			update_option( 'oxyrealm_sandbox_installed', time() );
		}

		$installed_db_version = get_option( 'oxyrealm_sandbox_db_version' );

		if ( ! $installed_db_version || intval( $installed_db_version ) !== intval( OXYREALM_SANDBOX_DB_VERSION ) ) {
			Migration::migrate( OXYREALM_SANDBOX_MIGRATION_PATH, "\\Oxyrealm\\Modules\\Sandbox\\Database\\Migrations\\", $installed_db_version ?: 0, OXYREALM_SANDBOX_DB_VERSION );
			update_option( 'oxyrealm_sandbox_db_version', OXYREALM_SANDBOX_DB_VERSION );
		}

		update_option( 'oxyrealm_sandbox_version', OXYREALM_SANDBOX_VERSION );

		if ( ! get_option( 'oxyrealm_sandbox_selected_session' ) && ! $this->get_sandbox_sessions() ) {
			self::init_sessions();
		}
	}

	public function plugin_deactivate(): void {
	}

	public static function get_filesystem() {
		global $wp_filesystem;

		if ( ! defined( 'FS_METHOD' ) ) {
			define( 'FS_METHOD', 'direct' );
		}

		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		return $wp_filesystem;
	}


	public function match_pattern($type, $str) {
		foreach ($this->pattern[$type] as $pattern) {
			if (strpos( $str, $pattern ) === 0) {
				return true;
			}
		}
		return false;
	}

	public function get_pattern(){
		$file = plugin_dir_path( OXYREALM_SANDBOX_FILE ) . 'pattern.json';

		$wp_filesystem = $this->get_filesystem();
		$data       = $wp_filesystem->get_contents( $file );
		$pattern_definition = json_decode($data, true);

		$pattern = [
			'options' => [],
			'post_metadata' => [],
		];

		foreach ($pattern_definition['pattern'] as $vendor) {
			$pattern['options'] = array_merge($pattern['options'], $vendor['options']);
			$pattern['post_metadata'] = array_merge($pattern['post_metadata'], $vendor['post_metadata']);
		}

		return $pattern;
	}
}

$aether_m_sandbox = Sandbox::run( 'aether_m_sandbox' );
