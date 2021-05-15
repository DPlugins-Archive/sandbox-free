<?php
/**
 * dPlugins Sandbox - Isolated Environment for Oxygen Builder
 *
 * @wordpress-plugin
 * Plugin Name:         dPlugins Sandbox
 * Description:         Isolated environment for Oxygen Builder plugin.
 * Version:             2.0.0
 * Author:              dPlugins
 * Author URI:          https://dplugins.com
 * Requires at least:   5.6
 * Tested up to:        5.7.2
 * Requires PHP:        7.4
 * Text Domain:         oxyrealm-sandbox
 * Domain Path:         /languages
 *
 * @package             Sandbox
 * @author              dPlugins <mailme@markokrstic.com>
 * @link                https://dplugins.com
 * @since               1.0.0
 * @copyright           2021 dplugins.com
 * @version             2.0.0
 */

namespace Oxyrealm\Modules\Sandbox;

defined( 'ABSPATH' ) || exit;

define( 'OXYREALM_SANDBOX_VERSION', '2.0.0' );
define( 'OXYREALM_SANDBOX_DB_VERSION', '001' );

define( 'OXYREALM_SANDBOX_FILE', __FILE__ );
define( 'OXYREALM_SANDBOX_PATH', dirname( OXYREALM_SANDBOX_FILE ) );
define( 'OXYREALM_SANDBOX_MIGRATION_PATH', OXYREALM_SANDBOX_PATH . '/database/migrations/' );
define( 'OXYREALM_SANDBOX_URL', plugins_url( '', OXYREALM_SANDBOX_FILE ) );
define( 'OXYREALM_SANDBOX_ASSETS', OXYREALM_SANDBOX_URL . '/public' );

require_once __DIR__ . '/vendor/autoload.php';

use Oxyrealm\Aether\Assets;
use Oxyrealm\Aether\Utils;
use Oxyrealm\Aether\Utils\DB;
use Oxyrealm\Aether\Utils\Migration;
use Oxyrealm\Aether\Utils\Notice;
use WP_Admin_Bar;

final class Sandbox {
	use AetherTrait, AdminMenuTrait;

	public string $module_id = 'aether_m_sandbox';

	protected bool $active = false;

	protected $selected_session;

	protected $notice_lib;

	public function __construct() {
		if ( ! $this->are_requirements_met( OXYREALM_SANDBOX_FILE ) ) {
			return;
		}

		register_activation_hook( OXYREALM_SANDBOX_FILE, [ $this, 'plugin_activate' ] );
		register_deactivation_hook( OXYREALM_SANDBOX_FILE, [ $this, 'plugin_deactivate' ] );

		add_action( 'plugins_loaded', [ $this, 'init_plugin' ] );

		$this->notice_lib = new Notice( $this->module_id, 'Sandbox' );
	}

	public static function run() {
		static $instance = false;

		if ( ! $instance ) {
			$instance = new Sandbox();
		}

		return $instance;
	}

	public function init_plugin() {
		Assets::register_style( "{$this->module_id}-admin", OXYREALM_SANDBOX_URL . '/assets/css/admin.css' );
		Assets::register_script( "{$this->module_id}-admin", OXYREALM_SANDBOX_URL . '/assets/js/admin.js' );
		Assets::register_style( "{$this->module_id}-oygen-editor", OXYREALM_SANDBOX_URL . '/assets/css/oxygen-editor.css' );
		Assets::register_script( "{$this->module_id}-oygen-editor", OXYREALM_SANDBOX_URL . '/assets/js/oxygen-editor.js' );

		$this->selected_session = get_option( 'oxyrealm_sandbox_selected_session' );

		if ( ! $this->selected_session && ! $this->get_sandbox_sessions() ) {
			$this->selected_session = self::init_sessions();
		}

		$this->active = $this->is_active();

		add_action( 'init', [ $this, 'boot' ] );
		add_action( 'admin_notices', [ $this->notice_lib, 'init' ] );
	}

	protected function get_sandbox_sessions() {
		return get_option( 'oxyrealm_sandbox_sessions' );
	}

