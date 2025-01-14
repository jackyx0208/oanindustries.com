<?php

use function WPML\Container\make;
use WCML\MultiCurrency\Geolocation;

/**
 * Class WCML_Multi_Currency
 *
 * Our case:
 * Multi-currency can be enabled by an option in wp_options - wcml_multi_currency_enabled
 * User currency will be set in the woocommerce session as 'client_currency'
 */
class WCML_Multi_Currency {

	/** @var  array */
	public $currencies = [];
	/** @var  array */
	public $currency_codes = [];

	/** @var  string */
	private $default_currency;
	/** @var  string */
	private $client_currency;
	/** @var  array */
	private $exchange_rates = [];
	/** @var  array */
	public $currencies_without_cents = [ 'JPY', 'TWD', 'KRW', 'BIF', 'BYR', 'CLP', 'GNF', 'ISK', 'KMF', 'PYG', 'RWF', 'VUV', 'XAF', 'XOF', 'XPF' ];

	/**
	 * @var WCML_Multi_Currency_Prices
	 */
	public $prices;
	/**
	 * @var WCML_Multi_Currency_Coupons
	 */
	public $coupons;
	/**
	 * @var WCML_Multi_Currency_Shipping
	 */
	public $shipping;

	/**
	 * @var WCML_Multi_Currency_Reports
	 */
	public $reports;
	/**
	 * @var WCML_Multi_Currency_Orders
	 */
	public $orders;
	/**
	 * @var WCML_Admin_Currency_Selector
	 */
	public $admin_currency_selector;
	/**
	 * @var WCML_Custom_Prices
	 */
	public $custom_prices;
	/**
	 * @var WCML_Currency_Switcher
	 */
	public $currency_switcher;
	/**
	 * @var WCML_Currency_Switcher_Ajax
	 */
	public $currency_switcher_ajax;
	/**
	 * @var WCML_Multi_Currency_Install
	 */
	public $install;

	public $W3TC = false;

	/**
	 * @var woocommerce_wpml
	 */
	public $woocommerce_wpml;

	/**
	 * @var WCML_Exchange_Rate_Service
	 */
	public $exchange_rate_services;

	/**
	 * @var WCML_Currencies_Payment_Gateways
	 */
	public $currencies_payment_gateways;

	/**
	 * @var bool
	 */
	public $load_filters;

	/**
	 * @var string
	 */
	public $switching_currency_html;


	/**
	 * WCML_Multi_Currency constructor.
	 */
	public function __construct() {
		global $woocommerce_wpml, $sitepress, $wpdb, $wp_locale, $wp;

		$this->woocommerce_wpml = $woocommerce_wpml;

		$this->install = new WCML_Multi_Currency_Install( $this, $woocommerce_wpml );

		$this->init_currencies();

		$this->load_filters = $this->are_filters_need_loading();
		$this->prices       = new WCML_Multi_Currency_Prices( $this, $woocommerce_wpml->get_setting( 'currency_options' ) );
		$this->prices->add_hooks();
		if ( $this->load_filters ) {
			$table_rate_shipping_multi_currency = new WCML_Multi_Currency_Table_Rate_Shipping();
			$table_rate_shipping_multi_currency->add_hooks();

			$this->coupons  = new WCML_Multi_Currency_Coupons();
			$this->shipping = new WCML_Multi_Currency_Shipping( $this, $sitepress, $wpdb );
			$this->shipping->add_hooks();
		}
		$this->reports = new WCML_Multi_Currency_Reports( $woocommerce_wpml, $sitepress, $wpdb );
		$this->reports->add_hooks();
		$this->orders                  = new WCML_Multi_Currency_Orders( $this, $woocommerce_wpml, $wp );
		$this->admin_currency_selector = new WCML_Admin_Currency_Selector(
			$woocommerce_wpml,
			new WCML_Admin_Cookie( '_wcml_dashboard_currency' )
		);
		$this->admin_currency_selector->add_hooks();
		$this->custom_prices = new WCML_Custom_Prices( $woocommerce_wpml, $wpdb );
		$this->custom_prices->add_hooks();
		$this->currency_switcher = new WCML_Currency_Switcher( $woocommerce_wpml, $sitepress );
		$this->currency_switcher->add_hooks();
		$this->currency_switcher_ajax = new WCML_Currency_Switcher_Ajax( $woocommerce_wpml );

		$this->exchange_rate_services = new WCML_Exchange_Rates( $this->woocommerce_wpml, $wp_locale );
		$this->exchange_rate_services->initialize_settings();
		$this->exchange_rate_services->add_actions();
		$this->exchange_rate_services->add_service( 'fixerio', new WCML_Exchange_Rates_Fixerio() );
		$this->exchange_rate_services->add_service( 'currencylayer', new WCML_Exchange_Rates_Currencylayer() );

		$this->currencies_payment_gateways = make( WCML_Currencies_Payment_Gateways::class );
		$this->currencies_payment_gateways->add_hooks();

		if ( defined( 'W3TC' ) ) {
			$this->W3TC = new WCML_W3TC_Multi_Currency();
		}

		WCML_Multi_Currency_Resources::set_up( $this, $this->woocommerce_wpml );
		WCML_Multi_Currency_Configuration::set_up( $this, $woocommerce_wpml );

		add_filter( 'init', [ $this, 'init' ], 5 );

		if ( is_ajax() ) {
			add_action( 'wp_ajax_nopriv_wcml_switch_currency', [ $this, 'switch_currency' ] );
			add_action( 'wp_ajax_wcml_switch_currency', [ $this, 'switch_currency' ] );
		}

	}

