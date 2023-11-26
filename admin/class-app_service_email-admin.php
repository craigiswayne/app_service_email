<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://www.microsoft.com
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
 * @package    App_service_email
 * @subpackage App_service_email/admin
 * @author     Microsoft <wordpressdev@microsoft.com>
 */
class App_service_email_Admin
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
    public function enqueue_styles()
    {
        // Enqueue your custom stylesheets     
        wp_enqueue_style('app_service_email_admin', plugin_dir_url(__FILE__) . 'css/app_service_email-admin.css', array(), '1.0.0', 'all');
    }

    /**
     * Register the JavaScript for the admin area.
     *
     * @since    1.0.0
     */

    public function enqueue_scripts()
    {
        // Enqueue your custom JavaScript file
        wp_enqueue_script($this->plugin_name, plugin_dir_url(__FILE__) . 'js/app_service_email-admin.js', array('jquery'), $this->version, true);

        // Pass data to JavaScript
        wp_localize_script($this->plugin_name, 'appServiceEmailData', array(
            'ajax_url' => admin_url('admin-ajax.php'),
        ));
    }

    public function email_logger_add_admin_menu()
    {
        add_menu_page(
            'Azure Email Logs',
            'Azure Email Logs',
            'manage_options',
            'email-logger',
            array($this, 'email_logger_display_logs')
        );
    }

    public function email_logger_display_logs()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'azure_email_logs';

        $this->handle_delete_request($wpdb, $table_name);
        $this->handle_bulk_delete_request($wpdb, $table_name);

        $total_logs = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM $table_name GROUP BY status"
        );

        $total_all = 0;
        $total_success = 0;
        $total_failure = 0;

        foreach ($total_logs as $log) {
            if ($log->status === 'Success') {
                $total_success = $log->count;
            } elseif ($log->status === 'Failure') {
                $total_failure = $log->count;
            }
            $total_all += $log->count;
        }

        // Check if filtering by success or failure logs is requested
        $filter_by = '';
        if (isset($_GET['filter']) && ($_GET['filter'] === 'Success' || $_GET['filter'] === 'Failure')) {
            $filter_by = $_GET['filter'];
        }

        $total_logs_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name" . ($filter_by ? $wpdb->prepare(" WHERE status = %s", $filter_by) : ''));

        // Set the number of logs to display per page
        $logs_per_page = 10;

        $total_pages = ceil($total_logs_count / $logs_per_page);
        // Validate the current page number
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        // Calculate the offset for pagination
        $offset = ($current_page - 1) * $logs_per_page;

        $orderby = isset($_GET['orderby']) ? $_GET['orderby'] : 'sent_date';
        $order = isset($_GET['order']) ? $_GET['order'] : 'desc';

        // Build the SQL query based on filter, sorting, and pagination
        $query = "SELECT * FROM $table_name";
        if ($filter_by) {
            $query .= $wpdb->prepare(" WHERE status = %s", $filter_by);
        }
        $query .= $wpdb->prepare(" ORDER BY $orderby $order LIMIT %d OFFSET %d", $logs_per_page, $offset);

        // Fetch email logs with sorting, filtering, and pagination
        $logs = $wpdb->get_results($query);
        $this->output_logs_table($logs, $orderby, $order, $total_all, $total_success, $total_failure);
        $this->output_pagination_links($total_pages, $current_page, $pagination_links);
    }

    private function handle_delete_request($wpdb, $table_name)
    {
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['email_log_id'])) {
            $log_id_to_delete = absint($_GET['email_log_id']);
            $wpdb->delete($table_name, array('id' => $log_id_to_delete));
        }
    }

    private function handle_bulk_delete_request($wpdb, $table_name)
    {
        if (isset($_POST['delete_logs']) && isset($_POST['email_log_ids'])) {
            $logs_to_delete = array_map('absint', $_POST['email_log_ids']);
            foreach ($logs_to_delete as $log_id) {
                $wpdb->delete($table_name, array('id' => $log_id));
            }
        }
    }

    private function output_pagination_links($total_pages, $current_page, $pagination_links)
    {
        // Output pagination links ...

        echo '<div class="tablenav">';
        echo '<div class="tablenav-pages">';
        $pagination_links = paginate_links(array(
            'base' => add_query_arg('paged', '%#%'),
            'format' => '',
            'prev_text' => '&laquo; Previous',
            'next_text' => 'Next &raquo;',
            'total' => $total_pages,
            'current' => $current_page,
            'mid_size' => 2,
            'type' => 'array',
            'prev_next' => true,
        ));

        if ($pagination_links) {
            echo '<div class="pagination-buttons">';
            foreach ($pagination_links as $link) {
                echo '<button class="pagination-button">' . $link . '</button>';
            }
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';
    }

    private function output_logs_table($logs, $orderby, $order, $total_all, $total_success, $total_failure)
    {
        ob_start(); // Start output buffering

?>
        <div class="wrap">
            <h1>Email Logs</h1>
            <p><a href="?page=email-logger">All (<?= $total_all ?>)</a> | <a href="?page=email-logger&filter=Success">Success (<?= $total_success ?>)</a> | <a href="?page=email-logger&filter=Failure">Failure (<?= $total_failure ?>)</a></p>
            <form method="post">
                <table class="wp-list-table widefat fixed striped">
                    <input type="submit" name="delete_logs" value="Delete" style="margin-bottom: 10px;" class="button button-primary">
                    <thead>
                        <tr>
                            <th width="5%"><input type="checkbox" id="select-all-logs" class="select-all-checkbox"></th>
                            <th><?= $this->getSortableColumnHeader('to_email', 'To Email', $orderby, $order) ?></th>
                            <th><?= $this->getSortableColumnHeader('subject', 'Subject', $orderby, $order) ?></th>
                            <th><?= $this->getSortableColumnHeader('sent_date', 'Sent Date', $orderby, $order) ?></th>
                            <th width="10%"><?= $this->getSortableColumnHeader('status', 'Status', $orderby, $order) ?></th>
                            <th width="20%">Error Message</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($logs as $log) : ?>
                            <tr>
                                <th><input type="checkbox" name="email_log_ids[]" value="<?= esc_attr($log->id) ?>"></th>
                                <td><?= esc_html($log->to_email) ?></td>
                                <td><?= esc_html($log->subject) ?></td>
                                <td><?= esc_html($log->sent_date) ?></td>
                                <td><?= esc_html($log->status) ?></td>
                                <td><?= wp_kses_post($log->error_message) ?></td>
                                <td>
                                    <a class="delete-link" href="<?= esc_url(add_query_arg(array('action' => 'delete', 'email_log_id' => $log->id))) ?>"><span class="dashicons dashicons-trash"></span></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
            <script src="<?= plugin_dir_url(__FILE__) . 'js/app_service_email-admin.js' ?>"></script>
        </div>
<?php

        echo ob_get_clean(); // Get the output buffer contents and clean the buffer
    }

    private function getSortableColumnHeader($column, $label, $orderby, $order)
    {
        $url = esc_url(add_query_arg(array('orderby' => $column, 'order' => ($orderby === $column && $order === 'asc') ? 'desc' : 'asc')));
        $arrow = ($orderby === $column) ? ($order === 'asc' ? '&#9650;' : '&#9660;') : '';

        return "<a href='$url'>$label $arrow</a>";
    }
}
