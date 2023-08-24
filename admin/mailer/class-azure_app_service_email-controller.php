<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://github.com/Azure/Wordpress-on-Linux-App-Service-plugins/tree/main/app_service_email/
 * @since      1.0.0
 *
 * @package    App_service_email
 * @subpackage App_service_email/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Azure_app_service_migration
 * @subpackage Azure_app_service_migration/admin
 * @author     Microsoft <wordpressdev@microsoft.com>
 */
class Azure_app_service_email_controller
{

    /**
     * The ID of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $plugin_name    The ID of this plugin.
     */
    private $plugin_name;

    /**
     * The version of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $version    The current version of this plugin.
     */
    private $version;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param      string    $plugin_name       The name of this plugin.
     * @param      string    $version    The version of this plugin.
     */
    public function __construct($plugin_name, $version)
    {

        $this->plugin_name = $plugin_name;
        $this->version = $version;
    }

    /**
     * Register the stylesheets for the admin area.
     *
     * @since    1.0.0
     */

    public function override_wp_mail_with_acs($args)
    {
        $maxRetries = 3;
        $retryCount = 0;

        // Extract email data from arguments

        $to = isset($args['to']) ? $args['to'] : '';
        $subject = isset($args['subject']) ? $args['subject'] : '';
        $message = isset($args['message']) ? $args['message'] : '';

        do {
            $result = $this->acs_send_email($to, $subject, $message);
            $retryCount++;
        } while (!$result && $retryCount < $maxRetries);

        return $result;
    }

    public function generate_request_body($to, $subject, $message, $senderaddress)
    {
        return json_encode([
            'senderAddress' => $senderaddress,
            'content' => [
                'subject' => $subject,
                'plainText' => $message
            ],
            'recipients' => [
                'to' => array(array('address' => $to))
            ]
        ]);
    }

    public function send_email_request($acsurl, $headers, $requestBody)
    {
        $args = [
            'headers' => $headers,
            'body' => $requestBody,
            'method' => 'POST'
        ];
        return wp_remote_post($acsurl, $args);
    }

    public function set_headers($dateStr, $hashedBodyStr, $acshost, $signature)
    {
        return [
            'Date' => $dateStr,
            'x-ms-content-sha256' => $hashedBodyStr,
            'Authorization' => 'HMAC-SHA256 SignedHeaders=date;host;x-ms-content-sha256&Signature=' . $signature,
            'Content-Type' => 'application/json'
        ];
    }

    public function generate_string_to_sign($requestMethod, $pathWithQuery, $dateStr, $acshost, $hashedBodyStr, $key)
    {
        $stringToSign = $requestMethod . "\n" . $pathWithQuery . "\n" . $dateStr . ";" . $acshost . ";" . $hashedBodyStr;
        return base64_encode(hash_hmac('sha256', $stringToSign, $key, true));
    }

    public function acs_send_email($to, $subject, $message)
    {
        if (empty(getenv('WP_EMAIL_CONNECTION_STRING'))) {
            do_action('wp_mail_failed', new WP_Error('acs_mail_failed', 'App Setting WP_EMAIL_CONNECTION_STRING is missing'));
            return false;
        }

        $appSetting = getenv('WP_EMAIL_CONNECTION_STRING');
        $pattern = '/endpoint=(.*?);senderaddress=(.*?);accesskey=(.*)$/';

        if (preg_match($pattern, $appSetting, $matches)) {
            $acsurl = $matches[1];
            $senderaddress = $matches[2];
            $apikey = $matches[3];
        } else {
            //logging error when app setting is not in expected format
            do_action('wp_mail_failed', new WP_Error('acs_mail_failed', 'App Setting WP_EMAIL_CONNECTION_STRING is not in the right format'));
            return false;
        }

        $acshost = str_replace('https://', '', $acsurl);
        $pathWithQuery = '/emails:send?api-version=2023-01-15-preview';
        $requestBody = $this->generate_request_body($to, $subject, $message, $senderaddress);
        $hashedBodyStr = base64_encode(hash('sha256', $requestBody, true));
        $requestMethod = 'POST';
        $dateStr = gmdate('D, d M Y H:i:s \G\M\T');

        $key = base64_decode($apikey);
        $signature = $this->generate_string_to_sign($requestMethod, $pathWithQuery, $dateStr, $acshost, $hashedBodyStr, $key);
        $headers = $this->set_headers($dateStr, $hashedBodyStr, $acshost, $signature);
        $acsurl = "https://" . $acshost . $pathWithQuery;
        try {
            $response = $this->send_email_request($acsurl, $headers, $requestBody);
            if (is_wp_error($response)) {
                do_action('wp_mail_failed', new WP_Error('acs_mail_failed', $response->get_error_message()));
                return false;
            } else {
                $response_code = wp_remote_retrieve_response_code($response);
                $response_array = json_decode(wp_json_encode($response), true);
                $body_array = json_decode($response_array['body'], true);
                $status = $body_array['status'];
                
                if ($response_code === 200 || ($response_code === 202 && $status === 'Running')) {
                    return true;
                } else {
                    $error_array = $body_array['error'];
                    $message = $error_array['message'];
                    do_action('wp_mail_failed', new WP_Error('acs_mail_failed', $message));
                    return false;
                }
            }
        } catch (Exception $e) {
            do_action('wp_mail_failed', new WP_Error('acs_mail_failed', 'An Error Occured: ' . $e->getMessage()));
            return false;
        }
    }
}
