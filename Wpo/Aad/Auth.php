<?php
    namespace Wpo\Aad;

    // prevent public access to this script
    defined( 'ABSPATH' ) or die();

    require_once($GLOBALS["WPO365_PLUGIN_DIR"] . "/Wpo/Util/Logger.php");
    require_once($GLOBALS["WPO365_PLUGIN_DIR"] . "/Wpo/Util/Helpers.php");
    require_once($GLOBALS["WPO365_PLUGIN_DIR"] . "/Wpo/Util/Error_Handler.php");
    require_once($GLOBALS["WPO365_PLUGIN_DIR"] . "/Wpo/User/User_Manager.php");
    require_once($GLOBALS["WPO365_PLUGIN_DIR"] . "/Firebase/JWT/JWT.php");

    
    use \Wpo\Util\Logger;
    use \Wpo\Util\Helpers;
    use \Wpo\Util\Error_Handler;
    use \Wpo\User\User_Manager;
    use \Firebase\JWT\JWT;
    
    class Auth {

        /**
         * Destroys any session and authenication artefacts and hooked up with wp_logout and should
         * therefore never be called directly to avoid endless loops etc.
         *
         * @since   1.0
         *
         * @return  void 
         */
        public static function destroy_session() {
            
            Logger::write_log("DEBUG", "Destroying session " . strtolower(basename($_SERVER['PHP_SELF'])));
            
            // destroy wpo session and cookies
            Helpers::set_cookie("WPO365_AUTH", "", time() -3600);

        }

        /**
         * Same as destroy_session but with redirect to login page
         *
         * @since   1.0
         * @return  void
         */
        public static function goodbye() {

            wp_logout(); // This will also call destroy_session because of wp_logout hook
            auth_redirect();

        }

        /**
         * Validates each incoming request to see whether user prior to request
         * was authenicated by Microsoft Office 365 / Azure AD.
         *
         * @since   1.0
         *
         * @return  void 
         */
        public static function validate_current_session() {

            // Check for error
            if(isset($_POST["error"])) {
            
                $error_string = $_POST["error"] . isset($_POST["error_description"]) ? $_POST["error_description"] : "";
                Logger::write_log("ERROR", $error_string);
                Error_Handler::add_login_message($_POST["error"] . __(". Please contact your System Administrator."));
                Auth::goodbye();
            
            }

            // Verify whether new (id_tokens) tokens are received and if so process them
            if(isset($_POST["state"]) && isset($_POST["id_token"])) {
                \Wpo\Aad\Auth::process_openidconnect_token();
            }

            // Don't continue validation if user is already logged in and is a Wordpress-only user
            if(User_Manager::user_is_o365_user() === false) {
                
                return;
                
            }

            // If selected scenario is 'Internet' (2) then only continue with validation when access to backend is requested
            if(isset($GLOBALS["wpo365_options"]["auth_scenario"])
                && !empty($GLOBALS["wpo365_options"]["auth_scenario"])
                && $GLOBALS["wpo365_options"]["auth_scenario"] == "2"
                && !is_admin()) {

                    Logger::write_log("DEBUG", "Cancelling session validation for page " . strtolower(basename($_SERVER['PHP_SELF'])) . " because selected scenario is 'Internet'");
                    return;

            }
            
            Logger::write_log("DEBUG", "Validating session for page " . strtolower(basename($_SERVER['PHP_SELF'])));
            
            // Is the current page blacklisted and if yes cancel validation
            if(!empty($GLOBALS["wpo365_options"]["pages_blacklist"]) 
                &&  strpos(strtolower($GLOBALS["wpo365_options"]["pages_blacklist"]), 
                    strtolower(basename($_SERVER['PHP_SELF']))) !== false) {

                Logger::write_log("DEBUG", "Cancelling session validation for page " . strtolower(basename($_SERVER['PHP_SELF'])));

                return;

            }

            // Don't allow access to the front end when WPO365 is unconfigured
            if((empty($GLOBALS["wpo365_options"]["tenant_id"])
                || empty($GLOBALS["wpo365_options"]["application_id"])
                || empty($GLOBALS["wpo365_options"]["redirect_url"])) 
                && !is_admin()) {
                
                Logger::write_log("ERROR", "WPO365 not configured");
                Error_Handler::add_login_message(__("Wordpress + Office 365 login not configured yet. Please contact your System Administrator."));
                Auth::goodbye();

            }

            // Refresh user's authentication when session not yet validated
            if(Helpers::get_cookie("WPO365_AUTH") == false) { // no session data found

                wp_logout(); // logout but don't redirect to the login page
                Logger::write_log("DEBUG", "Session data invalid or incomplete found");
                Auth::get_openidconnect_and_oauth_token();

            }

            $wp_usr_id = User_Manager::get_user_id();
            
            // Session validated but something must have gone wrong because user cannot be retrieved
            if($wp_usr_id === false) {

                Error_Handler::add_login_message(__("Could not retrieve your login. Please contact your System Administrator."));
                Auth::goodbye();
            }

            $wp_usr = get_user_by("ID", $wp_usr_id);

            Logger::write_log("DEBUG", "User " . $wp_usr->ID . " successfully authenticated");
            
            if(!is_user_logged_in()) {

                wp_set_auth_cookie($wp_usr->ID, true);

            }

            do_action("wpo365_session_validated");

        }

        /**
         * Gets authorization and id_tokens from Microsoft authorization endpoint by redirecting the user. The
         * state parameter is used to restore the user's state (= requested page) when redirected back to Wordpress
         * 
         * NOTE The refresh token is not used because it cannot be used to authenticate a user (no id_token)
         * See https://docs.microsoft.com/en-us/azure/active-directory/develop/active-directory-protocols-openid-connect-code 
         *
         * @since   1.0
         *
         * @return  void 
         */
        public static function get_openidconnect_and_oauth_token() {

            $nonce = uniqid();
            Helpers::set_cookie("WPO365_NONCE", $nonce, time() + 120);

            $params = array(
                "client_id" => $GLOBALS["wpo365_options"]["application_id"],
                "response_type" => "id_token code",
                "redirect_uri" => $GLOBALS["wpo365_options"]["redirect_url"],
                "response_mode" => "form_post",
                "scope" => $GLOBALS["wpo365_options"]["scope"],
                "resource" => $GLOBALS["wpo365_options"]["application_id"], // basically the app is asking permissiong to access itself and 
                                                                            // this scenario is only supported when using applciation id instead of application id uri
                "state" => (isset($_SERVER["HTTPS"]) ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]",
                "nonce" => $nonce
            );

            $authorizeUrl = "https://login.microsoftonline.com/" . $GLOBALS["wpo365_options"]["tenant_id"] . "/oauth2/authorize?" . http_build_query($params, "", "&");
            Logger::write_log("DEBUG", "Getting fresh id and authorization tokens: " . $authorizeUrl);

            // Redirect to Microsoft Authorization Endpoint
            wp_redirect($authorizeUrl);
            exit(); // exit after redirect
        }

        /**
         * Handles redirect from Microsofts authorization service and tries to detect
         * any wrong doing and if detected redirects wrong-doer to Wordpress login instead
         *
         * @since   1.0
         * @return  void
         */
         public static function process_openidconnect_token() {
            
            Logger::write_log("DEBUG", "Processing incoming OpenID Connect id_token");

            // Store the Authorization Code for extensions that may need it to obtain access codes for AAD secured resources
            if(isset($_POST["code"])) {
                
                Logger::write_log("DEBUG", "Code found: " . $_POST["code"]);
                Helpers::set_cookie("WPO365_AAD_AUTH_CODE", $_POST["code"], time() + 120);

            }

            // Decode the id_token
            $id_token = Auth::decode_id_token();

            if(Helpers::get_cookie("WPO365_NONCE") !== false) {

                Logger::write_log("DEBUG", "Found nonce cookie with value: " . Helpers::get_cookie("WPO365_NONCE"));

            }
            else {

                Logger::write_log("DEBUG", "Nonce cookie is missing");

            }
            
        
            // Handle if token could not be processed or nonce is invalid
            if($id_token === false 
                || Helpers::get_cookie("WPO365_NONCE") === false 
                || $id_token->nonce != $_COOKIE["WPO365_NONCE"]) {

                Error_Handler::add_login_message(__("Your login might be tampered with. Please contact your System Administrator."));
                Logger::write_log("ERROR", "id token could not be processed and user will be redirected to default Wordpress login");

                Auth::goodbye();

            }
        
            // Delete the nonce cookie variable
            Helpers::set_cookie("WPO365_NONCE", "", time() -3600);
                    
            // Ensure user with the information found in the id_token
            $usr = User_Manager::ensure_user($id_token);
            
            // Handle if user could not be processed
            if($usr === false) {

                Error_Handler::add_login_message(__("Could not create or retrieve your login. Please contact your System Administrator."));
                Logger::write_log("ERROR", "Could not get or create Wordpress user");

                Auth::goodbye();
            }

            // User could log on and everything seems OK so let's restore his state
            Logger::write_log("DEBUG", "Redirecting to " . $_POST["state"]);
            wp_redirect($_POST["state"]);
            exit(); // Always exit after a redirect

        }

        /**
         * Gets an access token in exchange for an authorization token that was received prior when getting
         * an OpenId Connect token
         *
         * @since   2.0
         *
         * @param   string  AAD secured resource for which the access token should give access
         * @return  object  access token as PHP std object
         * @todo    Implement support for refresh token
         */
        public static function get_access_token($resource) {
            
            // Check to see if a refresh code is available
            $refresh_token = Auth::get_refresh_token_for_resource($resource);

            // If not check to see if an authorization code is available
            if($refresh_token === false 
                && Helpers::get_cookie("WPO365_AAD_AUTH_CODE") === false) {

                Logger::write_log("DEBUG", "Could not get access code because of missing authorization or refresh code");
                return false;

            }

            // Test other dependencies
            if(!isset($GLOBALS["wpo365_options"]["application_secret"])
                || !isset($GLOBALS["wpo365_options"]["application_id"])) {
                
                Logger::write_log("DEBUG", "Missing prerequisites for getting an access code for " . $resource);
                return false;

            }

            // Assemble appropriate params object for desired flow
            $params = NULL;
            if($refresh_token !== false) {

                $params = array(
                    "grant_type" => "refresh_token",
                    "client_id" => $GLOBALS["wpo365_options"]["application_id"],
                    "refresh_token" => $refresh_token,
                    "resource" => $GLOBALS["wpo365_options"][$resource],
                    "client_secret" => $GLOBALS["wpo365_options"]["application_secret"]
                );

            }
            else {

                $params = array(
                    "grant_type" => "authorization_code",
                    "client_id" => $GLOBALS["wpo365_options"]["application_id"],
                    "code" => Helpers::get_cookie("WPO365_AAD_AUTH_CODE"),
                    "resource" => $GLOBALS["wpo365_options"][$resource],
                    "redirect_uri" => $GLOBALS["wpo365_options"]["redirect_url"],
                    "client_secret" => $GLOBALS["wpo365_options"]["application_secret"]
                );

            }

            $params_as_str = http_build_query($params, "", "&"); // Fix encoding of ampersand

            Logger::write_log("DEBUG", "Params as String: " . $params_as_str);

            $authorizeUrl = "https://login.microsoftonline.com/" . $GLOBALS["wpo365_options"]["tenant_id"] . "/oauth2/token";
            
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_URL, $authorizeUrl);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $params_as_str);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                "Content-Type: application/x-www-form-urlencoded"
            ));

            if(isset($GLOBALS["wpo365_options"]["skip_host_verification"])
                && $GLOBALS["wpo365_options"]["skip_host_verification"] == 1) {

                    Logger::write_log("DEBUG", "Skipping SSL peer and host verification");

                    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0); 
                    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0); 

            }
        
            $result = curl_exec($curl); // result holds the tokens
        
            if(curl_error($curl)) {

                Logger::write_log("DEBUG", "Error occured whilst getting an access token");
                curl_close($curl);

                return false;

            }
        
            curl_close($curl);
        
            // Validate the access token and return it
            $access_token_obj = json_decode($result);

            $access_token_is_valid = Auth::validate_access_token($access_token_obj);

            if($access_token_is_valid === false) {

                return false;

            }

            // Save refresh token
            Auth::set_refresh_token_for_resource($resource, $access_token_obj->refresh_token);

            return $access_token_obj;
        
        }
        
        /**
         * Helper to validate an oauth access token
         *
         * @since   2.0
         *
         * @param   object  access token as PHP std object
         * @return  object  access token as PHP std object or false if not valid
         * @todo    make by reference instead by value
         */
        private static function validate_access_token($access_token_obj) {
            
            if(isset($access_token_obj->error)) {

                Logger::write_log("DEBUG", "Error occured when validating access token: " . $access_token_obj->error_description);
                return false;

            }
        
            if(empty($access_token_obj) 
                || $access_token_obj === false
                || !isset($access_token_obj->access_token) 
                || !isset($access_token_obj->expires_in) 
                || !isset($access_token_obj->refresh_token)
                || !isset($access_token_obj->token_type)
                || !isset($access_token_obj->resource)
                || strtolower($access_token_obj->token_type) != "bearer" ) {
        
                Logger::write_log("DEBUG", "Access code could not be validated");
                return false;
            }
        
            return $access_token_obj;
        
        }

        /**
         * Unraffles the incoming JWT id_token with the help of Firebase\JWT and the tenant specific public keys available from Microsoft.
         * 
         * NOTE The refresh token is not used because it cannot be used to authenticate a user (no id_token)
         * See https://docs.microsoft.com/en-us/azure/active-directory/develop/active-directory-protocols-openid-connect-code 
         *
         * @since   1.0
         *
         * @return  void 
         */
        private static function decode_id_token() {

            Logger::write_log("DEBUG", "Processing an new id token");

            // Check whether an id_token is found in the posted payload
            if(!isset($_POST["id_token"])) {
                Logger::write_log("ERROR", "id token not found");
                return false;
            }

            // Get the token and get it's header for a first analysis
            $id_token = $_POST["id_token"];
            $jwt_decoder = new JWT();
            $header = $jwt_decoder::header($id_token);
            
            // Simple validation of the token's header
            if(!isset($header->kid) || !isset($header->alg)) {

                Logger::write_log("ERROR", "JWT header is missing so stop here");
                return false;

            }

            Logger::write_log("DEBUG", "Algorithm found " . $header->alg);

            // Discover tenant specific public keys
            $keys = Auth::discover_ms_public_keys();
            if($keys == NULL) {

                Logger::write_log("ERROR", "Could not retrieve public keys from Microsoft");
                return false;

            }

            // Find the tenant specific public key used to encode JWT token
            $key = Auth::retrieve_ms_public_key($header->kid, $keys);
            if($key == false) {

                Logger::write_log("ERROR", "Could not find expected key in keys retrieved from Microsoft");
                return false;

            }

            $pem_string = "-----BEGIN CERTIFICATE-----\n" . chunk_split($key, 64, "\n") . "-----END CERTIFICATE-----\n";

            // Decode athe id_token
            $decoded_token = $jwt_decoder::decode(
                $id_token, 
                $pem_string,
                array(strtoupper($header->alg))
            );

            if(!$decoded_token) {

                Logger::write_log("ERROR", "Failed to decode token " . substr($pem_string, 0, 35) . "..." . substr($pem_string, -35) . " using algorithm " . $header->alg);
                return false;

            }

            return $decoded_token;

        }

        /**
         * Discovers the public keys Microsoft used to encode the id_token
         *
         * @since   1.0
         *
         * @return  void 
         */
        private static function discover_ms_public_keys() {

            $ms_keys_url = "https://login.microsoftonline.com/common/discovery/keys";
            $curl = curl_init();

            curl_setopt($curl, CURLOPT_URL, $ms_keys_url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

            if(isset($GLOBALS["wpo365_options"]["skip_host_verification"])
                && $GLOBALS["wpo365_options"]["skip_host_verification"] == 1) {

                    Logger::write_log("DEBUG", "Skipping SSL peer and host verification");

                    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0); 
                    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0); 

            }

            Logger::write_log("DEBUG", "Getting current public keys from MSFT");
            $result = curl_exec($curl); // result holds the keys
            if(curl_error($curl)) {
                
                // TODO handle error
                Logger::write_log("ERROR", "error occured whilst getting a token: " . curl_error($curl));
                return NULL;

            }
            
            curl_close($curl);
            return json_decode($result);
        }
    
        /**
         * Retrieves the (previously discovered) public keys Microsoft used to encode the id_token
         *
         * @since   1.0
         *
         * @param   string  key-id to retrieve the matching keys
         * @param   array   keys previously discovered
         * @return  void 
         */
        private static function retrieve_ms_public_key($kid, $keys) {

            foreach($keys as $key) {

                if($key[0]->kid == $kid) {

                    if(is_array($key[0]->x5c)) {
                        return $key[0]->x5c[0];
                    }
                    else {
                        return $key[0]->x5c;
                    }
                }
            }
            return false;
        }

        /**
         * Parses url the user should be redirected to upon successful logon
         *
         * @since   1.0
         *
         * @param   string  url => redirect_to parameter set by Wordpress
         * @return  string  redirect_to or site url 
         */
        private static function get_redirect_to($url) {

            // Return base url if argument is missing
            if(empty($url)) {
                return get_site_url();
            }

            $query_string = explode("?", $url);
            parse_str($query_string, $out);
            
            if(isset($out["redirect_to"])) {
                Logger::write_log("DEBUG", "Redirect URL found and parsed: " . $out["redirect_to"]);
                return $out["redirect_to"];
            }

            return get_site_url();
        }

        /**
         * Tries and find a refresh token for an AAD resource that is stored as follows:
         * type1,resource1,token1;type2,resource2,token2
         *
         * @since   2.0
         * 
         * @param   string  $resource   Name for the resource key used to store that resource in the WPO365 options
         * @return  refresh token as string or false if not found
         */
        private static function get_refresh_token_for_resource($resource) {
            
            $refresh_cookie = Helpers::get_cookie("WPO365_REFRESH_TOKENS");
            if($refresh_cookie === false) {

                return false;

            }

            Logger::write_log("DEBUG", "Refresh cookie found: $refresh_cookie");

            $refresh_tokens = explode(";", $refresh_cookie);

            $refresh_token = array_map(function($input) use ($resource) {

                $result = explode(",", $input);
                if(strtolower($result[0]) == strtolower($resource)) { // e.g. spo_home, msft_graph 

                    return $result;

                }

            }, $refresh_tokens);

            // Return the refresh token
            if(sizeof($refresh_token) == 1
                && sizeof($refresh_token[0])) {

                return $refresh_token[0][1];

            }

            // Or else return false if the token was not found
            Logger::write_log("DEBUG", "Refresh cookies found but appears to be empty \"" . $refresh_cookie . "\"");
            return false;

        }

         /**
         * Sets a refresh token in a cookie as follows: resource1,token1;resource2,token2
         *
         * @since   2.0
         * 
         * @param   string  $resource name   Name for the resource key as used to store that resource in the WPO365 options
         * @return  refresh token as string or false if not found
         */
        private static function set_refresh_token_for_resource($resource, $refresh_token) {

            // Check prerequisites
            if(!isset($GLOBALS["wpo365_options"]["refresh_duration"])) {

                return false;

            }

            // Before saving a new refresh token a previous one must be deleted
            Auth::remove_refresh_token_for_resource($resource);

            // Get cookie or else provide empty array for storage
            $refresh_tokens = Helpers::get_cookie("WPO365_REFRESH_TOKENS")
                ? explode(";", $refresh_cookie = Helpers::get_cookie("WPO365_REFRESH_TOKENS"))
                : array();
            
            // Add new refresh token to the existing tokens
            $refresh_tokens[] = $resource . "," . $refresh_token;
            
            Helpers::set_cookie("WPO365_REFRESH_TOKENS", implode(";", $refresh_tokens), time() + intval($GLOBALS["wpo365_options"]["refresh_duration"]));

            return true;

        }

        /**
         * Tries and removes all refresh tokens for give resource from the fresh token cookie
         *
         * @since   2.0
         * 
         * @param   string  $resource   Name for the resource key used to store that resource in the WPO365 options
         */
        private static function remove_refresh_token_for_resource($resource) {

            Logger::write_log("DEBUG", "Requested removing of refresh tokens for resource: " . $resource);

            $refresh_cookie = Helpers::get_cookie("WPO365_REFRESH_TOKENS");
            if($refresh_cookie === false) {

                return;

            }

            $refresh_tokens_old = explode(";", $refresh_cookie);

            $refresh_token_new = array_map(function($input) use ($resource) {

                $result = explode(",", $input);
                if(strtolower($result[0]) != strtolower($resource)) { // e.g. spo_home, msft_graph 

                    return implode(",", $result);

                }
                

            }, $refresh_tokens_old);

            Helpers::set_cookie("WPO365_REFRESH_TOKENS", implode(";", $refresh_token_new), time() + intval($GLOBALS["wpo365_options"]["refresh_duration"]));

        }
        
    }
?>