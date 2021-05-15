<?php

namespace Oxyrealm\Modules\Sandbox;

trait AdminMenuTrait {
	public function admin_menu(): void {
		$capability = 'manage_options';

		if ( current_user_can( $capability ) ) {
			$hook = add_submenu_page(
				'ct_dashboard_page',
				__( 'Sandbox', 'aether' ),
				__( 'Sandbox', 'aether' ),
				$capability,
				$this->module_id,
				[
					$this,
					'plugin_page'
				]
			);

			add_action( 'load-' . $hook, [ $this, 'init_hooks' ] );
		}
	}

	public function init_hooks(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
	}

	public function enqueue_scripts(): void {
		wp_enqueue_style( "{$this->module_id}-admin" );
		wp_enqueue_script( "{$this->module_id}-admin" );
		wp_localize_script( "{$this->module_id}-admin", 'sandbox', [
			'ajax_url'  => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( $this->module_id ),
			'module_id' => $this->module_id,
		] );
	}

	public function plugin_page(): void {
		$sessions         = $this->get_sandbox_sessions();
		$selected_session = get_option( 'oxyrealm_sandbox_selected_session' );
		?>
        <!-- sandbox -->
        <div class="wrap">
            <h1 class="wp-heading-inline">Oxygen Sandbox</h1>
            by <a href="https://dplugins.com" target="_blank">dPlugins</a> & <a href="https://oxyrealm.com" target="_blank">OxyRealm</a>
            <a href="<?php echo add_query_arg( [
				'page'                           => $this->module_id,
				"{$this->module_id}_add_session" => wp_create_nonce( $this->module_id ),
			], admin_url( 'admin.php' ) ); ?>" class="page-title-action">Add New Session</a>
			<hr class="wp-header-end">

			<div class="sandbox-card" data-id="false">
				<input type="radio" id="default" name="sandbox"
					value="false" <?php echo $selected_session == false ? 'checked' : ''; ?>>
				<div class="card-content">
					<h2><label for="default">Current Website Style</label><br></h2>
					<div class="sandbox-description">No SandBox applied</div>
				</div>
			</div>

			<?php foreach ( $sessions['sessions'] as $key => $value ) : ?>
				<div class="sandbox-card" data-id="<?php echo $value['id']; ?>">
					<input type="radio" name="sandbox" id="sandbox-<?php echo $value['id']; ?>"
						value="sandbox-<?php echo $value['id']; ?>" <?php echo $selected_session == $value['id'] ? 'checked' : ''; ?>>
					<div class="card-content">
						<h2>
							<label for="sandbox-<?php echo $value['id']; ?>"><?php echo $value['name']; ?></label>
							<br>
						</h2>
						<div class="sandbox-description-wrap">
							<input type="text" class="sandbox-description"
								placeholder="Click here to change the SandBox session name">
						</div>
						
						<strong class="preview-link">Preview link:</strong>
						<div class="preview-link-form">
							<input type="text" onclick="this.select();" readonly value="<?php echo add_query_arg( [
								$this->module_id => $value['secret'],
								'session'        => $value['id'],
							], site_url() ); ?>">
						</div>
						<div class="actions">
							<a class="wp-core-ui publish-button" href="<?php echo add_query_arg( [
								'page'                       => $this->module_id,
								"{$this->module_id}_publish" => wp_create_nonce( $this->module_id ),
								"session"                    => $value['id'],
							], admin_url( 'admin.php' ) ); ?>">Publish</a>
							<a class="delete-button" href="<?php echo add_query_arg( [
								'page'                      => $this->module_id,
								"{$this->module_id}_delete" => wp_create_nonce( $this->module_id ),
								"session"                   => $value['id'],
							], admin_url( 'admin.php' ) ); ?>">Delete</a>
						</div>
					</div>
				</div>
			<?php endforeach; ?>

		</div>
        <!-- sandbox -->

		<?php
	}
}
