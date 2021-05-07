<?php

defined( 'ABSPATH' ) or die( "you do not have access to this page!" );

if ( ! class_exists( "rsssl_wizard" ) ) {
    class rsssl_wizard{
        private static $_this;
        public $position;
        public $total_steps = false;
        public $last_section;
        public $page_url;
        public $percentage_complete = false;

        function __construct() {
            if ( isset( self::$_this ) ) {
                wp_die( sprintf( '%s is a singleton class and you cannot create a second instance.',
                    get_class( $this ) ) );
            }

            self::$_this = $this;
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

            //process custom hooks
            add_action( 'admin_init', array( $this, 'process_custom_hooks' ) );
            add_action( 'rsssl_before_save_lets-encrypt_option', array( $this, 'before_save_wizard_option' ), 10, 4 );
            add_action( 'rsssl_after_save_lets-encrypt_option', array( $this, 'after_save_wizard_option' ), 10, 4 );
            add_action( 'rsssl_after_saved_all_fields', array( $this, 'after_saved_all_fields' ), 10, 1 );
            add_action( 'rsssl_last_step', array( $this, 'last_step_callback' ) );

            require_once(plugin_dir_path(__FILE__) . 'functions.php');

        }

        static function this() {
            return self::$_this;
        }

        public function process_custom_hooks() {
            do_action( "rsssl_wizard_lets-encrypt" );
        }

        /**
         * Initialize a page in the wizard
         * @param $page
         */
        public function initialize( $page ) {
            $this->last_section = $this->last_section( $page, $this->step() );
            $this->page_url     = rsssl_settings_page();
            //if a post id was passed, we copy the contents of that page to the wizard settings.
            if ( isset( $_GET['post_id'] ) ) {
                $post_id = intval( $_GET['post_id'] );
                //get all fields for this page
                $fields = RSSSL_LE()->config->fields( $page );
                foreach ( $fields as $fieldname => $field ) {
                    $fieldvalue = get_post_meta( $post_id, $fieldname, true );
                    if ( $fieldvalue ) {
                        if ( ! RSSSL_LE()->field->is_multiple_field( $fieldname ) ) {
                            RSSSL_LE()->field->save_field( $fieldname, $fieldvalue );
                        } else {
                            $field[ $fieldname ] = $fieldvalue;
                            RSSSL_LE()->field->save_multiple( $field );
                        }
                    }

                }
            }
        }


        /**
         * Some actions after the last step has been completed
         */
        public function last_step_callback() {
            if ( ! $this->all_required_fields_completed( 'lets-encrypt' ) ) {
                echo '<div class="rsssl-wizard-intro">';
                _e( "Not all required fields are completed yet. Please check the steps to complete all required questions", 'really-simple-ssl' );
                echo '</div>';
            } else {
                echo RSSSL()->really_simple_ssl->get_template('last-step.php', $path = rsssl_wizard_path);
            }
        }

        /**
         * Process completion of setup
         *
         * */

        public function wizard_after_step() {
            if ( ! rsssl_user_can_manage() ) {
                return;
            }
			error_log("after step");


        }

        /**
         * Do stuff before a page from the wizard is saved.
         *
         * */

        public function before_save_wizard_option(
            $fieldname, $fieldvalue, $prev_value, $type
        ) {

            //only run when changes have been made
            if ( $fieldvalue === $prev_value ) {
                return;
            }
        }

        /**
         * Handle some custom options after saving the wizard options
         *
         * After all fields have been saved
         * @param $posted_fields
         */

        public function after_saved_all_fields($posted_fields){

        }

        /**
         * Handle some custom options after saving the wizard options
         * @param string $fieldname
         * @param mixed $fieldvalue
         * @param mixed $prev_value
         * @param string $type
         */

        public function after_save_wizard_option( $fieldname, $fieldvalue, $prev_value, $type ) {
            //only run when changes have been made
            if ( $fieldvalue === $prev_value ) {
                return;
            }

            if ( $fieldname === 'email_address'&& is_email($fieldvalue) ) {
	            RSSSL_LE()->letsencrypt_handler->update_account($fieldvalue);
            }
        }

        /**
         * Get the next step with fields in it
         * @param string $page
         * @param int $step
         *
         * @return int
         */
        public function get_next_not_empty_step( $page, $step ) {
            if ( ! RSSSL_LE()->field->step_has_fields( $page, $step ) ) {
                if ( $step >= $this->total_steps( $page ) ) {
                    return $step;
                }
                $step ++;
                $step = $this->get_next_not_empty_step( $page, $step );
            }

            return $step;
        }

        /**
         * Get the next section which is not empty
         * @param string $page
         * @param int $step
         * @param int $section
         *
         * @return int|bool
         */
        public function get_next_not_empty_section( $page, $step, $section ) {
            if ( ! RSSSL_LE()->field->step_has_fields( $page, $step,
                $section )
            ) {
                //some keys are missing, so we need to count the actual number of keys.
                if ( isset( RSSSL_LE()->config->steps[ $page ][ $step ]['sections'] ) ) {
                    $n = array_keys( RSSSL_LE()->config->steps[ $page ][ $step ]['sections'] ); //<---- Grab all the keys of your actual array and put in another array
                    $count = array_search( $section, $n ); //<--- Returns the position of the offset from this array using search

                    //this is the actual list up to section key.
                    $new_arr = array_slice( RSSSL_LE()->config->steps[ $page ][ $step ]['sections'], 0, $count + 1, true );//<--- Slice it with the 0 index as start and position+1 as the length parameter.
                    $section_count = count( $new_arr ) + 1;
                } else {
                    $section_count = $section + 1;
                }

                $section ++;

                if ( $section_count > $this->total_sections( $page, $step ) ) {
                    return false;
                }

                $section = $this->get_next_not_empty_section( $page, $step, $section );
            }

            return $section;
        }

        /**
         * Get previous step which is not empty
         *
         * @param string $page
         * @param int $step
         *
         * @return int
         */
        public function get_previous_not_empty_step( $page, $step ) {
            if ( ! RSSSL_LE()->field->step_has_fields( $page, $step ) ) {
                if ( $step <= 1 ) {
                    return $step;
                }
                $step --;
                $step = $this->get_previous_not_empty_step( $page, $step );
            }

            return $step;
        }

        /**
         * Get previous section which is not empty
         * @param string $page
         * @param int $step
         * @param int $section
         *
         * @return false|int
         */
        public function get_previous_not_empty_section( $page, $step, $section
        ) {

            if ( ! RSSSL_LE()->field->step_has_fields( $page, $step,
                $section )
            ) {
                $section --;
                if ( $section < 1 ) {
                    return false;
                }
                $section = $this->get_previous_not_empty_section( $page, $step,
                    $section );
            }

            return $section;
        }

        /**
         * Lock the wizard for further use while it's being edited by the current user.
         *
         *
         * */

        public function lock_wizard() {
            $user_id = get_current_user_id();
            set_transient( 'rsssl_wizard_locked_by_user', $user_id, apply_filters( "rsssl_wizard_lock_time", 2 * MINUTE_IN_SECONDS ) );
        }


        /**
         * Check if the wizard is locked by another user
         *
         *
         * */

        public function wizard_is_locked() {
            $user_id      = get_current_user_id();
            $lock_user_id = $this->get_lock_user();
            if ( $lock_user_id && $lock_user_id != $user_id ) {
                return true;
            }

            return false;
        }

        /**
         * Get user which is locking the wizard
         * @return false|int
         */
        public function get_lock_user() {
            return get_transient( 'rsssl_wizard_locked_by_user' );
        }

        /**
         * Render wizard
         * @param string $page
         * @param string $wizard_title
         */
        public function wizard( $page, $wizard_title = '' )
        {

            if (!rsssl_user_can_manage()) {
                return;
            }

            if ($this->wizard_is_locked()) {
                $user_id = $this->get_lock_user();
                $user = get_user_by("id", $user_id);
                $lock_time = apply_filters("rsssl_wizard_lock_time",
                        2 * MINUTE_IN_SECONDS) / 60;

                rsssl_notice(sprintf(__("The wizard is currently being edited by %s",
                        'really-simple-ssl'), $user->user_nicename) . '<br>'
                    . sprintf(__("If this user stops editing, the lock will expire after %s minutes.",
                        'really-simple-ssl'), $lock_time), 'warning');

                return;
            }
            //lock the wizard for other users.
            $this->lock_wizard();

            $this->initialize($page);

            $section = $this->section();
            $step = $this->step();

            if ($this->section_is_empty($page, $step, $section)
                || (isset($_POST['rsssl-next'])
                    && !RSSSL_LE()->field->has_errors())
            ) {
                if (RSSSL_LE()->config->has_sections($page, $step)
                    && ($section < $this->last_section)
                ) {
                    $section = $section + 1;
                } else {
                    $step++;
                    $section = $this->first_section($page, $step);
                }

                $step = $this->get_next_not_empty_step($page, $step);
                $section = $this->get_next_not_empty_section($page, $step,
                    $section);
                //if the last section is also empty, it will return false, so we need to skip the step too.
                if (!$section) {
                    $step = $this->get_next_not_empty_step($page,
                        $step + 1);
                    $section = 1;
                }
            }

            if (isset($_POST['rsssl-previous'])) {
                if (RSSSL_LE()->config->has_sections($page, $step)
                    && $section > $this->first_section($page, $step)
                ) {
                    $section--;
                } else {
                    $step--;
                    $section = $this->last_section($page, $step);
                }

                $step = $this->get_previous_not_empty_step($page, $step);
                $section = $this->get_previous_not_empty_section($page, $step,
                    $section);
            }

            $menu = $this->wizard_menu( $page, $wizard_title, $step, $section );
            $content = $this->wizard_content($page, $step, $section );

            $args = array(
                'page' => 'lets-encrypt',
                'content' => $menu.$content,
            );
            echo RSSSL()->really_simple_ssl->get_template('admin_wrap.php', $path = rsssl_wizard_path, $args );
        }

        /**
         * Render Wizard menu
         * @param string $page
         * @param string $wizard_title
         * @param int $active_step
         * @param int $active_section
         *
         * @return false|string
         */
        public function wizard_menu( $page, $wizard_title, $active_step, $active_section )
        {
            $args_menu['steps'] = "";
            for ($i = 1; $i <= $this->total_steps($page); $i++)
            {
                $args['title'] = $i . '. ' . RSSSL_LE()->config->steps[$page][$i]['title'];
                $args['active'] = ($i == $active_step) ? 'active' : '';
                $args['completed'] = $this->required_fields_completed($page, $i, false) ? 'complete' : 'incomplete';
                $args['url'] = add_query_arg(array('tab' => 'lets-encrypt', 'step' => $i), $this->page_url);
                $args['sections'] = ($args['active'] == 'active') ? $this->wizard_sections($page, $active_step, $active_section) : '';
                $args_menu['steps'] .= RSSSL()->really_simple_ssl->get_template( 'step.php', $path = rsssl_wizard_path , $args);
            }
            $args_menu['percentage-complete'] = $this->wizard_percentage_complete($page, $active_step);
            $args_menu['title'] = !empty( $wizard_title ) ? '<div class="rsssl-wizard-subtitle"><h2>' . $wizard_title . '</h2></div>': '' ;

            return RSSSL()->really_simple_ssl->get_template( 'menu.php', $path = rsssl_wizard_path, $args_menu );
        }

        /**
         * @param string $page
         * @param int $step
         * @param int $active_section
         *
         * @return string
         */
        public function wizard_sections( $page, $step, $active_section ) {
            $sections = "";

            if ( RSSSL_LE()->config->has_sections( $page, $step )) {

                for ($i = $this->first_section( $page, $step ); $i <= $this->last_section( $page, $step ); $i ++) {
                    $icon = rsssl_icon('check', 'empty');

                    if ( $this->section_is_empty( $page, $step, $i ) ) continue;
                    if ( $i < $this->get_next_not_empty_section( $page, $step, $i ) ) continue;

                    $active = ( $i == $active_section ) ? 'active' : '';
                    if ( $active == 'active' ) {
                        $icon = rsssl_icon('arrow-right-alt2', 'success');
                    } else if ($this->required_fields_completed( $page, $step, $i )) {
                        $icon = rsssl_icon('check', 'success');
                    }

                    $completed = ( $this->required_fields_completed( $page, $step, $i ) ) ? "rsssl-done" : "rsssl-to-do";
                    $url = add_query_arg( array('tab' => 'lets-encrypt', 'step' => $step, 'section' => $i), $this->page_url );
                    $title = RSSSL_LE()->config->steps[ $page ][ $step ]['sections'][ $i ]['title'];
                    $args = array(
                        'active' => $active,
                        'completed' => $completed,
                        'icon' => $icon,
                        'url' => $url,
                        'title' => $title,
                    );
                    $sections .= RSSSL()->really_simple_ssl->get_template( 'section.php', $path = rsssl_wizard_path, $args );
                }
            }

            return $sections;
        }

        /**
         * Render wizard content
         * @param string $page
         * @param int $step
         * @param int $section
         *
         * @return false|string
         */

        public function wizard_content( $page, $step, $section ) {
            $args = array();
	        if (isset(RSSSL_LE()->config->steps[$page][$step]['sections'][$section]['title'])) {
                $args['title'] = RSSSL_LE()->config->steps[$page][$step]['sections'][$section]['title'];
            } else {
                $args['title'] = RSSSL_LE()->config->steps[$page][$step]['title'];
            }
            $args['save_notice'] = '';
            $args['save_as_notice'] = '';
            $args['previous_button'] = '';
            $args['next_button'] = '';
            $args['save_button'] = '';
            if ( isset( $_POST['rsssl-save'] ) ) {
                $args['save_notice'] = rsssl_notice( __( "Changes saved successfully", 'really-simple-ssl' ), 'success', true , false);
            }

            $args['intro'] = $this->get_intro( $page, $step, $section );
            $args['page_url'] = $this->page_url;
            $args['page'] = $page;

            ob_start();
            RSSSL_LE()->field->get_fields( $page, $step, $section );
            $args['fields'] = ob_get_clean();
            $args['step'] = $step;
            $args['section'] = $section;

            if ( $step > 1 || $section > 1 ) {
                $args['previous_button'] = '<input class="button button-link rsssl-previous" type="submit" name="rsssl-previous" value="'. __( "Previous", 'really-simple-ssl' ) . '">';
            }

            if ( $step < $this->total_steps( $page ) ) {
                $args['next_button'] = '<input class="button button-primary rsssl-next" type="submit" name="rsssl-next" value="'. __( "Next", 'really-simple-ssl' ) . '">';
            }

            if ( $step > 0  && $step < $this->total_steps( $page )) {
                $args['save_button'] = '<input class="button button-secondary rsssl-save" type="submit" name="rsssl-save" value="'. __( "Save", 'really-simple-ssl' ) . '">';
            } elseif ($step === $this->total_steps( $page )) {

            }

            return RSSSL()->really_simple_ssl->get_template( 'content.php', $path = rsssl_wizard_path, $args );
        }

        /**
         * If a section does not contain any fields to be filled, just drop it from the menu.
         * @return bool
         *
         * */

        public function section_is_empty( $page, $step, $section ) {
            $section_compare = $this->get_next_not_empty_section( $page, $step,
                $section );
            if ( $section != $section_compare ) {
                return true;
            }

            return false;
        }

        /**
         * Enqueue assets
         * @param $hook
         */
        public function enqueue_assets( $hook ) {
            $minified = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
            //@todo if $hook == rsssl le
    	    // Let's encrypt
            wp_register_style( 'rsssl-wizard', rsssl_url . "lets-encrypt/wizard/assets/css/wizard.css", false, rsssl_version );
            wp_enqueue_style( 'rsssl-wizard' );
            // @todo admin css in wizard.less
            wp_register_style( 'rsssl-wizard-admin', rsssl_url . "lets-encrypt/wizard/assets/css/admin.css", false, rsssl_version );
            wp_enqueue_style( 'rsssl-wizard-admin' );

            wp_enqueue_script( 'rsssl-wizard', rsssl_url . "lets-encrypt/wizard/assets/js/wizard$minified.js", array( 'jquery' ), rsssl_version, true );
            wp_localize_script(
            'rsssl-wizard',
            'rsssl_wizard',
                array(
                    'admin_url'    => admin_url( 'admin-ajax.php' ),
                )
            );
        }


        /**
         * Foreach required field, check if it's been answered
         * if section is false, check all fields of the step.
         * @param string $page
         * @param int $step
         * @param int $section
         *
         * @return bool
         */


        public function required_fields_completed( $page, $step, $section ) {
            //get all required fields for this section, and check if they're filled in
	        error_log("$page $step $section");
            $fields = RSSSL_LE()->config->fields( $page, $step, $section );
            $fields = rsssl_array_filter_multidimensional( $fields, 'required', true );
            foreach ( $fields as $fieldname => $args ) {
            	error_log($fieldname);
                //if a condition exists, only check for this field if the condition applies.
                if ( isset( $args['condition'] )
                    || isset( $args['callback_condition'] )
                    && ! RSSSL_LE()->field->condition_applies( $args )
                ) {
                	error_log("skip ".$fieldname);
                    continue;
                }
                $value = RSSSL_LE()->field->get_value( $fieldname );
                if ( empty( $value ) ) {
	                error_log("not completed ".$fieldname);

	                return false;
                }
            }
            error_log("all completed");
            return true;
        }

        public function all_required_fields_completed_wizard(){
            return $this->all_required_fields_completed('lets-encrypt');
        }

        /**
         * Check if all required fields are filled
         * @return bool
         *
         * */

        public function all_required_fields_completed( $page ) {
            for ( $step = 1; $step <= $this->total_steps( $page ); $step ++ ) {
                if ( RSSSL_LE()->config->has_sections( $page, $step ) ) {
                    for (
                        $section = $this->first_section( $page, $step );
                        $section <= $this->last_section( $page, $step );
                        $section ++
                    ) {
                        if ( ! $this->required_fields_completed( $page, $step,
                            $section )
                        ) {
                            return false;
                        }
                    }
                } else {
                    if ( ! $this->required_fields_completed( $page, $step,
                        false )
                    ) {
                        return false;
                    }
                }
            }

            return true;
        }

        /**
         * Get a notice style header with an intro above a step or section
         *
         * @param string $page
         * @param int $step
         * @param int $section
         *
         * @return string
         */

        public function get_intro( $page, $step, $section ) {
            //only show when in action
            $intro = '';
            if ( RSSSL_LE()->config->has_sections( $page, $step ) ) {
                if ( isset( RSSSL_LE()->config->steps[ $page ][ $step ]['sections'][ $section ]['intro'] ) ) {
                    $intro .= RSSSL_LE()->config->steps[ $page ][ $step ]['sections'][ $section ]['intro'];
                }
            } else {
                if ( isset( RSSSL_LE()->config->steps[ $page ][ $step ]['intro'] ) ) {
                    $intro .= RSSSL_LE()->config->steps[ $page ][ $step ]['intro'];
                }
            }

            if ( strlen( $intro ) > 0 ) {
                $intro = '<div class="rsssl-wizard-intro">'
                    . $intro
                    . '</div>';
            }

            return $intro;
        }


        public function get_type( $post_id = false ) {
            $page = false;
            if ( $post_id ) {
                $post_type = get_post_type( $post_id );
                $page      = str_replace( 'rsssl-', '', $post_type );
            }
            if ( isset( $_GET['page'] ) ) {
                $page = str_replace( 'rsssl-', '',
                    sanitize_title( $_GET['page'] ) );
            }

            return $page;
        }


        public function wizard_completed_once() {
            return get_option( 'rsssl_wizard_completed_once' );
        }


        public function set_wizard_completed_once() {
            update_option( 'rsssl_wizard_completed_once', true );
        }

        public function step( $page = false ) {
            $step = 1;
            if ( ! $page ) {
                $page = 'lets-encrypt';
            }

            $total_steps = $this->total_steps( $page );

            if ( isset( $_GET["step"] ) ) {
                $step = intval( $_GET['step'] );
            }

            if ( isset( $_POST["step"] ) ) {
                $step = intval( $_POST['step'] );
            }

            if ( $step > $total_steps ) {
                $step = $total_steps;
            }

            if ( $step <= 1 ) {
                $step = 1;
            }

            return $step;
        }

        public function section() {
            $section = 1;
            if ( isset( $_GET["section"] ) ) {
                $section = intval( $_GET['section'] );
            }

            if ( isset( $_POST["section"] ) ) {
                $section = intval( $_POST['section'] );
            }

            if ( $section > $this->last_section ) {
                $section = $this->last_section;
            }

            if ( $section <= 1 ) {
                $section = 1;
            }

            return $section;
        }

        /**
         * Get total number of steps for a page
         *
         * @param $page
         *
         * @return int
         */

        public function total_steps( $page ) {
            return count( RSSSL_LE()->config->steps[ $page ] );
        }

        public function total_sections( $page, $step ) {
            if ( ! isset( RSSSL_LE()->config->steps[ $page ][ $step ]['sections'] ) ) {
                return 0;
            }

            return count( RSSSL_LE()->config->steps[ $page ][ $step ]['sections'] );
        }

        public function last_section( $page, $step ) {
            if ( ! isset( RSSSL_LE()->config->steps[ $page ][ $step ]["sections"] ) ) {
                return 1;
            }

            $array = RSSSL_LE()->config->steps[ $page ][ $step ]["sections"];

            return max( array_keys( $array ) );

        }

        public function first_section( $page, $step ) {
            if ( ! isset( RSSSL_LE()->config->steps[ $page ][ $step ]["sections"] ) ) {
                return 1;
            }

            $arr       = RSSSL_LE()->config->steps[ $page ][ $step ]["sections"];
            $first_key = key( $arr );

            return $first_key;
        }


        public function remaining_time( $page, $step, $section = false ) {

            //get remaining steps including this one
            $time        = 0;
            $total_steps = $this->total_steps( $page );
            for ( $i = $total_steps; $i >= $step; $i -- ) {
                $sub = 0;

                //if we're on a step with sections, we should add the sections that still need to be done.
                if ( ( $step == $i )
                    && RSSSL_LE()->config->has_sections( $page, $step )
                ) {

                    for (
                        $s = $this->last_section( $page, $i ); $s >= $section;
                        $s --
                    ) {
                        $subsub         = 0;
                        $section_fields = RSSSL_LE()->config->fields( $page,
                            $step, $s );
                        foreach (
                            $section_fields as $section_fieldname =>
                            $section_field
                        ) {
                            if ( isset( $section_field['time'] ) ) {
                                $sub    += $section_field['time'];
                                $subsub += $section_field['time'];
                                $time   += $section_field['time'];
                            }
                        }
                    }
                } else {
                    $fields = RSSSL_LE()->config->fields( $page, $i, false );

                    foreach ( $fields as $fieldname => $field ) {
                        if ( isset( $field['time'] ) ) {
                            $sub  += $field['time'];
                            $time += $field['time'];
                        }

                    }
                }
            }

            return round( $time + 0.45 );
        }

        /**
         *
         * Check which percentage of the wizard is completed
         * @param bool $count_warnings
         *
         * @return int
         * */


        public function wizard_percentage_complete( $page, $step )
        {
            //store to make sure it only runs once.
            if ( $this->percentage_complete !== false ) {
                return $this->percentage_complete;
            }

            $total_steps = $this->total_steps( 'lets-encrypt' );
            $total_sections = $this->total_sections($page, $step);

//            if ($total_sections > 1) {
//                $step = $step / $total_sections;
//            }

            $percentage = round( 100 * ( $step / $total_steps ) + 0.45 );
            $this->percentage_complete = $percentage;
            return $percentage;

//            $active_section = '';
//            for ( $i = 1; $i <= $total_steps; $i ++ ) {
//                $fields = RSSSL_LE()->config->fields( 'lets-encrypt', $i, false );
//                foreach ( $fields as $fieldname => $field ) {
//                    //is field required
//                    $required = isset( $field['required'] ) ? $field['required'] : false;
//                    if ( ( isset( $field['condition'] ) || isset( $field['callback_condition'] ) ) && ! RSSSL_LE()->field->condition_applies( $field )
//                    ) {
//                        $required = false;
//                    }
//                    if ( $required ) {
//                        $value = rsssl_get_value( $fieldname, false, false, false );
//                        $total_fields ++;
//                        if ( ! empty( $value ) ) {
//                            $completed_fields ++;
//                        }
//                    }
//                }
//            }

        }

    }


} //class closure
