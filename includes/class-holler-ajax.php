<?php
/**
 * Handles sending messages
 * @since       0.1.0
 */


// Exit if accessed directly
if( !defined( 'ABSPATH' ) ) exit;

if( !class_exists( 'Holler_Ajax' ) ) {

    /**
     * Holler_Ajax class
     *
     * @since       0.2.0
     */
    class Holler_Ajax {

        /**
         * @var         Holler_Ajax $instance The one true Holler_Ajax
         * @since       0.2.0
         */
        private static $instance;
        public static $errorpath = '../php-error-log.php';
        // sample: error_log("meta: " . $meta . "\r\n",3,self::$errorpath);

        /**
         * Get active instance
         *
         * @access      public
         * @since       0.2.0
         * @return      object self::$instance The one true Holler_Ajax
         */
        public static function instance() {
            if( !self::$instance ) {
                self::$instance = new Holler_Ajax();
                self::$instance->hooks();
            }

            return self::$instance;
        }


        /**
         * Include necessary files
         *
         * @access      private
         * @since       0.2.0
         * @return      void
         */
        private function hooks() {

            add_action( 'wp_ajax_nopriv_hwp_send_email', array( $this, 'hwp_send_email' ) );
            add_action( 'wp_ajax_hwp_send_email', array( $this, 'hwp_send_email' ) );

            add_action( 'wp_ajax_nopriv_hwp_mc_subscribe', array( $this, 'hwp_mc_subscribe' ) );
            add_action( 'wp_ajax_hwp_mc_subscribe', array( $this, 'hwp_mc_subscribe' ) );

            add_action( 'wp_ajax_nopriv_hwp_mailpoet_subscribe', array( $this, 'mailpoet_subscribe' ) );
            add_action( 'wp_ajax_hwp_mailpoet_subscribe', array( $this, 'mailpoet_subscribe' ) );

            add_action( 'wp_ajax_nopriv_hwp_track_event', array( $this, 'hwp_track_event' ) );
            add_action( 'wp_ajax_hwp_track_event', array( $this, 'hwp_track_event' ) );

            add_action( 'wp_ajax_nopriv_hwp_track_view', array( $this, 'hwp_track_view' ) );
            add_action( 'wp_ajax_hwp_track_view', array( $this, 'hwp_track_view' ) );

            add_action( 'wp_ajax_hwp_toggle_active', array( $this, 'toggle_active' ) );

            add_action( 'wp_ajax_hwp_ajax_page_search', array( $this, 'page_search' ) );

        }

        /**
         * Send message via email
         *
         * @since       0.1.0
         * @return      void
         */
        public function hwp_send_email() {

            if( empty( $_GET['nonce'] ) || !wp_verify_nonce( $_GET['nonce'], 'holler-box' ) ) {
                wp_send_json_error('Verification failed.' );
            }

            if( empty( $_GET['id'] ) || empty( $_GET['email'] ) )
                wp_send_json_error('Missing required field.' );

            $msg = $_GET['msg'];

            $email = $_GET['email'];

            $id = $_GET['id'];

            $title = get_option( 'hwp_email_title' );

            $title = ( !empty( $title ) ? $title : "New Holler Box Message" );

            $sendto = get_post_meta( $id, 'opt_in_send_to', 1);

            $headers = array( 'Reply-To: <' . $email . '>' );

            $success = wp_mail( $sendto, $title, $msg, $headers );

            wp_send_json_success( 'Sent ' . $msg . ' from ' . $email . ' Success: ' . $success );
                
        }

        /**
         * Subscribe user via MailChimp API
         * Help from https://www.codexworld.com/add-subscriber-to-list-mailchimp-api-php/
         * 
         *
         * @since       0.1.0
         * @return      void
         */
        public function hwp_mc_subscribe() {

            if( empty( $_GET['nonce'] ) || !wp_verify_nonce( $_GET['nonce'], 'holler-box' ) ) {
                wp_send_json_error('Verification failed.' );
            }

            $list_id = $_GET['list_id'];

            $email = $_GET['email'];

            // MailChimp API credentials
            $api_key = get_option('hwp_mc_api_key');

            if( empty( $list_id ) || empty( $api_key ) || empty( $email ) )
                wp_send_json_error('Missing required field.');

            $headers = array(
                'Authorization' => 'Basic ' . base64_encode( 'user:' . $api_key ),
                'Content-Type' => 'application/json'
              );
            
            // MailChimp API URL
            $member_id = md5(strtolower($email));
            $data_center = substr($api_key,strpos($api_key,'-')+1);
            $url = 'https://' . $data_center . '.api.mailchimp.com/3.0/lists/' . $list_id . '/members/' . $member_id;
            
            // member information
            $body = json_encode( array(
                'email_address' => $email,
                'status'        => 'pending'
                // 'merge_fields'  => [
                //     'FNAME'     => $fname,
                //     'LNAME'     => $lname
                // ]
            ) );

            $response = wp_remote_post( $url, array(
                'method' => 'PUT',
                'timeout' => 15,
                'headers' => $headers,
                'body' => $body
                )
            );

            if ( is_wp_error( $response ) ) {
               $error_message = $response->get_error_message();
               wp_send_json_error( $error_message );
            } else {
               wp_send_json_success( $response );
            }
        }

        /**
         * Subscribe user via MailPoet API
         * http://beta.docs.mailpoet.com/article/195-add-subscribers-through-your-own-form-or-plugin
         * 
         *
         * @since       0.6.0
         * @return      void
         */
        public function mailpoet_subscribe() {

            if( empty( $_GET['nonce'] ) || !wp_verify_nonce( $_GET['nonce'], 'holler-box' ) ) {
                wp_send_json_error('Verification failed.' );
            }

            if( !class_exists('\MailPoet\API\API') )
                wp_send_json_error('Please install and activate the MailPoet plugin.' );

            $list = $_GET['list_id'];

            $subscriber = array(
                'email' => sanitize_text_field( $_GET['email'] )
                );

            if( empty( $subscriber ) )
                wp_send_json_error('Missing required field.');
            
            // Subscribe via MailPoet 3 API
            try {
              $subscriber = \MailPoet\API\API::MP('v1')->addSubscriber($subscriber, array( $list ) );
            } catch(Exception $exception) {
              wp_send_json_error( $exception->getMessage() ); 
            }

            wp_send_json_success( $subscriber );

        }

        /**
         * Track event (click)
         *
         * @since       0.1.0
         * @return      void
         */
        public function hwp_track_event() {

            $id = $_GET['id'];

            if( empty( $_GET['nonce'] ) || !wp_verify_nonce( $_GET['nonce'], 'holler-box' ) || empty( $id ) ) {
                wp_send_json_error('Missing required field.');
            }

            $conversions = get_post_meta( $id, 'hwp_conversions', 1 );

            if( $conversions ) {
                update_post_meta( $id, 'hwp_conversions', intval( $conversions ) + 1 );
            } else {
                $conversions = update_post_meta( $id, 'hwp_conversions', 1 );
            }

            wp_send_json_success( 'Interaction tracked, total: ' . $conversions );
                
        }

        /**
         * Track note shown (for conversion rates)
         *
         * @since       0.1.0
         * @return      void
         */
        public function hwp_track_view() {

            $id = $_GET['id'];

            if( empty( $_GET['nonce'] ) || !wp_verify_nonce( $_GET['nonce'], 'holler-box' ) || empty( $id ) ) {
                wp_send_json_error('Missing required field.');
            }

            if( get_post_meta( $id, 'hwp_views', 1 ) ) {
                $views = get_post_meta( $id, 'hwp_views', 1 );
                $views = update_post_meta( $id, 'hwp_views', intval( $views ) + 1 );
            } else {
                $views = update_post_meta( $id, 'hwp_views', 1 );
            }

            wp_send_json_success( 'View tracked, total: ' . get_post_meta( $id, 'hwp_views', 1 ) );
                
        }

        /**
         * Toggle active meta value. ID is required
         *
         * @since       0.1.0
         * @return      void
         */
        public function toggle_active() {

            $id = $_GET['id'];

            if( empty($id) )
                wp_send_json_error('ID is required.');

            if( get_post_meta( $id, 'hwp_active', 1 ) === '1' ) {
                delete_post_meta( $id, 'hwp_active' );
            } else {
                update_post_meta( $id, 'hwp_active', '1' );
            }

            wp_send_json_success( 'Toggled. New value: ' . get_post_meta( $id, 'hwp_active', 1 ) );
                
        }

        /**
         * Used for autofilling pages via admin text field, using jQuery suggest
         *
         * @access      public
         * @since       0.1
         * @return      void
         */
        public function page_search() {

            $s = wp_unslash( $_GET['q'] );

            $comma = _x( ',', 'page delimiter' );
            if ( ',' !== $comma )
                $s = str_replace( $comma, ',', $s );
            if ( false !== strpos( $s, ',' ) ) {
                $s = explode( ',', $s );
                $s = $s[count( $s ) - 1];
            }
            $s = trim( $s );

            $term_search_min_chars = 2;

            $the_query = new WP_Query( 
                array( 
                    's' => $s,
                    'posts_per_page' => 5,
                    'post_type' => 'page'
                    ) 
                );

            if ( $the_query->have_posts() ) {
                while ( $the_query->have_posts() ) {
                    $the_query->the_post();
                    $results[] = get_the_title();
                }
                /* Restore original Post Data */
                wp_reset_postdata();
            } else {
                $results = 'No results';
            }

            echo join( $results, "\n" );
            wp_die();
        }


    }

    $holler_ajax = new Holler_Ajax();
    $holler_ajax->instance();

} // end class_exists check