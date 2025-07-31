<?php
/**
 * Download handler
 *
 * @package Miguel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Download handler with dependency injection for better testability
 *
 * @package Miguel
 */
class Miguel_Download {

	/**
	 * Hook manager instance
	 *
	 * @var Miguel_Hook_Manager_Interface
	 */
	private $hook_manager;

	/**
	 * API instance
	 *
	 * @var Miguel_API
	 */
	private $api;

	/**
	 * File factory function
	 *
	 * @var callable
	 */
	private $file_factory;

	/**
	 * Error handler function
	 *
	 * @var callable
	 */
	private $error_handler;

	/**
	 * Redirect handler function
	 *
	 * @var callable
	 */
	private $redirect_handler;

	/**
	 * Constructor with dependency injection
	 *
	 * @param Miguel_Hook_Manager_Interface $hook_manager    Hook manager for registering actions.
	 * @param Miguel_API          $api             API instance for file generation.
	 * @param callable            $file_factory    Function to get file (default: miguel_get_file).
	 * @param callable            $error_handler   Function to handle errors (default: wp_die).
	 * @param callable            $redirect_handler Function to handle redirects (default: wp_redirect + exit).
	 */
	public function __construct(
		Miguel_Hook_Manager_Interface $hook_manager,
		Miguel_API $api,
		$file_factory = null,
		$error_handler = null,
		$redirect_handler = null
	) {
		$this->hook_manager     = $hook_manager;
		$this->api              = $api;
		$this->file_factory     = $file_factory ? $file_factory : 'miguel_get_file';
		$this->error_handler    = $error_handler ? $error_handler : 'wp_die';
		$this->redirect_handler = $redirect_handler ? $redirect_handler : array( $this, 'default_redirect_handler' );
	}

	/**
	 * Register WordPress hooks
	 */
	public function register_hooks() {
		$this->hook_manager->add_action( 'woocommerce_download_product', array( $this, 'download' ), 10, 6 );
	}

	/**
	 * Default redirect handler
	 *
	 * @param string $url URL to redirect to.
	 */
	private function default_redirect_handler( $url ) {
		wp_redirect( $url );
		exit;
	}

	/**
	 * Download
	 *
	 * @param string $email
	 * @param string $order_key
	 * @param int    $product_id
	 * @param int    $user_id
	 * @param int    $download_id
	 * @param int    $order_id
	 */
	public function download( $email, $order_key, $product_id, $user_id, $download_id, $order_id ) {
		try {
			$file = call_user_func( $this->file_factory, $product_id, $download_id );
			if ( is_wp_error( $file ) ) {
				return;
			}

			if ( ! $file->is_valid() ) {
				call_user_func( $this->error_handler, esc_html__( 'Invalid shortcode params.', 'miguel' ) );
				return;
			}

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				call_user_func( $this->error_handler, esc_html__( 'Invalid order.', 'miguel' ) );
				return;
			}

			$item = $this->get_item( $order, $download_id );

			$this->serve( $file, $order, $item );
		} catch ( Exception $e ) {
			call_user_func( $this->error_handler, esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * Serve
	 *
	 * @param Miguel_File           $file
	 * @param WC_Order              $order
	 * @param WC_Order_Item_Product $item
	 * @throws Exception If request is invalid.
	 */
	public function serve( $file, $order, $item ) {
		$request = new Miguel_Request( $order, $item );
		if ( ! $request->is_valid() ) {
			call_user_func( $this->error_handler, esc_html__( 'Invalid request.', 'miguel' ) );
			return;
		}

		$this->serve_file( $file, $request );
	}

	/**
	 * Serve file
	 *
	 * @param Miguel_File    $file
	 * @param Miguel_Request $request
	 */
	public function serve_file( $file, $request ) {
		$response = $this->api->generate( $file->get_name(), $file->get_format(), $request->to_array() );

		if ( is_wp_error( $response ) ) {
			call_user_func( $this->error_handler, esc_html( $response->get_error_message() ) );
			return;
		}

		$json = json_decode( $response['body'] );
		if ( ! $json ) {
			call_user_func( $this->error_handler, esc_html__( 'Something went wrong.', 'miguel' ) );
			return;
		}

		if ( property_exists( $json, 'reason' ) && $json->reason ) {
			call_user_func( $this->error_handler, esc_html( $json->reason ) );
			return;
		} elseif ( property_exists( $json, 'error' ) && $json->error ) {
			call_user_func( $this->error_handler, esc_html( $json->error . ': ' . $json->message ) );
			return;
		} elseif ( property_exists( $json, 'download_url' ) ) {
			$url = $json->download_url;
			call_user_func( $this->redirect_handler, $url );
			return;
		} else {
			call_user_func( $this->error_handler, esc_html__( 'Something went wrong.', 'miguel' ) );
			return;
		}
	}

	/**
	 * Get hook manager (for testing purposes)
	 *
	 * @return Miguel_Hook_Manager_Interface|null
	 */
	public function get_hook_manager() {
		return $this->hook_manager;
	}

	/**
	 * Get item
	 *
	 * @param WC_Order $order
	 * @param int      $download_id
	 *
	 * @return WC_Order_Item_Product|null
	 */
	protected function get_item( $order, $download_id ) {
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
				continue;
			}

			// Get the downloads for each item
			$downloads = $item->get_item_downloads();
			foreach ( $downloads as $download ) {
				// Check if the download ID matches the ID you are looking for
				if ( $download['id'] == $download_id ) {
					return $item;
				}
			}
		}
	}
}
