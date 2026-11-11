<?php
/**
 * Class EPS
 *
 * @package MCoder\EPS\Gateway
 */

namespace MCoder\EPS\Gateway;

class EPS extends \WC_Payment_Gateway {

    /**
     * EPS constructor.
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->init();
        $this->init_settings();

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ]);
        add_action('woocommerce_thankyou_' . $this->id, [ $this, 'thank_you_page' ]);
        $this->id           = 'eps';
    $this->has_fields   = false;
    $this->method_title = __('EPS', 'mc-eps');
    $this->title        = __('Visa/Mastercard/MFS', 'mc-eps');
    $this->description  = '<img src="https://eps.com.bd/images/banner.png" 
             alt="EPS Banner" 
             style="max-width:100%; margin-top:8px;">';
    $this->icon         = 'https://eps.com.bd/images/logo.png';

   // parent::__construct(); // ✅ REQUIRED

    }

    /**
     * Init gateway settings
     */
    public function init() {
        $this->id           = 'eps';
        $this->icon         = 'Visa/Mastercard/MFS'; // External EPS logo
        $this->has_fields   = true;
        $this->method_title = __('EPS', 'mc-eps');
        $this->title        = __('EPS (Easy Payment System)', 'mc-eps');
        $this->description  = __('',
    'mc-eps'
);
        
    }

    /**
     * Show EPS logo and label on checkout page under payment method
     */
    public function payment_fields() {
        echo '<div style="margin-bottom:10px; display:flex; align-items:center;">
            <img src="https://eps.com.bd/images/logo.png" style="height: 24px; margin-right: 10px;" alt="EPS Logo" />
            <strong style="color: black;">Visa/Mastercard/MFS</strong>
        </div>';
    }

    /**
     * Admin options with settings link
     */
    public function admin_options() {
        parent::admin_options();

        $eps_settings_url = admin_url('admin.php?page=mc-eps');

        printf(
            esc_html__('%1$sYou will get %2$s setting options in %3$s here %4$s.%5$s', 'mc-eps'),
            '<p>',
            esc_html($this->method_title),
            wp_kses_post(sprintf('<a href="%s">', $eps_settings_url)),
            '</a>',
            '</p>'
        );
    }

    /**
     * Handle payment process
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        $processor = dc_eps()->gateway->processor();

        $url = $this->get_return_url($order);
        $split_info = explode("order-received", $url);
        $r_url = count($split_info) ? $split_info[0] : $order->get_cancel_endpoint();
        $invoice_id = time() . wp_rand(100000, 999999);
        $order->update_meta_data('_eps_merchant_transaction_id', $invoice_id);
        $order->save();

        $create_payment_data = $processor->create_payment(
            (float) $order->get_total(),
            $invoice_id,
            $r_url,
            $this->get_return_url($order),
            $order
        );

        if (!$create_payment_data) {
            wc_add_notice(__('Payment error: ', 'mc-eps') . "Try again", 'error');
            return;
        }

        if (!isset($create_payment_data['RedirectURL']) || $create_payment_data['RedirectURL'] == null) {
            $err_msg = $create_payment_data['FailedReason'] ?? 'Error';
            wc_add_notice(__('Payment error: ', 'mc-eps') . $err_msg, 'error');
            return;
        }
        
        return [
            'result'              => 'success',
            'order_number'        => $order_id,
            'amount'              => (float) $order->get_total(),
            'checkout_order_pay'  => $order->get_checkout_payment_url(),
            'redirect'            => $create_payment_data['RedirectURL'],
            'create_payment_data' => $create_payment_data,
        ];
    }

    /**
     * Handle thank you page actions
     */
    public function thank_you_page($order_id) {
        $order = wc_get_order($order_id);

        if (!$order || 'eps' !== $order->get_payment_method() || $order->is_paid()) {
            return;
        }

        $expected_txn_id = (string) $order->get_meta('_eps_merchant_transaction_id');
        if ($expected_txn_id === '' || (isset($_GET['MerchantTransactionId']) && $_GET['MerchantTransactionId'] !== $expected_txn_id)) {
            $order->add_order_note('EPS: transaction ID does not belong to this order. Payment not accepted.');
            return;
        }

        $processor = dc_eps()->gateway->processor();
        global $wp;
        $params = ['MerchantTransactionId' => $expected_txn_id, 'EPSTransactionId' => $_GET['EPSTransactionId'] ?? null];

        $check_payment_data = $processor->check_payment($params['MerchantTransactionId'] ?? '');
        $status = 'Fail';
        $transection_id = $params['EPSTransactionId'] ?? null;

        if (!$check_payment_data) {
            $status = 'Payment Status Check Fail';
        } else {
            $status = $check_payment_data['Status'] ?? $check_payment_data['ErrorMessage'];
            $transection_id = $check_payment_data['FinancialEntity'];
        }

        $args = [
            'order_id'             => $order_id,
            'amount'               => (float) $order->get_total(),
            'transection_id'       => $transection_id,
            'response_description' => $status,
            'customer_account'     => $params['MerchantTransactionId'] ?? '',
        ];

        insertTransectionInfo($args);

        $is_success = is_array($check_payment_data)
            && strcasecmp(trim((string) ($check_payment_data['Status'] ?? '')), 'Success') === 0;
        $txn_matches = !isset($check_payment_data['MerchantTransactionId'])
            || (string) $check_payment_data['MerchantTransactionId'] === $expected_txn_id;
        $amount_matches = isset($check_payment_data['TotalAmount'])
            && abs((float) $check_payment_data['TotalAmount'] - (float) $order->get_total()) < 0.01;

        if (!$is_success || !$txn_matches) {
            $order->add_order_note('EPS: payment not confirmed by gateway (status: ' . sanitize_text_field((string) $status) . ').');
            return;
        }

        if (!$amount_matches) {
            $order->update_status('on-hold', 'EPS: paid amount does not match order total. Please review.');
            return;
        }

        // The plugin's other EPS calls and the return URL use "EPSTransactionId".
        $eps_txn_id = $check_payment_data['EPSTransactionId'] ?? $check_payment_data['EpsTransactionId'] ?? $params['EPSTransactionId'] ?? '';
        $order->payment_complete(sanitize_text_field((string) $eps_txn_id));
    }
}