	public function are_filters_need_loading() {
		$load = false;

		if ( ! is_admin() && $this->get_client_currency() !== wcml_get_woocommerce_currency_option() ) {
			$load = true;
		} else {
			if ( is_ajax() && $this->get_client_currency() !== wcml_get_woocommerce_currency_option() ) {

				$ajax_actions = apply_filters(
					'wcml_multi_currency_ajax_actions',
					[
						'woocommerce_get_refreshed_fragments',
						'woocommerce_update_order_review',
						'woocommerce-checkout',
						'woocommerce_checkout',
						'woocommerce_add_to_cart',
						'woocommerce_update_shipping_method',
						'woocommerce_json_search_products_and_variations',
						'woocommerce_add_coupon_discount',

					]
				);

				if ( ( isset( $_POST['action'] ) && in_array( $_POST['action'], $ajax_actions ) ) ||
					 ( isset( $_GET['action'] ) && in_array( $_GET['action'], $ajax_actions ) ) ) {
					$load = true;
				}
			}
		}

		/**
		 * @deprecated 3.9.2
		 */
		$load = apply_filters( 'wcml_load_multi_currency', $load );

		/**
		 * @since 3.9.2
		 */
		$load = apply_filters( 'wcml_load_multi_currency_in_ajax', $load );

		return $load;
	}

	public function init() {

		add_filter( 'wcml_get_client_currency', [ $this, 'get_client_currency' ] );
		add_action( 'wp_footer', [ $this, 'maybe_show_switching_currency_prompt_dialog' ] );
		add_action( 'wp_footer', [ $this, 'maybe_reset_cart_fragments' ] );

	}

	public function enable() {
		$this->woocommerce_wpml->settings['enable_multi_currency'] = WCML_MULTI_CURRENCIES_INDEPENDENT;
		$this->woocommerce_wpml->update_settings();
	}

	public function disable() {
		$this->woocommerce_wpml->settings['enable_multi_currency'] = WCML_MULTI_CURRENCIES_DISABLED;
		$this->woocommerce_wpml->update_settings();
	}

