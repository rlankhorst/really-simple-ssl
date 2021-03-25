<?php
defined('ABSPATH') or die("you do not have access to this page!");

class rsssl_admin extends rsssl_front_end
{

    private static $_this;
    public $wpconfig_siteurl_not_fixed = FALSE;
    public $no_server_variable = FALSE;
    public $errors = Array();
    public $tasks = array();

    public $do_wpconfig_loadbalancer_fix = FALSE;
    public $site_has_ssl = FALSE;
    public $ssl_enabled = FALSE;

    //multisite variables
    public $sites = Array(); //for multisite, list of all activated sites.

    //general settings
    public $capability = 'activate_plugins';

    public $htaccess_test_success = FALSE;
    public $plugin_version = rsssl_version; //deprecated, but used in pro plugin until 1.0.25

    public $plugin_dir = "really-simple-ssl";
    public $plugin_filename = "rlrsssl-really-simple-ssl.php";
    public $ABSpath;
    public $do_not_edit_htaccess = FALSE;
    public $javascript_redirect = FALSE;
    public $htaccess_redirect = FALSE;
    public $htaccess_warning_shown = FALSE;
    public $review_notice_shown = FALSE;
    public $dismiss_review_notice = FALSE;
    public $ssl_success_message_shown = FALSE;
    public $hsts = FALSE;
    public $debug = TRUE;
    public $debug_log;

    public $plugin_db_version;
    public $ssl_type = "NA";
    public $dismiss_all_notices = false;
    public $pro_url;

    function __construct()
    {

	    if (isset(self::$_this))
            wp_die(sprintf(__('%s is a singleton class and you cannot create a second instance.', 'really-simple-ssl'), get_class($this)));

        self::$_this = $this;

        $this->ABSpath = $this->getABSPATH();
        $this->get_options();
        $this->get_admin_options();

        $this->get_plugin_upgraded(); //call always, otherwise db version will not match anymore.

	    if (is_multisite()) {
	        $this->pro_url = 'https://really-simple-ssl.com/pro-multisite';
        } else {
	        $this->pro_url = 'https://really-simple-ssl.com/pro';
        }

        register_deactivation_hook(dirname(__FILE__) . "/" . $this->plugin_filename, array($this, 'deactivate'));
	    add_action('admin_init', array($this, 'add_privacy_info'));
	    add_action('admin_init', array($this, 'maybe_dismiss_review_notice'));
	    add_action( 'admin_init', array($this, 'insert_secure_cookie_settings'), 70);

	    add_action( "update_option_rlrsssl_options", array( $this, "maybe_clear_transients" ), 10, 3 );

	    // Only show deactivate popup when SSL has been enabled.
	    if ($this->ssl_enabled) {
            add_action('admin_footer', array($this, 'deactivate_popup'), 40);
        }
    }

    static function this()
    {
        return self::$_this;
    }

	/**
	 * @param $oldvalue
	 * @param $newvalue
	 * @param $option
	 */
    public function maybe_clear_transients($oldvalue, $newvalue, $option){
        if ($oldvalue !== $newvalue ) {
            $this->clear_transients();
        }
    }

	/**
	 * Clear some transients
	 */

    public function clear_transients(){
	    delete_transient('rsssl_mixed_content_fixer_detected');
	    delete_transient('rsssl_plusone_count');
	    delete_transient('rsssl_remaining_task_count');
    }

	/**
	 * Add some privacy info, telling our users we aren't tracking them
	 */

    public function add_privacy_info()
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content = sprintf(
            __('Really Simple SSL and Really Simple SSL add-ons do not process any personal identifiable information, so the GDPR does not apply to these plugins or usage of these plugins on your website. You can find our privacy policy <a href="%s" target="_blank">here</a>.', 'really-simple-ssl'),
            'https://really-simple-ssl.com/privacy-statement/'
        );

