<?php

/**
 * Insert a new Token
 *
 * @param  array  $args
 *
 * @return int|WP_Error
 */
function eps_insert_token( $args = [] ) {

    global $wpdb;

        $inserted = $wpdb->insert($wpdb->prefix . 'eps_token', $args);

        if ( ! $inserted ) {
            return 0;
        }

        return $wpdb->insert_id;
    
}

/**
 * Fetch a single info from the DB
 *
 * @param  int $order_id
 *
 * @return object
 */
function eps_get_token( $order_id ) {

    global $wpdb;

    return $wpdb->get_row(  $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}eps_token WHERE order_id = %d order by id DESC", $order_id )  );
}

/**
 * Delete an token
 *
 * @param  int $order_id
 *
 * @return int|boolean
 */
function delete_eps_token( $order_id ) {
    global $wpdb;

    return $wpdb->delete(
        $wpdb->prefix . 'eps_token',
        [ 'order_id' => $order_id ]
    );
}


function insertTransectionInfo( $args = [] ) {

    global $wpdb;
    $type = get_option('mc_eps_settings'); 
    $type = unserialize($type);
    $mode = $type['mode'] ?? 'sandbox';
    
    $order_id = $args['order_id'];
    $customer_account = $args['customer_account'];
    if($mode=="sandbox"){
    $table_name = $wpdb->prefix . 'eps_sandbox_transections';
    }else{
      $table_name = $wpdb->prefix . 'eps_transections';  
    }
   // $status =  $args['response_description'];
    $query = $wpdb->prepare("select * from $table_name where customer_account = %s order by id desc limit 1", $customer_account);
    $data = $wpdb->get_results( $query );
    if (count($data)> 0){

        if($data[0]->response_description) {
            //update status...
            $select_data_id = $data[0]->id;
            //execute query....
            $wpdb->update($table_name,$args , array( 'id' => $select_data_id ) );
        }

        // nothing to do....
    
    }
    else {

        $inserted = $wpdb->insert($table_name, $args);
        if ( ! $inserted ) {
            return 0;
        }
        return $wpdb->insert_id;
    }

    return 0;
}


add_action( 'wp_enqueue_scripts', 'my_custom_enqueue_scripts', 10 );

function my_custom_enqueue_scripts() {
    wp_enqueue_style('jquery-datatables-css','//cdn.datatables.net/1.11.3/css/jquery.dataTables.min.css');
    wp_enqueue_script('jquery-datatables-js','//cdn.datatables.net/1.10.22/js/jquery.dataTables.min.js',array('jquery'));
   
}


add_action('wp_ajax_eps_transection_endpoint', 'my_custom_ajax_endpoint'); //logged in

function my_custom_ajax_endpoint(){

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
    }

    $response = []; 
    
    //Get WordPress posts - you can get your own custom posts types etc here
    $posts = getTransectionInfo();
    
    //Add two properties to our response - 'data' and 'recordsTotal'
    $response['data'] = !empty($posts) ? $posts : []; //array of post objects if we have any, otherwise an empty array        
    $response['recordsTotal'] = !empty($posts) ? count($posts) : 0; //total number of posts without any filtering applied
    
    wp_send_json($response); //json_encodes our $response and sends it back with the appropriate headers

}
// Admin page
add_action('admin_enqueue_scripts', 'eps_enqueue_scripts');
function eps_enqueue_scripts($hook) {
    // Optional: load only on plugin page
    if ($hook !== 'toplevel_page_eps_plugin') return;

    // Enqueue JS
    wp_enqueue_script(
        'eps-plugin-js', // handle
        plugin_dir_url(__FILE__) . 'assets/js/eps-plugin.js', // path to your JS
        ['jquery'], // dependencies
        '1.0',
        true // load in footer
    );

    // Localize AFTER enqueue
    wp_localize_script('eps-plugin-js', 'eps_ajax', [
        'nonce'    => wp_create_nonce('eps_update_product_status_nonce'),
        'ajax_url' => admin_url('admin-ajax.php')
    ]);
}

add_action('wp_ajax_eps_update_product_status', 'eps_update_product_status');
//add_action('wp_ajax_nopriv_eps_update_product_status', 'eps_update_product_status'); // for guests (if needed)