	public function init_currencies() {
		global $sitepress;

		$this->default_currency = wcml_get_woocommerce_currency_option();
		$this->currencies       = $this->woocommerce_wpml->settings['currency_options'];

		// Add default currency if missing (set when MC is off).
		if ( ! empty( $this->default_currency ) && ! isset( $this->currencies[ $this->default_currency ] ) ) {
			$this->currencies[ $this->default_currency ] = [];
		}

		$save_to_db = false;

		$active_languages = $sitepress->get_active_languages();

		$currency_defaults = [
			'rate'               => 0,
			'position'           => 'left',
			'thousand_sep'       => ',',
			'decimal_sep'        => '.',
			'num_decimals'       => 2,
			'rounding'           => 'disabled',
			'rounding_increment' => 1,
			'auto_subtract'      => 0,
			'location_mode'      => 'all',
			'countries'          => [],
		];

		foreach ( $this->currencies as $code => $currency ) {
			foreach ( $currency_defaults as $key => $val ) {
				if ( ! isset( $currency[ $key ] ) ) {
					$this->currencies[ $code ][ $key ] = $val;
					$save_to_db                        = true;
				}
			}

			foreach ( $active_languages as $language ) {
				if ( ! isset( $currency['languages'][ $language['code'] ] ) ) {
					$this->currencies[ $code ]['languages'][ $language['code'] ] = 1;
					$save_to_db = true;
				}
			}
		}

		$this->currency_codes = array_keys( $this->currencies );

		// default language currencies.
		foreach ( $active_languages as $language ) {
			if ( ! isset( $this->woocommerce_wpml->settings['default_currencies'][ $language['code'] ] ) ) {
				$this->woocommerce_wpml->settings['default_currencies'][ $language['code'] ] = 0;
				$save_to_db = true;
			}
		}

		// sanity check.
		if ( isset( $this->woocommerce_wpml->settings['default_currencies'] ) ) {
			foreach ( $this->woocommerce_wpml->settings['default_currencies'] as $language => $value ) {
				if ( ! isset( $active_languages[ $language ] ) ) {
					unset( $this->woocommerce_wpml->settings['default_currencies'][ $language ] );
					$save_to_db = true;
				}
				if ( ! empty( $value ) && ! in_array( $value, $this->currency_codes ) && $value !== 'location' ) {
					$this->woocommerce_wpml->settings['default_currencies'][ $language ] = 0;
					$save_to_db = true;
				}
			}
		}

		// add missing currencies to currencies_order.
		if ( isset( $this->woocommerce_wpml->settings['currencies_order'] ) ) {
			foreach ( $this->currency_codes as $currency ) {
				if ( ! in_array( $currency, $this->woocommerce_wpml->settings['currencies_order'] ) ) {
					$this->woocommerce_wpml->settings['currencies_order'][] = $currency;
					$save_to_db = true;
				}
			}
		}

		if ( $save_to_db ) {
			$this->woocommerce_wpml->update_settings();
		}

		// force disable multi-currency when the default currency is empty.
		if ( empty( $this->default_currency ) ) {
			$this->woocommerce_wpml->settings['enable_multi_currency'] = WCML_MULTI_CURRENCIES_DISABLED;
		}

	}

	/**
	 *
	 * @return string
	 * @since 3.9.2
	 */
	public function get_default_currency() {
		return $this->default_currency;
	}

	public function get_currencies( $include_default = false ) {

		// by default, exclude default currency.
		$currencies       = [];
		$default_currency = wcml_get_woocommerce_currency_option();

		foreach ( $this->currencies as $key => $value ) {
			if ( $default_currency != $key || $include_default ) {
				$currencies[ $key ] = $value;
			}
		}

		return $currencies;
	}

	public function get_currency_codes() {
		return $this->currency_codes;
	}

	/**
	 * @param string $code
	 *
	 * @return bool
	 */
	public function is_currency_active( $code ) {
		return in_array( $code, $this->get_currency_codes(), true );
	}

	/**
	 * @return mixed|string
	 */
	public function get_currency_code() {
		$currency_code  = wcml_get_woocommerce_currency_option();
		$currency_codes = $this->get_currency_codes();
		if ( ! in_array( $currency_code, $currency_codes, true ) ) {
			$currency_code = $this->woocommerce_wpml->multi_currency->get_default_currency();
		}

		return $currency_code;
	}

	public function get_currency_details_by_code( $code ) {

		if ( isset( $this->currencies[ $code ] ) ) {
			return $this->currencies[ $code ];
		}

		return false;
	}

	public function delete_currency_by_code( $code, $settings = false, $update = true ) {
		$settings = $settings ? $settings : $this->woocommerce_wpml->get_settings();
		unset( $settings['currency_options'][ $code ] );

		if ( isset( $settings['currencies_order'] ) ) {
			foreach ( $settings['currencies_order'] as $key => $cur_code ) {
				if ( $cur_code == $code ) {
					unset( $settings['currencies_order'][ $key ] );
				}
			}
		}

		if ( $update ) {
			$this->woocommerce_wpml->update_settings( $settings );
		}

		return $settings;
	}

	public function get_exchange_rates() {

		if ( empty( $this->exchange_rates ) ) {

			$this->exchange_rates = [ wcml_get_woocommerce_currency_option() => 1 ];
			$woo_currencies       = get_woocommerce_currencies();

			$currencies = $this->get_currencies();
			foreach ( $currencies as $code => $currency ) {
				if ( ! empty( $woo_currencies[ $code ] ) ) {
					$this->exchange_rates[ $code ] = $currency['rate'];
				}
			}
		}

		return apply_filters( 'wcml_exchange_rates', $this->exchange_rates );
	}

