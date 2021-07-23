<?php

namespace Oxyrealm\Modules\Sandbox;

use Oxyrealm\Aether\Admin as AetherAdmin;
use Oxyrealm\Aether\Utils\Notice;
use Oxyrealm\Loader\Update;

class Admin {
	private $module_id;

	public function __construct( $module_id ) {
		$this->module_id = $module_id;

		add_action( 'admin_menu', [ $this, 'admin_menu' ], 100 );
	}

	public function admin_menu(): void {
		$capability = 'manage_options';

		if ( current_user_can( $capability ) ) {
			$hook = add_submenu_page(
				AetherAdmin::$slug,
				__( 'Sandbox', 'oxyrealm-sandbox' ),
				__( 'Sandbox', 'oxyrealm-sandbox' ),
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
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'settings';
		?>
        <h2>dPlugins Sandbox Settings</h2>
        <hr class="wp-header-end">
        <h2 class="nav-tab-wrapper">
            <a href="<?php echo add_query_arg( [
				'page' => $this->module_id,
				'tab'  => 'settings',
			], admin_url( 'admin.php' ) ); ?>"
               class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
            <a href="<?php echo add_query_arg( [
				'page' => $this->module_id,
				'tab'  => 'license',
			], admin_url( 'admin.php' ) ); ?>"
               class="nav-tab <?php echo $active_tab == 'license' ? 'nav-tab-active' : ''; ?>"> License </a>
            <a href="<?php echo add_query_arg( [
				'page' => $this->module_id,
				'tab'  => 'faq',
			], admin_url( 'admin.php' ) ); ?>"
               class="nav-tab <?php echo $active_tab == 'faq' ? 'nav-tab-active' : ''; ?>">FAQ</a>
            <a href="<?php echo add_query_arg( [
				'page' => AetherAdmin::$slug,
				'tab'  => 'main',
			], admin_url( 'admin.php' ) ); ?>" class="nav-tab"> Aether </a>
        </h2>
		<?php
		switch ( $active_tab ) {
			case 'faq':
				$this->faq_tab();
				break;
			case 'license':
				$this->license_tab();
				break;
			case 'settings':
			default:
				$this->setting_tab();
				break;
		}
	}

	public function setting_tab(): void {
		global $aether_m_sandbox;
		$sessions         = $aether_m_sandbox->get_sandbox_sessions();
		$selected_session = get_option( 'oxyrealm_sandbox_selected_session' );
		?>
        <!-- sandbox -->
        <div class="wrap">
            <h3 style="display: inline-block;margin-right: 5px;">Sandbox Sessions</h3>
            <a href="<?php echo add_query_arg( [
				'page'                           => $this->module_id,
				"{$this->module_id}_add_session" => wp_create_nonce( $this->module_id ),
			], admin_url( 'admin.php' ) ); ?>" class="page-title-action">Add New Session</a>

            <a class="page-title-action" id="import-session-btn" onClick="alert('Upgrade to Pro');">Import Session</a>

            <div class="upload-plugin-wrap">
                <div class="upload-plugin" id="upload-sandbox-session" style="display: none;">
                    <p class="install-help">Import session by locating session file and clicking "Import session".</p>
                    <form method="post" enctype="multipart/form-data" class="wp-upload-form"
                          action="<?php echo add_query_arg( [
						      'page'                      => $this->module_id,
						      "{$this->module_id}_import" => wp_create_nonce( $this->module_id ),
					      ], admin_url( 'admin.php' ) ); ?>">
                        <input type="file" id="sessionfile" name="sessionfile" accept=".json" required>
                        <input type="submit" class="button" value="Import session">
                    </form>
                </div>
            </div>

            <div class="sandbox-card" data-id="false">
                <input type="radio" id="default" name="sandbox"
                       value="false" <?php echo $selected_session == false ? 'checked' : ''; ?>>
                <div class="card-content">
                    <h2><label for="default">Disable Sandbox</label><br></h2>
                    <div class="sandbox-description">No Sandbox session applied</div>
                </div>
            </div>

			<?php foreach ( $sessions['sessions'] as $key => $value ) : ?>
                <div class="sandbox-card" style="position: relative;" data-id="<?php echo $value['id']; ?>">
                    <input type="radio" name="sandbox" id="sandbox-<?php echo $value['id']; ?>"
                           value="sandbox-<?php echo $value['id']; ?>" <?php echo $selected_session == $value['id'] ? 'checked' : ''; ?>>
                    <span style="position: absolute;right: 5px;top: 5px;opacity: 0.7;"><b>ID:</b> <?php echo $value['id'] ?></span>
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
                            <a class="wp-core-ui sandbox-button publish-button" href="<?php echo add_query_arg( [
								'page'                       => $this->module_id,
								"{$this->module_id}_publish" => wp_create_nonce( $this->module_id ),
								"session"                    => $value['id'],
							], admin_url( 'admin.php' ) ); ?>">
                                <span class="dashicons dashicons-cloud-saved"></span> Publish
                            </a>
                            <a class="wp-core-ui sandbox-button export-button" onClick="alert('Upgrade to Pro');">
                                <span class="dashicons dashicons-download"></span> Export
                            </a>
                            <a class="sandbox-button delete-button" href="<?php echo add_query_arg( [
								'page'                      => $this->module_id,
								"{$this->module_id}_delete" => wp_create_nonce( $this->module_id ),
								"session"                   => $value['id'],
							], admin_url( 'admin.php' ) ); ?>">
                                <span class="dashicons dashicons-trash"></span> Delete
                            </a>
                            <a class="sandbox-button reset-button" onClick="alert('Upgrade to Pro');">
                                <span class="dashicons dashicons-update-alt"></span> Reset Link
                            </a>
                        </div>
                    </div>
                </div>
			<?php endforeach; ?>

        </div>
        <!-- sandbox -->
		<?php
	}

	public function license_tab(): void {
		if ( isset( $_POST['submit'] ) ) {

			if ( ! wp_verify_nonce( $_POST["{$this->module_id}_settings_form"], $this->module_id ) ) {
				Notice::error( 'Nonce verification failed', $this->module_id );
				echo( "<script>location.href = '" . add_query_arg( [
						'page' => $this->module_id,
						'tab'  => 'license',
					], admin_url( 'admin.php' ) ) . "'</script>" );
				exit;
			}

			$_request_license_key = sanitize_text_field( $_REQUEST['license_key'] );

			if ( $_request_license_key !== get_option( "{$this->module_id}_license_key" ) ) {
				/** @var Collaboration $aether_m_sandbox */
				global $aether_m_sandbox;

				if ( empty( $_request_license_key ) ) {
					$aether_m_sandbox->skynet->deactivate();
					update_option( "{$this->module_id}_license_key", null );

					Notice::success( 'Plugin license key de-activated successfully', $this->module_id );
				} else {
					$response = $aether_m_sandbox->skynet->activate( $_request_license_key );

					if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
						Notice::error( [ is_wp_error( $response ) ? $response->get_error_message() : 'An error occurred, please try again.' ], $this->module_id );
					} else {
						$license_data = json_decode( wp_remote_retrieve_body( $response ) );

						if ( $license_data->license != 'valid' ) {
							Notice::error( [ Update::errorMessage( $license_data->error ) ], $this->module_id );
						} else {
							update_option( "{$this->module_id}_license_key", $_request_license_key );
							Notice::success( 'Plugin license key activated successfully', $this->module_id );
						}
					}
				}
			}

			update_option( "{$this->module_id}_beta", sanitize_text_field( $_REQUEST['beta'] ?? false ) );

			Notice::success( 'License have been saved!', $this->module_id );
			echo( "<script>location.href = '" . add_query_arg( [
					'page' => $this->module_id,
					'tab'  => 'license',
				], admin_url( 'admin.php' ) ) . "'</script>" );
			exit;
		}

		$_license_key = get_option( "{$this->module_id}_license_key" );
		$_beta        = get_option( "{$this->module_id}_beta" );

		?>
        <form method="POST">
			<?php wp_nonce_field( $this->module_id, "{$this->module_id}_settings_form" ); ?>
            <table class="form-table" role="presentation">
                <tbody>

                <tr>
                    <th scope="row"><label>License Key</label></th>
                    <td>
                        <input name="license_key" type="text" value="<?php echo esc_attr( $_license_key ); ?>"/>
                        <p class="description">Enter your <a href="https://go.oxyrealm.com/sandbox" target="_blank">license
                                key</a> to get update</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Enable pre-release version</label></th>
                    <td>
                        <input name="beta" type="checkbox" value="1" <?php if ( $_beta ) {
							echo "checked";
						} ?>>
                    </td>
                </tr>
                </tbody>
            </table>

            <p class="submit">
                <input type="submit" name="submit" id="submit" class="button button-primary" value="Save Changes">
            </p>
        </form>
		<?php
	}

	public function faq_tab(): void {
		?>

        <h3>Are all preview links accessible?</h3>
        <p>Yes, all links will work at the same time, no matter what sandbox session you choose.</p>

        <h3>I enjoy and want to support All our free plugins!</h3>
		<form action="https://www.paypal.com/cgi-bin/webscr" method="post" class="donation">
			<table class="form-table">
				<tbody>
				<tr>
					<th> Plugin Support: </th>
					<td>
					<div>
						<label>
							<p>
								Thank you, we appreciate your support. 
								It has required a great deal of time and effort to develop and
								you can help support this development by <strong>buy the coffee for the developer</strong>.
							</p>
						</label>

						<input type="hidden" name="cmd" value="_xclick" />
						<input type="hidden" name="business" value="suabahasa@gmail.com" />
						<input type="hidden" name="item_name" value="Aether (WordPress Plugin)" />
						<input type="hidden" name="buyer_credit_promo_code" value="" />
						<input type="hidden" name="buyer_credit_product_category" value="" />
						<input type="hidden" name="buyer_credit_shipping_method" value="" />
						<input type="hidden" name="buyer_credit_user_address_change" value="" />
						<input type="hidden" name="no_shipping" value="1" />
						<input type="hidden" name="return" value="<?php echo add_query_arg( [ 'page' => AetherAdmin::$slug, 'tab'  => 'main', ], admin_url( 'admin.php' ) ); ?>" />
						<input type="hidden" name="no_note" value="1" />
						<input type="hidden" name="currency_code" value="USD" />
						<input type="hidden" name="tax" value="0" />
						<input type="hidden" name="lc" value="US" />
						<input type="hidden" name="bn" value="PP-DonationsBF" />

						<div class="donation-amount">
						$ <input type="number" name="amount" min="5" value="20"> <span> 😀 </span>
						<input type="submit" class="button-primary" value="Support 💰">
						</div>
					</div>
					</td>
				</tr>
				</tbody>
			</table>
		</form>
		<style>.donation{max-width:800px}.donation .donation-amount{float:left;margin-top:10px;max-width:500px}.donation .donation-amount span{font-size:28px;margin-top:4px;vertical-align:bottom}.donation .donation-amount img{width:24px!important;margin-bottom:-5px!important}.donation .donation-amount::after{content:"";display:block;clear:both}.donation input[type=number]{width:60px;margin-left:10px}.donation td,.donation th{padding:0;margin-bottom:0}.donation input[type=submit]{margin-left:10px}</style>

		<?php
	}
}
