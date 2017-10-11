<?php
defined('ABSPATH') or die("you do not have acces to this page!");

if ( ! class_exists( 'rsssl_admin_mixed_content_fixer' ) ) {
  class rsssl_mixed_content_fixer {
    private static $_this;
    public $http_urls = array();

  function __construct() {
    if ( isset( self::$_this ) )
        wp_die( sprintf( __( '%s is a singleton class and you cannot create a second instance.','really-simple-ssl' ), get_class( $this ) ) );

    self::$_this = $this;

    //exclude admin here: for all well built plugins and themes, this should not be necessary.
    if (!is_admin() && is_ssl() && RSSSL()->rsssl_front_end->autoreplace_insecure_links) {
      $this->fix_mixed_content();
    }

    add_filter("rsssl_ob_start_hook", array($this, "ob_start_hook"), 10,1 );
    add_filter("rsssl_ob_flush_hook", array($this, "ob_flush_hook"), 10,1);

  }

  static function this() {
    return self::$_this;
  }

  /**
   *
   * add action hooks at the start and at the end of the WP process.
   *
   * @since  2.3
   *
   * @access public
   *
   */

  public function fix_mixed_content(){

    /* Do not fix mixed content when call is coming from wp_api or from xmlrpc */
    if ( defined( 'JSON_REQUEST' ) && JSON_REQUEST ) return;
    if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) return;

    $this->build_url_list();

    /*
        Take care with modifications to hooks here:
        hooks tend to differ between front and back-end.
    */

    if (is_admin()) {
      add_action("admin_init", array($this, "start_buffer"), 100);
      add_action("shutdown", array($this, "end_buffer"), 999);
    } else {
      add_action(apply_filters("rsssl_ob_start_hook", "template_redirect"), array($this, "start_buffer"));
      add_action( apply_filters("rsssl_ob_flush_hook", "wp_print_footer_scripts"), array($this, "end_buffer"), 999);
    }
  }


  /**
   * allow for a different hook to be used on the mixed content fixer
   *
   * @since  2.5
   *
   * @access public
   *
   */

  public function ob_start_hook($hook){

    if ( RSSSL()->rsssl_front_end->switch_mixed_content_fixer_hook || (defined( 'RSSSL_CONTENT_FIXER_ON_INIT' ) && RSSSL_CONTENT_FIXER_ON_INIT) ) {
      $hook = "init";
    }

    return $hook;

  }

  public function ob_flush_hook($hook){

    if ( RSSSL()->rsssl_front_end->switch_mixed_content_fixer_hook) {
      $hook = "shutdown";
    }

    return $hook;

  }

  /**
   * Apply the mixed content fixer.
   *
   * @since  2.3
   *
   * @access public
   *
   */

  public function filter_buffer($buffer) {
    $buffer = $this->replace_insecure_links($buffer);
    return $buffer;
  }

  /**
   * Start buffering the output
   *
   * @since  2.0
   *
   * @access public
   *
   */

  public function start_buffer(){
    ob_start(array($this, "filter_buffer"));
  }

  /**
   * Flush the output buffer
   *
   * @since  2.0
   *
   * @access public
   *
   */

  public function end_buffer(){
    if (ob_get_length()) ob_end_flush();
  }

  /**
   * Creates an array of insecure links that should be https and an array of secure links to replace with
   *
   * @since  2.0
   *
   * @access public
   *
   */

  public function build_url_list() {
    $home = str_replace ( "https://" , "http://" , get_option('home'));
    $home_no_www  = str_replace ( "://www." , "://" , $home);
    $home_yes_www = str_replace ( "://" , "://www." , $home_no_www);

    //for the escaped version, we only replace the home_url, not it's www or non www counterpart, as it is most likely not used
    $escaped_home = str_replace ( "/" , "\/" , $home);

    $this->http_urls = array(
        $home_yes_www,
        $home_no_www,
        $escaped_home,
        "src='http://",
        'src="http://',
        "srcset='http://",
        'srcset="http://',
    );
  }

  /**
   * Just before the page is sent to the visitor's browser, all homeurl links are replaced with https.
   *
   * @since  1.0
   *
   * @access public
   *
   */

   public function replace_insecure_links($str) {

       $search_array = apply_filters('rlrsssl_replace_url_args', $this->http_urls);
       $ssl_array = str_replace ( array("http://", "http:\/\/") , array("https://", "https:\/\/"), $search_array);
       //now replace these links
       $str = str_replace ($search_array , $ssl_array , $str);

       //replace all http links except hyperlinks
       //all tags with src attr are already fixed by str_replace
       $pattern = array(
         '/url\([\'"]?\K(http:\/\/)(?=[^)]+)/i',
         '/<link [^>]*?href=[\'"]\K(http:\/\/)(?=[^\'"]+)/i',
         '/<meta property="og:image" [^>]*?content=[\'"]\K(http:\/\/)(?=[^\'"]+)/i',
         '/<form [^>]*?action=[\'"]\K(http:\/\/)(?=[^\'"]+)/i',
       );
       $str = preg_replace($pattern, 'https://', $str);
       $str = str_replace ( "<body " , '<body data-rsssl=1 ', $str);
       return apply_filters("rsssl_fixer_output", $str);

   }

}
}
