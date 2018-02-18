<?php

    namespace Wpo\Util;

    // Prevent public access to this script
    defined( 'ABSPATH' ) or die();

    require_once( $GLOBALS[ 'WPO365_PLUGIN_DIR' ] . '/Wpo/Util/Helpers.php' );

    use \Wpo\Util\Helpers;

    class Error_Handler {

        const NOT_CONFIGURED    = 'NOT_CONFIGURED';
        const CHECK_LOG         = 'CHECK_LOG';
        const TAMPERED_WITH     = 'TAMPERED_WITH';
        const USER_NOT_FOUND    = 'USER_NOT_FOUND';

        const ERROR_MESSAGES = Array(
            'NOT_CONFIGURED'    => 'Wordpress + Office 365 login not configured yet. Please contact your System Administrator.',
            'CHECK_LOG'         => 'Please contact your System Administrator and check log file.',
            'TAMPERED_WITH'     => 'Your login might be tampered with. Please contact your System Administrator.',
            'USER_NOT_FOUND'    => 'Could not create or retrieve your login. Please contact your System Administrator.'
        );


        /**
         * Checks for errors in the login messages container and display and unset immediatly after if any
         *
         * @since   1.0
         * @return  void
         */
        public static function check_for_login_messages() {

            // Using $_GET here since wp_query is not loaded on login page
            $login_error_codes = $_GET[ 'login_errors' ];

            // Check if any login error codes
            if( empty( $login_error_codes ) ) {

                return;

            }

            $result = '';

            foreach( explode( ',', $login_error_codes ) as $login_error_code ) {

                $result .= '<p class="message">' . self::ERROR_MESSAGES[ $login_error_code ] . '</p><br />';

            }
            
            // Return messages to display to hook
            return $result;
        }
        
    }

?>