	public function get_client_currency() {
		global $woocommerce, $sitepress, $wpdb;

		$WCML_REST_API = new WCML_REST_API();
		if (
			$WCML_REST_API->is_rest_api_request() ||
			! empty( $_REQUEST['woocommerce_quick_edit'] )
		) {
			return wcml_get_woocommerce_currency_option();
		}

		$current_language   = $sitepress->get_current_language();
		$current_language   = ( $current_language !== 'all' && ! is_null( $current_language ) ) ? $current_language : $sitepress->get_default_language();

		if ( ! $this->client_currency &&
			 isset( $this->woocommerce_wpml->settings['display_custom_prices'] ) &&
			 $this->woocommerce_wpml->settings['display_custom_prices'] &&
			 is_product()
		) {

			$product_obj               = wc_get_product();
			$current_product_id        = get_post()->ID;
			$original_product_language = $this->woocommerce_wpml->products->get_original_product_language( $current_product_id );
			$default                   = false;

			if ( $product_obj->get_type() === 'variable' ) {
				foreach ( $product_obj->get_children() as $child ) {
					if ( ! get_post_meta( apply_filters( 'translate_object_id', $child, get_post_type( $child ), true, $original_product_language ), '_wcml_custom_prices_status', true ) ) {
						$default = true;
						break;
					}
				}
			} elseif ( ! get_post_meta( apply_filters( 'translate_object_id', $current_product_id, get_post_type( $current_product_id ), true, $original_product_language ), '_wcml_custom_prices_status', true ) ) {
				$default = true;
			}

			if ( $default ) {
				$this->client_currency = wcml_get_woocommerce_currency_option();
			}
		}

		if ( isset( $_GET['pay_for_order'] ) && $_GET['pay_for_order'] == true && isset( $_GET['key'] ) ) {
			$order_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_order_key' AND meta_value = %s", sanitize_text_field( $_GET['key'] ) ) );
			if ( $order_id ) {
				$this->client_currency = get_post_meta( $order_id, '_order_currency', true );
			}
		}

		if ( isset( $_GET['action'] ) &&
			 $_GET['action'] === 'woocommerce_json_search_products_and_variations' &&
			 ! empty( $_COOKIE['_wcml_order_currency'] ) ) {
			$this->client_currency = $_COOKIE['_wcml_order_currency'];
		}

		$this->client_currency = $this->maybe_get_currency_by_geolocation( $this->client_currency, $woocommerce->session );

		if ( is_null( $this->client_currency ) &&
		     ! empty( $woocommerce->session ) &&
		     $current_language != $woocommerce->session->get( 'client_currency_language' ) ) {

			$language_default_currency = $this->get_language_default_currency( $current_language );

			if ( $language_default_currency ) {
				$current_currency  = $woocommerce->session->get( 'client_currency' );
				$prevent_switching = apply_filters( 'wcml_switch_currency_exception', false, $current_currency, $language_default_currency, true );

				$this->client_currency = $language_default_currency;
				if ( ! array_key_exists( 'force_switch', $_POST ) && $prevent_switching ) {
					$this->switching_currency_html = $prevent_switching['prevent_switching'];
				}
			}
		}

		// edit order page.
		if ( isset( $_SERVER['HTTP_REFERER'] ) ) {
			$arg = parse_url( $_SERVER['HTTP_REFERER'] );
			if ( isset( $arg['query'] ) ) {
				parse_str( $arg['query'], $arg );
				if ( isset( $arg['post'] ) && get_post_type( $arg['post'] ) == 'shop_order' ) {
					$this->client_currency = get_post_meta( $arg['post'], '_order_currency', true );
				}
			}
		}

		// client currency in general / if enabled for this language.
		if ( is_null( $this->client_currency ) && ! empty( $woocommerce->session ) ) {
			$session_currency = $woocommerce->session->get( 'client_currency' );
			if ( $session_currency && ! empty( $this->currencies[ $session_currency ]['languages'][ $current_language ] ) ) {

				$this->client_currency = $woocommerce->session->get( 'client_currency' );
			}
		}

		if ( is_null( $this->client_currency ) ) {
			$woocommerce_currency = wcml_get_woocommerce_currency_option();

			// fall on WC currency if enabled for this language.
			if ( ! empty( $this->currencies[ $woocommerce_currency ]['languages'][ $current_language ] ) ) {
				$this->client_currency = $woocommerce_currency;
			} else {
				// first currency enabled for this language.
				foreach ( $this->currencies as $code => $data ) {
					if ( ! empty( $data['languages'][ $current_language ] ) ) {
						$this->client_currency = $code;
						break;
					}
				}
			}
		}

		$this->client_currency = apply_filters( 'wcml_client_currency', $this->client_currency );

		if ( ! empty( $woocommerce->session ) && $this->client_currency ) {
			$woocommerce->session->set( 'client_currency', $this->client_currency );
			$woocommerce->session->set( 'client_currency_language', $current_language );
		}

		return $this->client_currency;
	}

