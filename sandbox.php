<?php
/**
 * Oxyrealm Sandbox
 *
 * @wordpress-plugin
 * Plugin Name:         Oxyrealm Sandbox
 * Description:         Sandbox for Oxygen Builder.
 * Version:             1.0.2
 * Author:              oxyrealm
 * Author URI:          https://oxyrealm.com
 * Requires at least:   5.6
 * Tested up to:        5.7
 * Requires PHP:        7.4
 * Text Domain:         oxyrealm-sandbox
 * Domain Path:         /languages
 *
 * @package             Sandbox
 * @author              oxyrealm <hello@oxyrealm.com>
 * @link                https://oxyrealm.com
 * @since               1.0.0
 * @copyright           2021 oxyrealm
 * @version             1.0.2
 */

namespace Oxyrealm\Modules\Sandbox;

defined( 'ABSPATH' ) || exit;

define( 'OXYREALM_SANDBOX_VERSION', '1.0.2' );
define( 'OXYREALM_SANDBOX_DB_VERSION', '001' );

define( 'OXYREALM_SANDBOX_FILE', __FILE__ );
define( 'OXYREALM_SANDBOX_PATH', dirname( OXYREALM_SANDBOX_FILE ) );
define( 'OXYREALM_SANDBOX_MIGRATION_PATH', OXYREALM_SANDBOX_PATH . '/database/migrations/' );
define( 'OXYREALM_SANDBOX_URL', plugins_url( '', OXYREALM_SANDBOX_FILE ) );
define( 'OXYREALM_SANDBOX_ASSETS', OXYREALM_SANDBOX_URL . '/public' );

use Oxyrealm\Aether\Assets;
use Oxyrealm\Aether\Utils\DB;
use Oxyrealm\Aether\Utils\Migration;
use WP_Admin_Bar;

final class Sandbox {

    public string $module_id = 'aether_m_sandbox';

    protected bool $active = false;

    protected $secret;

	/**
	 * Slug for the Aether plugin.
	 *
	 * @var string
	 */
	private $aether_plugin_path = 'aether/aether.php';

    public function __construct() {
        if ( ! $this->is_aether_installed() ) {
            if ( ! $this->install_aether() ) {
                add_action( 'admin_notices', function () {
                    echo sprintf(
                        '<div class="notice notice-%s is-dismissible"><p><b>Sandbox</b>: %s</p></div>',
                        'error',
                        '<a href="https://wordpress.org/plugins/aether" target="_blank">Aether plugin</a> is required to run Sandbox (by OxyRealm), but it could not be installed automatically. Please install and activate the Aether plugin first.'
                    );
                } );
                $this->deactivate_module();
                return;
            }

			if ( ! $this->is_aether_activated() ) {
				if ( ! $this->activate_aether() ) {
                    add_action( 'admin_notices', function () {
                        echo sprintf(
                            '<div class="notice notice-%s is-dismissible"><p><b>Sandbox</b>: %s</p></div>',
                            'error',
                            '<a href="https://wordpress.org/plugins/aether" target="_blank">Aether plugin</a> is required to run Sandbox (by OxyRealm), but it could not be activated automatically. Please install and activate the Aether plugin first.'
                        );
                    } );
                    $this->deactivate_module();
                    return;
				}
			}
        }

        register_activation_hook( OXYREALM_SANDBOX_FILE, [ $this, 'plugin_activate' ] );
        register_deactivation_hook( OXYREALM_SANDBOX_FILE, [ $this, 'plugin_deactivate' ] );

        add_action( 'plugins_loaded', [ $this, 'init_plugin' ] );
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

        self::set_secret();
    }

    public function plugin_deactivate(): void {
        self::unset_secret();
    }

    private function is_active(): bool {
        if ( current_user_can( 'manage_options' ) || $this->validate_cookie() ) {
            return true;
        }

        $secret = isset( $_GET[ $this->module_id ] ) ? sanitize_text_field( $_GET[ $this->module_id ] ) : false;
        if ( $secret ) {
            if ( $secret === $this->secret ) {
                $this->set_cookie();
            } else {
                $this->unset_cookie();
            }
        }

        return false;
    }

