<?php
/**
 * Customize Menu Location Control Class
 *
 * @package WordPress
 * @subpackage Customize
 */

/**
 * Customize Menu Location Control Class
 *
 * This custom control is only needed for JS.
 *
 * @since 4.3.0
 */
class WP_Customize_Nav_Menu_Location_Control extends WP_Customize_Control {
	/**
	 * Control type
	 *
	 * @access public
	 * @var string
	 */
	public $type = 'nav_menu_location';

	/**
	 * Location ID
	 *
	 * @access public
	 * @var string
	 */
	public $location_id = '';

	/**
	 * Refresh the parameters passed to JavaScript via JSON.
	 *
	 * @since Menu Customizer 0.4
	 * @uses WP_Customize_Control::to_json()
	 */
	public function to_json() {
		parent::to_json();
		$this->json['locationId'] = $this->location_id;
	}

	/**
	 * Render content just like a normal select control.
	 *
	 * @since Menu Customizer 0.4
	 */
	public function render_content() {
		if ( empty( $this->choices ) ) {
			return;
		}

		?>
		<label>
			<?php if ( ! empty( $this->label ) ) : ?>
				<span class="customize-control-title"><?php echo esc_html( $this->label ); ?></span>
			<?php endif; ?>

			<?php if ( ! empty( $this->description ) ) : ?>
				<span class="description customize-control-description"><?php echo $this->description; ?></span>
			<?php endif; ?>

			<select <?php $this->link(); ?>>
				<?php
				foreach ( $this->choices as $value => $label ) {
					echo '<option value="' . esc_attr( $value ) . '"' . selected( $this->value(), $value, false ) . '>' . $label . '</option>';
				}
				?>
			</select>
		</label>
	<?php
	}
}
