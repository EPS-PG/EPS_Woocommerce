<?php
/**
 * EPS payment processor helper class
 *
 * Class Processor
 *
 *
 * @package MCoder\EPS\Gateway
 */

namespace MCoder\EPS\Gateway;

//use PHPMailer\PHPMailer\Exception;

/**
 * Class Processor
 */
class Processor {
	/**
	 * Holds the processor class
	 *
	 * @var Processor
	 *
	 */
	public static $instance;

	/**
	 * Holds grant token url
	 *
	 *
	 * @var string
	 */
	public $grant_token_url;

	/**
	 * Holds payment query url
	 *
	 *
	 * @var string
	 */
	public $payment_query_url;

	/**
	 * Holds payment search url
	 *
	 *
	 * @var string
	 */
	public $payment_search_url;

	/**
	 * Holds the version.
	 *
	 * @var string
	 */
	protected $version = 'v1.2.0-beta';

	/**
	 * Holds the base urls.
	 *
	 * @var array
	 */
	private $base_url = array('sandbox' => 'https://sandboxpgapi.eps.com.bd', 'production' => 'https://pgapi.eps.com.bd'); 

	/**
	 * Get self instance
	 *
	 *
	 * @return Processor
	 */
	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new Processor();
		}

		return self::$instance;
	}

	public function GenerateHash($payload,$hashkey){
		$utf8_key = (string) $hashkey;
		$utf8_payload = (string) $payload;
		$data = hash_hmac('sha512', $utf8_payload, $utf8_key,true);
		$hmac = base64_encode($data);
		return $hmac;
	}
	
	/**
	 * Create payment request in Mlajan.
	 *
	 * @param float   $amount                 Amount.
	 * @param string  $invoice_id             Invoice ID.
	 *
	 * @return bool|mixed|string
	 */
	public function create_payment( $amount, $invoice_id, $redirect_url = null, $redirect_success_url = null, $order = [] ) {
		
		try {

			$data = get_option('mc_eps_settings'); 
		
			$data = unserialize($data);
		
			$mode = $data['mode'] ?? 'sandbox';

			$apiendpoint = $this->base_url[$mode];
			$password  = $data['plugin_key'] ?? "";
			$module_val = $data['module_val'] ?? "";
			$merchent_code = $data['merchent_code'] ?? "";
			$merchent_id = $data['api_base_url'] ?? "";
			$store_id = $data['redirect_url'] ?? "";

			// $signinResponse = $this->get_key( $merchent_code, $password, $module_val, $mode);
			
			// if(!$signinResponse || (isset($signinResponse['succeeded']) && $signinResponse['succeeded'] == false ) ) {

            //     //$this->errors['error_message'] = __( 'Unauthorized!', 'mc-eps' );
            //     return false;
			// }
			
			$getTokenResponse = $this->get_token( null, $password, $module_val,$merchent_code,$mode);

			// if(!$getTokenResponse || !isset($getTokenResponse['jwtToken'])) {

            //     return false;
			// }

			if(!$getTokenResponse || !isset($getTokenResponse['token']) || $getTokenResponse['token'] == null) {

                return false;
			}

			$invoice_id = (string)$invoice_id;
			$productProfile = array();
			$productName = array();
			$productCategory = array();
            $productName2='product';
            $productProfile2='';
            $productCategory2="";
            $product_arr= array();
			// Get and Loop Over Order Items
			foreach ( $order->get_items() as $item_id => $item ) {
					
				$product_id = $item->get_product_id();
				$product_name = $item->get_name();
				$item_type = $item->get_type(); // e.g. "line_item"
                
				array_push($productName, $product_name );
				array_push($productProfile, $product_id );
				array_push($productCategory, $item_type );
				$product_arr[]=[
                "ProductName"=>(string)$product_name, 
                "NoOfItem"=> (string)$item->get_quantity(), 
                "ProductProfile"=> (string)$product_id, 
                "ProductCategory"=>(string)$item_type, 
                "ProductPrice"=> (string)$item->get_total() 
                ];
                $productName2=(string)$product_name.'...';
                $productProfile2=(string)$product_id.'...';
                $productCategory2=(string)$item_type.'...';
			}
		    date_default_timezone_set('Asia/Dhaka');
		     $args = [
                    'order_id'             => 'order'.(string)$order->get_id(),
                    'amount'               => $amount,
                    'transection_id'       => '',
                    'response_description' => 'Initialize',
                    'created_at' => date('Y-m-d H:i:s'),
                    'customer_account'     => $invoice_id,
                ];
            $customerName = $order->get_billing_first_name().' '.$order->get_billing_last_name();
        
			$payment_data = [
			    "CustomerOrderId"=>'order'.(string)$order->get_id().'-'.rand(1000,9999),
				"deviceTypeId"=> 4,
				"merchantId" => $merchent_id,
				"storeId" => $store_id,
				"merchantTransactionId" => $invoice_id,
				"transactionTypeId" => 1,
				"financialEntityId" => 0,
				"totalAmount" => $amount,
				"ipAddress" => $order->get_customer_ip_address(),
				"version"=> "1",
				"successUrl" => $order->get_checkout_order_received_url(),
				"failUrl" => $redirect_url,
				"cancelUrl" => $order->get_cancel_order_url(),


				"transactionDate" => date('c'),
				"transitionStatusId" => 0,

				"customerName" => $customerName?$customerName:'Customer',
				"customerEmail" => $order->get_billing_email()?$order->get_billing_email():'epscustomer@email.com',
				"customerAddress" => $order->get_billing_address_1()?$order->get_billing_address_1():'customerAddress',
				"customerAddress2" => $order->get_billing_address_2(),
				"customerCity" => $order->get_billing_city()?$order->get_billing_city():'dhaka',
				"customerState" => $order->get_billing_state()?$order->get_billing_state():'dhaka',
				"customerPostcode" => $order->get_billing_postcode()?$order->get_billing_postcode():'1207',
				"customerCountry" => $order->get_billing_country()?$order->get_billing_country():'BD',
				"customerPhone"=> $order->get_billing_phone()?$order->get_billing_phone():'123456789',

				"shipmentName"=> $order->get_shipping_first_name().' '.$order->get_shipping_last_name(),
				"shipmentAddress"=> $order->get_shipping_address_1(),
				"shipmentAddress2"=> $order->get_shipping_address_2(),
				"shipmentCity"=> $order->get_shipping_city(),
				"shipmentState"=> $order->get_shipping_state(),
				"shipmentPostcode"=> $order->get_shipping_postcode(),
				"shipmentCountry"=> $order->get_shipping_country(),

				"valueA"=> (string)$order->get_customer_id(),
				"valueB"=> $order->get_transaction_id(),
				"valueC"=> (string)$order->get_id(),
				"valueD"=> "",

				"shippingMethod"=> $order->get_shipping_method(),
				"noOfItem"=> (string)$order->get_item_count(),
				"productName"=> $productName2,
				"productProfile"=> $productProfile2,
				"productCategory"=> $productCategory2,
				"ProductList"=>$product_arr
			];

			$x_hash = $this->GenerateHash($invoice_id,$merchent_code);
            insertTransectionInfo($args);
           
            
			return $this->make_request( $apiendpoint.'/v1/EPSEngine/InitializeEPS', $payment_data, [ 'headers' => [ 'Content-Type' => 'application/json', 'x-hash' => $x_hash, 'Authorization' => 'Bearer '.$getTokenResponse['token'] ?? "" ] ] );
			
		} catch ( Exception $e ) {

			 return false;
		}
	}

	public function check_payment($merchantTransactionId) {
		
		try {
		
			$data = get_option('mc_eps_settings'); 
		
			$data = unserialize($data);

			$mode = $data['mode'] ?? 'sandbox';

			$apiendpoint = $this->base_url[$mode];

			$password  = $data['plugin_key'] ?? "";
			$module_val = $data['module_val'] ?? "";
			$merchent_code = $data['merchent_code'] ?? "";
			$merchent_id = $data['api_base_url'] ?? "";
			$store_id = $data['redirect_url'] ?? "";

			$getTokenResponse = $this->get_token( null, $password, $module_val,$merchent_code,$mode);

			if(!$getTokenResponse || !isset($getTokenResponse['token']) || $getTokenResponse['token'] == null) {

                return false;
			}
			$x_hash = $this->GenerateHash($merchantTransactionId,$merchent_code);

			return $this->make_get_request( $apiendpoint.'/v1/EPSEngine/CheckMerchantTransactionStatus?merchantTransactionId='.$merchantTransactionId, [ 'headers' => [ 'Content-Type' => 'application/json', 'x-hash' => $x_hash, 'Authorization' => 'Bearer '.$getTokenResponse['token'] ?? "" ] ] );
			
		} catch ( Exception $e ) {

			 return false;
		}
	}
	public function GenerateHashForSync($storeId, $hashKey)
    {
        $utf8_payload = (string) $storeId;
        $data = hash_hmac('sha512', $utf8_payload, $hashKey, true);
        return base64_encode($data);
    }
    public function make_request_sync($url, $args)
    {
        $request = [
            'method'      => 'POST',
            'body'        => $args['body'], // 👈 RAW JSON (no encoding here)
            'headers'     => $args['headers'],
            'timeout'     => 30,
            'redirection' => 30,
            'blocking'    => true,
        ];
    
        $response = wp_remote_post(esc_url_raw($url), $request);
    
        if (is_wp_error($response)) {
            return $response;
        }
    
        $body = wp_remote_retrieve_body($response);
    
        // Debug safety
        if (empty($body)) {
            return null;
        }
    
        return json_decode($body, true);
    }

	public function eps_sync_gateway($startDate,$endDate) {
		
		try {
		
			$data = get_option('mc_eps_settings'); 
	
			$data = unserialize($data);

			$mode = $data['mode'] ?? 'sandbox';

			$apiendpoint = $this->base_url[$mode];

			$password  = $data['plugin_key'] ?? "";
			$module_val = $data['module_val'] ?? "";
			$merchent_code = $data['merchent_code'] ?? "";
			$merchent_id = $data['api_base_url'] ?? "";
			$store_id = $data['redirect_url'] ?? "";

			$getTokenResponse = $this->get_token( null, $password, $module_val,$merchent_code,$mode);

			if(!$getTokenResponse || !isset($getTokenResponse['token']) || $getTokenResponse['token'] == null) {

                return false; 
			}
		
			$x_hash = $this->GenerateHash($store_id,$merchent_code);
            
          
            $body = json_encode([
                'merchantId' => $merchent_id,
                'storeId'    => $store_id,
                'startDate'  => date('Y-m-d', strtotime($startDate)),
                'endDate'    => date('Y-m-d', strtotime($endDate)),
            ]);
            
            $res = $this->make_request_sync(
                $apiendpoint.'/v1/EPSEngine/MerchantTransactionHistory',
                [
                    'headers' => [
                        'Content-Type'  => 'application/json',
                        'Accept'        => 'application/json',
                        'x-hash'        => $x_hash,
                        'Authorization' => 'Bearer '.$getTokenResponse['token'],
                    ],
                    'body' => $body
                ]
            );

		
			return $res;
			
		} catch ( Exception $e ) {

			 return false;
		}
	}


	public function get_key( $merchent_code, $password, $module_val, $mode) {
		
		try {

			$apiendpoint = $this->base_url[$mode];
	
			$getkey_data = [
				'userName' => $module_val,
				'password'                => $password ,
				'deviceTypeId' => 4
			];

			$x_hash = $this->GenerateHash($module_val,$merchent_code);

			return $this->make_request( $apiendpoint.'/v1/SignIn', $getkey_data, [ 'headers' => [ 'Content-Type' => 'application/json', 'x-hash' => $x_hash ] ] );
			
		} catch ( Exception $e ) {

			 return false;
		}
	}

	public function get_token( $loginResponseData, $password, $module_val,$merchent_code,$mode) {
		
		try {

			$apiendpoint = $this->base_url[$mode];
	
			// $getkey_data = [

			// 	"id" => $loginResponseData['id'] ?? "", 
			// 	"userName" => $module_val,
			// 	"password" => $password,
			// 	"name" => $loginResponseData['name'] ?? "",
			// 	"email" => $loginResponseData['email'] ?? "",
			// 	"phoneNumber" => $loginResponseData['phoneNumber'] ?? "",
			// 	"role" => $loginResponseData['role'] ?? "",
			// ];

			$getkey_data = [

				"userName" => $module_val,
				"password" => $password
			];

			$x_hash = $this->GenerateHash(($module_val ?? ""),$merchent_code);

			//return $this->make_request( $apiendpoint.'/v1/Auth/GetToken', $getkey_data, [ 'headers' => [ 'Content-Type' => 'application/json', 'x-hash' => $x_hash, 'Authorization' => 'Bearer '.$loginResponseData['apiToken'] ?? "" ] ] );

			return $this->make_request( $apiendpoint.'/v1/Auth/GetToken', $getkey_data, [ 'headers' => [ 'Content-Type' => 'application/json', 'x-hash' => $x_hash ] ] );
			
		} catch ( Exception $e ) {

			 return false;
		}
	}
		/**
	 * Sending remote request.
	 *
	 * @param string $url     Target URL request.
	 * @param array  $data    Data for sending request.
	 * @param array  $headers Headers data.
	 *
	 * @return mixed|string|\WP_Error
	 */
	public function make_request( $url, $data, $headers = [] ) {
		if ( isset( $headers['headers'] ) ) {
			$headers = $headers['headers'];
		}

		$args = [
			'body'        => wp_json_encode( $data ),
			'redirection' => '30',
			'httpversion' => '1.0',
			'blocking'    => true,
			'headers'     => $headers,
			'cookies'     => [],
		];

		$response = wp_remote_post( esc_url_raw( $url ), $args );

		//print_r($response);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );

		return json_decode( $body, true );
	}

	public function make_get_request( $url, $headers = [] ) {
		if ( isset( $headers['headers'] ) ) {
			$headers = $headers['headers'];
		}

		$args = [
			'redirection' => '30',
			'httpversion' => '1.0',
			'blocking'    => true,
			'headers'     => $headers,
			'cookies'     => [],
		];

		$response = wp_remote_get( esc_url_raw( $url ), $args );

		//print_r($response);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );

		return json_decode( $body, true );
	}
}
