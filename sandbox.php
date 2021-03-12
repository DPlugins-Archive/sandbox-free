<?php
/**
 * Sandbox
 *
 * @wordpress-plugin
 * Plugin Name:         Sandbox
 * Description:         Sandbox for Oxygen Builder.
 * Version:             0.0.1
 * Author:              oxyrealm
 * Author URI:          https://oxyrealm.com
 * Requires at least:   5.6
 * Tested up to:        5.7
 * Requires PHP:        7.4
 * Text Domain:         sandbox
 * Domain Path:         /languages
 *
 * @package             Sandbox
 * @author              oxyrealm <hello@oxyrealm.com>
 * @link                https://oxyrealm.com
 * @since               0.0.1
 * @copyright           2021 oxyrealm
 * @version             0.0.1
 */

namespace Oxyrealm\Modules\Sandbox;

defined( 'ABSPATH' ) || exit;

define( 'OXYREALM_SANDBOX_VERSION', '0.0.1' );
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

    public function __construct() {
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

        $this->set_secret();
    }

    public function plugin_deactivate(): void {
        $this->unset_secret();
    }

    private function is_active(): bool {
        if ( current_user_can( 'manage_options' ) || $this->validate_cookie() ) {
            return true;
        }

        $secret = $_GET[ $this->module_id ] ?? false;
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
        $cookie = $_COOKIE[ $this->module_id ] ?? false;

        return $cookie && $cookie === $this->secret;
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

    public function set_secret(): void {
        update_option( "{$this->module_id}_secret", wp_generate_uuid4() );
    }

    public function unset_secret(): void {
        delete_option( "{$this->module_id}_secret" );
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

        $this->secret = get_option( "{$this->module_id}_secret" );
        $this->active = $this->is_active();

        add_action( 'init', [ $this, 'boot' ] );
    }

    public function boot() {
        Assets::do_register();

        if ( ! $this->active ) {
            return;
        }

        wp_enqueue_style( "{$this->module_id}-admin" );

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
        add_action( "admin_post_{$this->module_id}_publish", [ $this, 'publish_changes' ] );
        add_action( "admin_post_{$this->module_id}_delete", [ $this, 'delete_changes' ] );
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
            'id'    => 'sandbox',
            'title' => 'Sandbox <span class="text-green-400">●</span>',
            'meta'  => [
                'title' => 'Sandbox Mode - Aether'
            ]
        ] );
    }

    private function ltrim( string $string, string $prefix ): string {
        return strpos( $string, $prefix ) === 0
            ? substr( $string, strlen( $prefix ) )
            : $string;
    }

    public function publish_changes(): void {
        wp_verify_nonce( $this->module_id );

        $this->publish_sandbox_options();
        $this->publish_sandbox_postmeta();
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
                $option  = (object) $option;
                $_option_name  = $this->ltrim( $option->option_name, "{$this->module_id}_" );

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

    }

}

if ( class_exists( '\Aether' ) ) {
    Sandbox::run();
} else {
    add_action( 'admin_notices', function () {
        echo sprintf(
            '<div class="notice notice-%s is-dismissible"><p><b>Sandbox</b>: %s</p></div>',
            'error',
            '<a href="https://aether.oxyrealm.com/downloads/aether" target="_blank">Aether plugin</a> is required to run Sandbox (by Oxyrealm), but it could not be installed automatically. Please install and activate the Aether plugin first.'
        );
    } );
}