function eps_update_product_status() {
    global $wpdb;

    // Optional: nonce check
    if ( ! isset($_POST['_ajax_nonce']) || ! wp_verify_nonce($_POST['_ajax_nonce'], 'eps_update_product_status_nonce') ) {
        wp_send_json_error(['message' => 'Invalid nonce'], 400);
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
    }

    // Validate POST
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';

    if ($id <= 0 || empty($status)) {
        wp_send_json_error(['message' => 'Missing parameters'], 400);
    }
    $type = get_option('mc_eps_settings'); 
    $type = unserialize($type);
    $mode = $type['mode'] ?? 'sandbox';
    if($mode=="sandbox"){
    $table_name = $wpdb->prefix . 'eps_sandbox_transections';
    }else{
      $table_name = $wpdb->prefix . 'eps_transections';  
    }

    $updated = $wpdb->update(
        $table_name,
        ['product_status' => $status],
        ['id' => $id]
    );

    if ($updated === false) {
        wp_send_json_error(['message' => 'DB update failed'], 500);
    }

    wp_send_json_success(['message' => 'Status updated']);
}

add_action('wp_ajax_eps_sync_gateway', 'eps_sync_gateway');

function eps_sync_gateway() {
    global $wpdb;

    // Check nonce
    if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce($_POST['_ajax_nonce'], 'eps_update_product_status_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce'], 400);
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
    }
    
       // Current UTC time = end date
    $range = sanitize_text_field($_POST['range'] ?? '12_months');

    $endDate = (new DateTime('now', new DateTimeZone('UTC')))
        ->format('Y-m-d\TH:i:s.v\Z');

    if ($range === '7_days') {
        $startDate = (new DateTime('now', new DateTimeZone('UTC')))
            ->modify('-7 days')
            ->format('Y-m-d\TH:i:s.v\Z');
    } else {
        // default = 12 months
        $startDate = (new DateTime('now', new DateTimeZone('UTC')))
            ->modify('-12 months')
            ->format('Y-m-d\TH:i:s.v\Z');
    }
    $processor = new \MCoder\EPS\Gateway\Processor();
    // Call your EPS gateway API here
    $gateway_data = $processor->eps_sync_gateway($startDate, $endDate); // your custom function

    if(!$gateway_data){
        wp_send_json_success(['data' => []]);
        //wp_send_json_error(['message' => 'Failed to fetch data from gateway']);
    }
    $type = get_option('mc_eps_settings'); 
    $type = unserialize($type);
    $mode = $type['mode'] ?? 'sandbox';
    if($mode=="sandbox"){
    $table_name = $wpdb->prefix . 'eps_sandbox_transections';
    }else{
      $table_name = $wpdb->prefix . 'eps_transections';  
    }

        foreach ($gateway_data as $tran) {
        
            $tran_id = $tran['EPSTransactionId'];
        
            // Check if record exists
            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, is_sync FROM {$table_name} WHERE customer_account = %s",
                    $tran_id
                )
            );
        
            // If exists and synced → SKIP
            /*if ($existing && (int)$existing->is_sync === 1) {
                continue;
            }*/
        
            // If exists and not synced → UPDATE
            if ($existing) {
                $wpdb->update(
                    $table_name,
                    [
                        'order_id'             => $tran['OrderId'],
                        'amount'               => $tran['TotalAmount'],
                        'customer_account'     => $tran['EPSTransactionId'],
                        'response_description' => $tran['Status'],
                        'created_at'           => $tran['TransactionDate'],
                        //'product_status'       => 'Pending',
                        'is_sync'=>1,
                    ],
                    [
                        'id' => $existing->id
                    ],
                    ['%s','%s','%s','%s','%s','%s'],
                    ['%d']
                );
            }
            // If not exists → INSERT
            else {
               
                $wpdb->insert(
                    $table_name,
                    [
                        'order_id'             => $tran['OrderId'],
                        'amount'               => $tran['TotalAmount'],
                        'customer_account'     => $tran['EPSTransactionId'],
                        'transection_id'       => $tran['FinancialEntity'],
                        'response_description' => $tran['Status'],
                        'created_at'           => $tran['TransactionDate'],
                        'product_status'       => 'Pending',
                        'is_sync'              => 1
                    ],
                    ['%s','%s','%s','%s','%s','%s','%s','%d']
                );
            
            }
        }

    wp_send_json_success(['message' => 'Data synced successfully!']);
}