    private function validate_cookie(): bool {
        $cookie = isset ( $_COOKIE[ $this->module_id ] ) ? sanitize_text_field( $_COOKIE[ $this->module_id ] ) : false;

        if ( $cookie ) {
            if ( $cookie === $this->secret ) {
                return true;
            }

            $this->unset_cookie();
        }

        return false;
    }

    private function set_cookie(): void {
        setcookie( $this->module_id, $this->secret, time() + DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );

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

    public static function set_secret(): void {
        update_option( 'oxyrealm_sandbox_secret', wp_generate_uuid4() );
    }

    public static function unset_secret(): void {
        delete_option( 'oxyrealm_sandbox_secret' );
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

        $this->secret = get_option( 'oxyrealm_sandbox_secret' );
        $this->active = $this->is_active();

        add_action( 'init', [ $this, 'boot' ] );
    }

    public function boot() {
        Assets::do_register();

        if ( ! $this->active ) {
            return;
        }

        wp_enqueue_style( "{$this->module_id}-admin" );
        wp_enqueue_script( "{$this->module_id}-admin" );

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
        add_action( 'admin_notices', [ $this, 'admin_notice_module_action' ] );

        if ( isset( $_REQUEST["{$this->module_id}_publish"] ) && wp_verify_nonce( $_REQUEST["{$this->module_id}_publish"], $this->module_id ) ) {
            $this->publish_changes();
        } elseif ( isset( $_REQUEST["{$this->module_id}_delete"] ) && wp_verify_nonce( $_REQUEST["{$this->module_id}_delete"], $this->module_id ) ) {
            $this->delete_changes();
        }
    }

    public function pre_get_option( $pre_option, string $option, $default ) {
        if ( $option === 'oxygen_vsb_universal_css_cache' ) {
            return 'false';
        }

        if ( DB::has( 'options', [ 'option_name' => "{$this->module_id}_{$option}", ] ) ) {
            $pre_option = get_option( "{$this->module_id}_{$option}", $default );
        }

        return $pre_option;
    }

    public function pre_update_option( $value, $old_value, string $option ) {
        if ( $option === 'oxygen_vsb_universal_css_cache' ) {
            return $old_value;
        }

        update_option( "{$this->module_id}_{$option}", $value );

        return $old_value;
    }

    public function update_post_metadata( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
        return strpos( $meta_key, 'ct_' ) === 0
            ? update_metadata( 'post', $object_id, "{$this->module_id}_{$meta_key}", $meta_value, $prev_value )
            : $check;
    }

    public function delete_post_metadata( $delete, $object_id, $meta_key, $meta_value, $delete_all ) {
        return strpos( $meta_key, 'ct_' ) === 0
            ? delete_metadata( 'post', $object_id, "{$this->module_id}_{$meta_key}", $meta_value, $delete_all )
            : $delete;
    }

    public function get_post_metadata( $value, $object_id, $meta_key, $single ) {
        if ( strpos( $meta_key, 'ct_' ) === 0 && metadata_exists( 'post', $object_id, "{$this->module_id}_{$meta_key}" ) ) {
            $value = get_metadata( 'post', $object_id, "{$this->module_id}_{$meta_key}", $single );
            if ( $single && is_array( $value ) ) {
                $value = [ $value ];
            }
        }

        return $value;
    }

    public function admin_bar_node( WP_Admin_Bar $wp_admin_bar ) {
        $wp_admin_bar->add_node( [
            'id'    => 'oxyrealm-sandbox',
            'title' => 'Sandbox <span class="text-green-400">●</span>',
            'meta'  => [
                'title' => 'Sandbox Mode - Aether'
            ]
        ] );
    }

    public function admin_notice_module_action(): void {
        echo sprintf(
            '<div class="notice notice-info"><p><strong> Sandbox Mode is Active	</strong><br>Any change you made to Oxygen Builder\'s settings, post, page, and template will isolated until you published it.</p><p><div><strong>Preview link</strong>: <input type="text" style="width:60%%" onclick="this.select();" readonly value="%s"></div><br><div><a id="sandbox-publish-changes" href="%s" class="button button-primary"> Publish </a> <a id="sandbox-delete-changes" href="%s" class="button button-secondary"> Delete </a></div></p></div>',
            add_query_arg( $this->module_id, $this->secret, site_url() ),
            add_query_arg( [ "{$this->module_id}_publish" => wp_create_nonce( $this->module_id ), ], admin_url() ),
            add_query_arg( [ "{$this->module_id}_delete" => wp_create_nonce( $this->module_id ), ], admin_url() )
        );
    }

    private function ltrim( string $string, string $prefix ): string {
        return strpos( $string, $prefix ) === 0
            ? substr( $string, strlen( $prefix ) )
            : $string;
    }

    public function publish_changes(): void {
        $this->publish_sandbox_options();
        $this->publish_sandbox_postmeta();
        self::set_secret();
        $this->deactivate_module();

        if ( wp_redirect( admin_url( 'admin.php?page=oxygen_vsb_settings&tab=cache&start_cache_generation=true' ) ) ) {
            exit;
        }
    }

    public function publish_sandbox_options(): void {
        $options = DB::select( 'options', [
            'option_id',
            'option_name',
            'option_value',
        ], [
            'option_name[~]' => "{$this->module_id}_%"
        ] );

        if ( $options ) {
            foreach ( $options as $option ) {
                $option       = (object) $option;
                $_option_name = $this->ltrim( $option->option_name, "{$this->module_id}_" );

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
                    'option_name' => "{$this->module_id}_{$_option_name}",
                ], [
                    'option_id' => $option->option_id,
                ] );
            }
        }
    }

    public function publish_sandbox_postmeta(): void {
        $postmetas = DB::select( 'postmeta', [
            'meta_id',
            'post_id',
            'meta_key',
            'meta_value',
        ], [
            'meta_key[~]' => "{$this->module_id}_%"
        ] );

        if ( $postmetas ) {
            foreach ( $postmetas as $postmeta ) {
                $postmeta      = (object) $postmeta;
                $_postmeta_key = $this->ltrim( $postmeta->meta_key, "{$this->module_id}_" );

                $exist_postmeta = DB::get( 'postmeta', 'meta_id', [
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
                    'meta_key' => "{$this->module_id}_{$_postmeta_key}",
                ], [
                    'meta_id' => $postmeta->meta_id,
                ] );
            }
        }
    }

    public function delete_changes(): void {
        $this->delete_sandbox_options();
        $this->delete_sandbox_postmeta();
        self::set_secret();
        $this->deactivate_module();

        if ( wp_redirect( admin_url( 'admin.php?page=oxygen_vsb_settings&tab=cache&start_cache_generation=true' ) ) ) {
            exit;
        }
    }

    public function delete_sandbox_options(): void {
        $options = DB::delete( 'options', [
            'option_name[~]' => "{$this->module_id}_%"
        ] );
    }

    public function delete_sandbox_postmeta(): void {
        $postmetas = DB::delete( 'postmeta', [
            'meta_key[~]' => "{$this->module_id}_%"
        ] );
    }

    public function deactivate_module(): void {
        if ( ! function_exists( 'deactivate_plugins' ) ) {
            require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
        }

        deactivate_plugins( plugin_basename( OXYREALM_SANDBOX_FILE ) );
    }

	public function is_aether_activated() {
		$active_plugins = get_option( 'active_plugins', [] );
		return in_array( $this->aether_plugin_path, $active_plugins, true );
	}

    public function is_aether_installed() {
        if ( $this->is_aether_activated() ) {
			return true;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed_plugins = get_plugins();

		return array_key_exists( $this->aether_plugin_path, $installed_plugins );
    }

	/**
	 * Install Aether plugin from the wordpress.org repository.
	 *
	 * @return bool Whether install was successful.
	 */
	public function install_aether() {
		include_once ABSPATH . 'wp-includes/pluggable.php';
		include_once ABSPATH . 'wp-admin/includes/misc.php';
		include_once ABSPATH . 'wp-admin/includes/file.php';
		include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		$skin        = new \Automatic_Upgrader_Skin();
		$upgrader    = new \Plugin_Upgrader( $skin );
		$plugin_file = 'https://downloads.wordpress.org/plugin/aether.latest-stable.zip';
		$result      = $upgrader->install( $plugin_file );

		return $result;
	}

	/**
	 * Activate Aether plugin.
	 *
	 * @return bool Whether activation was successful or not.
	 */
	public function activate_aether() {
		return activate_plugin( $this->aether_plugin_path );
	}

}

Sandbox::run();