        wp_add_privacy_policy_content(
            'Really Simple SSL',
            wp_kses_post(wpautop($content, false))
        );
    }

	/**
	 * Dismiss review notice of dismissed by the user
	 */

    public function maybe_dismiss_review_notice() {
	    if (isset($_GET['rsssl_dismiss_review_notice'])){
		    $this->review_notice_shown = true;
		    $this->save_options();
	    }
    }

    /**
     * Initializes the admin class
     *
     * @since  2.2
     *
     * @access public
     *
     */

    public function init()
    {
        if (!current_user_can($this->capability)) return;
        $is_on_settings_page = $this->is_settings_page();

        if (defined("RSSSL_FORCE_ACTIVATE") && RSSSL_FORCE_ACTIVATE) {
            $options = get_option('rlrsssl_options');
            $options['ssl_enabled'] = true;
            update_option('rlrsssl_options', $options);
        }

        /*
         * check if we're one minute past the activation. Then flush rewrite rules
         * this way we lower the memory impact on activation
         * Flush should happen on shutdown, not on init, as often happens in other plugins
         * https://codex.wordpress.org/Function_Reference/flush_rewrite_rules
         * */

        $activation_time = get_option('rsssl_flush_rewrite_rules');
        $more_than_one_minute_ago = $activation_time < strtotime("-1 minute");
        $less_than_5_minutes_ago = $activation_time > strtotime("-5 minute");
        if (get_option('rsssl_flush_rewrite_rules') && $more_than_one_minute_ago && $less_than_5_minutes_ago){
            delete_option('rsssl_flush_rewrite_rules');
            add_action('shutdown', 'flush_rewrite_rules');
        }

        // Set default progress toggle to remaining tasks if it hasn't been set
        if (!get_option('rsssl_all_tasks') && !get_option('rsssl_remaining_tasks') ) {
            update_option('rsssl_remaining_tasks', true);
        }

        /*
        Detect configuration when:
        - SSL activation just confirmed.
        - on settings page
        - No SSL detected
        */

        //when configuration should run again
        if ($this->clicked_activate_ssl() || !$this->ssl_enabled || !$this->site_has_ssl || $is_on_settings_page || is_network_admin() || defined('RSSSL_DOING_SYSTEM_STATUS') ) {
            if (is_multisite()) $this->build_domain_list();//has to come after clicked_activate_ssl, otherwise this domain won't get counted.
            $this->detect_configuration();

            //flush caches when just activated ssl
            //flush the permalinks
            if ($this->clicked_activate_ssl()) {
	            if (!defined('RSSSL_NO_FLUSH') || !RSSSL_NO_FLUSH) {
                    update_option('rsssl_flush_rewrite_rules', time());
                }
                add_action('admin_init', array(RSSSL()->rsssl_cache, 'flush'), 40);
            }

            if (!$this->wpconfig_ok()) {
                //if we were to activate ssl, this could result in a redirect loop. So warn first.
                add_action("admin_notices", array($this, 'show_notice_wpconfig_needs_fixes'));
                if (is_multisite()) add_action('network_admin_notices', array($this, 'show_notice_wpconfig_needs_fixes'), 10);
                $this->ssl_enabled = false;
                $this->save_options();
            } elseif ($this->ssl_enabled) {
                add_action('init', array($this, 'configure_ssl'), 20);
            }
        }

        add_action( 'admin_init', array( $this, 'check_upgrade' ), 10, 2 );

        //when SSL is enabled, and not enabled by user, ask for activation.
        add_action("admin_notices", array($this, 'show_notice_activate_ssl'), 10);
        add_action('rsssl_activation_notice', array($this, 'ssl_detected'), 10);
        add_action('rsssl_activation_notice_inner', array($this, 'almost_ready_to_migrate'), 30);
        add_action('rsssl_activation_notice_footer', array($this, 'show_enable_ssl_button'), 50);

        //add the settings page for the plugin
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

        //settings page, form  and settings link in the plugins page
        add_action('admin_menu', array($this, 'add_settings_page'), 40);
	    add_action('admin_init', array($this, 'create_form'), 40);
        add_action('admin_init', array($this, 'listen_for_deactivation'), 40);
        add_action( 'update_option_rlrsssl_options', array( $this, 'maybe_remove_highlight_from_url' ), 50 );

        $plugin = rsssl_plugin;
        add_filter("plugin_action_links_$plugin", array($this, 'plugin_settings_link'));

        //Add update notification to Settings admin menu
        add_action('admin_menu', array($this, 'rsssl_edit_admin_menu') );


        //callbacks for the ajax dismiss buttons
        add_action('wp_ajax_dismiss_success_message', array($this, 'dismiss_success_message_callback'));
        add_action('wp_ajax_rsssl_dismiss_review_notice', array($this, 'dismiss_review_notice_callback'));
        add_action('wp_ajax_rsssl_dismiss_settings_notice', array($this, 'dismiss_settings_notice_callback'));
        add_action('wp_ajax_rsssl_update_task_toggle_option', array($this, 'update_task_toggle_option'));

        //handle notices
        add_action('admin_notices', array($this, 'show_notices'));
        //show review notice, only to free users
        if (!defined("rsssl_pro_version") && (!defined("rsssl_pp_version")) && (!defined("rsssl_soc_version")) && (!class_exists('RSSSL_PRO')) && (!is_multisite())) {
            add_action('admin_notices', array($this, 'show_leave_review_notice'));
        }
        add_action("update_option_rlrsssl_options", array($this, "update_htaccess_after_settings_save"), 20, 3);
    }

    public function check_upgrade() {
	    $prev_version = get_option( 'rsssl_current_version', false );
        if ( $prev_version && version_compare( $prev_version, '4.0', '<' ) ) {
            update_option('rsssl_remaining_tasks', true);
        }

        if ( $prev_version && version_compare( $prev_version, '4.0.10', '<=' ) ) {
            if (function_exists('is_wpe') && is_wpe()) {
                $this->wp_redirect = true;
                $this->htaccess_redirect = false;
                $this->save_options();
            }
        }

        update_option( 'rsssl_current_version', rsssl_version );
    }

    /**
     * Deactivate the plugin while keeping SSL
     * Activated when the 'uninstall_keep_ssl' button is clicked in the settings tab
     *
     */

    public function listen_for_deactivation()
    {
        //check user role
        if (!current_user_can($this->capability)) return;

        //check nonce
        if (!isset($_GET['token']) || (!wp_verify_nonce($_GET['token'], 'rsssl_deactivate_plugin'))) return;

        //check for action
        if (isset($_GET["action"]) && $_GET["action"] == 'uninstall_keep_ssl') {
            //deactivate plugin, but don't revert to http.
            $plugin = $this->plugin_dir . "/" . $this->plugin_filename;
            $plugin = plugin_basename(trim($plugin));

            if (is_multisite()) {
                $network_current = get_site_option('active_sitewide_plugins', array());
                if (is_plugin_active_for_network($plugin)) {
                    unset($network_current[$plugin]);
                }
                update_site_option('active_sitewide_plugins', $network_current);

                //remove plugin one by one on each site
                $sites = get_sites();
                foreach ($sites as $site) {
                    RSSSL()->rsssl_multisite->switch_to_blog_bw_compatible($site);

                    $current = get_option('active_plugins', array());
                    $current = $this->remove_plugin_from_array($plugin, $current);
                    update_option('active_plugins', $current);

                    restore_current_blog(); //switches back to previous blog, not current, so we have to do it each loop
                }
            } else {
                $current = get_option('active_plugins', array());
                $current = $this->remove_plugin_from_array($plugin, $current);
                update_option('active_plugins', $current);
            }
            wp_redirect(admin_url('plugins.php'));
            exit;
        }
    }

    /**
     * Remove the plugin from the active plugins array when called from listen_for_deactivation
     *
     * */

    public function remove_plugin_from_array($plugin, $current)
    {
        $key = array_search($plugin, $current);
        if (false !== $key) {
            unset($current[$key]);
        }
        return $current;
    }

    /**
     * @Since 3.1
     *
     * Check if site uses an htaccess.conf file, used in bitnami installations
     *
     */

    public function uses_htaccess_conf() {
        $htaccess_conf_file = dirname(ABSPATH) . "/conf/htaccess.conf";
        //conf/htaccess.conf can be outside of open basedir, return false if so
        $open_basedir = ini_get("open_basedir");

        if (!empty($open_basedir)) return false;

        if (is_file($htaccess_conf_file) ) {
            return true;
        } else {
            return false;
        }
    }

    public function get_sites_bw_compatible()
    {
        global $wp_version;
        $sites = ($wp_version >= 4.6) ? get_sites() : wp_get_sites();
        return $sites;
    }

	/**
     * The new get_sites function returns an object.
	 * @param $site
	 */

    public function switch_to_blog_bw_compatible($site)
    {

        global $wp_version;
        if ($wp_version >= 4.6) {
            switch_to_blog($site->blog_id);
        } else {
            switch_to_blog($site['blog_id']);
        }
    }


    /**
     *   checks if the user just clicked the "activate SSL" button.
    */

    private function clicked_activate_ssl()
    {
        if (!current_user_can($this->capability)) return;

        if (isset($_POST['rsssl_do_activate_ssl'])) {
            $this->activate_ssl();
            update_option('rsssl_activation_timestamp', time());

            return true;
        }

        return false;
    }


	/**
	 *  Activate the SSL for this site
	 */

    public function activate_ssl()
    {
        $this->ssl_enabled = true;
        $this->wp_redirect = true;
        $this->set_siteurl_to_ssl();
        $this->save_options();
    }

	/**
	 * Deactivate SSL for this site
	 */

    public function deactivate_ssl()
    {
        //only revert if SSL was enabled first.
        if ($this->ssl_enabled) {
	        $this->ssl_enabled       = false;
	        $this->wp_redirect       = false;
	        $this->htaccess_redirect = false;
	        $this->remove_ssl_from_siteurl();
	        $this->save_options();
        }
    }

	/**
	 * redirect to settings page
	 */

    public function redirect_to_settings_page() {
        if (isset($_GET['page']) && $_GET['page'] == 'rlrsssl_really_simple_ssl') return;
	        $url = add_query_arg( array(
		        "page" => "rlrsssl_really_simple_ssl",
                "tab" => "configuration",
	        ), admin_url( "options-general.php" ) );
	        wp_redirect( $url );
	        exit;
    }


    public function wpconfig_ok()
    {

        if (($this->do_wpconfig_loadbalancer_fix || $this->no_server_variable || $this->wpconfig_siteurl_not_fixed) && !$this->wpconfig_is_writable()) {
            $result = false;
        } else {
            $result = true;
        }

        return apply_filters('rsssl_wpconfig_ok_check', $result);
    }

    /**
      This message is shown when SSL is not enabled by the user yet
      */

    public function show_notice_activate_ssl()
    {
        //prevent showing the review on edit screen, as gutenberg removes the class which makes it editable.
        $screen = get_current_screen();
	    if ( $screen->base === 'post' ) return;

        if ($this->ssl_enabled) return;

        if (defined("RSSSL_DISMISS_ACTIVATE_SSL_NOTICE") && RSSSL_DISMISS_ACTIVATE_SSL_NOTICE) return;

        //for multisite, show only activate when a choice has been made to activate networkwide or per site.
        if (is_multisite() && !RSSSL()->rsssl_multisite->selected_networkwide_or_per_site) return;

        //on multisite, only show this message on the network admin. Per site activated sites have to go to the settings page.
        //otherwise sites that do not need SSL possibly get to see this message.

        if (is_multisite() && !is_network_admin()) return;

        if (!$this->wpconfig_ok()) return;

        if (!current_user_can($this->capability)) return;

        do_action('rsssl_activation_notice');

    }

	/**
	 *  Show a notice that the website is ready to migrate to SSL.
	 */

    public function ssl_detected()
    {
	    if ($this->site_has_ssl || (defined('RSSSL_FORCE_ACTIVATE') && RSSSL_FORCE_ACTIVATE)) {

	        ob_start();
	        do_action('rsssl_activation_notice_inner');
	        $content = ob_get_clean();

            ob_start();
            do_action('rsssl_activation_notice_footer');
            $footer = ob_get_clean();

	        $class = apply_filters("rsssl_activation_notice_classes", "updated activate-ssl rsssl-pro-dismiss-notice");
	        $title = __("Almost ready to migrate to SSL!", "really-simple-ssl");
	        echo $this->notice_html( $class, $title, $content, $footer);
        }
    }

	/**
	 * Show almost ready to migrate notice
	 */
	public function almost_ready_to_migrate()
	{
		_e("Before you migrate, please check for: ", 'really-simple-ssl'); ?>
        <p>
            <ul>
                <li><?php _e('Http references in your .css and .js files: change any http:// into https://', 'really-simple-ssl'); ?></li>
                <li><?php _e('Images, stylesheets or scripts from a domain without an SSL certificate: remove them or move to your own server', 'really-simple-ssl'); ?></li><?php

                $backup_link = "https://really-simple-ssl.com/knowledge-base/backing-up-your-site/";
                $link_open = '<a target="_blank" href="'.$backup_link.'">';
                $link_close = '</a>';
                ?>
                <li><?php printf(__("We strongly recommend to create a %sbackup%s of your site before activating SSL", 'really-simple-ssl'), $link_open, $link_close); ?> </li>
                <li><?php _e("You may need to login in again.", "really-simple-ssl") ?></li>
            </ul>
        </p>
        <p><?php
			if (!defined('rsssl_pro_version')) {
			_e('You can also let the automatic scan of the pro version handle this for you, and get premium support, increased security with HSTS and more!', 'really-simple-ssl');
			?>
            <a target="_blank"
               href="<?php
			   echo $this->pro_url; ?>"><?php _e("Check out Really Simple SSL Pro", "really-simple-ssl");
				}
				?>
            </a>
        </p>

		<?php
	}


	/**
	 * @param string $class
	 * @param string $title
	 * @param string $content
	 * @param string|bool $footer
	 * @return false|string
	 *
	 * @since 4.0
	 * Return the notice HTML
	 *
	 */

	public function notice_html($class, $title, $content, $footer=false) {

		ob_start();
		?>
        <?php if ( is_rtl() ) { ?>
            <style>
                #rsssl-message .error{
                    border-right-color:#d7263d;
                }
                .activate-ssl {
                    border-right: 4px solid #F8BE2E;
                }
                .activate-ssl .button {
                    margin-bottom: 5px;
                }

                #rsssl-message .button-primary {
                    margin-left: 10px;
                }

                .rsssl-notice-header {
                    height: 60px;
                    border-bottom: 1px solid #dedede;
                    display: flex;
                    flex-direction: row;
                    justify-content: space-between;
                    align-items: center;
                    padding-right: 25px;
                }
                .rsssl-notice-header h1 {
                    font-weight: bold;
                }

                .rsssl-notice-content {
                    margin-top: 20px;
                    padding-bottom: 20px;
                    padding-right: 25px;
                }

                .rsssl-notice-footer {
                    border-top: 1px solid #dedede;
                    height: 35px;
                    display: flex;
                    align-items: center;
                    padding-top: 10px;
                    padding-bottom: 10px;
                    margin-right: 25px;
                    margin-left: 25px;
                }

                #rsssl-message {
                    padding: 0;
                    border-right-color: #333;
                }

                #rsssl-message .rsssl-notice-li::before {
                    vertical-align: middle;
                    margin-left: 25px;
                    color: lightgrey;
                    content: "\f345";
                    font: 400 21px/1 dashicons;
                }

                #rsssl-message ul {
                    list-style: none;
                    list-style-position: inside;
                }
                #rsssl-message li {
                    margin-right:30px;
                    margin-bottom:10px;
                }
                #rsssl-message li:before {
                    background-color: #f8be2e;
                    color: #fff;
                    height: 10px;
                    width: 10px;
                    border-radius:50%;
                    content: '';
                    position: absolute;
                    margin-top: 5px;
                    margin-right:-30px;
                }

                .settings_page_rlrsssl_really_simple_ssl #wpcontent #rsssl-message, .settings_page_really-simple-ssl #wpcontent #rsssl-message {
                    margin: 20px;
                }
                <?php echo apply_filters('rsssl_pro_inline_style', ''); ?>
            </style>
        <?php } else { ?>
            <style>
                #rsssl-message .error{
                    border-left-color:#d7263d;
                }
                .activate-ssl {
                    border-left: 4px solid #F8BE2E;
                }
                .activate-ssl .button {
                    margin-bottom: 5px;
                }

                #rsssl-message .button-primary {
                    margin-right: 10px;
                }

                .rsssl-notice-header {
                    height: 60px;
                    border-bottom: 1px solid #dedede;
                    display: flex;
                    flex-direction: row;
                    justify-content: space-between;
                    align-items: center;
                    padding-left: 25px;
                }
                .rsssl-notice-header h1 {
                    font-weight: bold;
                }

                .rsssl-notice-content {
                    margin-top: 20px;
                    padding-bottom: 20px;
                    padding-left: 25px;
                }

                .rsssl-notice-footer {
                    border-top: 1px solid #dedede;
                    height: 35px;
                    display: flex;
                    align-items: center;
                    padding-top: 10px;
                    padding-bottom: 10px;
                    margin-left: 25px;
                    margin-right: 25px;
                }

                #rsssl-message {
                    padding: 0;
                    border-left-color: #333;
                }

                #rsssl-message .rsssl-notice-li::before {
                    vertical-align: middle;
                    margin-right: 25px;
                    color: lightgrey;
                    content: "\f345";
                    font: 400 21px/1 dashicons;
                }

                #rsssl-message ul {
                    list-style: none;
                    list-style-position: inside;
                }
                #rsssl-message li {
                    margin-left:30px;
                    margin-bottom:10px;
                }
                #rsssl-message li:before {
                    background-color: #f8be2e;
                    color: #fff;
                    height: 10px;
                    width: 10px;
                    border-radius:50%;
                    content: '';
                    position: absolute;
                    margin-top: 5px;
                    margin-left:-30px;
                }

                .settings_page_rlrsssl_really_simple_ssl #wpcontent #rsssl-message, .settings_page_really-simple-ssl #wpcontent #rsssl-message {
                    margin: 20px;
                }
                <?php echo apply_filters('rsssl_pro_inline_style', ''); ?>
            </style>
        <?php } ?>
        <div id="rsssl-message" class="notice <?php echo $class?> really-simple-plugins">
            <div class="rsssl-notice">
                <div class="rsssl-notice-header">
                    <h1><?php echo $title ?></h1>
                </div>
                <div class="rsssl-notice-content">
					<?php echo $content ?>
                </div>
				<?php
				if ($footer ) { ?>
                    <div class="rsssl-notice-footer">
						<?php echo $footer;?>
                    </div>
				<?php } ?>
            </div>
        </div>
		<?php

		$content = ob_get_clean();
		return $content;
	}


    /**
     * @since 2.3
     * Returns button to enable SSL.
     * @access public
     */

    public function show_enable_ssl_button()
    {
        ?>
            <form action="" method="post">
                <?php wp_nonce_field('rsssl_nonce', 'rsssl_nonce'); ?>
                <input type="submit" class='button button-primary'
                       value="<?php _e("Go ahead, activate SSL!", "really-simple-ssl"); ?>" id="rsssl_do_activate_ssl"
                       name="rsssl_do_activate_ssl">
                <?php if (!defined("rsssl_pro_version") ) { ?>
                <a class="button button-default" href="<?php echo $this->pro_url ?>" target="_blank"><?php _e("Get ready with PRO!", "really-simple-ssl"); ?></a>
                <?php } ?>
            </form>
        <?php
    }

    /**
     * @return bool
     *
     * Check if wp-config.php is writeable
     *
     * @access public
     */

    public function wpconfig_is_writable()
    {
        $wpconfig_path = $this->find_wp_config_path();
        if (is_writable($wpconfig_path))
            return true;
        else
            return false;
    }

	/**
     * Check if the uninstall file is renamed to .php
     *
	 * @return string
	 */

    public function check_for_uninstall_file()
    {
        if (file_exists(dirname(__FILE__) . '/force-deactivate.php')) {
            return 'fail';
        }
        return 'success';
    }

    /**
     * Get the options for this plugin
     *
     * @since  2.0
     *
     * @access public
     *
     */

    public function get_admin_options()
    {

        $options = get_option('rlrsssl_options');

        if (isset($options)) {
            $this->site_has_ssl = isset($options['site_has_ssl']) ? $options['site_has_ssl'] : FALSE;
            $this->hsts = isset($options['hsts']) ? $options['hsts'] : FALSE;
            $this->htaccess_warning_shown = isset($options['htaccess_warning_shown']) ? $options['htaccess_warning_shown'] : FALSE;
            $this->review_notice_shown = isset($options['review_notice_shown']) ? $options['review_notice_shown'] : FALSE;
            $this->ssl_success_message_shown = isset($options['ssl_success_message_shown']) ? $options['ssl_success_message_shown'] : FALSE;
            $this->plugin_db_version = isset($options['plugin_db_version']) ? $options['plugin_db_version'] : "1.0";
            $this->do_not_edit_htaccess = isset($options['do_not_edit_htaccess']) ? $options['do_not_edit_htaccess'] : FALSE;
            $this->htaccess_redirect = isset($options['htaccess_redirect']) ? $options['htaccess_redirect'] : FALSE;
            $this->switch_mixed_content_fixer_hook = isset($options['switch_mixed_content_fixer_hook']) ? $options['switch_mixed_content_fixer_hook'] : FALSE;
	        $this->dismiss_all_notices = isset($options['dismiss_all_notices']) ? $options['dismiss_all_notices'] : FALSE;
	        $this->debug_log = isset($options['debug_log']) ? $options['debug_log'] : $this->debug_log;
            $this->dismiss_review_notice = isset($options['dismiss_review_notice']) ? $options['dismiss_review_notice'] : $this->dismiss_review_notice;
        }

        if (is_multisite()) {
            $network_options = get_site_option('rlrsssl_network_options');
            $network_htaccess_redirect = isset($network_options["htaccess_redirect"]) ? $network_options["htaccess_redirect"] : false;
            $network_do_not_edit_htaccess = isset($network_options["do_not_edit_htaccess"]) ? $network_options["do_not_edit_htaccess"] : false;
            /*
              If multiste, and networkwide, only the networkwide setting counts.
              if multisite, and per site, only the networkwide setting counts if it is true.
           */
            $ssl_enabled_networkwide = isset($network_options["ssl_enabled_networkwide"]) ? $network_options["ssl_enabled_networkwide"] : false;
            if ($ssl_enabled_networkwide) {
                $this->htaccess_redirect = $network_htaccess_redirect;
                $this->do_not_edit_htaccess = $network_do_not_edit_htaccess;
            } else {
                if ($network_do_not_edit_htaccess) $this->do_not_edit_htaccess = $network_do_not_edit_htaccess;
                if ($network_htaccess_redirect) $this->htaccess_redirect = $network_htaccess_redirect;
            }
        }

        //if the define is true, it overrides the db setting.
        if (defined('RLRSSSL_DO_NOT_EDIT_HTACCESS')) {
            $this->do_not_edit_htaccess = RLRSSSL_DO_NOT_EDIT_HTACCESS;
        }

    }

    /**
     * Creates an array of all domains where the plugin is active AND SSL is active, only used for multisite.
     *
     * @since  2.1
     *
     * @access public
     *
     */

    public function build_domain_list()
    {
        if (!is_multisite()) return;

        $this->sites = get_transient('rsssl_domain_list');
        if (!$this->sites) {

            //create list of all activated sites with SSL
            $this->sites = array();
            $nr_of_sites = RSSSL()->rsssl_multisite->get_total_blog_count();
            $sites = RSSSL()->rsssl_multisite->get_sites_bw_compatible(0, $nr_of_sites);

            $this->trace_log("building domain list for multisite...");
            $has_sites_with_ssl = false;
            foreach ($sites as $site) {
                $this->switch_to_blog_bw_compatible($site);
                $options = get_option('rlrsssl_options');

                $ssl_enabled = FALSE;
                if (isset($options)) {
                    $site_has_ssl = isset($options['site_has_ssl']) ? $options['site_has_ssl'] : FALSE;
                    $ssl_enabled = isset($options['ssl_enabled']) ? $options['ssl_enabled'] : $site_has_ssl;
                }

                if (is_plugin_active(rsssl_plugin) && $ssl_enabled) {
                    $this->trace_log("- adding: " . home_url());
                    $this->sites[] = home_url();
	                $has_sites_with_ssl = true;
                }
                restore_current_blog(); //switches back to previous blog, not current, so we have to do it each loop
            }

            if (!$has_sites_with_ssl) $this->trace_log("- SSL not enabled on any site " );

            set_transient('rsssl_domain_list', $this->sites, HOUR_IN_SECONDS);

            $this->save_options();
        }
    }

    /**
     * check if the plugin was upgraded to a new version
     *
     * @since  2.1
     *
     * @access public
     *
     */

    public function get_plugin_upgraded()
    {
        if ($this->plugin_db_version != rsssl_version) {
	        if ( $this->plugin_db_version !== '1.0'  && version_compare( $this->plugin_db_version, '4.0.0', '<' ) ) {
	            update_option('rsssl_upgraded_to_four', true);
	        }

	        if ( $this->plugin_db_version !== '1.0' ) {
		        $dismiss_options = $this->get_notices_list( array(
			        'dismiss_on_upgrade' => true,
		        ) );
		        foreach ($dismiss_options as $dismiss_option ) {
			        update_option( "rsssl_" . $dismiss_option . "_dismissed" , true);
		        }
		        delete_transient( 'rsssl_plusone_count' );
	        }

            $this->plugin_db_version = rsssl_version;
            $this->save_options();
        }
    }

    /**
     * Log events during plugin execution
     *
     * @param string $msg
     *
     * @since  2.1
     *
     * @access public
     *
     */

    public function trace_log($msg)
    {
	    if (defined('RSSSL_DOING_SYSTEM_STATUS') || (defined('WP_DEBUG') && WP_DEBUG ) )

        if (strpos($this->debug_log, $msg)) return;
        $this->debug_log = $this->debug_log . "\n" . $msg;
    }

    /**
     * Configures the site for SSL
     *
     * @since  2.2
     *
     * @access public
     *
     */

    public function configure_ssl()
    {
        if (!current_user_can($this->capability)) return;
        $safe_mode = FALSE;
        if (defined('RSSSL_SAFE_MODE') && RSSSL_SAFE_MODE) $safe_mode = RSSSL_SAFE_MODE;

        if (!current_user_can($this->capability)) return;
        $this->trace_log("<br>" . "<b>" . "SSL Configuration" . "</b>");
        if ($this->site_has_ssl) {
            //when one of the used server variables was found, test if the redirect works
            if (RSSSL()->rsssl_server->uses_htaccess() && $this->ssl_type != "NA") {
                $this->test_htaccess_redirect();
            }

            //in a configuration reverse proxy without a set server variable https, add code to wpconfig
            if ($this->do_wpconfig_loadbalancer_fix) {
                $this->wpconfig_loadbalancer_fix();
            }

            if ($this->no_server_variable)
                $this->wpconfig_server_variable_fix();

            if (!$safe_mode) {
                $this->editHtaccess();
            }

            if (!$safe_mode && $this->clicked_activate_ssl()) {
                $this->wp_redirect = TRUE;
                $this->save_options();
            }

            if (!$safe_mode && $this->wpconfig_siteurl_not_fixed)
                $this->fix_siteurl_defines_in_wpconfig();

            if (!$safe_mode) {
                $this->set_siteurl_to_ssl();
            }

	        if (!is_multisite()) {
		        $this->redirect_to_settings_page();
	        }

        }
    }


    /**
     * Check to see if we are on the settings page, action hook independent
     *
     * @since  2.1
     *
     * @access public
     *
     */

    public function is_settings_page()
    {
        if (!isset($_SERVER['QUERY_STRING'])) return false;

        parse_str($_SERVER['QUERY_STRING'], $params);
        if (array_key_exists("page", $params) && ($params["page"] == "rlrsssl_really_simple_ssl")) {
            return true;
        }
        return false;
    }

    /**
     * Find the path to wp-config
     *
     * @since  2.1
     *
     * @access public
     *
     */

    public function find_wp_config_path()
    {
        //limit nr of iterations to 20
        $i = 0;
        $maxiterations = 20;
        $dir = dirname(__FILE__);
        do {
            $i++;
            if (file_exists($dir . "/wp-config.php")) {
                return $dir . "/wp-config.php";
            }
        } while (($dir = realpath("$dir/..")) && ($i < $maxiterations));
        return null;
    }

    /**
     * remove https from defined siteurl and homeurl in the wpconfig, if present
     *
     * @since  2.1
     *
     * @access public
     *
     */

    public function remove_ssl_from_siteurl_in_wpconfig()
    {
        $wpconfig_path = $this->find_wp_config_path();
        if (!empty($wpconfig_path)) {
            $wpconfig = file_get_contents($wpconfig_path);

            $homeurl_pos = strpos($wpconfig, "define('WP_HOME','https://");
            $siteurl_pos = strpos($wpconfig, "define('WP_SITEURL','https://");

            if (($homeurl_pos !== false) || ($siteurl_pos !== false)) {
                if (is_writable($wpconfig_path)) {
                    $search_array = array("define('WP_HOME','https://", "define('WP_SITEURL','https://");
                    $ssl_array = array("define('WP_HOME','http://", "define('WP_SITEURL','http://");
                    //now replace these urls
                    $wpconfig = str_replace($search_array, $ssl_array, $wpconfig);
                    file_put_contents($wpconfig_path, $wpconfig);
                } else {
                    $this->errors['wpconfig not writable'] = TRUE;
                }
            }

        }
    }


    /**
     *
     *     Checks if the wp config contains any defined siteurl and homeurl
     *
     *
     */

    private function check_for_siteurl_in_wpconfig()
    {

        $wpconfig_path = $this->find_wp_config_path();

        if (empty($wpconfig_path)) return;

        $wpconfig = file_get_contents($wpconfig_path);
        $homeurl_pattern = '/(define\(\s*\'WP_HOME\'\s*,\s*\'http\:\/\/)/';
        $siteurl_pattern = '/(define\(\s*\'WP_SITEURL\'\s*,\s*\'http\:\/\/)/';

        $this->wpconfig_siteurl_not_fixed = FALSE;
        if (preg_match($homeurl_pattern, $wpconfig) || preg_match($siteurl_pattern, $wpconfig)) {
            $this->wpconfig_siteurl_not_fixed = TRUE;
            $this->trace_log("siteurl or home url defines found in wpconfig");
        }
    }


    /**
     * Runs only when siteurl or homeurl define was found in the wpconfig, with the check_for_siteurl_in_wpconfig function
     * and only when wpconfig is writable.
     *
     * @since  2.1
     *
     * @access public
     *
     */

    private function fix_siteurl_defines_in_wpconfig()
    {
        $wpconfig_path = $this->find_wp_config_path();

        if (empty($wpconfig_path)) return;

        $wpconfig = file_get_contents($wpconfig_path);
        $homeurl_pattern = '/(define\(\s*\'WP_HOME\'\s*,\s*\'http\:\/\/)/';
        $siteurl_pattern = '/(define\(\s*\'WP_SITEURL\'\s*,\s*\'http\:\/\/)/';

        if (preg_match($homeurl_pattern, $wpconfig) || preg_match($siteurl_pattern, $wpconfig)) {
            if (is_writable($wpconfig_path)) {
                $this->trace_log("wp config siteurl/homeurl edited.");
                $wpconfig = preg_replace($homeurl_pattern, "define('WP_HOME','https://", $wpconfig);
                $wpconfig = preg_replace($siteurl_pattern, "define('WP_SITEURL','https://", $wpconfig);
                file_put_contents($wpconfig_path, $wpconfig);
            } else {
                $this->trace_log("not able to fix wpconfig siteurl/homeurl.");
                //only when siteurl or homeurl is defined in wpconfig, and wpconfig is not writable is there a possible issue because we cannot edit the defined urls.
                $this->wpconfig_siteurl_not_fixed = TRUE;
            }
        } else {
            $this->trace_log("no siteurl/homeurl defines in wpconfig");
        }
    }


    /**
     * Check if the wpconfig is already fixed
     *
     * @since  2.2
     *
     * @access public
     *
     */

    public function wpconfig_has_fixes()
    {
        $wpconfig_path = $this->find_wp_config_path();
        if (empty($wpconfig_path)) return false;
        $wpconfig = file_get_contents($wpconfig_path);

        //only one of two fixes possible.
        if (strpos($wpconfig, "//Begin Really Simple SSL Load balancing fix") !== FALSE) {
            return true;
        }

        if (strpos($wpconfig, "//Begin Really Simple SSL Server variable fix") !== FALSE) {
            return true;
        }

        return false;
    }


    /**
     * In case of load balancer without server https on, add fix in wp-config
     *
     * @since  2.1
     *
     * @access public
     *
     */


    public function wpconfig_loadbalancer_fix()
    {
        if (!current_user_can($this->capability)) return;

        $wpconfig_path = $this->find_wp_config_path();
        if (empty($wpconfig_path)) return;
        $wpconfig = file_get_contents($wpconfig_path);
        $this->wpconfig_loadbalancer_fix_failed = FALSE;
        //only if loadbalancer AND NOT SERVER-HTTPS-ON should the following be added. (is_ssl = false)
        if (strpos($wpconfig, "//Begin Really Simple SSL Load balancing fix") === FALSE) {
            if (is_writable($wpconfig_path)) {
                $rule = "\n" . "//Begin Really Simple SSL Load balancing fix" . "\n";
                $rule .= 'if ((isset($_ENV["HTTPS"]) && ("on" == $_ENV["HTTPS"]))' . "\n";
                $rule .= '|| (isset($_SERVER["HTTP_X_FORWARDED_SSL"]) && (strpos($_SERVER["HTTP_X_FORWARDED_SSL"], "1") !== false))' . "\n";
                $rule .= '|| (isset($_SERVER["HTTP_X_FORWARDED_SSL"]) && (strpos($_SERVER["HTTP_X_FORWARDED_SSL"], "on") !== false))' . "\n";
                $rule .= '|| (isset($_SERVER["HTTP_CF_VISITOR"]) && (strpos($_SERVER["HTTP_CF_VISITOR"], "https") !== false))' . "\n";
                $rule .= '|| (isset($_SERVER["HTTP_CLOUDFRONT_FORWARDED_PROTO"]) && (strpos($_SERVER["HTTP_CLOUDFRONT_FORWARDED_PROTO"], "https") !== false))' . "\n";
                $rule .= '|| (isset($_SERVER["HTTP_X_FORWARDED_PROTO"]) && (strpos($_SERVER["HTTP_X_FORWARDED_PROTO"], "https") !== false))' . "\n";
                $rule .= '|| (isset($_SERVER["HTTP_X_PROTO"]) && (strpos($_SERVER["HTTP_X_PROTO"], "SSL") !== false))' . "\n";
                $rule .= ') {' . "\n";
                $rule .= '$_SERVER["HTTPS"] = "on";' . "\n";
                $rule .= '}' . "\n";
                $rule .= "//END Really Simple SSL" . "\n";

                $insert_after = "<?php";
                $pos = strpos($wpconfig, $insert_after);
                if ($pos !== false) {
                    $wpconfig = substr_replace($wpconfig, $rule, $pos + 1 + strlen($insert_after), 0);
                }

                file_put_contents($wpconfig_path, $wpconfig);
                $this->trace_log("wp config loadbalancer fix inserted");
            } else {
                $this->trace_log("wp config loadbalancer fix FAILED");
                $this->wpconfig_loadbalancer_fix_failed = TRUE;
            }
        } else {
            $this->trace_log("wp config loadbalancer fix already in place, great!");
        }
        $this->save_options();

    }


    /**
     * Getting WordPress to recognize setup as being SSL when no https server variable is available
     *
     * @since  2.1
     *
     * @access public
     *
     */

    public function wpconfig_server_variable_fix()
    {
        if (!current_user_can($this->capability)) return;

        $wpconfig_path = $this->find_wp_config_path();
        if (empty($wpconfig_path)) return;
        $wpconfig = file_get_contents($wpconfig_path);

        //check permissions
        if (!is_writable($wpconfig_path)) {
            $this->trace_log("wp-config.php not writable");
            return;
        }

        //when more than one blog, first remove what we have
        if (is_multisite() && !RSSSL()->rsssl_multisite->is_multisite_subfolder_install() && !RSSSL()->rsssl_multisite->ssl_enabled_networkwide && count($this->sites) > 1) {
            $wpconfig = preg_replace("/\/\/Begin\s?Really\s?Simple\s?SSL.*?\/\/END\s?Really\s?Simple\s?SSL/s", "", $wpconfig);
            $wpconfig = preg_replace("/\n+/", "\n", $wpconfig);
            file_put_contents($wpconfig_path, $wpconfig);
        }

        //now create new

        //check if the fix is already there
        if (strpos($wpconfig, "//Begin Really Simple SSL Server variable fix") !== FALSE) {
            $this->trace_log("wp config server variable fix already in place, great!");
            return;
        }

        $this->trace_log("Adding server variable to wpconfig");
        $rule = $this->get_server_variable_fix_code();

        $insert_after = "<?php";
        $pos = strpos($wpconfig, $insert_after);
        if ($pos !== false) {
            $wpconfig = substr_replace($wpconfig, $rule, $pos + 1 + strlen($insert_after), 0);
        }
        file_put_contents($wpconfig_path, $wpconfig);
        $this->trace_log("wp config server variable fix inserted");

        $this->save_options();
    }


    /**
     * @return string
     *
     * Get code for server variable fix
     *
     * @access protected
     *
     */

    protected function get_server_variable_fix_code()
    {
        if (is_multisite() && !RSSSL()->rsssl_multisite->ssl_enabled_networkwide && RSSSL()->rsssl_multisite->is_multisite_subfolder_install()) {
            $this->trace_log("per site activation on subfolder install, wp config server variable fix skipped");
            return "";
        }

        if (is_multisite() && !RSSSL()->rsssl_multisite->ssl_enabled_networkwide && count($this->sites) == 0) {
            $this->trace_log("no sites left with SSL, wp config server variable fix skipped");
            return "";
        }

        if (is_multisite() && !RSSSL()->rsssl_multisite->ssl_enabled_networkwide) {
            $rule = "\n" . "//Begin Really Simple SSL Server variable fix" . "\n";
            foreach ($this->sites as $domain) {
                //remove http or https.
                $this->trace_log("getting server variable rule for:" . $domain);
                $domain = preg_replace("/(http:\/\/|https:\/\/)/", "", $domain);

                //we excluded subfolders, so treat as domain
                //check only for domain without www, as the www variant is found as well with the no www search.
                $domain_no_www = str_replace("www.", "", $domain);

                $rule .= 'if ( strpos($_SERVER["HTTP_HOST"], "' . $domain_no_www . '")!==FALSE ) {' . "\n";
                $rule .= '   $_SERVER["HTTPS"] = "on";' . "\n";
                $rule .= '}' . "\n";
            }
            $rule .= "//END Really Simple SSL" . "\n";
        } else {
            $rule = "\n" . "//Begin Really Simple SSL Server variable fix" . "\n";
            $rule .= '$_SERVER["HTTPS"] = "on";' . "\n";
            $rule .= "//END Really Simple SSL" . "\n";
        }

        return $rule;
    }

    /**
     * Removing changes made to the wpconfig
     *
     * @since  2.1
     *
     * @access public
     *
     */

    public function remove_wpconfig_edit()
    {

        $wpconfig_path = $this->find_wp_config_path();
        if (empty($wpconfig_path)) return;
        $wpconfig = file_get_contents($wpconfig_path);

        //check for permissions
        if (!is_writable($wpconfig_path)) {
            $this->trace_log("could not remove wpconfig edits, wp-config.php not writable");
            $this->errors['wpconfig not writable'] = TRUE;
            return;
        }

        //remove edits
        $wpconfig = preg_replace("/\/\/Begin\s?Really\s?Simple\s?SSL.*?\/\/END\s?Really\s?Simple\s?SSL/s", "", $wpconfig);
        $wpconfig = preg_replace("/\n+/", "\n", $wpconfig);
        file_put_contents($wpconfig_path, $wpconfig);

        //in multisite environment, with per site activation, re-add
        if (is_multisite() && !RSSSL()->rsssl_multisite->ssl_enabled_networkwide) {
            if ($this->do_wpconfig_loadbalancer_fix)
                $this->wpconfig_loadbalancer_fix();

            if ($this->no_server_variable)
                $this->wpconfig_server_variable_fix();
        }

    }

    /**
     * Changes the siteurl and homeurl to https
     *
     * @since  2.0
     *
     * @access public
     *
     */

    public function set_siteurl_to_ssl()
    {
        $this->trace_log("converting siteurl and homeurl to https");

        $siteurl_ssl = str_replace("http://", "https://", get_option('siteurl'));
        $homeurl_ssl = str_replace("http://", "https://", get_option('home'));
        update_option('siteurl', $siteurl_ssl);
        update_option('home', $homeurl_ssl);

        //RSSSL has it's own, more extensive mixed content fixer.
	    update_option( 'https_migration_required', false );
    }

    /**
     * On de-activation, siteurl and homeurl are reset to http
     *
     * @since  2.0
     *
     * @access public
     *
     */

    public function remove_ssl_from_siteurl()
    {
        $siteurl_no_ssl = str_replace("https://", "http://", get_option('siteurl'));
        $homeurl_no_ssl = str_replace("https://", "http://", get_option('home'));
        update_option('siteurl', $siteurl_no_ssl);
        update_option('home', $homeurl_no_ssl);
    }

    /**
     * Save the plugin options
     *
     * @since  2.0
     *
     * @access public
     *
     */

    public function save_options()
    {
	    //any options added here should also be added to function options_validate()
        $options = array(
            'site_has_ssl' => $this->site_has_ssl,
            'hsts' => $this->hsts,
            'htaccess_warning_shown' => $this->htaccess_warning_shown,
            'review_notice_shown' => $this->review_notice_shown,
            'ssl_success_message_shown' => $this->ssl_success_message_shown,
            'autoreplace_insecure_links' => $this->autoreplace_insecure_links,
            'plugin_db_version' => $this->plugin_db_version,
            'do_not_edit_htaccess' => $this->do_not_edit_htaccess,
            'htaccess_redirect' => $this->htaccess_redirect,
            'ssl_enabled' => $this->ssl_enabled,
            'javascript_redirect' => $this->javascript_redirect,
            'wp_redirect' => $this->wp_redirect,
            'switch_mixed_content_fixer_hook' => $this->switch_mixed_content_fixer_hook,
            'dismiss_all_notices' => $this->dismiss_all_notices,
            'dismiss_review_notice' => $this->dismiss_review_notice,

        );

	    update_option('rlrsssl_options', $options);
    }


    /**
     * Handles deactivation of this plugin
     *
     * @since  2.0
     *
     * @access public
     *
     */

    public function deactivate($networkwide)
    {
        if ( $this->ssl_enabled ) {
	        $this->remove_ssl_from_siteurl();
	        $this->remove_ssl_from_siteurl_in_wpconfig();
	        $this->remove_secure_cookie_settings();

	        $this->site_has_ssl = FALSE;
	        $this->hsts = FALSE;
	        $this->htaccess_warning_shown = FALSE;
	        $this->review_notice_shown = FALSE;
	        $this->ssl_success_message_shown = FALSE;
	        $this->autoreplace_insecure_links = TRUE;
	        $this->do_not_edit_htaccess = FALSE;
	        $this->htaccess_redirect = FALSE;
	        $this->javascript_redirect = FALSE;
	        $this->wp_redirect = FALSE;
	        $this->ssl_enabled = FALSE;
	        $this->switch_mixed_content_fixer_hook = FALSE;
	        $this->dismiss_all_notices = FALSE;
	        $this->dismiss_review_notice = FALSE;


	        $this->save_options();

	        //when on multisite, per site activation, recreate domain list for htaccess and wpconfig rewrite actions
	        if (is_multisite()) {
		        RSSSL()->rsssl_multisite->deactivate();
		        if (!RSSSL()->rsssl_multisite->ssl_enabled_networkwide) $this->build_domain_list();
	        }
	        do_action("rsssl_deactivate");

	        $this->remove_wpconfig_edit();
	        $this->removeHtaccessEdit();
        }
    }

	/**
	 * remove secure cookie settings
	 *
	 * @since  4.0.10
	 *
	 * @access public
	 *
	 */

	public function remove_secure_cookie_settings() {

		if ( wp_doing_ajax() || !current_user_can("activate_plugins")) return;

		if ( !$this->contains_secure_cookie_settings()) return;

		$wpconfig_path = $this->find_wp_config_path();

		if ( !is_writable($wpconfig_path) ) return;

		if (!empty($wpconfig_path)) {
			$wpconfig = file_get_contents($wpconfig_path);
			$wpconfig = preg_replace("/\/\/Begin\s?Really\s?Simple\s?SSL\s?session\s?cookie\s?settings.*?\/\/END\s?Really\s?Simple\s?SSL/s", "", $wpconfig);
			$wpconfig = preg_replace("/\n+/","\n", $wpconfig);
			file_put_contents($wpconfig_path, $wpconfig);
		}
	}


    /**
     * Checks if we are currently on SSL protocol, but extends standard wp with loadbalancer check.
     *
     * @since  2.0
     *
     * @access public
     *
     */

    public function is_ssl_extended()
    {
        $server_var = FALSE;

        if ((isset($_ENV['HTTPS']) && ('on' == $_ENV['HTTPS']))
            || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && (strpos($_SERVER['HTTP_X_FORWARDED_SSL'], '1') !== false))
            || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && (strpos($_SERVER['HTTP_X_FORWARDED_SSL'], 'on') !== false))
            || (isset($_SERVER['HTTP_CF_VISITOR']) && (strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false))
            || (isset($_SERVER['HTTP_CLOUDFRONT_FORWARDED_PROTO']) && (strpos($_SERVER['HTTP_CLOUDFRONT_FORWARDED_PROTO'], 'https') !== false))
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && (strpos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') !== false))
            || (isset($_SERVER['HTTP_X_PROTO']) && (strpos($_SERVER['HTTP_X_PROTO'], 'SSL') !== false))
        ) {
            $server_var = TRUE;
        }

        if (is_ssl() || $server_var) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Checks for SSL by opening a test page in the plugin directory
     *
     * @since  2.0
     *
     * @access public
     *
     */

    public function detect_configuration()
    {
        $this->trace_log("Detecting configuration");
        //if current page is on SSL, we can assume SSL is available, even when an errormsg was returned
        if ($this->is_ssl_extended()) {
            $this->site_has_ssl = TRUE;
        } else {
            //if certificate is valid
            $this->trace_log("Check SSL by retrieving SSL certificate info");
            $this->site_has_ssl = RSSSL()->rsssl_certificate->is_valid();
        }

        if ($this->site_has_ssl) {
            $filecontents = $this->get_test_page_contents();

            //get filecontents to check .htaccess redirection method and wpconfig fix
            //check the type of SSL, either by parsing the returned string, or by reading the server vars.
            if ((strpos($filecontents, "#CLOUDFRONT#") !== false) || (isset($_SERVER['HTTP_CLOUDFRONT_FORWARDED_PROTO']) && ($_SERVER['HTTP_CLOUDFRONT_FORWARDED_PROTO'] == 'https'))) {
                $this->ssl_type = "CLOUDFRONT";
            } elseif ((strpos($filecontents, "#CLOUDFLARE#") !== false) || (isset($_SERVER['HTTP_CF_VISITOR']) && (strpos($_SERVER["HTTP_CF_VISITOR"], "https") !== false))) {
                $this->ssl_type = "CLOUDFLARE";
            } elseif ((strpos($filecontents, "#LOADBALANCER#") !== false) || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && ($_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https'))) {
                $this->ssl_type = "LOADBALANCER";
            } elseif ((strpos($filecontents, "#HTTP_X_PROTO#") !== false) || (isset($_SERVER['HTTP_X_PROTO']) && ($_SERVER['HTTP_X_PROTO'] == 'SSL'))) {
                $this->ssl_type = "HTTP_X_PROTO";
            } elseif ((strpos($filecontents, "#HTTP_X_FORWARDED_SSL_ON#") !== false) || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] == 'on')) {
                $this->ssl_type = "HTTP_X_FORWARDED_SSL_ON";
            } elseif ((strpos($filecontents, "#HTTP_X_FORWARDED_SSL_1#") !== false) || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] == '1')) {
                $this->ssl_type = "HTTP_X_FORWARDED_SSL_1";
            } elseif ((strpos($filecontents, "#SERVER-HTTPS-ON#") !== false) || (isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) == 'on')) {
                $this->ssl_type = "SERVER-HTTPS-ON";
            } elseif ((strpos($filecontents, "#SERVER-HTTPS-1#") !== false) || (isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) == '1')) {
                $this->ssl_type = "SERVER-HTTPS-1";
            } elseif ((strpos($filecontents, "#SERVERPORT443#") !== false) || (isset($_SERVER['SERVER_PORT']) && ('443' == $_SERVER['SERVER_PORT']))) {
                $this->ssl_type = "SERVERPORT443";
            } elseif ((strpos($filecontents, "#ENVHTTPS#") !== false) || (isset($_ENV['HTTPS']) && ('on' == $_ENV['HTTPS']))) {
                $this->ssl_type = "ENVHTTPS";
            } elseif ((strpos($filecontents, "#NO KNOWN SSL CONFIGURATION DETECTED#") !== false)) {
                //if we are here, SSL was detected, but without any known server variables set.
                //So we can use this info to set a server variable ourselves.
                if (!$this->wpconfig_has_fixes()) {
                    $this->no_server_variable = TRUE;
                }
                $this->trace_log("No server variable detected ");
                $this->ssl_type = "NA";
            } else {
                //no valid response, so set to NA
                $this->ssl_type = "NA";
            }

            //check for is_ssl()
            if ((!$this->is_ssl_extended() &&
                    (strpos($filecontents, "#SERVER-HTTPS-ON#") === false) &&
                    (strpos($filecontents, "#SERVER-HTTPS-1#") === false) &&
                    (strpos($filecontents, "#SERVERPORT443#") === false)) || (!is_ssl() && $this->is_ssl_extended())) {
                //when is_ssl would return false, we should add some code to wp-config.php
                if (!$this->wpconfig_has_fixes()) {
                    $this->trace_log("is_ssl() will return false: wp-config fix needed");
                    $this->do_wpconfig_loadbalancer_fix = TRUE;
                }
            }

        }
        $this->check_for_siteurl_in_wpconfig();

        $this->save_options();
    }


    /**
     * Test if the htaccess redirect will work
     * This way, no redirect loops should occur.
     *
     * @since  2.1
     *
     * @access public
     *
     */

    public function test_htaccess_redirect()
    {
        if (!current_user_can($this->capability)) return;

        $this->htaccess_test_success = get_transient('rsssl_htaccess_test_success');
        if (!$this->htaccess_test_success) {
            $this->trace_log("testing htaccess rules...");

            $filecontents = "";
            $testpage_url = trailingslashit($this->test_url()) . "testssl/";
            switch ($this->ssl_type) {
                case "CLOUDFRONT":
                    $testpage_url .= "cloudfront";
                    break;
                case "CLOUDFLARE":
                    $testpage_url .= "cloudflare";
                    break;
                case "LOADBALANCER":
                    $testpage_url .= "loadbalancer";
                    break;
                case "HTTP_X_PROTO":
                    $testpage_url .= "serverhttpxproto";
                    break;
                case "HTTP_X_FORWARDED_SSL_ON":
                    $testpage_url .= "serverhttpxforwardedsslon";
                    break;
                case "HTTP_X_FORWARDED_SSL_1":
                    $testpage_url .= "serverhttpxforwardedssl1";
                    break;
                case "SERVER-HTTPS-ON":
                    $testpage_url .= "serverhttpson";
                    break;
                case "SERVER-HTTPS-1":
                    $testpage_url .= "serverhttps1";
                    break;
                case "SERVERPORT443":
                    $testpage_url .= "serverport443";
                    break;
                case "ENVHTTPS":
                    $testpage_url .= "envhttps";
                    break;
                default:
                    $testpage_url .= "serverhttpson";
            }

            $testpage_url .= ("/ssl-test-page.html");

            $response = wp_remote_get($testpage_url);
            if (is_array($response)) {
                $status = wp_remote_retrieve_response_code($response);
                $filecontents = wp_remote_retrieve_body($response);
            }

            $this->trace_log("test page url, enter in browser to check manually: " . $testpage_url);

            if (!is_wp_error($response) && (strpos($filecontents, "#SSL TEST PAGE#") !== false)) {
                $htaccess_test_success = 'success';
                $this->trace_log("htaccess rules tested successfully.");
            } else {
                //.htaccess rewrite rule seems to be giving problems.
                $htaccess_test_success = 'error';
                if (is_wp_error($response)) {
                    $this->trace_log("htaccess rules test failed with error: " . $response->get_error_message());
                } else {
                    $this->trace_log("htaccess test rules failed. Set WordPress redirect in settings/SSL");
                }
            }
            if (empty($filecontents)) {
                $htaccess_test_success = 'no-response';
            }
            set_transient('rsssl_htaccess_test_success', $this->htaccess_test_success, 600);
        }


        if ($htaccess_test_success == 'no-response'){
            $this->htaccess_test_success = FALSE;
        }
        if ($htaccess_test_success == 'success'){
            $this->htaccess_test_success = true;
        }
        if ($htaccess_test_success == 'error'){
            $this->htaccess_test_success = FALSE;
        }

    }


    /**
     * Get an url with which we can test the SSL connection and htaccess redirect rules.
     *
     * @since  2.0
     *
     * @access public
     *
     */

    public function test_url()
    {
        $plugin_url = str_replace("http://", "https://", trailingslashit(rsssl_url));
        $https_home_url = str_replace("http://", "https://", home_url());

        //in some case we get a relative url here, so we check that.
        //we compare to urls replaced to https, in case one of them is still on http.
        if ((strpos($plugin_url, "https://") === FALSE) &&
            (strpos($plugin_url, $https_home_url) === FALSE)
        ) {
            //make sure we do not have a slash at the start
            $plugin_url = ltrim($plugin_url, "/");
            $plugin_url = trailingslashit(home_url()) . $plugin_url;
        }

        //for subdomains or domain mapping situations, we have to convert the plugin_url from main site to the subdomain url.
        if (is_multisite() && (!is_main_site(get_current_blog_id())) && (!RSSSL()->rsssl_multisite->is_multisite_subfolder_install())) {
            $mainsiteurl = trailingslashit(str_replace("http://", "https://", network_site_url()));

            $home = trailingslashit($https_home_url);
            $plugin_url = str_replace($mainsiteurl, $home, $plugin_url);

            //return http link if original url is http.
            //if (strpos(home_url(), "https://")===FALSE) $plugin_url = str_replace("https://","http://",$plugin_url);
        }

        return $plugin_url;
    }


    /**
     * removes the added redirect to https rules to the .htaccess file.
     *
     * @since  2.0
     *
     * @access public
     *
     */

    public function removeHtaccessEdit()
    {
        if (file_exists($this->htaccess_file()) && is_writable($this->htaccess_file())) {
            $htaccess = file_get_contents($this->htaccess_file());

            //if multisite, per site activation and more than one blog remaining on ssl, remove condition for this site only
            //the domain list has been rebuilt already, so current site is already removed.
            if (is_multisite() && !RSSSL()->rsssl_multisite->ssl_enabled_networkwide && count($this->sites) > 0) {
                //remove http or https.
                $domain = preg_replace("/(http:\/\/|https:\/\/)/", "", home_url());
                $pattern = "/#wpmu\srewritecond\s?" . preg_quote($domain, "/") . "\n.*?#end\swpmu\srewritecond\s?" . preg_quote($domain, "/") . "\n/s";

                //only remove if the pattern is there at all
                if (preg_match($pattern, $htaccess)) $htaccess = preg_replace($pattern, "", $htaccess);
                //now replace any remaining "or" on the last condition.
                $pattern = "/(\[OR\])(?!.*(\[OR\]|#start).*?RewriteRule)/s";
                $htaccess = preg_replace($pattern, "", $htaccess, 1);

            } else {
                // remove everything
                $pattern = "/#\s?BEGIN\s?rlrssslReallySimpleSSL.*?#\s?END\s?rlrssslReallySimpleSSL/s";
                //only remove if the pattern is there at all
                if (preg_match($pattern, $htaccess)) $htaccess = preg_replace($pattern, "", $htaccess);

            }

            $htaccess = preg_replace("/\n+/", "\n", $htaccess);
            file_put_contents($this->htaccess_file(), $htaccess);
            $this->save_options();
        } else {
            $this->errors['HTACCESS_NOT_WRITABLE'] = TRUE;
            $this->trace_log("could not remove rules from htaccess, file not writable");
        }
    }

    /**
     * @return bool|string
     *
     * Get the .htaccess version
     *
     * @access public
     *
     */

    public function get_htaccess_version()
    {
        if (!file_exists($this->htaccess_file())) return false;

        $htaccess = file_get_contents($this->htaccess_file());
        $versionpos = strpos($htaccess, "rsssl_version");

        if ($versionpos === false) {
            //no version found, so not .htaccess rules.
            return false;
        } else {
            //find closing marker of version
            $close = strpos($htaccess, "]", $versionpos);
            $version = substr($htaccess, $versionpos + 14, $close - ($versionpos + 14));
            return $version;
        }
    }

	/**
	 * @return bool
     *
     * Check if the .htaccess redirect is allowed on this setup
     *
     * @since 2.0
     *
	 */

    public function htaccess_redirect_allowed()
    {
        if (is_multisite() && RSSSL()->rsssl_multisite->is_per_site_activated_multisite_subfolder_install()) {
            return false;
        } if (RSSSL()->rsssl_server->uses_htaccess()) {
            return true;
        } else {
            return false;
        }
    }

	/**
	 * @return bool
     *
     * Checks if the htaccess contains redirect rules, either actual redirect or a rsssl marker.
     *
     * @since 2.0
     *
	 */

    public function htaccess_contains_redirect_rules()
    {

        if (!file_exists($this->htaccess_file()))  {
            return false;
        }

        $htaccess = file_get_contents($this->htaccess_file());

        $needle_old = "RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]";
        $needle_new = "RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]";
        if (strpos($htaccess, $needle_old) !== FALSE || strpos($htaccess, $needle_new) !== FALSE || $this->contains_rsssl_rules()) {
            return true;
        } else {
            $this->trace_log(".htaccess does not contain default Really Simple SSL redirect");
            return false;
        }

    }


    /*
  *    Checks if the htaccess contains the Really Simple SSL comment.
  *
  */

    public function contains_rsssl_rules()
    {
        if (!file_exists($this->htaccess_file())) {
            return false;
        }

        $htaccess = file_get_contents($this->htaccess_file());

        $check = null;
        preg_match("/BEGIN rlrssslReallySimpleSSL/", $htaccess, $check);
        if (count($check) === 0) {
            return false;
        } else {
            return true;
        }
    }

	/**
	 * @return bool
     *
     * Checks if a 301 redirect is set
	 * this is the case if either the wp_redirect is set, or the htaccess redirect is set.
     *
	 */

    public function has_301_redirect()
    {
        if ($this->wp_redirect) return true;

        if (RSSSL()->rsssl_server->uses_htaccess() && $this->htaccess_contains_redirect_rules()) {
            return true;
        }

        return false;
    }

    /**
     * Checks if the HSTS rule is already in the htaccess file
     * Set the hsts variable in the db accordingly. applies to preload version as well.
     *
     * @since  2.1
     *
     * @access public
     *
     */

    public function contains_hsts()
    {
        if (!file_exists($this->htaccess_file())) {
            $this->trace_log(".htaccess not found in " . $this->ABSpath);
            $result = $this->hsts; //just return the setting.
        } else {
            $htaccess = file_get_contents($this->htaccess_file());

            preg_match("/Strict-Transport-Security/", $htaccess, $check);
            if (count($check) === 0) {
                $result = false;
            } else {
                $result = true;
            }
        }

        return $result;
    }

	/**
	 * returns list of recommended, but not active security headers for this site
     * returns empty array if no .htacces file exists
     * @return array
	 *
	 * @since  4.0
	 *
	 * @access public
	 *
	 */

	public function get_recommended_security_headers()
	{
		$not_used_headers = array();
		if (RSSSL()->rsssl_server->uses_htaccess() && file_exists($this->htaccess_file())) {
		    $check_headers = array(
                array(
                    'name' => 'HTTP Strict Transport Security',
                    'pattern' =>  'Strict-Transport-Security',
                ),
                array(
                    'name' => 'Content Security Policy: Upgrade Insecure Requests',
                    'pattern' =>  'upgrade-insecure-requests',
                ),
			    array(
				    'name' => 'X-XSS protection',
				    'pattern' =>  'X-XSS-Protection',
			    ),
			    array(
				    'name' => 'X-Content Type Options',
				    'pattern' =>  'X-Content-Type-Options',
			    ),
                array(
				    'name' => 'Referrer-Policy',
				    'pattern' =>  'Referrer-Policy',
			    ),
                array(
				    'name' => 'Expect-CT',
				    'pattern' =>  'Expect-CT',
			    ),
            );

			$htaccess = file_get_contents($this->htaccess_file());
            foreach ($check_headers as $check_header){
	            if ( !preg_match("/".$check_header['pattern']."/", $htaccess, $check) ) {
	                $not_used_headers[] = $check_header['name'];
                }
            }
		}

		return $not_used_headers;
	}

	/**
	 * @return bool
	 */
	public function recommended_security_headers_set(){
	    if (count($this->get_recommended_security_headers()) >0 ) {
	        return false;
	    } else {
	        return true;
	    }
	}


    /**
     * Adds redirect to https rules to the .htaccess file or htaccess.conf on Bitnami.
     *
     * @since  2.0
     *
     * @access public
     *
     */

    public function editHtaccess()
    {
        if (!current_user_can($this->capability)) return;

        //check if htaccess exists and  if htaccess is writable
        //update htaccess to redirect to ssl

        $this->trace_log("checking if .htaccess can or should be edited...");

        //does it exist?
        if (!file_exists($this->htaccess_file()) ) {
            $this->trace_log(".htaccess not found.");
            return;
        }

        //check if editing is blocked.
        if ($this->do_not_edit_htaccess) {
            $this->trace_log("Edit of .htaccess blocked by setting or define 'do not edit htaccess' in Really Simple SSL.");
            return;
        }

         $htaccess = file_get_contents($this->htaccess_file());

        if (!$this->htaccess_contains_redirect_rules()) {

            if (!is_writable($this->htaccess_file())) {
                //set the wp redirect as fallback, because .htaccess couldn't be edited.
                if ($this->clicked_activate_ssl()) $this->wp_redirect = true;
                if (is_multisite()) {
                    RSSSL()->rsssl_multisite->wp_redirect = true;
                    RSSSL()->rsssl_multisite->save_options();
                }
                $this->save_options();
                $this->trace_log(".htaccess not writable.");
                return;
            }

            $rules = $this->get_redirect_rules();

            //insert rules before wordpress part.
            if (strlen($rules) > 0) {
                $wptag = "# BEGIN WordPress";
                if (strpos($htaccess, $wptag) !== false) {
                    $htaccess = str_replace($wptag, $rules . $wptag, $htaccess);
                } else {
                    $htaccess = $htaccess . $rules;
                }
                file_put_contents($this->htaccess_file(), $htaccess);
            }

        }
    }

	/**
	 * @param bool $oldvalue
	 * @param bool $newvalue
	 * @param bool $option
     *
     * Update the .htaccess file after saving settings
     *
	 */

    public function update_htaccess_after_settings_save($oldvalue = false, $newvalue = false, $option = false)
    {
        if (!current_user_can($this->capability)) return;

        //does it exist?
        if (!file_exists($this->htaccess_file())) {
            $this->trace_log(".htaccess not found.");
            return;
        }

        if (!is_writable($this->htaccess_file())) {
            $this->trace_log(".htaccess not writable.");
            return;
        }

        //check if editing is blocked.
        if ($this->do_not_edit_htaccess) {
            $this->trace_log("Edit of .htaccess blocked by setting or define 'do not edit htaccess' in Really Simple SSL.");
            return;
        }

        $htaccess = file_get_contents($this->htaccess_file());
        $htaccess = preg_replace("/#\s?BEGIN\s?rlrssslReallySimpleSSL.*?#\s?END\s?rlrssslReallySimpleSSL/s", "", $htaccess);
        $htaccess = preg_replace("/\n+/", "\n", $htaccess);

        $rules = $this->get_redirect_rules();

        //insert rules before WordPress part.
        $wptag = "# BEGIN WordPress";
        if (strpos($htaccess, $wptag) !== false) {
            $htaccess = str_replace($wptag, $rules . $wptag, $htaccess);
        } else {
            $htaccess = $htaccess . $rules;
        }
        file_put_contents($this->htaccess_file(), $htaccess);

    }

    /**
     *
     * @since 2.2
     * Check if the mixed content fixer is functioning on the front end, by scanning the source of the homepage for the fixer comment.
     * @access public
     * @return string $mixed_content_fixer_detected
     */

    public function mixed_content_fixer_detected()
    {
        $status = 0;

        $mixed_content_fixer_detected = get_transient('rsssl_mixed_content_fixer_detected');

        if (!$mixed_content_fixer_detected) {

            $web_source = "";
            //check if the mixed content fixer is active
            $response = wp_remote_get(home_url());

            if (!is_wp_error($response)) {
	            if ( is_array( $response ) ) {
		            $status = wp_remote_retrieve_response_code( $response );
		            $web_source = wp_remote_retrieve_body( $response );
	            }

	            if ( $status != 200 ) {
		            $mixed_content_fixer_detected = 'no-response';
	            } elseif ( strpos( $web_source, "data-rsssl=" ) === false ) {
		            $mixed_content_fixer_detected = 'not-found';
	            } else {
		            $mixed_content_fixer_detected = 'found';
	            }
            }

            if (is_wp_error($response)) {
                $mixed_content_fixer_detected = 'error';
                $error = $response->get_error_message();
                set_transient('rsssl_curl_error' , $error, 600);
                if (!empty($error) && (strpos($error, "cURL error") !== false) ) {
                    $mixed_content_fixer_detected = 'curl-error';
                }
            }

            if ($this->autoreplace_insecure_links == ! true) {
                $mixed_content_fixer_detected = 'not-enabled';
            }

            set_transient('rsssl_mixed_content_fixer_detected', $mixed_content_fixer_detected, 600);
        }

        if ($mixed_content_fixer_detected === 'no-response'){
            //Could not connect to website
            $this->trace_log("Could not connect to webpage to detect mixed content fixer");
            $this->mixed_content_fixer_detected = FALSE;
        }
        if ($mixed_content_fixer_detected === 'not-found'){
            //Mixed content fixer marker not found in the websource
            $this->trace_log("Mixed content marker not found in websource");
            $this->mixed_content_fixer_detected = FALSE;
        }
	    if ($mixed_content_fixer_detected === 'error'){
	        $this->trace_log("Mixed content marker not found: unknown error");
		    //Error encountered while retrieving the webpage. Fallback since most errors should be cURL errors
		    $this->mixed_content_fixer_detected = FALSE;
	    }
	    if ($mixed_content_fixer_detected === 'curl-error'){
		    //Site has has a cURL error
            $this->trace_log("Mixed content fixer could not be detected: cURL error");
		    $this->mixed_content_fixer_detected = FALSE;
	    }
        if ($mixed_content_fixer_detected === 'found'){
            $this->trace_log("Mixed content fixer successfully detected");
            //Mixed content fixer was successfully detected on the front end
            $this->mixed_content_fixer_detected = true;
        }

        if ($mixed_content_fixer_detected === 'not-enabled') {
            $this->trace_log("Mixed content fixer not enabled");
            $this->mixed_content_fixer_detected = FALSE;
        }

        return $mixed_content_fixer_detected;
    }

	/**
     * Create redirect rules for the .htaccess.
	 * @since  2.1
	 *
	 * @access public
     *
	 * @param bool $manual
	 *
	 * @return string
	 */

    public function get_redirect_rules($manual = false)
    {
        $this->trace_log("retrieving redirect rules");
        //only add the redirect rules when a known type of SSL was detected. Otherwise, we use https.
        $rule = "";
        //if the htaccess test was successfull, and we know the redirectype, edit
        if ($this->htaccess_redirect && ($manual || $this->htaccess_test_success) && $this->ssl_type != "NA") {
	        $this->trace_log("starting insertion of .htaccess redirects.");
            $rule .= "<IfModule mod_rewrite.c>" . "\n";
            $rule .= "RewriteEngine on" . "\n";

            $or = "";
            if ($this->ssl_type == "SERVER-HTTPS-ON") {
                $rule .= "RewriteCond %{HTTPS} !=on [NC]" . "\n";
            } elseif ($this->ssl_type == "SERVER-HTTPS-1") {
                $rule .= "RewriteCond %{HTTPS} !=1" . "\n";
            } elseif ($this->ssl_type == "LOADBALANCER") {
                $rule .= "RewriteCond %{HTTP:X-Forwarded-Proto} !https" . "\n";
            } elseif ($this->ssl_type == "HTTP_X_PROTO") {
                $rule .= "RewriteCond %{HTTP:X-Proto} !SSL" . "\n";
            } elseif ($this->ssl_type == "CLOUDFLARE") {
                $rule .= "RewriteCond %{HTTP:CF-Visitor} '" . '"scheme":"http"' . "'" . "\n";//some concatenation to get the quotes right.
            } elseif ($this->ssl_type == "SERVERPORT443") {
                $rule .= "RewriteCond %{SERVER_PORT} !443" . "\n";
            } elseif ($this->ssl_type == "CLOUDFRONT") {
                $rule .= "RewriteCond %{HTTP:CloudFront-Forwarded-Proto} !https" . "\n";
            } elseif ($this->ssl_type == "HTTP_X_FORWARDED_SSL_ON") {
                $rule .= "RewriteCond %{HTTP:X-Forwarded-SSL} !on" . "\n";
            } elseif ($this->ssl_type == "HTTP_X_FORWARDED_SSL_1") {
                $rule .= "RewriteCond %{HTTP:X-Forwarded-SSL} !=1" . "\n";
            } elseif ($this->ssl_type == "ENVHTTPS") {
                $rule .= "RewriteCond %{ENV:HTTPS} !=on" . "\n";
            }

            //if multisite, and NOT subfolder install (checked for in the detect_config function)
            //, add a condition so it only applies to sites where plugin is activated
            if (is_multisite() && !RSSSL()->rsssl_multisite->ssl_enabled_networkwide) {
                $this->trace_log("multisite, per site activation");

                foreach ($this->sites as $domain) {
                    $this->trace_log("adding condition for:" . $domain);

                    //remove http or https.
                    $domain = preg_replace("/(http:\/\/|https:\/\/)/", "", $domain);
                    //We excluded subfolders, so treat as domain

                    $domain_no_www = str_replace("www.", "", $domain);
                    $domain_yes_www = "www." . $domain_no_www;

                    $rule .= "#wpmu rewritecond " . $domain . "\n";
                    $rule .= "RewriteCond %{HTTP_HOST} ^" . preg_quote($domain_no_www, "/") . " [OR]" . "\n";
                    $rule .= "RewriteCond %{HTTP_HOST} ^" . preg_quote($domain_yes_www, "/") . " [OR]" . "\n";
                    $rule .= "#end wpmu rewritecond " . $domain . "\n";
                }

                //now remove last [OR] if at least on one site the plugin was activated, so we have at least one condition
                if (count($this->sites) > 0) {
                    $rule = strrev(implode("", explode(strrev("[OR]"), strrev($rule), 2)));
                }
            } else {
                $this->trace_log("single site or networkwide activation");
            }

            //fastest cache compatibility
            if (class_exists('WpFastestCache')) {
                $rule .= "RewriteCond %{REQUEST_URI} !wp-content\/cache\/(all|wpfc-mobile-cache)" . "\n";
            }

            //Exclude .well-known/acme-challenge for Let's Encrypt validation
            if ($this->has_acme_challenge_directory() && !$this->has_well_known_needle()) {
                $rule .= "RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/" . "\n";
            }

            $rule .= "RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]" . "\n";

                $rule .= "</IfModule>" . "\n";
        }

        if (strlen($rule) > 0) {
            $rule = "\n" . "# BEGIN rlrssslReallySimpleSSL rsssl_version[" . rsssl_version . "]\n" . $rule . "# END rlrssslReallySimpleSSL" . "\n";
        }

        $rule = apply_filters("rsssl_htaccess_output", $rule);

        $rule = preg_replace("/\n+/", "\n", $rule);
        return $rule;
    }

    /**
     * Show warning when wpconfig could not be fixed
     *
     * @since 2.2
     *
     * @access public
     *
     */

    public function show_notice_wpconfig_needs_fixes()
    {
        //prevent showing the review on edit screen, as gutenberg removes the class which makes it editable.
        $screen = get_current_screen();
	    if ( $screen->base === 'post' ) return;

        ob_start();
        if ($this->wpconfig_siteurl_not_fixed) { ?>
            <p>
                <?php echo __("A definition of a siteurl or homeurl was detected in your wp-config.php, but the file is not writable.", "really-simple-ssl"); ?>
            </p>
            <p><?php echo sprintf(__("Set your wp-config.php to %swritable%s and reload this page.", "really-simple-ssl"), '<a target="_blank" href="https://really-simple-ssl.com/knowledge-base/htaccess-wp-config-files-not-writable/">', '</a>'); ?></p>
        <?php }
        if ($this->do_wpconfig_loadbalancer_fix) { ?>
            <p><?php echo __("Your wp-config.php has to be edited, but is not writable.", "really-simple-ssl"); ?></p>
            <p><?php echo __("Because your site is behind a loadbalancer and is_ssl() returns false, you should add the following line of code to your wp-config.php.", "really-simple-ssl"); ?>
                <br><br><code>
                    //Begin Really Simple SSL Load balancing fix<br>
                    if ((isset($_ENV["HTTPS"]) && ("on" == $_ENV["HTTPS"]))<br>
                    || (isset($_SERVER["HTTP_X_FORWARDED_SSL"]) && (strpos($_SERVER["HTTP_X_FORWARDED_SSL"], "1") !== false))<br>
                    || (isset($_SERVER["HTTP_X_FORWARDED_SSL"]) && (strpos($_SERVER["HTTP_X_FORWARDED_SSL"], "on") !== false))<br>
                    || (isset($_SERVER["HTTP_CF_VISITOR"]) && (strpos($_SERVER["HTTP_CF_VISITOR"], "https") !== false))<br>
                    || (isset($_SERVER["HTTP_CLOUDFRONT_FORWARDED_PROTO"]) && (strpos($_SERVER["HTTP_CLOUDFRONT_FORWARDED_PROTO"], "https") !== false))<br>
                    || (isset($_SERVER["HTTP_X_FORWARDED_PROTO"]) && (strpos($_SERVER["HTTP_X_FORWARDED_PROTO"], "https") !== false))<br>
                    || (isset($_SERVER["HTTP_X_PROTO"]) && (strpos($_SERVER["HTTP_X_PROTO"], "SSL") !== false))<br>
                    ) {<br>
                    &nbsp;&nbsp; $_SERVER["HTTPS"] = "on";<br>
                    }<br>
                    //END Really Simple SSL
                </code><br>
            </p>
            <p><?php echo sprintf(__("Or set your wp-config.php to %swritable%s and reload this page.", "really-simple-ssl"), '<a target="_blank" href="https://really-simple-ssl.com/knowledge-base/htaccess-wp-config-files-not-writable/">', '</a>'); ?></p>
            <?php
        }

        if ($this->no_server_variable) {
            ?>
            <p><?php echo __('Because your server does not pass a variable with which WordPress can detect SSL, WordPress may create redirect loops on SSL.', 'really-simple-ssl'); ?></p>
            <p><?php echo sprintf(__("Set your wp-config.php to %swritable%s and reload this page.", "really-simple-ssl"), '<a target="_blank" href="https://really-simple-ssl.com/knowledge-base/htaccess-wp-config-files-not-writable/">', '</a>');?></p>
            <?php
        }

        $content = ob_get_clean();
	    $class = "error";
	    $title = __("System detection encountered issues", "really-simple-ssl");

	    echo $this->notice_html( $class, $title, $content );
    }


    /**
     *
     * @return bool
     * since 3.1
     * Check if .well-known/acme-challenge directory exists
     * @access public
     */

    public function has_acme_challenge_directory()
    {
        if (file_exists("$this->ABSpath.well-known/acme-challenge")) {
            return true;
        }
        return false;
    }

    /**
     *
     * @return bool
     * since 3.1
     * Check if there are already .well-known rules in .htaccess file
     * @access public
     *
     */

    public function has_well_known_needle()
    {
        $htaccess = file_get_contents($this->htaccess_file());
        $well_known_needle = ".well-known";

        if (strpos($htaccess, $well_known_needle) !== false) {
            return true;
        }

        return false;
    }

	/**
	 * Shows a notice, asking users for a review.
	 */

    public function show_leave_review_notice()
    {
        if ($this->dismiss_all_notices) return;

        //prevent showing the review on edit screen, as gutenberg removes the class which makes it editable.
        $screen = get_current_screen();
	    if ( $screen->base === 'post' ) return;

        //this user has never had the review notice yet.
        if ($this->ssl_enabled && !get_option('rsssl_activation_timestamp')){
            $month = rand ( 0, 11);
            $trigger_notice_date = time() + $month * MONTH_IN_SECONDS;
	        update_option('rsssl_activation_timestamp', $trigger_notice_date);
	        update_option('rsssl_before_review_notice_user', true);
        }

        if (!$this->review_notice_shown && get_option('rsssl_activation_timestamp') && get_option('rsssl_activation_timestamp') < strtotime("-1 month")) {
            add_action('admin_print_footer_scripts', array($this, 'insert_dismiss_review'));
            ?>
            <?php if ( is_rtl() ) { ?>
                <style>
                    .rlrsssl-review .rsssl-container {
                        display: flex;
                        padding:12px;
                    }
                    .rlrsssl-review .rsssl-container .dashicons {
                        margin-left:10px;
                        margin-right:5px;
                    }
                    .rlrsssl-review .rsssl-review-image img{
                        margin-top:0.5em;
                    }
                    .rlrsssl-review .rsssl-buttons-row {
                        margin-top:10px;
                        display: flex;
                        align-items: center;
                    }
                </style>
            <?php } else { ?>
                <style>
                    .rlrsssl-review .rsssl-container {
                        display: flex;
                        padding:12px;
                    }
                    .rlrsssl-review .rsssl-container .dashicons {
                        margin-right:10px;
                        margin-left:5px;
                    }
                    .rlrsssl-review .rsssl-review-image img{
                        margin-top:0.5em;
                    }
                    .rlrsssl-review .rsssl-buttons-row {
                        margin-top:10px;
                        display: flex;
                        align-items: center;
                    }
                </style>
            <?php } ?>
            <div id="message" class="updated fade notice is-dismissible rlrsssl-review really-simple-plugins" style="border-left:4px solid #333">
                <div class="rsssl-container">
                    <div class="rsssl-review-image"><img width=80px" src="<?php echo rsssl_url?>/assets/icon-128x128.png" alt="review-logo"></div>
                    <div style="margin-left:30px">
                        <?php if (get_option("rsssl_before_review_notice_user")){?>
                            <p><?php printf(__('Hi, Really Simple SSL has kept your site secure for some time now, awesome! If you have a moment, please consider leaving a review on WordPress.org to spread the word. We greatly appreciate it! If you have any questions or feedback, leave us a %smessage%s.', 'really-simple-ssl'),'<a href="https://really-simple-ssl.com/contact" target="_blank">','</a>'); ?></p>
                        <?php } else {?>
                            <p><?php printf(__('Hi, Really Simple SSL has kept your site secure for a month now, awesome! If you have a moment, please consider leaving a review on WordPress.org to spread the word. We greatly appreciate it! If you have any questions or feedback, leave us a %smessage%s.', 'really-simple-ssl'),'<a href="https://really-simple-ssl.com/contact" target="_blank">','</a>'); ?></p>
	                    <?php }?>

                        <i>- Rogier</i>
                        <div class="rsssl-buttons-row">
                            <a class="button button-primary" target="_blank"
                               href="https://wordpress.org/support/plugin/really-simple-ssl/reviews/#new-post"><?php _e('Leave a review', 'really-simple-ssl'); ?></a>
                            <div class="dashicons dashicons-calendar"></div><a href="#" id="maybe-later"><?php _e('Maybe later', 'really-simple-ssl'); ?></a>
                            <div class="dashicons dashicons-no-alt"></div><a href="<?php echo esc_url(add_query_arg(array("page"=>"rlrsssl_really_simple_ssl", "tab"=>"configuration", "rsssl_dismiss_review_notice"=>1),admin_url("options-general.php") ) );?>"><?php _e('Don\'t show again', 'really-simple-ssl'); ?></a>
                        </div>
                    </div>
                </div>
            </div>
            <?php
        }
    }

    /**
     * Show notices
     *
     * @since  2.0
     *
     * @access public
     *
     */

    public function show_notices()
    {
	    //prevent showing the review on edit screen, as gutenberg removes the class which makes it editable.
	    $screen = get_current_screen();
	    if ( $screen->base === 'post' ) return;

        //don't show admin notices on our own settings page: we have the warnings there
        if ( $this->is_settings_page() ) return;

	    $notices = $this->get_notices_list( array('admin_notices'=>true) );
        foreach ( $notices as $id => $notice ){
            $notice = $notice['output'];
            $class = ( $notice['status'] !== 'completed' ) ? 'error' : 'updated';
	        echo $this->notice_html( $class.' '.$id, $notice['title'], $notice['msg'] );
        }
    }

    /**
     * Insert some ajax script to dismiss the review notice, and stop nagging about it
     *
     * @since  3.0
     *
     * @access public
     *
     * type: dismiss, later
     *
     */

    public function insert_dismiss_review()
    {
        $ajax_nonce = wp_create_nonce("really-simple-ssl");
        ?>
        <script type='text/javascript'>
            jQuery(document).ready(function ($) {
                $(".rlrsssl-review.notice.is-dismissible").on("click", ".notice-dismiss", function (event) {
                    rsssl_dismiss_review('dismiss');
                });
                $(".rlrsssl-review.notice.is-dismissible").on("click", "#maybe-later", function (event) {
                    rsssl_dismiss_review('later');
                    $(this).closest('.rlrsssl-review').remove();
                });
                $(".rlrsssl-review.notice.is-dismissible").on("click", ".review-dismiss", function (event) {
                    rsssl_dismiss_review('dismiss');
                    $(this).closest('.rlrsssl-review').remove();
                });

                function rsssl_dismiss_review(type){
                    var data = {
                        'action': 'rsssl_dismiss_review_notice',
                        'type' : type,
                        'security': '<?php echo $ajax_nonce; ?>'
                    };
                    $.post(ajaxurl, data, function (response) {});
                }
            });
        </script>
        <?php
    }


    /**
     * Process the ajax dismissal of the success message.
     *
     * @since  2.0
     *
     * @access public
     *
     */

    public function dismiss_success_message_callback()
    {
        if (!current_user_can($this->capability) ) return;
        $this->ssl_success_message_shown = TRUE;
        $this->save_options();
        wp_die();
    }

    /**
     * Process the ajax dismissal of settings notice
     *
     * Since 3.1
     *
     * @access public
     *
     */

    public function dismiss_settings_notice_callback()
    {
        if (!current_user_can($this->capability) ) return;

	    if (!isset($_POST['token']) || (!wp_verify_nonce($_POST['token'], 'rsssl_nonce'))) {
		    return;
	    }

	    if (isset($_POST['type'])) {
	        $dismiss_type = sanitize_title( $_POST['type'] );
	        update_option( "rsssl_".$dismiss_type."_dismissed", true );
            delete_transient( 'rsssl_plusone_count' );
        }

	    // count should be updated, therefore clear cache
	    $this->clear_transients();

	    $data     = array(
		    'tasks' => $this->get_remaining_tasks_count(),
		    'percentage' => $this->get_score_percentage(),
	    );
	    $response = json_encode( $data );
	    header( "Content-Type: application/json" );
	    echo $response;
	    exit;
    }

    /**
     * Process the ajax dismissal of the htaccess message.
     *
     * @since  2.1
     *
     * @access public
     *
     */

    public function dismiss_review_notice_callback()
    {
        $type = isset($_POST['type']) ? $_POST['type'] : false;

        if ($type === 'dismiss'){
            $this->review_notice_shown = TRUE;
        }
        if ($type === 'later') {
            //Reset activation timestamp, notice will show again in one month.
            update_option('rsssl_activation_timestamp', time());
        }

        $this->save_options();
        wp_die(); // this is required to terminate immediately and return a proper response
    }

    /**
     * Adds the admin options page
     *
     * @since  2.0
     *
     * @access public
     *
     */

    public function add_settings_page()
    {
        if (!current_user_can($this->capability)) return;

        //hides the settings page if the hide menu for subsites setting is enabled
        if (is_multisite() && rsssl_multisite::this()->hide_menu_for_subsites && !is_super_admin()) return;

        global $rsssl_admin_page;

        $count = $this->count_plusones();
        if ($count > 0 ) {
            $update_count = "<span class='update-plugins rsssl-update-count'><span class='update-count'>$count</span></span>";
        } else {
            $update_count = "";
        }

        $rsssl_admin_page = add_options_page(
            __("SSL settings", "really-simple-ssl"), //link title
            __("SSL", "really-simple-ssl") . $update_count, //page title
            $this->capability, //capability
            'rlrsssl_really_simple_ssl', //url
            array($this, 'settings_page')); //function

    }

    /**
     *
     * @since 3.1.6
     *
     * Add an update count to the WordPress admin Settings menu item
     * Doesn't work when the Admin Menu Editor plugin is active
     *
     */

    public function rsssl_edit_admin_menu()
    {
        if (!current_user_can($this->capability)) return;
        global $menu;
        $count = $this->count_plusones();
        $menu_slug = 'options-general.php';
        $menu_title = __('Settings');
        foreach($menu as $index => $menu_item){
            if (!isset($menu_item[2]) || !isset($menu_item[0])) continue;
            if ($menu_item[2]===$menu_slug){
                $pattern = '/<span.*>([1-9])<\/span><\/span>/i';
                    if (preg_match($pattern, $menu_item[0], $matches)){
                        if (isset($matches[1])) $count = intval($count) + intval($matches[1]);
                    }

                $update_count = $count > 0 ? "<span class='update-plugins rsssl-update-count'><span class='update-count'>$count</span></span>":'';
                $menu[$index][0] = $menu_title . $update_count;
            }
        }
    }

    /**
     * Create tabs on the settings page
     *
     * @since  2.1
     *
     * @access public
     *
     */

    public function admin_tabs($current = 'homepage')
    {
        $tabs = array(
            'configuration' => '',
        );
        $tabs = apply_filters("rsssl_grid_tabs", $tabs);

        //allow the license tab to show up for older version, to allow for upgrading
	    $legacy_tabs = apply_filters("rsssl_tabs", array());
	    if (isset($legacy_tabs['license'])) $tabs['license']= $legacy_tabs['license'];

	    // Only show general tab if there are other tabs as well
	    if (count($tabs) > 1) {
            $tabs['configuration'] = __("General", "really-simple-ssl");
        }

        ?>
        <div class="nav-tab-wrapper">
            <div class="rsssl-logo-container">
                <div id="rsssl-logo"><img src="<?php echo rsssl_url?>/assets/really-simple-ssl-logo.svg" alt="review-logo"></div>
            </div>
            <?php
                if (count($tabs)>1) {
	                foreach ( $tabs as $tab => $name ) {
		                $class = ( $tab == $current ) ? ' nav-tab-active' : '';
		                echo "<a class='nav-tab$class' href='?page=rlrsssl_really_simple_ssl&tab=$tab'>$name</a>";
	                }
                }
            ?>
            <div class="header-links">
                <div class="documentation">
                    <a href="https://really-simple-ssl.com/knowledge-base" class="<?php if (defined('rsssl_pro_version')) echo "button button-primary"?>" target="_blank"><?php _e("Documentation", "really-simple-ssl");?></a>
                </div>
                <div class="header-upsell">
                    <?php if (defined('rsssl_pro_version')) { ?>
                    <?php } else { ?>
                        <div class="documentation">
                            <a href="https://wordpress.org/support/plugin/really-simple-ssl/" class="button button-primary" target="_blank"><?php _e("Support", "really-simple-ssl") ?></a>
                        </div>
                    <?php } ?>
                </div>
            </div>
        </div>
        <?php
    }

	/**
     * Check if user has upgraded to four from a previous version
	 * @return bool
	 */

    public function upgraded_to_four(){
        return get_option( 'rsssl_upgraded_to_four' ) ? true : false;
    }

    /**
     * Get array of notices
     * - condition: function returning boolean, if notice should be shown or not
     * - callback: function, returning boolean or string, with multiple possible answers, and resulting messages and icons
     * @param array $args
     * @return array
     */

    public function get_notices_list( $args = array() )
    {
        $defaults = array(
            'admin_notices' => false,
            'premium_only' => false,
            'dismiss_on_upgrade' => false,
            'status' => 'open', //status can be "all" (all tasks, regardless of dismissed or open), "open" (not success/completed) or "completed"
        );
        $args = wp_parse_args($args, $defaults);

	    $cache_admin_notices = !$this->is_settings_page() && $args['admin_notices'];

	    //if we're on the settings page, we need to clear the admin notices transient, because this list never gets requested on the settings page, and won'd get cleared otherwise
	    if ($this->clicked_activate_ssl() || $this->is_settings_page() || isset($_GET['ssl_reload_https']) ) {
	        delete_transient('rsssl_admin_notices');
	    }
	    if ( $cache_admin_notices) {
		    $cached_notices = get_transient('rsssl_admin_notices');
		    if ( $cached_notices ) return $cached_notices;
	    }

	    $htaccess_file = $this->uses_htaccess_conf() ? "htaccess.conf (/conf/htaccess.conf/)" : $htaccess_file = ".htaccess";
        if ( $this->ssl_type != "NA" ) {
            $rules            = $this->get_redirect_rules( true );
            $arr_search       = array( "<", ">", "\n" );
            $arr_replace      = array( "&lt", "&gt", "<br>" );
            $rules            = str_replace( $arr_search, $arr_replace, $rules );
            $rules            = substr($rules, 4, -4);
        } else {
            $rules = __( "No recommended redirect rules detected.", "really-simple-ssl" ) ;
        }
	    $rules            = '<br><code>' . $rules . '</code><br>';

	    $notice_defaults = array(
            'condition' => array(),
            'callback' => false,
        );

	    $curl_error = get_transient('rsssl_curl_error');
        $current_plugin_folder = $this->get_current_rsssl_free_dirname();
        $reload_https_url = add_query_arg( array( 'ssl_reload_https' => '1') , esc_url_raw("https://" . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"]) );
        $notices = array(
            'deactivation_file_detected' => array(
                'callback' => 'RSSSL()->really_simple_ssl->check_for_uninstall_file',
                'score' => 30,
                'output' => array(
                    'fail' => array(
                        'title' => __("Major security issue!", "really-simple-ssl"),
                        'msg' => __("The 'force-deactivate.php' file has to be renamed to .txt. Otherwise your ssl can be deactivated by anyone on the internet.", "really-simple-ssl") .' '.
                                 '<a href="'.add_query_arg(array('page'=>'rlrsssl_really_simple_ssl'), admin_url('options-general.php?page=')).'">'.__("Check again", "really-simple-ssl").'</a>',
                        'icon' => 'warning',
                        'admin_notice' => true,
                        'plusone' => true,
                    ),
                ),
            ),

            'non_default_plugin_folder' => array(
                'callback' => 'RSSSL()->really_simple_ssl->uses_default_folder_name',
                'score' => 30,
                'output' => array(
                    'false' => array(
	                    'msg' => sprintf(__("The Really Simple SSL plugin folder in the /wp-content/plugins/ directory has been renamed to %s. This might cause issues when deactivating, or with premium add-ons. To fix this you can rename the Really Simple SSL folder back to the default %s.", "really-simple-ssl"),"<b>" . $current_plugin_folder . "</b>" , "<b>really-simple-ssl</b>"),
	                    'url' => 'https://really-simple-ssl.com/knowledge-base/why-you-should-use-the-default-plugin-folder-name-for-really-simple-ssl/',
                        'icon' => 'warning',
                        'admin_notice' => false,
                    ),
                ),
            ),

            'mixed_content_scan' => array(
                'dismiss_on_upgrade' => true,
	            'condition' => array('rsssl_ssl_enabled'),
	            'callback' => '_true_',
	            'score' => 5,
	            'output' => array(
		            'true' => array(
                        'url' => 'https://really-simple-ssl.com/knowledge-base/how-to-track-down-mixed-content-or-insecure-content/',
			            'msg' => __("SSL is now activated. Check if your website is secure by following this article.", "really-simple-ssl"),
			            'icon' => 'open',
			            'dismissible' => true,
			            'plusone' => true,
		            ),
	            ),
            ),

            'google_analytics' => array(
	            'dismiss_on_upgrade' => true,
	            'callback' => '_true_',
                'condition' => array('rsssl_ssl_enabled', 'rsssl_ssl_activation_time_no_longer_then_3_days_ago'),
                'score' => 5,
                'output' => array(
                    'true' => array(
                        'msg' => __("Don't forget to change your settings in Google Analytics and Search Console.", "really-simple-ssl"),
                        'url' => 'https://really-simple-ssl.com/knowledge-base/how-to-setup-google-analytics-and-google-search-consolewebmaster-tools/',
                        'icon' => 'open',
                        'dismissible' => true,
                        'plusone' => true,
                    ),
                ),
            ),

            'upgraded_to_four' => array(
	            'callback' => 'RSSSL()->really_simple_ssl->upgraded_to_four',
	            'score' => 5,
	            'output' => array(
		            'true' => array(
                        'url' => __('https://really-simple-ssl.com/really-simple-ssl-4-a-new-dashboard'),
			            'msg' => __("Really Simple SSL 4.0. Learn more about our newest major release.", "really-simple-ssl"),
			            'icon' => 'open',
			            'dismissible' => true,
			            'plusone' => true,
		            ),
	            ),
            ),

            'ssl_enabled' => array(
                'callback' => 'rsssl_ssl_enabled',
                'score' => 30,
                'output' => array(
                    'true' => array(
                        'msg' =>__('SSL is enabled on your site.', 'really-simple-ssl'),
                        'icon' => 'success'
                    ),
                    'false' => array(
                        'msg' => __('SSL is not enabled yet.', 'really-simple-ssl'),
                        'icon' => 'warning',
                    ),
                ),
            ),

            'ssl_detected' => array(
	            'callback' => 'rsssl_ssl_detected',
	            'score' => 30,
	            'output' => array(
		            'fail' => array(
			            'msg' =>__('Cannot activate SSL due to system configuration.', 'really-simple-ssl'),
			            'icon' => 'warning'
		            ),
		            'no-ssl-detected' => array(
			            'title' => __("No SSL detected", "really-simple-ssl"),
			            'msg' => sprintf(__("No SSL detected. See our guide on how to %sget a free SSL certificate%s. If you do have an SSL certificate, try to reload this page over https by clicking this link: %sReload over https.%s", "really-simple-ssl"), '<a target="_blank" href="https://really-simple-ssl.com/knowledge-base/how-to-install-a-free-ssl-certificate-on-your-wordpress-cpanel-hosting/">', '</a>', '<a href="' .$reload_https_url . '">' , '</a>'),
			            'icon' => 'warning',
			            'admin_notice' => true,
		            ),
		            'ssl-detected' => array(
			            'msg' => __('An SSL certificate was detected on your site.', 'really-simple-ssl'),
			            'icon' => 'success'
		            ),
	            ),
            ),

            'mixed_content_fixer_detected' => array(
                'condition' => array('rsssl_site_has_ssl', 'rsssl_ssl_enabled'),
                'callback' => 'RSSSL()->really_simple_ssl->mixed_content_fixer_detected',
                'score' => 10,
                'output' => array(
                    'found' => array(
                        'msg' =>__('Mixed content fixer was successfully detected on the front-end.', 'really-simple-ssl'),
                        'icon' => 'success'
                    ),
                    'no-response' => array(
                        'url' => 'https://really-simple-ssl.com/knowledge-base/how-to-fix-no-response-from-webpage-warning/',
                        'msg' => __('Really Simple SSL has received no response from the webpage.', 'really-simple-ssl'),
                        'icon' => 'open',
                        'dismissible' => true,
                        'plusone' => true
                    ),
                    'not-found' => array(
                        'url' => "https://really-simple-ssl.com/knowledge-base/how-to-check-if-the-mixed-content-fixer-is-active/",
                        'msg' => __('The mixed content fixer is active, but was not detected on the frontpage.', "really-simple-ssl"),
                        'icon' => 'open',
                        'dismissible' => true
                    ),
                    'error' => array(
	                    'msg' =>__('Error occurred when retrieving the webpage.', 'really-simple-ssl'),
	                    'icon' => 'open',
                        'dismissible' => true
                    ),
                    'not-enabled' => array(
	                    'url' => $this->generate_enable_link($setting_name = 'autoreplace_insecure_links', $type='free'),
	                    'msg' =>__('Mixed content fixer not enabled. Enable the option to fix mixed content on your site.', 'really-simple-ssl'),
                        'icon' => 'open',
                        'dismissible' => true
                    ),
                    'curl-error' => array(
                        'url' => 'https://really-simple-ssl.com/knowledge-base/curl-errors/',
	                    'msg' =>sprintf(__("The mixed content fixer could not be detected due to a cURL error: %s. cURL errors are often caused by an outdated version of PHP or cURL and don't affect the front-end of your site. Contact your hosting provider for a fix.", 'really-simple-ssl'), "<b>" . $curl_error . "</b>"),
	                    'icon' => 'open',
                        'dismissible' => true,
                    ),
                ),
            ),

            'wordpress_redirect' => array(
	            'condition' => array('rsssl_ssl_enabled'),
	            'callback' => 'RSSSL()->really_simple_ssl->has_301_redirect',
                'score' => 10,
                'output' => array(
                     'true' => array(
                        'msg' => __('301 redirect to https set.', 'really-simple-ssl'),
                        'icon' => 'success'
                        ),
                     'false' => array(
                         'msg' => __('No 301 redirect is set. Enable the WordPress 301 redirect in the settings to get a 301 permanent redirect.', 'really-simple-ssl'),
                         'icon' => 'open'
                     ),
                )
            ),

            'check_redirect' => array(
	            'condition' => array('rsssl_ssl_enabled' , 'RSSSL()->really_simple_ssl->htaccess_redirect_allowed', 'NOT is_multisite'),
	            'callback' => 'rsssl_check_redirect',
                'score' => 10,
	            'output' => array(
                    'htaccess-redirect-set' => array(
                        'msg' =>__('301 redirect to https set: .htaccess redirect.', 'really-simple-ssl') ,
                        'icon' => 'success'
                    ),
                    'wp-redirect-to-htaccess' => array(
                        'url' => $this->generate_enable_link($setting_name = 'wp-redirect-to-htaccess', $type='free'),
                        'msg' => __('WordPress 301 redirect enabled. We recommend to enable a 301 .htaccess redirect.', 'really-simple-ssl'),
                        'icon' => 'open',
                        'plusone' => RSSSL()->rsssl_server->uses_htaccess(),
                        'dismissible' => true,
                    ),
                    'no-redirect-set' => array(
                        'msg' => __('Enable a .htaccess redirect or WordPress redirect in the settings to create a 301 redirect.', 'really-simple-ssl') ,
                        'icon' => 'open',
                        'dismissible' => false
                    ),
                    'htaccess-not-writeable' => array(
                        'url' => 'https://really-simple-ssl.com/knowledge-base/manually-insert-htaccess-redirect-http-to-https/',
                        'msg' => sprintf(__('The %s file is not writable. You can either use the WordPress redirect, add the rules manually, or set the file to %swritable%s.', 'really-simple-ssl'), $htaccess_file, '<a target="_blank" href="https://really-simple-ssl.com/knowledge-base/htaccess-wp-config-files-not-writable/">', '</a>'),
                        'icon' => 'warning',
                        'dismissible' => true
                    ),
                    'htaccess-rules-test-failed' => array(
	                    'url' => 'https://really-simple-ssl.com/knowledge-base/manually-insert-htaccess-redirect-http-to-https/',
	                    'msg' => __('The .htaccess redirect rules selected by this plugin failed in the test. Set manually or dismiss to leave on WordPress redirect.', 'really-simple-ssl') . $rules,
                        'icon' => 'warning',
                        'dismissible' => true,
                        'plusone'=>true,
                    ),
                ),
            ),

            'elementor' => array(
	            'condition' => array( 'rsssl_ssl_activation_time_no_longer_then_3_days_ago'),
	            'callback' => 'rsssl_uses_elementor',
	            'score' => 5,
	            'output' => array(
		            'true' => array(
                        'url' => 'https://really-simple-ssl.com/knowledge-base/how-to-fix-mixed-content-in-elementor-after-moving-to-ssl/',
			            'msg' => __("Your site uses Elementor. This can require some additional steps before getting the secure lock.", "really-simple-ssl"),
			            'icon' => 'open',
			            'dismissible' => true
		            ),
	            ),
            ),

            'divi' => array(
	            'condition' => array( 'rsssl_ssl_activation_time_no_longer_then_3_days_ago'),
	            'callback' => 'rsssl_uses_divi',
	            'score' => 5,
	            'output' => array(
		            'true' => array(
                        'url' => "https://really-simple-ssl.com/knowledge-base/mixed-content-when-using-divi-theme/",
			            'msg' => __("Your site uses Divi. This can require some additional steps before getting the secure lock.", "really-simple-ssl"),
			            'icon' => 'open',
			            'dismissible' => true
		            ),
	            ),
            ),

            'hsts_enabled' => array(
                'condition' => array('NOT is_multisite'),
                'callback' => 'RSSSL()->really_simple_ssl->contains_hsts',
                'score' => 5,
                'output' => array(
                    'true' => array(
                        'msg' =>__('HTTP Strict Transport Security was enabled.', 'really-simple-ssl'),
                        'icon' => 'success'
                    ),
                    'false' => array(
                        'msg' => sprintf(__('HTTP Strict Transport Security is not enabled %s(Read more)%s.', "really-simple-ssl"), '<a href="https://really-simple-ssl.com/hsts-http-strict-transport-security-good/" target="_blank">', '</a>' ),
                        'icon' => 'premium'
                    ),
                ),
            ),

            'secure_cookies_set' => array(
	            'condition' => array(
	                    'rsssl_ssl_enabled',
                        'RSSSL()->really_simple_ssl->can_apply_networkwide',
                ),
	            'callback' => 'RSSSL()->really_simple_ssl->secure_cookie_settings_status',
                'score' => 5,
                'output' => array(
                    'set' => array(
                        'msg' =>__('New feature! HttpOnly Secure cookies have been set automatically!', 'really-simple-ssl'),
                        'icon' => 'open',
                        'dismissible' => true,
                        'plusone' => true,
                        'url' => 'https://really-simple-ssl.com/secure-cookies-with-httponly-secure-and-use_only_cookies/',
                    ),
                    'not-set' => array(
	                    'msg' => __('HttpOnly Secure cookies not set.', 'really-simple-ssl'),
	                    'icon' => 'warning',
	                    'dismissible' => true,
	                    'plusone' => true,
                        'url' => 'https://really-simple-ssl.com/secure-cookies-with-httponly-secure-and-use_only_cookies/',
                    ),
                    'wpconfig-not-writable' => array(
                        'msg' =>    __("To set the httponly secure cookie settings, your wp-config.php has to be edited, but the file is not writable.","really-simple-ssl").'&nbsp;'.__("Add the following lines of code to your wp-config.php.","really-simple-ssl") .
                                    "<br><br><code>
                                            //Begin Really Simple SSL session cookie settings <br>
                                            &nbsp;&nbsp;@ini_set('session.cookie_httponly', true); <br>
                                            &nbsp;&nbsp;@ini_set('session.cookie_secure', true); <br>
                                            &nbsp;&nbsp;@ini_set('session.use_only_cookies', true); <br>
                                            //END Really Simple SSL cookie settings <br>
                                        </code><br>
                                    ".__("Or set your wp-config.php to writable and reload this page.", "really-simple-ssl"),
                        'icon' => 'warning',
                        'dismissible' => true,
                        'plusone' => true,
                        'url' => 'https://really-simple-ssl.com/secure-cookies-with-httponly-secure-and-use_only_cookies/',
                    )
                ),
            ),

            'recommended_security_headers_not_set' => array(
	            'condition' => array(
		            'rsssl_ssl_enabled',
	            ),
                'callback' => 'RSSSL()->really_simple_ssl->recommended_security_headers_set',
	            'score' => 5,
	            'output' => array(
		            'false' => array(
			            'msg' => sprintf(__("Recommended security headers not enabled (%sRead more%s).", "really-simple-ssl"), '<a target="_blank" href="https://really-simple-ssl.com/everything-you-need-to-know-about-security-headers/">', '</a>'),
			            'icon' => 'premium'
		            ),
		            'true' => array(
			            'msg' => sprintf(__("Recommended security headers enabled (%sRead more%s).", "really-simple-ssl"), '<a target="_blank" href="https://really-simple-ssl.com/everything-you-need-to-know-about-security-headers/">', '</a>'),
			            'icon' => 'success'
		            ),
	            ),
            ),
            'uses_wp_engine' => array(
                'condition' => array('rsssl_uses_wp_engine'),
                'callback' => '_true_',
                'score' => 5,
                'output' => array(
                    'true' => array(
                        'msg' =>__('Due to a recent update by WP Engine, we have changed your settings automatically to adapt.', 'really-simple-ssl'),
                        'url' => 'https://really-simple-ssl.com/really-simple-ssl-adapts-to-recent-wp-engine-changes/',
                        'icon' => 'open',
                        'dismissible' => true
                    ),
                ),
            ),
        );

        $notices = apply_filters('rsssl_notices', $notices);
        foreach ($notices as $id => $notice) {
            $notices[$id] = wp_parse_args($notice, $notice_defaults);
        }

	    /**
	     * If a list of notices that should be dismissed on upgrade is requested
	     */
	    if ( $args['dismiss_on_upgrade'] ) {
		    $output = array();
            foreach( $notices as $key => $notice ) {
                if ( isset($notice['dismiss_on_upgrade']) && $notice['dismiss_on_upgrade'] ) {
                    $output[] = $key;
                }
            }
		    return $output;
	    }

	    /**
	     * Filter out notice that do not apply, or are dismissed
	     */

	    foreach ( $notices as $id => $notice ) {
		    if (get_option( "rsssl_" . $id . "_dismissed" )) {
			    unset($notices[$id]);
			    continue;
		    }

		    $func   = $notice['callback'];
		    $output = $this->validate_function($func);

            //check if all notices should be dismissed
            if ( ( isset( $notice['output'][$output]['dismissible'] )
                && $notice['output'][$output]['dismissible']
                && ( $this->dismiss_all_notices ) )
            ) {
                unset($notices[$id]);
                continue;
            }

            if ( !isset($notice['output'][ $output ]) ) {
	            unset($notices[$id]);
	            continue;
            } else {
                $notices[$id]['output'] = $notice['output'][ $output ];
            }

		    $notices[$id]['output']['status'] = ( $notices[$id]['output']['icon'] !== 'success') ? 'open' : 'completed';

		    if ( $args['status'] === 'open' && ($notices[$id]['output']['status'] === 'completed' ) ){
			    unset($notices[$id]);
			    continue;
            }

		    $condition_functions = $notice['condition'];
		    foreach ( $condition_functions as $func ) {
			    $condition = $this->validate_function($func, true);
			    if ( ! $condition ) {
				    unset($notices[$id]);
			    }
		    }
	    }

        //if only admin_notices are required, filter out the rest.
	    if ( $args['admin_notices'] ) {
            foreach ( $notices as $id => $notice ) {
                if (!isset($notice['output']['admin_notice']) || !$notice['output']['admin_notice']){
	                unset( $notices[$id]);
                }
            }
		    set_transient('rsssl_admin_notices', $notices, DAY_IN_SECONDS );
        }

	    //sort so warnings are on top
	    $warnings = array();
	    $open = array();
	    $other = array();
	    foreach ($notices as $key => $notice){
	        if ($notice['output']['icon']==='warning') {
	            $warnings[$key] = $notice;
            } else if ($notice['output']['icon']==='open') {
		        $open[$key] = $notice;
	        } else {
		        $other[$key] = $notice;
	        }
        }

	    $notices = $warnings + $open + $other;

	    //add plus ones, but not when in admin notice
        if ( !$args['admin_notices'] ) {
	        foreach ( $notices as $key => $notice ) {
		        if ( isset( $notice['output']['url'] ) ) {
			        $url    = $notice['output']['url'];
			        $target = '';
			        if ( strpos( $url, 'https://really-simple-ssl.com' ) !== false ) {
				        $info   = __( '%sMore info%s or %sdismiss%s', 'really-simple-ssl' );
				        $target = 'target="_blank"';
			        } else {
				        $info = __( '%sEnable%s or %sdismiss%s', 'really-simple-ssl' );
			        }
			        $dismiss_open                     = "<span class='rsssl-dashboard-dismiss' data-dismiss_type='" . $key . "'><a href='#' class='rsssl-dismiss-text rsssl-close-warning'>";
			        $notices[ $key ]['output']['msg'] .= ' ' . sprintf( $info, '<a ' . $target . ' href="' . $url . '">', '</a>', $dismiss_open, "</a></span>" );
		        }

		        if ( isset( $notice['output']['plusone'] ) && $notice['output']['plusone'] ) {
			        $plusone                          = "<span class='rsssl-dashboard-plusone update-plugins rsssl-update-count'><span class='update-count'>1</span></span>";
			        $notices[ $key ]['output']['msg'] .= $plusone;
		        }
	        }
        }
	    //if we only want a list of premium notices
	    if ( $args['premium_only'] ) {
		    foreach ($notices as $key => $notice){
			    if ( !isset($notice['output']['icon']) || $notice['output']['icon'] !== 'premium' ) {
				    unset($notices[$key]);
			    }
		    }
        }
        return $notices;
    }

	/**
     * Count number of premium notices we have in the list.
	 * @return int
	 */
    public function get_lowest_possible_task_count(){
        $premium_notices = $this->get_notices_list(array('premium_only'=>true));
        return count($premium_notices) ;
    }

	/**
     * Get output of function, in format 'function', or 'class()->sub()->function'
	 * @param string $func
     * @param bool $is_condition // if the check is a condition, which should return a boolean
     * @return string|bool
	 */

    private function validate_function($func, $is_condition = false ){
	    $invert = false;
	    if (strpos($func, 'NOT ') !== FALSE ) {
		    $func = str_replace('NOT ', '', $func);
		    $invert = true;
	    }

	    if ( $func === '_true_') {
	        $output = true;
        } else if ( $func === '_false_' ) {
		    $output = false;
	    } else {
		    if ( preg_match( '/(.*)\(\)\-\>(.*)->(.*)/i', $func, $matches)) {
			    $base = $matches[1];
			    $class = $matches[2];
			    $function = $matches[3];
			    $output = call_user_func( array( $base()->{$class}, $function ) );
		    } else {
			    $output = $func();
		    }

		    if ( $invert ) {
			    $output = !$output;
		    }
        }

	    //stringyfy booleans
        if (!$is_condition) {
	        if ( $output === false || $output === 0 ) {
		        $output = 'false';
	        }
	        if ( $output === true || $output === 1 ) {
		        $output = 'true';
	        }
        }
	    return sanitize_text_field($output);
    }

    /**
     * Calculate the percentage completed in the dashboard progress section
     * Determine max score by adding $notice['score'] to the $max_score variable
     * Determine actual score by adding $notice['score'] of each item with a 'success' output to $actual_score
     * @return int
     *
     * @since 4.0
     *
     */

    public function get_score_percentage() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return 0;
        }

        $max_score    = 0;
        $actual_score = 0;
        $notices = $this->get_notices_list(array(
                'status' => 'all',
        ));
        foreach ( $notices as $id => $notice ) {
            if (isset( $notice['score'] )) {
                // Only items matching condition will show in the dashboard. Only use these to determine max count.
                $max_score = $max_score + intval( $notice['score'] );
                $success = ( isset( $notice['output']['icon'] )
                             && ( $notice['output']['icon']
                                  === 'success' ) ) ? true : false;
                if ( $success ) {
                    // If the output is success, task is completed. Add to actual count.
                    $actual_score = $actual_score + intval( $notice['score'] );
                }
            }
        }
        if ($max_score>0) {
	        $score = $actual_score / $max_score;
        } else {
            $score = 0;
        }
        $score = $score * 100;
        $score = intval( round( $score ) );

        return $score;
    }

	/**
     * Generate an enable link for the specific setting, redirects to settings page and highlights the setting.
     *
	 * @param string $setting_name
	 * @param string $type
	 *
	 * @return string
	 */

    public function generate_enable_link($setting_name, $type = 'free' )
    {
	    if ( is_network_admin() ) {
		    $page = "really-simple-ssl";
		    $wp_page = network_admin_url('settings.php' );
	    } else {
		    $page = "rlrsssl_really_simple_ssl";
		    $wp_page = admin_url('options-general.php');
	    }
        $args = array(
                "page" => $page,
                "highlight" => $setting_name
        );

	    if ( $type === 'premium' && !is_network_admin() ) {
		    $args['tab'] = 'premium';
        }

	    return add_query_arg($args, $wp_page);
    }

	/**
	 * @param $id
	 * @param $notice
     *
     * Generate a notice row in the configuration dashboard tab
     *
     * @since 3.2
     *
	 */

    private function notice_row($id, $notice){
        if (!current_user_can('manage_options')) return;

        if (!isset($notice['output'])) {
            return;
        }

        $msg = $notice['output']['msg'];
        $icon_type = $notice['output']['icon'];

        // Do not show completed tasks if remaining tasks are selected.
        if ($icon_type === 'success' && !get_option('rsssl_all_tasks') && get_option('rsssl_remaining_tasks')) return;

        $icon = $this->icon($icon_type);
        $dismiss = (isset($notice['output']['dismissible']) && $notice['output']['dismissible']) ? $this->rsssl_dismiss_button() : '';

        ?>
        <tr>
            <td><?php echo $icon?></td><td class="rsssl-table-td-main-content"><?php echo $msg?></td>
            <td class="rsssl-dashboard-dismiss" data-dismiss_type="<?php echo $id?>"><?php echo $dismiss?></td>
        </tr>
        <?php
    }



	/**
     * Count the plusones
     *
	 * @return int
     *
     * @since 3.2
	 */

	public function count_plusones() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return 0;
		}

		$cache = $this->is_settings_page() ? false : true;
		$count = get_transient( 'rsssl_plusone_count' );
		if ( !$cache || ($count === false) ) {
			$count = 0;
			$notices = $this->get_notices_list();
			foreach ( $notices as $id => $notice ) {
                $success = ( isset( $notice['output']['icon'] ) && ( $notice['output']['icon'] === 'success' ) ) ? true : false;
                if ( ! $success
                     && isset( $notice['output']['plusone'] )
                     && $notice['output']['plusone']
                ) {
                    $count++;
                }
			}
			set_transient( 'rsssl_plusone_count', $count, WEEK_IN_SECONDS );
		}
		return $count;
	}

    /**
     * Build the settings page
     *
     * @since  2.0
     *
     * @access public
     *
     */

	public function general_grid(){
		$grid_items = array(
			'progress' =>array(
				'title' => __("Your progress", "really-simple-ssl"),
				'header' => rsssl_template_path . 'progress-header.php',
				'content' => rsssl_template_path . 'progress.php',
				'footer' => rsssl_template_path . 'progress-footer.php',
				'class' => 'regular rsssl-progress',
				'type' => 'all',
			),
			'settings' => array(
				'title' => __("Settings", "really-simple-ssl"),
                'content' => rsssl_template_path . 'settings.php',
				'footer' => rsssl_template_path . 'settings-footer.php',
				'class' => 'small settings',
				'type' => 'settings',
			),
            'tipstricks' => array(
                'title' => __("Tips & Tricks", "really-simple-ssl"),
                'header' => '',
                'content' => rsssl_template_path . 'tips-tricks.php',
                'footer' => rsssl_template_path . 'tips-tricks-footer.php',
                'class' => 'small',
                'type' => 'popular',
            ),
            'plugins' => array(
                'title' => __("Our plugins", "really-simple-ssl"),
                'header' => rsssl_template_path . 'our-plugins-header.php',
                'content' => rsssl_template_path . 'other-plugins.php',
                'class' => 'half-height no-border no-background upsell-grid-container',
                'can_hide' => false,
            ),
			'support' => array(
				'title' => __("Support forum", "really-simple-ssl"),
				'header' => '',
				'content' => rsssl_template_path . 'support.php',
				'footer' => rsssl_template_path . 'support-footer.php',
				'type' => 'tasks',
				'class' => 'half-height',
			),
		);
		return apply_filters( 'rsssl_grid_items',  $grid_items );
	}

    /**
     * Save the task toggle option
     * @since 4.0
     */

    public function update_task_toggle_option() {

        if (!isset($_POST['token']) || (!wp_verify_nonce($_POST['token'], 'rsssl_nonce'))) {
            return;
        }

        if (!isset($_POST["action"]) && $_POST["action"] ==! 'rsssl_update_task_toggle_option') return;

        if (!isset($_POST['alltasks']) || (!isset($_POST['remainingtasks']) ) ) return;

        if ($_POST['alltasks'] === 'checked') {
            update_option('rsssl_all_tasks', true);
        } else {
            update_option('rsssl_all_tasks', false);
        }

        if ($_POST['remainingtasks'] === 'checked') {
            update_option('rsssl_remaining_tasks', true);
        } else {
            update_option('rsssl_remaining_tasks', false);
        }

        wp_die();
    }

	/**
     * Get count of all tasks
	 * @return int
	 */
    public function get_all_task_count() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return 0;
        }

        $count = count($this->get_notices_list(
            array( 'status' => 'all' )
        ));

        return $count;
    }

    /**
     * @return int
     *
     * Get the remaining open task count, shown in the progress header
     *
     */

    public function get_remaining_tasks_count() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return 0;
        }

        $cache = !$this->is_settings_page();

        $count = get_transient( 'rsssl_remaining_task_count' );
        if ( !$cache || $count === false ) {
            $count = count($this->get_notices_list(
                    array( 'status' => 'open' )
            ) );
            set_transient( 'rsssl_remaining_task_count', $count, DAY_IN_SECONDS );
        }

        return $count;
    }

    /**
     * Get status link for plugin, depending on installed, or premium availability
     * @param $item
     *
     * @return string
     */

    public function get_status_link($item){
        if (!defined($item['constant_free']) && !defined($item['constant_premium'])) {
            $args = array(
                "s" => $item['search'],
                "tab" => "search",
                "type" => "term"
            );
            $admin_url= is_multisite() ? network_admin_url('plugin-install.php') : admin_url('plugin-install.php');
	        $link = add_query_arg( $args, $admin_url );
	        $status = '<a href="'.esc_url_raw($link).'">'.__('Install', 'really-simple-ssl').'</a>';
        } elseif (isset($item['constant_premium']) && !defined($item['constant_premium'])) {
	        $link = $item['website'];
	        $status = '<a href="'.esc_url_raw($link).'">'.__('Upgrade to pro', 'really-simple-ssl').'</a>';
        } else {
	        $status = __( "Installed", "really-simple-ssl" );
        }
        return $status;
    }

	/**
	 * Render the settings page
	 */

    public function settings_page()
    {
        if (!current_user_can($this->capability)) return;
        if (isset ($_GET['tab'])) $this->admin_tabs($_GET['tab']); else $this->admin_tabs('configuration');
        if (isset ($_GET['tab'])) $tab = $_GET['tab']; else $tab = 'configuration';
        ?>
        <div class="rsssl-container">
            <div class="rsssl-main"><?php
                switch ($tab) {
                    case 'configuration' :
                    $this->render_grid($this->general_grid());
                    do_action("rsssl_configuration_page");
                }
                //possibility to hook into the tabs.
                do_action("show_tab_{$tab}");
                ?>
            </div>
        </div>
        <?php
    }

	/**
     * Render grid from grid array
	 * @param array $grid
	 */
    public function render_grid($grid){
	    $container = $this->get_template('grid-container.php', rsssl_path . 'grid/');
	    $element = $this->get_template('grid-element.php', rsssl_path . 'grid/');
	    $output = '';
	    $defaults = array(
		    'title' => '',
		    'header' => rsssl_template_path . 'header.php',
		    'content' => '',
		    'footer' => '',
		    'class' => '',
		    'type' => 'plugins',
		    'can_hide' => true,
		    'instructions' => false,
	    );
	    foreach ($grid as $index => $grid_item) {
		    $grid_item = wp_parse_args($grid_item, $defaults);
		    $footer = $this->get_template_part($grid_item, 'footer', $index);
		    $content = $this->get_template_part($grid_item, 'content', $index);
		    $header = $this->get_template_part($grid_item, 'header', $index);
            $instructions = $grid_item['instructions'] ? '<a href="'.esc_url($grid_item['instructions']).'" target="_blank">'.__("Instructions manual", "really-simple-ssl").'</a>' : '';
		    // Add form if type is settings
		    $block = str_replace(array('{class}', '{title}', '{header}', '{content}', '{footer}', '{instructions}'), array($grid_item['class'], $grid_item['title'], $header, $content, $footer, $instructions), $element);
		    $output .= $block;
	    }

	    echo str_replace('{content}', $output, $container);
    }

	/**
     * Render grid item based on template
	 * @param array $grid_item
	 * @param string $key
     * @oaram string $index
	 *
	 * @return string
	 */

    public function get_template_part($grid_item, $key, $index) {

	    if ( !isset($grid_item[$key]) || !$grid_item[$key] ) {
		    $template_part = '';
	    } else {
		    if ( strpos( $grid_item[ $key ], '.php' ) !== false && file_exists($grid_item[ $key ])  ) {
		        ob_start();
			    require $grid_item[ $key ];
			    $template_part = ob_get_clean();
		    } else {
			    $template_part = '';
            }
	    }

	    if ($key === 'content' ) {
		    if ( $grid_item['type'] === 'scan' ) {
			    $template_part = '<form id="rsssl_scan_form" action="" method="post">'.$template_part;
		    } elseif ( $grid_item['type'] === 'settings' ) {
			    if ( is_network_admin() ) {
				    $template_part = '<form action="edit.php?action=rsssl_update_network_settings" method="post">'.wp_nonce_field('rsssl_ms_settings_update', 'rsssl_ms_nonce').
				                     $template_part;
			    } else {
				    $template_part = '<form action="options.php" method="post">'.$template_part;
			    }
		    }
	    }

	    if ( $key === 'footer' && ( $grid_item['type'] === 'scan' || $grid_item['type'] === 'settings') ) {
		    $template_part .= '</form>';
	    }

	    return apply_filters("rsssl_template_part_".$key.'_'.$index, $template_part, $grid_item);
    }

    /**
     * Returns a success, error or warning image for the settings page
     *
     * @since  2.0
     *
     * @access public
     *
     * @param string $type the type of image
     *
     * @return string
     */

    public function icon($type)
    {
        if ($type == 'success') {
            return "<span class='rsssl-progress-status rsssl-success'>".__("Completed", "really-simple-ssl")."</span>";
        } elseif ($type == "warning") {
            return "<span class='rsssl-progress-status rsssl-warning'>".__("Warning", "really-simple-ssl")."</span>";
        } elseif ($type == "open") {
            return "<span class='rsssl-progress-status rsssl-open'>".__("Open", "really-simple-ssl")."</span>";
        } elseif ($type == "premium") {
	        return "<span class='rsssl-progress-status rsssl-premium'>".__("Premium", "really-simple-ssl")."</span>";
        }
    }

    /**
     *
     * Add a dismiss button which will dismiss the nearest <tr>. Used on 'Configuration' dashboard page
     *
     * @since 3.1.6
     *
     */

    public function rsssl_dismiss_button()
    {
         return '<button type="button" class="close">
                <span class="rsssl-close-warning-x">X</span>
            </button>';
    }

    /**
     * @param $args
     *
     * @since 3.0
     *
     * Generate the HTML for the settings page sidebar
     *
     */

    private function get_banner_html($args)
    {
        $default = array(
            'pro' => false,
        );

        $args = wp_parse_args($args, $default);

        $pro = $args['pro'] ? '-pro' : '';
        ?>
        <div class="rsssl-sidebar-single-content-container<?php echo $pro ?>">
            <img class="rsssl-sidebar-image<?php echo $pro ?>"
                 src="<?php echo trailingslashit(rsssl_url) . 'assets/' . $args['img'] ?>"
                 alt="<?php echo $args['title'] ?>">
            <div class="rsssl-sidebar-text-content<?php echo $pro ?>">
                <?php echo $args['description'] ?>
            </div>
            <div class="rsssl-more-info-button">
                <a id="rsssl-premium-button<?php echo $pro ?>" class="button"
                   href="<?php echo $args['url'] ?>"
                   target="_blank"> <?php echo __("More info", "really-simple-ssl") ?> </a>
            </div>
        </div>
        <?php
    }

    /**
     * Add some css for the settings page
     *
     * @since  2.0
     *
     * @access public
     *
     */

    public function enqueue_assets($hook)
    {
        //load on network admin or normal admin settings page
        if ( $hook !== 'settings_page_really-simple-ssl' && $hook !== 'settings_page_rlrsssl_really_simple_ssl' ) return;

        if (is_rtl()) {
            wp_register_style('rlrsssl-css', trailingslashit(rsssl_url) . 'css/main-rtl.min.css', array(), rsssl_version);
            wp_register_style('rsssl-grid', trailingslashit(rsssl_url) . 'grid/css/grid-rtl.min.css', array(), rsssl_version);
        } else {
	        wp_register_style('rlrsssl-css', trailingslashit(rsssl_url) . 'css/main.min.css', array(), rsssl_version );
            wp_register_style('rsssl-grid', trailingslashit(rsssl_url) . 'grid/css/grid.css', array(), rsssl_version );
        }

        wp_register_style('rsssl-scrollbar', trailingslashit(rsssl_url) . 'includes/simple-scrollbar.css', "", rsssl_version);
        wp_enqueue_style('rsssl-scrollbar');

	    wp_enqueue_style('rlrsssl-css');
	    wp_enqueue_style('rsssl-grid');

        wp_register_script('rsssl',
            trailingslashit(rsssl_url)
            . 'js/scripts.js', array("jquery"), rsssl_version);
        wp_enqueue_script('rsssl');

        $finished_text = apply_filters('rsssl_finished_text', sprintf(__("Basic SSL configuration finished! Improve your score with %sReally Simple SSL Pro%s.", "really-simple-ssl"), '<a target="_blank" href="' . $this->pro_url . '">', '</a>') );
	    if ($this->ssl_enabled) {
		    $ssl_status = __( "SSL is activated on your site.",  'really-simple-ssl' );
        } else {
		    $ssl_status = __( "SSL is not yet enabled on this site.",  'really-simple-ssl' );
        }

	    $not_completed_text_singular =  $ssl_status.' '. __("You still have %s task open.",  'really-simple-ssl' );
	    $not_completed_text_plural =  $ssl_status .' '.__(" You still have %s tasks open.",  'really-simple-ssl' );

        wp_localize_script('rsssl', 'rsssl',
            array(
                'ajaxurl' => admin_url( 'admin-ajax.php' ),
                'token'   => wp_create_nonce( 'rsssl_nonce'),
                'copied_text' => __("Copied!", "really-simple-ssl"),
                'finished_text' => $finished_text,
                'not_complete_text_singular' => $not_completed_text_singular,
                'not_complete_text_plural' => $not_completed_text_plural,
                'lowest_possible_task_count' =>	RSSSL()->really_simple_ssl->get_lowest_possible_task_count(),
            )
        );
        wp_register_script('rsssl-scrollbar',
            trailingslashit(rsssl_url)
            . 'includes/simple-scrollbar.js', array("jquery"), rsssl_version);
        wp_enqueue_script('rsssl-scrollbar');
    }

    /**
     * Create the settings page form
     *
     * @since  2.0
     *
     * @access public
     *
     */

    public function create_form()
    {
        register_setting('rlrsssl_options', 'rlrsssl_options', array($this, 'options_validate'));
        add_settings_section('rlrsssl_settings', __("Settings", "really-simple-ssl"), array($this, 'section_text'), 'rlrsssl');

	    $help_tip = RSSSL()->rsssl_help->get_help_tip(__("In most cases you need to leave this enabled, to prevent mixed content issues on your site.", "really-simple-ssl"), $return=true);
	    add_settings_field('id_autoreplace_insecure_links', $help_tip . "<div class='rsssl-settings-text'>" . __("Mixed content fixer", "really-simple-ssl"), array($this, 'get_option_autoreplace_insecure_links'), 'rlrsssl', 'rlrsssl_settings');

        //only show option to enable or disable mixed content and redirect when SSL is detected
        if ($this->ssl_enabled) {
	        $help_tip = RSSSL()->rsssl_help->get_help_tip(__("Redirects all requests over HTTP to HTTPS using a PHP 301 redirect. Enable if the .htaccess redirect cannot be used, for example on NGINX servers.", "really-simple-ssl"), $return=true);
	        add_settings_field('id_wp_redirect', $help_tip . "<div class='rsssl-settings-text'>" . __("Enable WordPress 301 redirect", "really-simple-ssl"), array($this, 'get_option_wp_redirect'), 'rlrsssl', 'rlrsssl_settings', ['class' => 'rsssl-settings-row'] );

            //when enabled networkwide, it's handled on the network settings page
            if (RSSSL()->rsssl_server->uses_htaccess() && (!is_multisite() || !RSSSL()->rsssl_multisite->ssl_enabled_networkwide)) {
	            $help_tip = RSSSL()->rsssl_help->get_help_tip(__("A .htaccess redirect is faster and works better with caching. Really Simple SSL detects the redirect code that is most likely to work (99% of websites), but this is not 100%. Make sure you know how to regain access to your site if anything goes wrong!", "really-simple-ssl"), $return=true);
	            add_settings_field('id_htaccess_redirect', $help_tip . "<div class='rsssl-settings-text'>" . __("Enable 301 .htaccess redirect", "really-simple-ssl"), array($this, 'get_option_htaccess_redirect'), 'rlrsssl', 'rlrsssl_settings');
            }
        }

        //on multisite this setting can only be set networkwide
        if (RSSSL()->rsssl_server->uses_htaccess() && !is_multisite()) {
	        $help_tip = RSSSL()->rsssl_help->get_help_tip(__("If you want to customize the Really Simple SSL .htaccess, you need to prevent Really Simple SSL from rewriting it. Enabling this option will do that.", "really-simple-ssl"), $return=true);
	        add_settings_field('id_do_not_edit_htaccess', $help_tip . "<div class='rsssl-settings-text'>" . __("Stop editing the .htaccess file", "really-simple-ssl"), array($this, 'get_option_do_not_edit_htaccess'), 'rlrsssl', 'rlrsssl_settings');
        }

        //don't show alternative mixed content fixer option if mixed content fixer is disabled.
	    if ($this->autoreplace_insecure_links) {
	        $help_tip = RSSSL()->rsssl_help->get_help_tip(__("If this option is set to true, the mixed content fixer will fire on the init hook instead of the template_redirect hook. Only use this option when you experience problems with the mixed content fixer.\"", "really-simple-ssl"), $return=true);
		    add_settings_field('id_switch_mixed_content_fixer_hook', $help_tip . "<div class='rsssl-settings-text'>" . __("Fire mixed content fixer with different method", "really-simple-ssl"), array($this, 'get_option_switch_mixed_content_fixer_hook'), 'rlrsssl', 'rlrsssl_settings');
	    }

	    $help_tip = RSSSL()->rsssl_help->get_help_tip(__("Enable this option to permanently dismiss all +1 notices in the 'Your progress' tab", "really-simple-ssl"), $return=true);
	    add_settings_field('id_dismiss_all_notices', $help_tip . "<div class='rsssl-settings-text'>" .  __("Dismiss all Really Simple SSL notices", "really-simple-ssl"), array($this, 'get_option_dismiss_all_notices'), 'rlrsssl', 'rlrsssl_settings');

    }

    /**
     * Insert some explanation above the form
     *
     * @since  2.0
     *
     * @access public
     *
     */

    public function section_text()
    {

    }

    /**
     * Check the posted values in the settings page for validity
     *
     * @since  2.0
     *
     * @access public
     *
     */

    public function options_validate($input)
    {
        //fill array with current values, so we don't lose any
        $newinput = array();
        $newinput['site_has_ssl'] = $this->site_has_ssl;
        $newinput['ssl_success_message_shown'] = $this->ssl_success_message_shown;
        $newinput['htaccess_warning_shown'] = $this->htaccess_warning_shown;
        $newinput['review_notice_shown'] = $this->review_notice_shown;
        $newinput['plugin_db_version'] = $this->plugin_db_version;
        $newinput['ssl_enabled'] = $this->ssl_enabled;

        if (!empty($input['hsts']) && $input['hsts'] == '1') {
            $newinput['hsts'] = TRUE;
        } else {
            $newinput['hsts'] = FALSE;
        }

        if (!empty($input['javascript_redirect']) && $input['javascript_redirect'] == '1') {
            $newinput['javascript_redirect'] = TRUE;
        } else {
            $newinput['javascript_redirect'] = FALSE;
        }

        if (!empty($input['wp_redirect']) && $input['wp_redirect'] == '1') {
            $newinput['wp_redirect'] = TRUE;
        } else {
            $newinput['wp_redirect'] = FALSE;
        }

        if (!empty($input['autoreplace_insecure_links']) && $input['autoreplace_insecure_links'] == '1') {
            $newinput['autoreplace_insecure_links'] = TRUE;
        } else {
            $newinput['autoreplace_insecure_links'] = FALSE;
        }

        if (!empty($input['do_not_edit_htaccess']) && $input['do_not_edit_htaccess'] == '1') {
            $newinput['do_not_edit_htaccess'] = TRUE;
        } else {
            $newinput['do_not_edit_htaccess'] = FALSE;
        }

        if (!empty($input['switch_mixed_content_fixer_hook']) && $input['switch_mixed_content_fixer_hook'] == '1') {
            $newinput['switch_mixed_content_fixer_hook'] = TRUE;
        } else {
            $newinput['switch_mixed_content_fixer_hook'] = FALSE;
        }

	    if (!empty($input['dismiss_all_notices']) && $input['dismiss_all_notices'] == '1') {
		    $newinput['dismiss_all_notices'] = TRUE;
	    } else {
		    $newinput['dismiss_all_notices'] = FALSE;
	    }

        if (!empty($input['htaccess_redirect']) && $input['htaccess_redirect'] == '1') {
            $newinput['htaccess_redirect'] = TRUE;
        } else {
            $newinput['htaccess_redirect'] = FALSE;
        }

        return $newinput;
    }


    /**
     * Insert option into settings form
     * @since  2.5.0
     *
     * @access public
     *
     */

    public function get_option_wp_redirect()
    {
        $wp_redirect = $this->wp_redirect;
        $disabled = "";
        $comment = "";

        if (is_multisite() && rsssl_multisite::this()->wp_redirect) {
            $disabled = "disabled";
            $wp_redirect = TRUE;
            $comment = __("This option is enabled on the network menu.", "really-simple-ssl");
        }
        ?>
        <label class="rsssl-switch">
            <input id="rlrsssl_options" name="rlrsssl_options[wp_redirect]" size="40" value="1"
                   type="checkbox" <?php echo $disabled?> <?php checked(1, $wp_redirect, true) ?> />
            <span class="rsssl-slider rsssl-round"></span>
        </label>
        <?php
	    RSSSL()->rsssl_help->get_comment($comment);
    }

    /**
     * Insert option into settings form
     * The .htaccess redirect is not shown for multisite sites that are enabled network wide.
     *
     * @since  2.5.8
     *
     * @access public
     *
     */

	public function get_option_htaccess_redirect()
	{
		$comment = $disabled = "";
		$link_open = '<a href="https://really-simple-ssl.com/knowledge-base/remove-htaccess-redirect-site-lockout/" target="_blank">';
		if (!$this->htaccess_redirect) $comment = sprintf(__("Before you enable the htaccess redirect, make sure you know how to %sregain access%s to your site in case of a redirect loop.", "really-simple-ssl"), $link_open, '</a>');
		//networkwide is not shown, so this only applies to per site activated sites.
		if (is_multisite() && RSSSL()->rsssl_multisite->htaccess_redirect) {
            $disabled = "disabled";
            $comment = __("This option is enabled on the network menu.", "really-simple-ssl");
		} elseif ($this->do_not_edit_htaccess) {
            //on multisite, the .htaccess do not edit option is not available
            $comment = __("If the setting 'stop editing the .htaccess file' is enabled, you can't change this setting.", "really-simple-ssl");
            $disabled = "disabled";
		}
		?>
        <label class="rsssl-switch" id="rsssl-maybe-highlight-wp-redirect-to-htaccess">
            <input id="rlrsssl_options" name="rlrsssl_options[htaccess_redirect]" size="40" value="1"
                   type="checkbox" <?php checked(1, $this->htaccess_redirect, true) ?> <?php echo $disabled?>/>
            <span class="rsssl-slider rsssl-round"></span>
        </label>
		<?php
		RSSSL()->rsssl_help->get_comment($comment);
	}

    /**
     * Insert option into settings form
     *
     * @since  2.0
     *
     * @access public
     *
     */

    public function get_option_do_not_edit_htaccess()
    {
        ?>
        <label class="rsssl-switch">
            <input id="rlrsssl_options" name="rlrsssl_options[do_not_edit_htaccess]" size="40" value="1"
                   type="checkbox" <?php checked(1, $this->do_not_edit_htaccess, true) ?> />
            <span class="rsssl-slider rsssl-round"></span>
        </label>
        <?php
        if ( !$this->do_not_edit_htaccess && !is_writable($this->htaccess_file()))  {
            $comment = sprintf(__(".htaccess is currently not %swritable%s.", "really-simple-ssl"), '<a target="_blank" href="https://really-simple-ssl.com/knowledge-base/htaccess-wp-config-files-not-writable/">', '</a>');
	        RSSSL()->rsssl_help->get_comment($comment);
        }
    }

    /**
     * Insert option into settings form
     *
     * @since  2.1
     *
     * @access public
     *
     */

    public function get_option_switch_mixed_content_fixer_hook()
    {
        ?>
        <label class="rsssl-switch">
            <input id="rlrsssl_options" name="rlrsssl_options[switch_mixed_content_fixer_hook]" size="40" value="1"
                   type="checkbox" <?php checked(1, $this->switch_mixed_content_fixer_hook, true) ?> />
            <span class="rsssl-slider rsssl-round"></span>
        </label>
        <?php
    }

	/**
	 *
     * Get the option to dismiss all Really Simple SSL notices
     *
     * @since 3.2
     *
     * @access public
     *
	 */

	public function get_option_dismiss_all_notices()
	{
		?>
        <label class="rsssl-switch">
            <input id="rlrsssl_options" name="rlrsssl_options[dismiss_all_notices]" size="40" value="1"
                   type="checkbox" <?php checked(1, $this->dismiss_all_notices, true) ?> />
            <span class="rsssl-slider rsssl-round"></span>
        </label>
		<?php
	}

    /**
     *
     * Add a button and thickbox to deactivate the plugin while keeping SSL
     *
     * @since 3.0
     *
     * @access public
     *
     */

    public function deactivate_popup()
    {
        //only on plugins page
        $screen = get_current_screen();
        if (!$screen || $screen->base !=='plugins' ) return;

        ?>
	    <?php add_thickbox();?>
        <?php if ( is_rtl() ) { ?>
            <style>
                #TB_ajaxContent.rsssl-deactivation-popup {
                    text-align: center !important;
                    width:750px;
                }
                #TB_window.rsssl-deactivation-popup {
                    height: 440px !important;
                    border-right: 7px solid black;
                }
                .rsssl-deactivation-popup #TB_title{
                    height: 70px;
                    border-bottom: 1px solid #dedede;
                }
                .rsssl-deactivation-popup #TB_ajaxWindowTitle {
                    font-weight:bold;
                    font-size:30px;
                    padding: 20px;
                }

                .rsssl-deactivation-popup .tb-close-icon {
                    color:#dedede;
                    width: 50px;
                    height: 50px;
                    top: 12px;
                    left: 20px;
                }
                .rsssl-deactivation-popup .tb-close-icon:before {
                    font: normal 50px/50px dashicons;
                }
                .rsssl-deactivation-popup #TB_closeWindowButton:focus .tb-close-icon {
                    outline:0;
                    box-shadow: 0 0 0 0 #5b9dd9, 0 0 0 0 rgba(30, 140, 190, .8);
                    color:#dedede;
                }
                .rsssl-deactivation-popup #TB_closeWindowButton .tb-close-icon:hover {
                    color:#666;
                }
                .rsssl-deactivation-popup #TB_closeWindowButton:focus {
                    outline:0;
                }
                .rsssl-deactivation-popup #TB_ajaxContent {
                    width: 100% !important;
                    padding: 0;
                }

                .rsssl-deactivation-popup .button-rsssl-tertiary.button {
                    background-color: #D7263D !important;
                    color: white !important;
                    border-color: #D7263D;
                }

                .rsssl-deactivation-popup .button-rsssl-tertiary.button:hover {
                    background-color: #f1f1f1 !important;
                    color: #d7263d !important;
                }

                .rsssl-deactivate-notice-content {
                    margin: 20px
                }
                .rsssl-deactivate-notice-content h3 , .rsssl-deactivate-notice-content ul{
                    font-size:1.1em;
                }

                .rsssl-deactivate-notice-footer {
                    padding-top: 20px;
                    position:absolute;
                    bottom:15px;
                    width: 94%;
                    margin-right: 3%;
                    border-top: 1px solid #dedede;
                }

                .rsssl-deactivation-popup ul {
                    list-style: circle;
                    padding-right: 20px;
                }
                .rsssl-deactivation-popup a {
                    margin-left:10px !important;
                }
            </style>
        <?php } else { ?>
            <style>
                #TB_ajaxContent.rsssl-deactivation-popup {
                    text-align: center !important;
                    width:750px;
                }
                #TB_window.rsssl-deactivation-popup {
                    height: 440px !important;
                    border-left: 7px solid black;
                }
                .rsssl-deactivation-popup #TB_title{
                    height: 70px;
                    border-bottom: 1px solid #dedede;
                }
                .rsssl-deactivation-popup #TB_ajaxWindowTitle {
                    font-weight:bold;
                    font-size:30px;
                    padding: 20px;
                }

                .rsssl-deactivation-popup .tb-close-icon {
                    color:#dedede;
                    width: 50px;
                    height: 50px;
                    top: 12px;
                    right: 20px;
                }
                .rsssl-deactivation-popup .tb-close-icon:before {
                    font: normal 50px/50px dashicons;
                }
                .rsssl-deactivation-popup #TB_closeWindowButton:focus .tb-close-icon {
                    outline:0;
                    box-shadow: 0 0 0 0 #5b9dd9, 0 0 0 0 rgba(30, 140, 190, .8);
                    color:#dedede;
                }
                .rsssl-deactivation-popup #TB_closeWindowButton .tb-close-icon:hover {
                    color:#666;
                }
                .rsssl-deactivation-popup #TB_closeWindowButton:focus {
                    outline:0;
                }
                .rsssl-deactivation-popup #TB_ajaxContent {
                    width: 100% !important;
                    padding: 0;
                }

                .rsssl-deactivation-popup .button-rsssl-tertiary.button {
                    background-color: #D7263D !important;
                    color: white !important;
                    border-color: #D7263D;
                }

                .rsssl-deactivation-popup .button-rsssl-tertiary.button:hover {
                    background-color: #f1f1f1 !important;
                    color: #d7263d !important;
                }

                .rsssl-deactivate-notice-content {
                    margin: 20px
                }
                .rsssl-deactivate-notice-content h3 , .rsssl-deactivate-notice-content ul{
                    font-size:1.1em;
                }

                .rsssl-deactivate-notice-footer {
                    padding-top: 20px;
                    position:absolute;
                    bottom:15px;
                    width: 94%;
                    margin-left: 3%;
                    border-top: 1px solid #dedede;
                }

                .rsssl-deactivation-popup ul {
                    list-style: circle;
                    padding-left: 20px;
                }
                .rsssl-deactivation-popup a {
                    margin-right:10px !important;
                }
            </style>
        <?php } ?>
        <script>
            jQuery(document).ready(function ($) {
                $('#rsssl_close_tb_window').click(tb_remove);
                $(document).on('click', '#deactivate-really-simple-ssl', function(e){
                    e.preventDefault();
                    tb_show( '<?php _e("Are you sure?", "really-simple-ssl") ?>', '#TB_inline?height=420&inlineId=deactivate_keep_ssl', 'null');
                    $("#TB_window").addClass('rsssl-deactivation-popup');

                });
                if ($('#deactivate-really-simple-ssl').length){
                    $('.rsssl-button-deactivate-revert').attr('href',  $('#deactivate-really-simple-ssl').attr('href') );
                }

            });
        </script>
        <div id="deactivate_keep_ssl" style="display: none;">
                <div class="rsssl-deactivate-notice-content">
                    <h3 style="margin: 20px 0; text-align: left;">
                        <?php _e("To deactivate the plugin correctly, please select if you want to:", "really-simple-ssl") ?></h3>
                    <ul style="text-align: left; font-size: 1.2em;">
                        <li><?php _e("Deactivate, but stay on SSL.", "really-simple-ssl") ?></li>
                        <li><?php _e("Deactivate, and revert to http. This will remove all changes by the plugin.", "really-simple-ssl") ?></li>
                    </ul>
                    <h3><?php _e("Deactivating the plugin while keeping SSL will do the following:", "really-simple-ssl") ?></h3>
                    <ul style="text-align: left; font-size: 1.2em;">
                        <li><?php _e("The mixed content fixer will stop working", "really-simple-ssl") ?></li>
                        <li><?php _e("The WordPress 301 redirect will stop working", "really-simple-ssl") ?></li>
                        <li><?php _e("Your site address will remain https://", "really-simple-ssl") ?> </li>
                        <li><?php _e("The .htaccess redirect will remain active", "really-simple-ssl") ?></li>
                    </ul>
                </div>

                <?php
                $token = wp_create_nonce('rsssl_deactivate_plugin');
                $deactivate_keep_ssl_link = admin_url("options-general.php?page=rlrsssl_really_simple_ssl&action=uninstall_keep_ssl&token=" . $token);

                ?>
                <div class="rsssl-deactivate-notice-footer">
                    <a class="button button-default" href="#" id="rsssl_close_tb_window"><?php _e("Cancel", "really-simple-ssl") ?></a>
                    <a class="button button-primary" href="<?php echo $deactivate_keep_ssl_link ?>"><?php _e("Deactivate, keep https", "really-simple-ssl") ?></a>
                    <a class="button  button-rsssl-tertiary rsssl-button-deactivate-revert" href="#"><?php _e("Deactivate, revert to http", "really-simple-ssl") ?></a>
                </div>
        </div>
        <?php
    }

	/**
	 *
     * Mixed content fixer option
     *
	 */

    public function get_option_autoreplace_insecure_links()
    {
        $autoreplace_mixed_content = $this->autoreplace_insecure_links;
        $disabled = "";
        $comment = "";

        if (is_multisite() && rsssl_multisite::this()->autoreplace_mixed_content) {
            $disabled = "disabled";
            $autoreplace_mixed_content = TRUE;
            $comment = __("This option is enabled on the network menu.", "really-simple-ssl");
        }

        ?>
        <label class="rsssl-switch" id="rsssl-maybe-highlight-autoreplace_insecure_links">
            <input id="rlrsssl_options" name="rlrsssl_options[autoreplace_insecure_links]" size="40" value="1"
                   type="checkbox" <?php checked(1, $autoreplace_mixed_content, true) ?> <?php echo $disabled?>/>
            <span class="rsssl-slider rsssl-round"></span>
        </label>

       <?php
	    RSSSL()->rsssl_help->get_comment($comment);
    }

    /**
     * Add settings link on plugins overview page
     * @param string $links
     * @return string $links
     * @since  2.0
     *
     * @access public
     *
     */


    public function plugin_settings_link($links)
    {
        $settings_link = '<a href="' . admin_url("options-general.php?page=rlrsssl_really_simple_ssl") . '">' . __("Settings", "really-simple-ssl") . '</a>';
        array_unshift($links, $settings_link);

	    if ( apply_filters('rsssl_settings_link', 'free') === 'free' ) {
		    $support = '<a target="_blank" href="https://wordpress.org/support/plugin/really-simple-ssl/">' . __('Support', 'really-simple-ssl') . '</a>';
	    } else {
		    $support = '<a target="_blank" href="https://really-simple-ssl.com/support">' . __('Premium Support', 'really-simple-ssl') . '</a>';
	    }
	    array_unshift($links, $support);

	    if ( ! defined( 'rsssl_pro_version' ) ) {
	        $upgrade_link = '<a style="color:#f8be2e;font-weight:bold" target="_blank" href="https://really-simple-ssl.com/pro">'
		      . __( 'Upgrade to premium', 'really-simple-ssl' ) . '</a>';
	        array_unshift( $links, $upgrade_link );
	    }
	    return $links;
    }


    /**
     * Check if wpconfig contains httponly cookie settings
     *
     * @since  4.0.11
     *
     * @access public
     * @return boolean
     *
     */

    public function contains_secure_cookie_settings()
    {
        if ( $this->secure_cookie_settings_status() === 'set' ) {
            return true;
        } else {
            return false;
        }
    }

	/**
     * Check if wpconfig contains httponly cookie settings
     *
	 * @return string
	 */

	public function secure_cookie_settings_status()
	{
		$wpconfig_path = $this->find_wp_config_path();
		if (!$wpconfig_path) {
			return 'wpconfig-not-writable';
		}

		$wpconfig = file_get_contents($wpconfig_path);
		if ((strpos($wpconfig, "//Begin Really Simple SSL session cookie settings") !== FALSE) || (strpos($wpconfig, "cookie_httponly") !== FALSE)) {
			return 'set';
		}

		if ( !is_writable($wpconfig_path) ) {
			return 'wpconfig-not-writable';
		}

		return 'not-set';
	}

	/**
	 * Insert secure cookie settings
	 */

	public function insert_secure_cookie_settings(){
		if (!current_user_can("activate_plugins")) return;

		if ( wp_doing_ajax() || !$this->is_settings_page() ) return;

		//only if this site has SSL activated, otherwise, remove cookie settings and exit.
		if (!$this->ssl_enabled) {
			$this->remove_secure_cookie_settings();
			return;
		}

		//if multisite, only on network wide activated setups
		if (is_multisite() && !RSSSL()->rsssl_multisite->ssl_enabled_networkwide ) return;

		//only if cookie settings were not inserted yet
		if (!$this->contains_secure_cookie_settings() ) {
			$wpconfig_path = RSSSL()->really_simple_ssl->find_wp_config_path();
			$wpconfig = file_get_contents($wpconfig_path);
			if ((strlen($wpconfig)!=0) && is_writable($wpconfig_path)) {
				$rule  = "\n"."//Begin Really Simple SSL session cookie settings"."\n";
				$rule .= "@ini_set('session.cookie_httponly', true);"."\n";
				$rule .= "@ini_set('session.cookie_secure', true);"."\n";
				$rule .= "@ini_set('session.use_only_cookies', true);"."\n";
				$rule .= "//END Really Simple SSL"."\n";

				$insert_after = "<?php";
				$pos = strpos($wpconfig, $insert_after);
				if ($pos !== false) {
					$wpconfig = substr_replace($wpconfig,$rule,$pos+1+strlen($insert_after),0);
				}

				file_put_contents($wpconfig_path, $wpconfig);
			}
		}
	}



	/**
     * Get the absolute path the the www directory of this site, where .htaccess lives.
     *
     * @since  2.0
     *
     * @access public
     *
     */

    public function getABSPATH()
    {
        $path = ABSPATH;
        if ($this->is_subdirectory_install()) {
            $siteUrl = site_url();
            $homeUrl = home_url();
            $diff = str_replace($homeUrl, "", $siteUrl);
            $diff = trim($diff, "/");
            $pos = strrpos($path, $diff);
            if ($pos !== false) {
                $path = substr_replace($path, "", $pos, strlen($diff));
                $path = trim($path, "/");
                $path = "/" . $path . "/";
            }
        }

        return $path;
    }

	/**
     * Check if it's either a single site, or when multisite, network enabled.
	 * @return bool
	 */
    public function can_apply_networkwide(){
        if ( !is_multisite() ) {
            return true;
        } elseif (RSSSL()->rsssl_multisite->ssl_enabled_networkwide) {
            return true;
        }
        return false;
    }

    /**
     * Find if this WordPress installation is installed in a subdirectory
     *
     * @since  2.0
     *
     * @access protected
     *
     */

    protected function is_subdirectory_install()
    {
        if (strlen(site_url()) > strlen(home_url())) {
            return true;
        }
        return false;
    }

	/**
	 * @return mixed|string
     *
     * Retrieve the contents of the test page
     *
	 */

    protected function get_test_page_contents()
    {
        $filecontents = get_transient('rsssl_testpage');
        if (!$filecontents) {
            $filecontents = "";

            $testpage_url = trailingslashit($this->test_url()) . "ssl-test-page.php";
            $this->trace_log("Opening testpage to check server configuration: " . $testpage_url);

            $response = wp_remote_get($testpage_url);

            if (is_array($response)) {
                $status = wp_remote_retrieve_response_code($response);
                $filecontents = wp_remote_retrieve_body($response);
            }

            $this->trace_log("test page url, enter in browser to check manually: " . $testpage_url);

            if (!is_wp_error($response) && (strpos($filecontents, "#SSL TEST PAGE#") !== false)) {
                $this->trace_log("SSL test page loaded successfully");
            } else {
                $error = "";
                if (is_wp_error($response)) $error = $response->get_error_message();
                $this->trace_log("Could not open testpage " . $error);
            }
            if (empty($filecontents)) {
                $filecontents = 'not-valid';
            }
            set_transient('rsssl_testpage', $filecontents, 600);
        }
        return $filecontents;
    }

    /**
     * Determine dirname to show in admin_notices() in really-simple-ssl-pro.php to show a warning when free folder has been renamed
     *
     * @return string
     *
     * since 3.1
     *
     */

    public function get_current_rsssl_free_dirname() {
        return basename( __DIR__ );
    }


	/**
	 *
	 * Check the current free plugin folder path and compare it to default path to detect if the plugin folder has been renamed
	 *
	 * @return boolean
	 *
	 * @since 3.1
	 *
	 */

	public function uses_default_folder_name() {
		$current_plugin_path = $this->get_current_rsssl_free_dirname();
		if ( $this->plugin_dir === $current_plugin_path ) {
			return true;
		} else {
			return false;
		}
	}

    /**
     * @return string
     *
     * since 3.1
     *
     * Determine the htaccess file. This can be either the regular .htaccess file, or an htaccess.conf file on bitnami installations.
     *
     */

    public function htaccess_file() {
        if ($this->uses_htaccess_conf()) {
            $htaccess_file = realpath(dirname(ABSPATH) . "/conf/htaccess.conf");
        } else {
            $htaccess_file = $this->ABSpath . ".htaccess";
        }

        return $htaccess_file;
    }

	/**
	 *
     * Determine whether or not to remove the &highlight= parameter from URL
     *
     * @since 3.2
     *
     * @access public
     *
	 */

    public function maybe_remove_highlight_from_url() {

	    $http_referrer = isset($_POST['_wp_http_referer']) ? $_POST['_wp_http_referer'] : false;
	    if ($http_referrer && strpos( $http_referrer, "&highlight=" ) ) {
		    $url = add_query_arg( array(
			    "page" => "rlrsssl_really_simple_ssl",
			    "tab"  => "configuration"
		    ), admin_url( "options-general.php" ) );
		    wp_safe_redirect( $url );
		    exit;
	    }
    }

	public function get_template($file, $path = rsssl_path, $args = array())
	{

		$file = trailingslashit($path) . 'templates/' . $file;
		$theme_file = trailingslashit(get_stylesheet_directory()) . dirname(rsssl_path) . $file;

		if (file_exists($theme_file)) {
			$file = $theme_file;
		}

		if (strpos($file, '.php') !== false) {
			ob_start();
			require $file;
			$contents = ob_get_clean();
		} else {
			$contents = file_get_contents($file);
		}

		return $contents;
	}
} //class closure

