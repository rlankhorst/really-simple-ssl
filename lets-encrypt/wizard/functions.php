<?php

defined( 'ABSPATH' ) or die( "you do not have access to this page!" );

if ( ! function_exists( 'rsssl_user_can_manage' ) ) {
    function rsssl_user_can_manage() {
        if ( ! is_user_logged_in() ) {
            return false;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        return true;
    }
}

if ( !function_exists('rsssl_settings_page') ) {
    function rsssl_settings_page(){
            return add_query_arg(array('page' => 'rlrsssl_really_simple_ssl', 'tab' => 'lets-encrypt'), admin_url('options-general.php?page=') );
    }
}

/**
 * Check if we're on CPanel
 * @return bool
 */
function rsssl_is_cpanel(){
	return file_exists("/usr/local/cpanel");
}

/**
 * Check if CPanel supports the api
 * @return bool
 */
function rsssl_cpanel_api_supported(){
	return rsssl_is_cpanel() && file_exists("/usr/local/cpanel/php/cpanel.php");
}

function rsssl_is_plesk(){
	return false;
}

/**
 * @param bool $nicename
 *
 * @return string
 */
function rsssl_hosting_environment($nicename = false ){
	$system = 'other';
	if (rsssl_is_cpanel()) {
		$system = 'cpanel';
	}

	if (rsssl_is_plesk()) {
		$system = 'plesk';
	}

	if ($nicename){
		$list = array(
			'plesk' => "Plesk",
			'cpanel' => "CPanel",
			'other' => __("Other hosting environment.", "really-simple-ssl"),
		);
		return $list[$system];

	} else {
		return $system;
	}


}


if ( ! function_exists( 'rsssl_get_value' ) ) {

    /**
     * Get value for an a rsssl option
     * For usage very early in the execution order, use the $page option. This bypasses the class usage.
     *
     * @param string $fieldname
     * @param bool|int $post_id
     * @param bool|string $page
     * @param bool $use_default
     * @param bool $use_translate
     *
     * @return array|bool|mixed|string
     */

    function rsssl_get_value(
        $fieldname, $use_default = true
    ) {
        if ( ! isset( RSSSL_LE()->config->fields[ $fieldname ] ) ) {
            return false;
        }

        $page = RSSSL_LE()->config->fields[ $fieldname ]['source'];
        $fields = get_option( 'rsssl_options_' . $page );
        $default = ( $use_default && $page && isset( RSSSL_LE()->config->fields[ $fieldname ]['default'] ) )
            ? RSSSL_LE()->config->fields[ $fieldname ]['default'] : '';

        $value   = isset( $fields[ $fieldname ] ) ? $fields[ $fieldname ] : $default;
        return $value;
    }
}

if ( ! function_exists( 'rsssl_notice' ) ) {
    /**
     * Notification without arrow on the left. Should be used outside notifications center
     * @param string $msg
     * @param string $type notice | warning | success
     * @param bool   $remove_after_change
     * @param bool   $echo
     * @param array  $condition $condition['question'] $condition['answer']
     *
     * @return string|void
     */
    function rsssl_notice( $msg, $type = 'notice', $remove_after_change = false, $echo = true, $condition = false) {
        if ( $msg == '' ) {
            return;
        }

        // Condition
        $condition_check = "";
        $condition_question = "";
        $condition_answer = "";
        $rsssl_hidden = "";
        if ($condition) {
            $condition_check = "condition-check";
            $condition_question = "data-condition-question='{$condition['question']}'";
            $condition_answer = "data-condition-answer='{$condition['answer']}'";
            $args['condition'] = array($condition['question'] => $condition['answer']);
            $rsssl_hidden = rsssl_field::this()->condition_applies($args) ? "" : "rsssl-hidden";;
        }

        // Hide
        $remove_after_change_class = $remove_after_change ? "rsssl-remove-after-change" : "";

        $html = "<div class='rsssl-panel-wrap'><div class='rsssl-panel rsssl-notification rsssl-{$type} {$remove_after_change_class} {$rsssl_hidden} {$condition_check}' {$condition_question} {$condition_answer}><div>{$msg}</div></div></div>";

        if ( $echo ) {
            echo $html;
        } else {
            return $html;
        }
    }
}

if ( ! function_exists( 'rsssl_sidebar_notice' ) ) {
    /**
     * @param string $msg
     * @param string $type notice | warning | success
     * @param bool   $remove_after_change
     * @param bool   $echo
     * @param bool|array  $condition $condition['question'] $condition['answer']
     *
     * @return string|void
     */

    function rsssl_sidebar_notice( $msg, $type = 'notice', $remove_after_change = false, $echo = true, $condition = false) {
        if ( $msg == '' ) {
            return;
        }

        // Condition
        $condition_check = "";
        $condition_question = "";
        $condition_answer = "";
        $rsssl_hidden = "";
        if ($condition) {
            $condition_check = "condition-check";
            $condition_question = "data-condition-question='{$condition['question']}'";
            $condition_answer = "data-condition-answer='{$condition['answer']}'";
            $args['condition'] = array($condition['question'] => $condition['answer']);
            $rsssl_hidden = rsssl_field::this()->condition_applies($args) ? "" : "rsssl-hidden";;
        }

        // Hide
        $remove_after_change_class = $remove_after_change ? "rsssl-remove-after-change" : "";

        $html = "<div class='rsssl-help-modal rsssl-notice rsssl-{$type} {$remove_after_change_class} {$rsssl_hidden} {$condition_check}' {$condition_question} {$condition_answer}>{$msg}</div>";

        if ( $echo ) {
            echo $html;
        } else {
            return $html;
        }
    }
}


if (!function_exists('rsssl_read_more')) {
    /**
     * Create a generic read more text with link for help texts.
     *
     * @param string $url
     * @param bool   $add_space
     *
     * @return string
     */
    function rsssl_read_more( $url, $add_space = true ) {
        $html
            = sprintf( __( "For more information on this subject, please read this %sarticle%s",
            'really-simple-ssl' ), '<a target="_blank" href="' . $url . '">',
            '</a>' );
        if ( $add_space ) {
            $html = '&nbsp;' . $html;
        }

        return $html;
    }
}


register_activation_hook( __FILE__, 'rsssl_set_activation_time_stamp' );
if ( ! function_exists( 'rsssl_set_activation_time_stamp' ) ) {
    function rsssl_set_activation_time_stamp( $networkwide ) {
        update_option( 'rsssl_activation_time', time() );
    }
}

if ( ! function_exists( 'rsssl_array_filter_multidimensional' ) ) {
    function rsssl_array_filter_multidimensional(
        $array, $filter_key, $filter_value
    ) {
        $new = array_filter( $array,
            function ( $var ) use ( $filter_value, $filter_key ) {
                return isset( $var[ $filter_key ] ) ? ( $var[ $filter_key ]
                    == $filter_value )
                    : false;
            } );

        return $new;
    }
}

if ( ! function_exists( 'rsssl_get_non_www_domain' ) ) {
    function rsssl_get_non_www_domain() {

        //Get current domain
        $domain = site_url();
        //Parse to strip off any /subfolder/
        $parse = parse_url($domain);
        $domain = $parse['host'];

        $domain = str_replace(array('http://', 'https://', 'www.'), '', $domain);

        return $domain;
    }
}

