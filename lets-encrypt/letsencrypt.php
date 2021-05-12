<?php
defined('ABSPATH') or die("you do not have access to this page!");
/**
 * Capability handling for lets encrypt
 * @return bool
 */
if (!function_exists('rsssl_letsencrypt_generation_allowed')) {
	function rsssl_letsencrypt_generation_allowed() {
		if (version_compare(PHP_VERSION, '7.3', '<')) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) || wp_doing_cron() ) {
			return true;
		}

		return false;
	}
}



if ( rsssl_letsencrypt_generation_allowed() ) {

	class RSSSL_LETSENCRYPT {
		private static $instance;

		public $wizard;
		public $field;
		public $config;
		public $letsencrypt_handler;

		private function __construct() {

		}

		public static function instance() {
			if ( ! isset( self::$instance ) && ! ( self::$instance instanceof RSSSL_LETSENCRYPT ) ) {
				self::$instance = new RSSSL_LETSENCRYPT;
				self::$instance->setup_constants();
				self::$instance->includes();
				if ( is_admin() || wp_doing_cron() ) {
					self::$instance->field               = new rsssl_field();
					self::$instance->wizard              = new rsssl_wizard();
					self::$instance->config              = new rsssl_config();
					self::$instance->letsencrypt_handler = new rsssl_letsencrypt_handler();
				}
				self::$instance->hooks();
			}

			return self::$instance;
		}

		private function setup_constants() {
			define('rsssl_le_url', plugin_dir_url(__FILE__));
			define('rsssl_le_path', trailingslashit(plugin_dir_path(__FILE__)));
			define('rsssl_le_wizard_path', trailingslashit(plugin_dir_path(__FILE__)).'/wizard/');
		}

		private function includes() {
			require_once( rsssl_le_path . 'cron.php' );

			if ( is_admin() || wp_doing_cron() ) {
				require_once( rsssl_le_path . 'wizard/assets/icons.php' );
				require_once( rsssl_le_path . 'wizard/class-field.php' );
				require_once( rsssl_le_path . 'wizard/class-wizard.php' );
				require_once( rsssl_le_path . 'wizard/config/class-config.php' );
				require_once( rsssl_le_path . 'class-letsencrypt-handler.php' );
			}
		}

		private function hooks() {


		}

		/**
		 * Notice about possible compatibility issues with add ons
		 */
		public static function admin_notices() {

		}
	}

	function RSSSL_LE() {
		return RSSSL_LETSENCRYPT::instance();
	}

	add_action( 'plugins_loaded', 'RSSSL_LE', 9 );
}