	public function boot() {
		Assets::do_register();

		if ( Utils::is_request( 'ajax' ) && current_user_can( 'manage_options' ) ) {
			add_action( "wp_ajax_{$this->module_id}_update_session", [ $this, 'ajax_update_session' ] );
			add_action( "wp_ajax_{$this->module_id}_rename_session", [ $this, 'ajax_rename_session' ] );
		}

		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
		add_action( 'admin_notices', [ $this, 'admin_notices' ] );

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
			if ( strpos( $option, 'oxygen_vsb_' ) === 0 || strpos( $option, 'ct_' ) === 0 ) {
				add_filter( "pre_option_{$option}", [ $this, 'pre_get_option' ], 0, 3 );
				add_filter( "pre_update_option_{$option}", [ $this, 'pre_update_option' ], 0, 3 );
			}
		}

		add_filter( 'get_post_metadata', [ $this, 'get_post_metadata' ], 0, 4 );
		add_filter( 'update_post_metadata', [ $this, 'update_post_metadata' ], 0, 5 );
		add_filter( 'delete_post_metadata', [ $this, 'delete_post_metadata' ], 0, 5 );

		add_action( 'admin_bar_menu', [ $this, 'admin_bar_node' ], 100 );

	}

	private function actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
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

			$this->notice_lib->success( "New sandbox session created with id: #{$random_number}" );

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
					$this->notice_lib->success( "Sandbox session (name: {$session_name}) published succesfuly." );

					if ( wp_redirect( admin_url( 'admin.php?page=oxygen_vsb_settings&tab=cache&start_cache_generation=true' ) ) ) {
						exit;
					}
				} elseif (
					isset( $_REQUEST["{$this->module_id}_delete"] )
					&& wp_verify_nonce( $_REQUEST["{$this->module_id}_delete"], $this->module_id )
				) {
					$this->delete_changes( $session );
					$this->notice_lib->success( "Sandbox session (name: {$session_name}) deleted succesfuly." );
					wp_redirect( add_query_arg( [ 'page' => $this->module_id, ], admin_url( 'admin.php' ) ) );
					exit;
				}
			}
		}
	}

	private function is_active(): bool {
		if ( ! $this->selected_session ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) || $this->validate_cookie() ) {
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
		$cookie = isset ( $_COOKIE[ $this->module_id ] ) ? json_decode( $_COOKIE[ $this->module_id ] ) : false;

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

		if ( isset( $_SERVER['REQUEST_URI'] ) && wp_redirect( $_SERVER['REQUEST_URI'] ) ) {
			exit;
		}
	}

	private function unset_cookie(): void {
		setcookie( $this->module_id, null, - 1, COOKIEPATH, COOKIE_DOMAIN );

		if ( isset( $_SERVER['REQUEST_URI'] ) && wp_redirect( $_SERVER['REQUEST_URI'] ) ) {
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
		return strpos( $meta_key, 'ct_' ) === 0
			? update_metadata( 'post', $object_id, "{$this->module_id}_{$this->selected_session}_{$meta_key}", $meta_value, $prev_value )
			: $check;
	}

	public function delete_post_metadata( $delete, $object_id, $meta_key, $meta_value, $delete_all ) {
		return strpos( $meta_key, 'ct_' ) === 0
			? delete_metadata( 'post', $object_id, "{$this->module_id}_{$this->selected_session}_{$meta_key}", $meta_value, $delete_all )
			: $delete;
	}

	public function get_post_metadata( $value, $object_id, $meta_key, $single ) {
		if ( strpos( $meta_key, 'ct_' ) === 0 && metadata_exists( 'post', $object_id, "{$this->module_id}_{$this->selected_session}_{$meta_key}" ) ) {
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
			'id'    => 'oxyrealm-sandbox',
			'title' => "Sandbox: {$session_name} <span class=\"text-green-400\">●</span>",
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
				$_option_name = $this->ltrim( $option->option_name, "{$this->module_id}_{$session}_" );

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
				$_postmeta_key = $this->ltrim( $postmeta->meta_key, "{$this->module_id}_{$session}_" );

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

		if ( null === get_option( 'oxyrealm_sandbox_selected_session', null ) ) {
			self::init_sessions();
		}
	}

	public function plugin_deactivate(): void {
	}

}

$aether_m_sandbox = Sandbox::run();