/**
 * Wrapper functions for dashboard notices()
 * @return string
 */


if (!function_exists('rsssl_site_has_ssl')) {
	function rsssl_site_has_ssl() {
		return RSSSL()->really_simple_ssl->site_has_ssl;
	}
}

if (!function_exists('rsssl_ssl_enabled')) {
    function rsssl_ssl_enabled() {
        return RSSSL()->really_simple_ssl->ssl_enabled;
    }
}

if (!function_exists('rsssl_ssl_detected')) {
	function rsssl_ssl_detected() {

	    if ( RSSSL()->really_simple_ssl->ssl_enabled ) {
		    return apply_filters('rsssl_ssl_detected', 'ssl-detected');
	    }

		if ( ! RSSSL()->really_simple_ssl->wpconfig_ok() ) {
			return apply_filters('rsssl_ssl_detected', 'fail');
		}

		if ( RSSSL()->really_simple_ssl->site_has_ssl ) {
			return apply_filters('rsssl_ssl_detected', 'ssl-detected');
		}

		if ( !RSSSL()->rsssl_certificate->is_valid() ) {
			return apply_filters('rsssl_ssl_detected', 'no-ssl-detected');
		}

		return apply_filters('rsssl_ssl_detected', 'ssl-detected');
	}
}

if (!function_exists('rsssl_check_redirect')) {
	function rsssl_check_redirect() {
		if ( ! RSSSL()->really_simple_ssl->has_301_redirect() ) {
			return 'no-redirect-set';
		}

		if ( RSSSL()->really_simple_ssl->has_301_redirect() && RSSSL()->rsssl_server->uses_htaccess() && RSSSL()->really_simple_ssl->htaccess_contains_redirect_rules() ) {
			return 'htaccess-redirect-set';
		}

		if ( RSSSL()->rsssl_server->uses_htaccess() && ! is_writable( RSSSL()->really_simple_ssl->htaccess_file()) && ( ! is_multisite() || ! RSSSL()->rsssl_multisite->is_per_site_activated_multisite_subfolder_install() ) ) {
		    return 'htaccess-not-writeable';
	    }

		if ( RSSSL()->really_simple_ssl->htaccess_redirect && !RSSSL()->really_simple_ssl->htaccess_test_success) {
            return 'htaccess-rules-test-failed';
		}

        if ( RSSSL()->really_simple_ssl->has_301_redirect() && RSSSL()->really_simple_ssl->wp_redirect && RSSSL()->rsssl_server->uses_htaccess() && ! RSSSL()->really_simple_ssl->htaccess_redirect && ( ! is_multisite() || ! RSSSL()->rsssl_multisite->is_per_site_activated_multisite_subfolder_install() )) {
			return 'wp-redirect-to-htaccess';
		}

        return 'default';
	}
}

if (!function_exists('rsssl_uses_elementor')) {
	function rsssl_uses_elementor() {
		return ( defined( 'ELEMENTOR_VERSION' ) || defined( 'ELEMENTOR_PRO_VERSION' ) );
	}
}

if (!function_exists('rsssl_uses_divi')) {
	function rsssl_uses_divi() {
		return defined( 'ET_CORE_PATH' );
	}
}

if (!function_exists('rsssl_uses_wp_engine')) {
    function rsssl_uses_wp_engine() {
        if (function_exists('is_wpe') && is_wpe()) {
            return true;
        }
        return false;
    }
}

if (!function_exists('rsssl_ssl_activation_time_no_longer_then_3_days_ago')) {
	function rsssl_ssl_activation_time_no_longer_then_3_days_ago() {

		$activation_time             = get_option( 'rsssl_activation_timestamp' );
		$three_days_after_activation = $activation_time + 3 * DAY_IN_SECONDS;

		if ( time() < $three_days_after_activation ) {
			return true;
		} else {
			return false;
		}
	}
}