function getTransectionInfo( $args = [] ) {

    global $wpdb;

    $defaults = [
		'number'  => 50000,
		'offset'  => 0,
		'orderby' => 'id',
		'order'   => 'DESC',
	];

	$args = wp_parse_args( $args, $defaults );
	$type = get_option('mc_eps_settings'); 
    $type = unserialize($type);
    $mode = $type['mode'] ?? 'sandbox';
    if($mode=="sandbox"){
    $table_name = $wpdb->prefix . 'eps_sandbox_transections';
    }else{
      $table_name = $wpdb->prefix . 'eps_transections';  
    }
	//$table_name = $wpdb->prefix . 'eps_transections';

    $query = sprintf( 'SELECT * FROM %s', $table_name );

	$query .= sprintf( ' ORDER BY `%1$s` %2$s LIMIT %3$d, %4$d', $args['orderby'], $args['order'], $args['offset'], $args['number'] );

	//phpcs:ignore
	$items = $wpdb->get_results( $query );

	return $items;
}
add_action( 'template_redirect', 'myplugin_handle_cancel_payment' );

function myplugin_handle_cancel_payment() {
    
    if(isset($_GET['Status'])){
       if($_GET['Status']=='Failed'){
           $status  = sanitize_text_field( $_GET['Status'] ?? '' );
            $message = sanitize_text_field( $_GET['Message'] ?? '' );
            $eps_txn = sanitize_text_field( $_GET['MerchantTransactionId'] ?? '' );
            //$total = $order->get_total();
            if($eps_txn){
            $args = [
                    
                    'response_description' => 'Failure',
                    'customer_account'     => $eps_txn,
                ];
            insertTransectionInfo($args);
            }
       }
    }
    if ( ! isset($_GET['cancel_order']) ) {
        return;
    }
// normalize amp;
    foreach ($_GET as $k => $v) {
        $_GET[str_replace('amp;', '', $k)] = $v;
    }

    $order_id = absint($_GET['order_id'] ?? 0);
   
    if ( ! $order_id ) {
        return;
    }

    $order = wc_get_order($order_id);
    if ( ! $order ) {
        return;
    }
     
   
    

    // Optional: only your gateway
    if ( $order->get_payment_method() !== 'eps' ) {
        return;
    }

    // Only the customer (who holds the order key) may cancel, and only unpaid orders.
    $order_key = sanitize_text_field( wp_unslash( $_GET['order'] ?? '' ) );
    if ( ! $order_key || ! hash_equals( $order->get_order_key(), $order_key ) || ! $order->has_status( array( 'pending', 'failed' ) ) ) {
        return;
    }
    
    // Read gateway response
    $status  = sanitize_text_field( $_GET['Status'] ?? '' );
    $message = sanitize_text_field( $_GET['Message'] ?? '' );
    $eps_txn = sanitize_text_field( $_GET['MerchantTransactionId'] ?? '' );
    $total = $order->get_total();
    $args = [
                    
                    'response_description' => $status=="Aborted"?"Cancel":'Cancel',
                    'customer_account'     => $eps_txn,
                ];
    

    if ($order && !$order->has_status('cancelled')) {
        $order->update_status('cancelled', 'Payment was cancelled by the user.');
    }           
    insertTransectionInfo($args);
    // Update order
   /* if ( $status === 'Aborted' ) {
        $order->update_status(
            'cancelled',
            'Payment cancelled by customer. EPS TXN: ' . $eps_txn . ' | ' . $message
        );
    }*/
}
add_action( 'woocommerce_order_status_cancelled', 'eps_order_cancelled', 10, 1 );

function eps_order_cancelled( $order_id ) {
    $order = wc_get_order( $order_id );

    // Example actions
    // Update DB
    // Send API request
    // Log cancel event

    error_log( "Order Cancelled: " . $order_id );
}
add_action( 'woocommerce_order_status_failed', 'eps_order_failed', 10, 1 );

function eps_order_failed( $order_id ) {
    $order = wc_get_order( $order_id );

    error_log( "Order Failed: " . $order_id );
}

// Add this to your theme's functions.php or a custom plugin
/*add_action('woocommerce_payment_complete', 'auto_complete_paid_order');

function auto_complete_paid_order($order_id) {
    if (!$order_id) return;

    $order = wc_get_order($order_id);

    // Only change status if it's a paid order
    if ($order && $order->has_status(array('processing', 'on-hold'))) {
        $order->update_status('completed');
    }
}*/

function warning_handler($errno, $errstr, $errfile, $errline, array $errcontext = []) { }


