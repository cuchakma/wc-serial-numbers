<?php
defined( 'ABSPATH' ) || exit();

/**
 * since 1.0.0
 * @param $serial_number
 * @param string $context
 *
 * @return string
 */
function wc_serial_numbers_get_serial_number_status( $serial_number, $context = 'edit' ) {
	if ( ! isset( $serial_number->status ) && is_numeric( $serial_number ) ) {
		$serial_number = wc_serial_numbers_get_serial_number( $serial_number );
	}
	$statues = wc_serial_numbers_get_serial_number_statuses();
	$status  = 'inactive';

	if ( array_key_exists( $serial_number->status, $statues ) ) {
		$status = $serial_number->status;
	}


	return 'edit' === $context ? $status : $statues[ $status ];
}

/**
 * get expiration date
 *
 * since 1.0.0
 *
 * @param $serial
 *
 * @return string
 */
function wc_serial_numbers_get_serial_expiration_date( $serial ) {
	if ( empty( $serial->validity ) ) {
		return __( 'Never Expire', 'wc-serial-numbers' );
	}

	return date( 'Y-m-d', strtotime( $serial->order_date . ' + ' . $serial->validity . ' Day ' ) );
}