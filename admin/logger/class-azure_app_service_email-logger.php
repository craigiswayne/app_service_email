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
class Azure_app_service_email_logger
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

    public function email_logger_capture_emails($to, $subject, $status, $error_message)
    {
        // Log the email in a custom database table
        global $wpdb;
        $table_name = $wpdb->prefix . 'azure_email_logs';
        $wpdb->insert(
            $table_name,
            array(
                'to_email' => $to,
                'subject' => $subject,
                'sent_date' => current_time('mysql'),
                'status' => $status,
                'error_message' => $error_message,

            ),
            array('%s', '%s', '%s', '%s', '%s', '%s')
        );
    }

    public function email_logger_create_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'azure_email_logs';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id int(11) NOT NULL AUTO_INCREMENT,
        to_email varchar(255) NOT NULL,
        subject text NOT NULL,
        sent_date datetime NOT NULL,
		status varchar(20) NOT NULL,
        error_message text,
        PRIMARY KEY (id)
    ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }


    // Hook to schedule the daily cleanup event
    public function custom_schedule_email_logs_cleanup()
    {
        if (!wp_next_scheduled('custom_daily_email_logs_cleanup')) {
            wp_schedule_event(time(), 'daily', 'custom_daily_email_logs_cleanup');
        }
    }

    // Function to delete old email logs
    public function custom_delete_old_email_logs()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'azure_email_logs';

        // Fetch the number of days from the database
        $number_of_days = get_option('custom_email_logs_retention_days', 30);
        $expiry_date = date('Y-m-d', strtotime("-$number_of_days days"));
        $wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE sent_date < %s", $expiry_date));
    }
}
