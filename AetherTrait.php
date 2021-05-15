<?php

namespace Oxyrealm\Modules\Sandbox;

use Automatic_Upgrader_Skin;
use Plugin_Upgrader;

trait AetherTrait {

	/**
	 * Slug for the Aether plugin.
	 *
	 * @var string
	 */
	protected $aether_plugin_path = 'aether/aether.php';

	protected $notices = [];

	public function deactivate_module( $file ): void {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}

		deactivate_plugins( plugin_basename( $file ) );
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
		$skin        = new Automatic_Upgrader_Skin();
		$upgrader    = new Plugin_Upgrader( $skin );
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

	private function are_requirements_met( $file ) {
		if ( $this->is_aether_being_deactivated() ) {
			$this->notices[] = [
				'level'   => 'error',
				'message' => '<a href="https://wordpress.org/plugins/aether" target="_blank">Aether plugin</a> is required to run this plugins, Both plugins are now disabled.'
			];
		} elseif ( $this->is_aether_being_updated() ) {
			return false;
		} else {
			if ( ! $this->is_aether_installed() ) {
				if ( ! $this->install_aether() ) {
					$this->notices[] = [
						'level'   => 'error',
						'message' => '<a href="https://wordpress.org/plugins/aether" target="_blank">Aether plugin</a> is required to run this plugin, but it could not be installed automatically. Please install and activate the Aether plugin first.'
					];
				}
			}

			if ( ! $this->is_aether_activated() ) {
				if ( ! $this->activate_aether() ) {
					$this->notices[] = [
						'level'   => 'error',
						'message' => '<a href="https://wordpress.org/plugins/aether" target="_blank">Aether plugin</a> is required to run this plugin, but it could not be activated automatically. Please install and activate the Aether plugin first.'
					];
				}
			}
		}

		if ( empty( $this->notices ) ) {
			return true;
		}

		add_action( 'admin_notices', [ $this, 'admin_notices' ] );
		$this->deactivate_module( $file );

		return false;

	}

	public function admin_notices() {
		foreach ( $this->notices as $notice ) {
			echo sprintf(
				'<div class="notice notice-%s is-dismissible"><p><b>%s</b>: %s</p></div>',
				$notice['level'],
				str_replace( 'aether_m_', '', $this->module_id ),
				$notice['message']
			);
		}
	}

	public function is_aether_being_deactivated() {
		if ( ! is_admin() ) {
			return false;
		}

		$action = isset( $_REQUEST['action'] ) && $_REQUEST['action'] != - 1 ? $_REQUEST['action'] : '';
		if ( ! $action ) {
			$action = isset( $_REQUEST['action2'] ) && $_REQUEST['action2'] != - 1 ? $_REQUEST['action2'] : '';
		}
		$plugin  = isset( $_REQUEST['plugin'] ) ? $_REQUEST['plugin'] : '';
		$checked = isset( $_POST['checked'] ) && is_array( $_POST['checked'] ) ? $_POST['checked'] : [];

		$deactivate          = 'deactivate';
		$deactivate_selected = 'deactivate-selected';
		$actions             = [ $deactivate, $deactivate_selected ];

		if ( ! in_array( $action, $actions, true ) ) {
			return false;
		}

		if ( $action === $deactivate && $plugin !== $this->aether_plugin_path ) {
			return false;
		}

		if ( $action === $deactivate_selected && ! in_array( $this->aether_plugin_path, $checked, true ) ) {
			return false;
		}

		return true;
	}

	public function is_aether_being_updated() {
		$action  = isset( $_POST['action'] ) && $_POST['action'] != - 1 ? $_POST['action'] : '';
		$plugins = isset( $_POST['plugin'] ) ? (array) $_POST['plugin'] : [];
		if ( empty( $plugins ) ) {
			$plugins = isset( $_POST['plugins'] ) ? (array) $_POST['plugins'] : [];
		}

		$update_plugin   = 'update-plugin';
		$update_selected = 'update-selected';
		$actions         = [ $update_plugin, $update_selected ];

		if ( ! in_array( $action, $actions, true ) ) {
			return false;
		}

		return in_array( $this->aether_plugin_path, $plugins, true );
	}

	public function ltrim( string $string, string $prefix ): string {
		return strpos( $string, $prefix ) === 0
			? substr( $string, strlen( $prefix ) )
			: $string;
	}
}