	/**
	 * @param null|string $client_currency
	 * @param WC_Session|WC_Session_Handler $woocommerce_session
	 *
	 * @return null|string
	 */
	public function maybe_get_currency_by_geolocation( $client_currency, $woocommerce_session ) {

	    if( Geolocation::MODE_BY_LOCATION !== $this->woocommerce_wpml->get_setting( 'currency_mode' ) ){
		    return $client_currency;
        }

		$currency_not_in_session = is_null( $client_currency ) && ( empty( $woocommerce_session ) || ! $woocommerce_session->get( 'client_currency' ) );
		$is_checkout_ajax        = is_ajax() && isset( $_GET['wc-ajax'] ) && 'update_order_review' === $_GET['wc-ajax'];

		if ( $currency_not_in_session || $is_checkout_ajax ) {
			$location_currency = $this->get_currency_by_geolocation();
			if ( $location_currency ) {
				return $location_currency;
			}
		}

		return $client_currency;
	}

	/**
	 * @return bool|string
	 */
	private function get_currency_by_geolocation() {

		$cache_group = 'WCML_Multi_Currency';
		$wpml_cache  = new WPML_WP_Cache( $cache_group );
		wp_cache_add_non_persistent_groups( $cache_group );

		$cache_key         = 'location_currency';
		$found             = false;
		$location_currency = $wpml_cache->get( $cache_key, $found );

		if ( ! $found ) {
			$location_currency = Geolocation::getCurrencyCodeByUserCountry();

			if (
				! $this->is_currency_active( $location_currency ) ||
				! Geolocation::isCurrencyAvailableForCountry( $this->woocommerce_wpml->settings['currency_options'][ $location_currency ] )
			) {
				$location_currency = Geolocation::getFirstAvailableCountryCurrencyFromSettings( $this->woocommerce_wpml->settings['currency_options'] );
			}

			$wpml_cache->set( $cache_key, $location_currency );
		}

		return $location_currency;
	}

	public function maybe_show_switching_currency_prompt_dialog() {
		if ( $this->switching_currency_html ) {
			echo $this->switching_currency_html;
		}
	}

	public function maybe_reset_cart_fragments() {
		global $woocommerce;

		if ( ! empty( $woocommerce->session ) && $woocommerce->session->get( 'client_currency_switched' ) ) {
			?>
			<script type="text/javascript">
				jQuery(function () {
					wcml_reset_cart_fragments();
				});
			</script>
			<?php
			$woocommerce->session->set( 'client_currency_switched', false );
		}

	}

	public function set_client_currency( $currency ) {
		global $woocommerce, $sitepress;

		$this->client_currency = $currency;

		$woocommerce->session->set( 'client_currency', $currency );
		$woocommerce->session->set( 'client_currency_language', $sitepress->get_current_language() );

		do_action( 'wcml_set_client_currency', $currency );

	}

	public function switch_currency() {

		$currency     = filter_input( INPUT_POST, 'currency', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$force_switch = filter_input( INPUT_POST, 'force_switch', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		parse_str( filter_input( INPUT_POST, 'params', FILTER_SANITIZE_STRING ), $params );
		$from_currency = $this->client_currency;

		do_action( 'wcml_before_switch_currency', $currency, $force_switch );

		if ( ! $force_switch && apply_filters( 'wcml_switch_currency_exception', false, $from_currency, $currency ) ) {
			die();
		}

		$this->set_client_currency( $currency, $force_switch );

		// force set user cookie when user is not logged in.
		global $woocommerce, $current_user;
		if ( empty( $woocommerce->session->data ) && empty( $current_user->ID ) ) {
			$woocommerce->session->set_customer_session_cookie( true );
		}

		$woocommerce->session->set( 'client_currency_switched', true );

		do_action( 'wcml_switch_currency', $currency );

		$response = $this->prices->filter_pre_selected_widget_prices_in_new_currency( [], $currency, $from_currency, $params );

		wp_send_json_success( $response );
	}

	public function get_currencies_without_cents() {

		return apply_filters( 'wcml_currencies_without_cents', $this->currencies_without_cents );
	}

	/**
	 * @param string $language
	 *
	 * @return string|bool
	 */
	public function get_language_default_currency( $language ) {

		$default_currencies = $this->woocommerce_wpml->settings['default_currencies'];

		if ( isset( $default_currencies[ $language ] ) ) {
			if ( 'location' === $default_currencies[ $language ] ) {
				$location_currency = $this->get_currency_by_geolocation();
				if ( $location_currency ) {
					return $location_currency;
				}
			} else {
				return $default_currencies[ $language ];
			}
		}

		return false;
	}
}

