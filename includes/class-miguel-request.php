<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

/**
 * Request
 *
 * @package Miguel
 */
class Miguel_Request {

  /**
   * @var WC_Order
   */
  protected $order;

  /**
   * @var WP_User|false
   */
  protected $user;

  /**
   * @param WC_Order $order
   */
  public function __construct( $order ) {
    $this->order = $order;

    $user_id = $order->get_user_id();
    if ($user_id > 0) {
      $this->user = get_user_by( 'id', $user_id );
    } else {
      $this->user = false;
    }
  }

  /**
   * @return string
   */
  public function get_id() {
    return md5( $this->get_email() );
  }

  /**
   * @return int
   */
  public function get_order_id() {
    return $this->order->get_id();
  }

  /**
   * @return string
   */
  public function get_email() {
    if ($this->user) {
      return $this->user->user_email;
    } else {
      return $this->order->get_billing_email();
    }
  }

  /**
   * @return string
   */
  public function get_full_name() {
    return implode( ' ', array(
      $this->order->get_billing_first_name(),
      $this->order->get_billing_last_name()
    ) );
  }

  /**
   * @return string
   */
  public function get_address() {
    return implode( ' ', array(
      $this->order->get_billing_address_1(),
      $this->order->get_billing_city()
    ) );
  }

  /**
   * @return string|null
   */
  public function get_purchase_date() {
    $paid_date = $this->order->get_date_paid();
    if ( ! $paid_date ) {
      return;
    }

    return $paid_date->format( 'Y-m-d' );
  }

  /**
   * @return string
   */
  public function get_language() {
    return get_user_locale($this->user);
  }

  /**
   * @return bool
   */
  public function is_valid() {
    return ! empty( $this->get_purchase_date() );
  }

  /**
   * @return array
   */
  public function to_array() {
    return array(
      'user' => array(
        'id' => $this->get_id(),
        'email' => $this->get_email(),
        'address' => $this->get_address(),
        'full_name' => $this->get_full_name(),
        'lang' => $this->get_language(),
      ),
      'purchase_date' => $this->get_purchase_date()
    );
  }
}
