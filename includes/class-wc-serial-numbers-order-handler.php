<?php
defined( 'ABSPATH' ) || exit();

class WC_Serial_Numbers_Handler {

	/**
	 * @since 1.2.0
	 */
	public static function init() {
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'validate_checkout' ) );

		//autocomplete
		add_action( 'template_redirect', array( __CLASS__, 'maybe_autocomplete_order' ) );

		//add serial numbers
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'maybe_assign_serial_numbers' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'maybe_assign_serial_numbers' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'maybe_assign_serial_numbers' ) );
		add_action( 'woocommerce_order_status_on-hold', array( __CLASS__, 'maybe_assign_serial_numbers' ) );

		// revoke ordered serial numbers
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'revoke_serial_numbers' ) );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'revoke_serial_numbers' ) );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'revoke_serial_numbers' ) );
		add_action( 'woocommerce_order_partially_refunded', array( __CLASS__, 'revoke_serial_numbers' ), 10, 2 );

		//
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'order_print_items' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'order_print_items' ), 10 );
	}

	/**
	 * If selling from stock then check if there is enough
	 * serial numbers available otherwise disable checkout
	 *
	 * since 1.2.0
	 * @return void
	 */
	public static function validate_checkout() {
		$car_products = WC()->cart->get_cart_contents();
		foreach ( $car_products as $id => $cart_product ) {
			/** @var \WC_Product $product */
			$product         = $cart_product['data'];
			$product_id      = $product->get_id();
			$quantity        = $cart_product['quantity'];
			$allow_backorder = apply_filters( 'wc_serial_numbers_allow_backorder', false, $product_id );

			if ( wc_serial_numbers_product_serial_enabled( $product_id ) && ! $allow_backorder ) {
				$per_item_quantity = absint( apply_filters( 'wc_serial_numbers_per_product_delivery_qty', 1, $product_id ) );
				$needed_quantity   = $quantity * ( empty( $per_item_quantity ) ? 1 : absint( $per_item_quantity ) );
				$source            = apply_filters( 'wc_serial_numbers_product_serial_source', 'custom_source', $product_id, $needed_quantity );

				if ( 'custom_source' == $source ) {
					$total_number = WC_Serial_Numbers_Query::init()
														   ->from( 'serial_numbers' )
														   ->where( 'product_id', $product_id )
														   ->where( 'status', 'available' )
														   ->where( 'source', $source )
														   ->limit( $needed_quantity )
														   ->count();

					if ( $total_number < $needed_quantity ) {
						$stock   = floor( $total_number / $per_item_quantity );
						$message = sprintf( __( 'Sorry, There is not enough serial numbers available for %s, Please remove this item or lower the quantity, For now we have %s Serial Number for this product.', 'wc-serial-numbers' ), '{product_title}', '{stock_quantity}' );
						$notice  = apply_filters( 'wc_serial_numbers_low_stock_message', $message );
						$notice  = str_replace( '{product_title}', $product->get_title(), $notice );
						$notice  = str_replace( '{stock_quantity}', $stock, $notice );

						wc_add_notice( $notice, 'error' );

						return false;
					}
				}
			}

			do_action( 'wc_serial_number_product_cart_validation_complete', $product_id, $cart_product );
		}
	}

	/**
	 * Autocomplete order.
	 *
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	public static function maybe_autocomplete_order() {
		if ( is_checkout() && ! empty( is_wc_endpoint_url( 'order-received' ) ) && ! empty( get_query_var( 'order-received' ) ) ) {
			$order_id = get_query_var( 'order-received' );
			$order    = wc_get_order( $order_id );

			//only autocomplete if contains serials
			if ( empty( $order ) || ! wc_serial_numbers_order_has_serial_numbers( $order_id ) ) {
				return $order;
			}

			if ( 'completed' === $order->get_status() ) {
				return false;
			}

			$order->update_status( 'completed' );

			$order->add_order_note( __( 'Order marked as complete by WC Serial Numbers', 'wc-serial-numbers' ) );

			return true;
		}

		return false;
	}

	/**
	 * Conditionally add serial numbers.
	 *
	 * @param int $order_id
	 *
	 * @version 1.2.0
	 */
	public static function maybe_assign_serial_numbers( $order_id ) {
		$manual_delivery = apply_filters( 'wc_serial_numbers_maybe_manual_delivery', false );
		$order           = wc_get_order( $order_id );
		$order->add_order_note( $order->get_status() );
		if ( ! $manual_delivery ) {
			wc_serial_numbers_order_connect_serial_numbers( $order_id );
		}
	}

	/**
	 * Remove serial numbers from order.
	 *
	 * @param $order_id
	 *
	 * @return bool|int
	 * @since 1.2.0
	 */
	public static function revoke_serial_numbers( $order_id ) {
		$order    = wc_get_order( $order_id );
		$order_id = $order->get_id();

		// bail for no order
		if ( ! $order_id ) {
			return false;
		}

		return wc_serial_numbers_order_disconnect_serial_numbers( $order_id );
	}

	/**
	 * Print ordered serials
	 *
	 * @param $order
	 *
	 * @throws \Exception
	 * @since 1.2.0
	 */
	public static function order_print_items( $order ) {
		$order_id = $order->get_id();

		if ( 'completed' !== $order->get_status( 'edit' ) ) {
			echo sprintf( '<p>Order is waiting for approval</p>' );

			return;
		}

		$serial_numbers = WC_Serial_Numbers_Query::init()->from( 'serial_numbers' )->where( 'order_id', intval( $order_id ) )->get();
		echo sprintf( '<h2 class="woocommerce-order-downloads__title">%s</h2>', esc_html( "Serial Numbers" ) );

		if ( empty( $serial_numbers ) ) {
			echo sprintf( '<p>Order is waiting for approval</p>' );

			return;
		}

		$columns = wc_serial_numbers_get_order_table_columns();
		?>
		<table class="woocommerce-table woocommerce-table--order-details shop_table order_details wc-serial-numbers-order-items" style="width: 100%; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; margin-bottom: 40px;" cellspacing="0" cellpadding="6" border="1">
			<thead>
			<tr>
				<?php foreach ( $columns as $key => $label ) {
					echo sprintf( '<th class="td %s" scope="col" style="text-align:left;">%s</th>', sanitize_html_class( $key ), $label );
				} ?>
			</tr>
			</thead>
			<tbody>
			<?php
			foreach ( $serial_numbers as $serial_number ) {
				echo '<tr>';
				foreach ( $columns as $key => $label ) {
					echo '<td class="td" style="text-align:left;">';
					switch ( $key ) {
						case 'product':
							echo sprintf( '<a href="%s">%s</a>', esc_url( get_permalink( $serial_number->product_id ) ), get_the_title( $serial_number->product_id ) );
							break;
						case 'serial_key':
							echo wc_serial_numbers_decrypt_key( $serial_number->serial_key );
							break;
						case 'activation_email':
							echo $order->get_billing_email();
							break;
						case 'activation_limit':
							if ( empty( $serial_number->activation_limit ) ) {
								echo __( 'Unlimited', 'wc-serial-numbers' );
							} else {
								echo $serial_number->activation_limit;
							}
							break;
						case 'expire_date':
							if ( empty( $serial_number->validity ) ) {
								echo __( 'Lifetime', 'wc-serial-numbers' );
							} else {
								echo date( 'Y-m-d', strtotime( $serial_number->order_date . ' + ' . $serial_number->validity . ' Day ' ) );
							}
							break;

						default:
							do_action( 'wc_serial_numbers_order_table_cell_content', $key, $serial_number, $order_id );
					}
					echo '</td>';
				}
				echo '</tr>';
			} ?>

			</tbody>
		</table>
		<?php

	}


}

WC_Serial_Numbers_Handler::init();
