<?php
class ConvertCSV extends AccDBToWoo{


	/**
	* __construct()
	* A dummy constructor to ensure ConvertCSV is only setup once.
	* @param	void
	* @return	void
	*/	
	public function __construct() {
    global $access_db_file;
		$access_db_file = '';
	}

  /**
	 * initialize()
	 * Sets up the ConvertCSV class.
	 * @param	void
	 * @return void
	 */
	public function initialize() {
    if ( is_admin() && ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) ){
      add_action('acf/save_post', array($this, 'prep_csv_convert'), 20);
    }
  }

  function prep_csv_convert() {
    $screen = get_current_screen();

    if ( $screen->id == "toplevel_page_wm-csv-convert" ) {
      $file_arr = get_field('file_accessdb_csv', 'option');
      
      if($file_arr){
        global $access_db_file;
        $access_db_file = $file_arr['url'];
        $result_new = $this->generateCSVforImport($access_db_file, 'new');

        parent::wmlogs('DONE New subscriptions CSV');

        if($result_new){
          // export match
          $result_match = $this->generateCSVforImport($access_db_file, 'match');
          parent::wmlogs('DONE: Matched subscription CSV');
        }
      }
    }elseif ( $screen->id == "wm-csv-converter_page_acf-options-sync-addresses" ) {
      $file_arr = get_field('file_sync_subs_addresses', 'option');
      
      if($file_arr){
        global $subs_csv_file;
        $subs_csv_file = $file_arr['url'];

        $update_subs = $this->mergeSubsAddresses($subs_csv_file);
        if($update_subs){
          parent::wmlogs('DONE: Updating Subscription record');
        }
      }
    }
  }
  
  /*****************
   * generateCSVforImport()
   * Generates a CSV file to be used for importing subscription 
   * using the WebToffee plugin
   */
  protected function generateCSVforImport($csvfile, $convert_type){

    if( $csvfile == null || $csvfile == ''){
      return;
    }

    parent::wmlogs("Subscription export mode...");

    // Get ACCESSDB CSV subs
    $subs_from_csv = json_decode( $this->get_subscriptions_data_csv( $csvfile ) );
    parent::wmlogs( "Opening file... " . $csvfile);
    

    $results = [];
    $i = 0;
    if( !is_array($subs_from_csv) ){
      parent::wmlogs( "Unable to open: " . $subs_from_csv );
      parent::wmlogs( "Exiting CSV conversion process... " );
      return false;
    }

    parent::wmlogs( "[Subscriber/Users] CSV Count: " . count($subs_from_csv) );
    foreach ($subs_from_csv as $subs){

      // if no customer email, generate one
      if(!isset($subs->EmailAddress) || empty($subs->EmailAddress)){
        $customer_email = $this->generateCustomEmail($subs->SubscriberID);
      }else{
        $customer_email = str_replace(',', '.', $subs->EmailAddress);
      }

      // Get customer details using customer_email
      $customer = $this->getUserDatabyEmail( $customer_email );
      $line_items = '';

      // get Woocommerce customer ID
      if( $customer == true ){
        $customer_id 									= $customer->ID;
        $customer_username 						=	!empty($customer->user_login) ? $customer->user_login : $customer_email;
        $subscription_data 						= $this->getSubscriptionID($customer_id);

        // Get orders from the customer with customer_id.
        // $line_items .= generateOrderLineItems($customer_id);
      }else{
        $customer_id 									= "";
        $customer_username 						=	$customer_email;
        $subscription_data						= [];
      }

      // Get billing period and billing interval
      $billing_settings = $this->createBillingPeriod($subs->SubTypeName);

      $expiry_date = $this->formatDateTime($subs->ExpiryDate);

      // compute next_payment_date based on billing_settings and expiry_date
      $last_payment_date = $this->formatDateTime($subs->DatePaid);
      $next_payment_date = $this->createNextPaymentDate($expiry_date, $billing_settings, $subs->RecurringPayment);

      // make sure that start_date is in the past
      $start_date = $this->createStartDate($subs->StartDate, $billing_settings);

      $subscription_id = "";
      $subscription_status = ($convert_type == 'new') ? "active" : "";

      if( !empty($subscription_data) ){
        $subscription_data = reset($subscription_data);
        $subscription_id = $subscription_data['subscription_id'];
        $subscription_status = $subscription_data['subscription_status'];
      }
      
      if ($convert_type == 'match'){
        // Skip customers that has no subscription
        if( $subscription_id == "" ){
          continue;
        }
      }else if ($convert_type == 'new'){
        // Skip customers already have subscription
        if( $subscription_id != "" ){
          continue;
        }
      }
      
      $results[$i]['subscription_id'] 								= $subscription_id;
      $results[$i]['customer_id'] 										= $customer_id;
      $results[$i]['customer_username'] 							= str_replace('+', '_', $customer_username);
      $results[$i]['customer_password'] 							= "";
      $results[$i]['customer_email'] 									= $customer_email;
      $results[$i]['subscription_status'] 						= $subscription_status;
      $results[$i]['start_date'] 									  	= $start_date;
      $results[$i]['trial_end_date'] 									= '0';
      $results[$i]['next_payment_date'] 							= $next_payment_date;
      $results[$i]['last_payment_date'] 							= $last_payment_date;
      $results[$i]['end_date'] 												= $expiry_date;
      $results[$i]['billing_period'] 									= $billing_settings['period'];
      $results[$i]['billing_interval'] 								= $billing_settings['interval'];
      
      // create shipping method
      $shipping_settings = $this->createShippingMethod($subs->tblSubscriber_Country);

      $results[$i]['order_shipping'] 									= $shipping_settings['cost'];
      
      $results[$i]['order_shipping_tax'] 							= 0;
      $results[$i]['order_tax'] 											= 0;
      $results[$i]['cart_discount'] 									= 0;
      $results[$i]['cart_discount_tax'] 							= 0;
      $results[$i]['order_total'] 										= $subs->LastPaid;
      $results[$i]['order_currency'] 									= 'NZD';

      // check payment method
      if(empty($subscription_id)){
        $results[$i]['payment_method'] 									= "manual";
        $results[$i]['payment_method_title'] 						= "Manual Renewal";
      }else{
        $payment_settings = $this->createPaymentMethod($subs->RecurringPayment);
        $results[$i]['payment_method'] 									= $payment_settings['method'];
        $results[$i]['payment_method_title'] 						= $payment_settings['title'];
      }

      $results[$i]['payment_method_post_meta'] 				= '';
      $results[$i]['payment_method_user_meta'] 				= '';

      $results[$i]['shipping_method'] 								= $shipping_settings['method'];

      if( isset($subs->Gift) &&  $subs->Gift == "TRUE"){
        $billing_first_name 																		= $subs->DonorFirstName;
        $billing_last_name 																			= $subs->DonorLastName;
        $billing_email			 																		= $subs->DonorEmail;
        $billing_phone			 																		= '';
        $billing_address_1 																			= $subs->tblDonor_Address1;
        $billing_address_2 																			= ($subs->tblDonor_Address3 != "") ? $subs->tblDonor_Address2 . ", " . $subs->tblDonor_Address3 : $subs->tblDonor_Address2;
        $billing_postcode 																			= !empty($subs->tblDonor_Postcode) ? $this->formatPostCode($subs->tblDonor_Postcode) : '';
        $billing_city 																					= $subs->tblDonor_City;				
        $billing_state 																					= $this->getStateByCity($subs->tblDonor_City);
        $billing_country 																				= $subs->tblDonor_Country;
        $billing_company			 																	= !empty($subs->DonorCompanyName) ? $subs->DonorCompanyName : '';
      }else{
        $billing_first_name 																		= $subs->FirstName;
        $billing_last_name 																			= $subs->LastName;
        $billing_email			 																		= $customer_email;

        // Assign billing_phone
        if(!empty( $subs->HomePhone )){
          $billing_phone = $subs->HomePhone;
        }elseif(!empty( $subs->MobilePhone )){
          $billing_phone = $subs->MobilePhone;
        }elseif(!empty( $subs->WorkPhone )){
          $billing_phone = $subs->WorkPhone;
        }else{
          $billing_phone = "";
        }

        $billing_address_1 																			= $subs->tblSubscriber_Address1;
        $billing_address_2 																			= ($subs->tblSubscriber_Address3 != "") ? $subs->tblSubscriber_Address2 . ", " . $subs->tblSubscriber_Address3 : $subs->tblSubscriber_Address2;
        $billing_postcode 																			= !empty($subs->tblSubscriber_Postcode) ? $this->formatPostCode($subs->tblSubscriber_Postcode) : '';
        $billing_city 																					= $subs->tblSubscriber_City;
        $billing_state  																				= $this->getStateByCity($subs->tblSubscriber_City);
        $billing_country 	 																			= $subs->tblSubscriber_Country;
        $billing_company	 																			= !empty($subs->CompanyName) ? $subs->CompanyName : '';
      }

      $results[$i]['billing_first_name'] 							= $billing_first_name;
      $results[$i]['billing_last_name'] 							= $billing_last_name;
      $results[$i]['billing_email'] 									= $billing_email;
      $results[$i]['billing_phone'] 									= $billing_phone;
      $results[$i]['billing_address_1'] 							= $billing_address_1;
      $results[$i]['billing_address_2'] 							= $billing_address_2;
      $results[$i]['billing_postcode'] 								= $billing_postcode;
      $results[$i]['billing_city'] 										= $billing_city;
      $results[$i]['billing_state'] 									= $billing_state;
      $results[$i]['billing_country'] 								= $billing_country ;
      $results[$i]['billing_company'] 								= $billing_company;
      $results[$i]['shipping_first_name'] 						= $subs->FirstName;
      $results[$i]['shipping_last_name'] 							= $subs->LastName;
      $results[$i]['shipping_address_1'] 							= $subs->tblSubscriber_Address1;
      $results[$i]['shipping_address_2'] 							= ($subs->tblSubscriber_Address3 != "") ? $subs->tblSubscriber_Address2 . ", " . $subs->tblSubscriber_Address3 : $subs->tblSubscriber_Address2;
      $results[$i]['shipping_postcode'] 							= !empty($subs->tblSubscriber_Postcode) ? $this->formatPostCode($subs->tblSubscriber_Postcode) : '';
      $results[$i]['shipping_city'] 									= $subs->tblSubscriber_City;
      $results[$i]['shipping_state'] 									= $this->getStateByCity($subs->tblSubscriber_City);
      $results[$i]['shipping_country'] 								= $subs->tblSubscriber_Country;
      $results[$i]['shipping_company'] 								= !empty($subs->CompanyName) ? $subs->CompanyName : '';
      $results[$i]['customer_note'] 									= '';

      // order_items-> product_id:1|name:Imported Subscription with Custom Line Item Name|quantity:4|total:38.00|meta:|tax:3.80
      $results[$i]['order_items'] 										= $this->getOrderItems($billing_settings['interval']);
      // order_notes-> product_id:1|name:Imported Subscription with Custom Line Item Name|quantity:4|total:38.00|meta:|tax:3.80
      $results[$i]['order_notes'] 										= 'Status changed from Pending to Active.;';
      // coupon_items-> code:rd5|description:|amount:20.00;code:rd5pc|description:|amount:2.00
      $results[$i]['coupon_items'] 										= '';
      // fee_items-> name:Custom Fee|total:5.00|tax:0.50
      $results[$i]['fee_items'] 											= '';
      // tax_items-> id:4|code:Sales Tax|total:4.74
      $results[$i]['tax_items'] 											= '';
      $results[$i]['download_permissions'] 						= '1';
      $results[$i]['is_gift'] 									      = $subs->Gift;
      $i++;
    }
    parent::wmlogs( "[Subscriptions] Results Count: " . count($results) );

    $column_headers = array('subscription_id', 'customer_id', 'customer_username', 'customer_password', 'customer_email', 'subscription_status',
                            'start_date', 'trial_end_date', 'next_payment_date', 'last_payment_date', 'end_date', 
                            'billing_period', 'billing_interval', 'order_shipping', 'order_shipping_tax', 'order_tax', 
                            'cart_discount', 'cart_discount_tax', 'order_total', 'order_currency', 'payment_method',
                            'payment_method_title', 'payment_method_post_meta', 'payment_method_user_meta', 'shipping_method',
                            'billing_first_name', 'billing_last_name', 'billing_email', 'billing_phone', 'billing_address_1', 
                            'billing_address_2', 'billing_postcode', 'billing_city', 'billing_state', 'billing_country',
                            'billing_company', 'shipping_first_name', 'shipping_last_name', 'shipping_address_1', 'shipping_address_2',
                            'shipping_postcode', 'shipping_city', 'shipping_state', 'shipping_country', 'shipping_company', 'customer_note',
                            'order_items', 'order_notes', 'coupon_items', 'fee_items', 'tax_items', 'download_permissions', 'is_gift');
    
    
    // Export file
    $this->exportToCSV($column_headers, $results, $convert_type);

    return true;
    
  }
  

  protected function getStateByCity($value){

    $output = '';
    $regiondb = ['AK' => ['Albany', 'Auckland', 'Auckland Central', 'Bombay', 'Clevedon', 'Coatesville', 'Drury', 
                          'Dairy Flat', 'Great Barrier / Aotea Island', 'Great Barrier Island', 'Grey Lynn', 'Hauraki', 
                          'Helensville', 'Henderson', 'Howick', 'Kaukapakapa', 'Kumeu', 'Leigh', 'Manurewa', 'Matakana', 'New Lynn', 'Orewa', 
                          'Papakura', 'Porirua', 'Pukekohe', 'Red Beach', 'Silverdale', 'Snells Beach', 'Takapuna', 'Waimauku', 'Waitakere City', 
                          'Waiuku', 'Whangaparaoa', 'Whangaparoa', 'Whangapararoa', 'Warkworth', 'Waiheke Island', 'Wellsford'],
                'BP' => ['Papamoa', 'Hairini - Tauranga', 'Katikati', 'Mount Maunganui', 'Mt Maunganui', 'Murupara', 'Ohope', 'Omokoroa', 
                          'Opotiki', 'Rotorua', 'Tauranga', 'Te Puke', 'Waihi Beach', 'Whakatane',],
                'CT' => ['Akaroa', 'Amberley', 'Aoraki Mount Cook', 'Arthur\'s Pass', 'Ashburton', 'Asburton', 
                          'Burnham', 'Cass Bay', 'Cave', 'Cheviot', 'Christchurch', 'Chirstchurch', 'Coalgate', 
                          'Culverden', 'Darfield', 'Diamond Harbour', 'Dunsandel', 'Fairlie', 'Greta Valley', 
                          'Geraldine', 'Hanmer Springs', 'Hamner Springs', 'Hawarden', 'Kaiapoi', 'Kaikoura', 
                          'Kurow', 'Lake Tekapo', 'Leeston', 'Lincoln', 'Little River', 'Lyttelton', 'Methven', 'Oxford', 'Pegasus', 
                          'Pleasant Point', 'Prebbleton', 'Rakaia Gorge', 'Rangiora', 'Rolleston', 'Temuka', 
                          'Timaru', 'Twizel', 'Waikuku Beach', 'Waikuku, Rangiora', 'Waimate', 'West Melton', 'Woodend'],
                'GB' => ['Gisborne', 'Gidborne'],
                'HB' => ['Elsthorpe CHB', 'Hastings', 'Hawkes Bay', 'Napier', 'Havelock North', 'Waipawa', 'Waipukurau',],
                'MB' => ['Fairhall', 'Havelock', 'Blenheim', 'Marlborough', 'Picton',],
                'MW' => ['Apiti', 'Ashhurst', 'Brunswick', 'Bulls', 'Dannevirke', 'Eketahuna', 'Feilding', 'Fielding', 'Foxton', 'Halcombe', 'Levin', 'Lecvin', 'Mangaweka', 'Marton', 'Ohakune', 'Owhango', 'Pahiatua', 'Palmerston', 'Palmerston North', 'Palmertson North', 'Taumarunui', 'Taihape', 'Tokomaru', 'Wanganui', 'Whanganui'],
                'NL' => ['Dargaville', 'Ruawai', 'Waipu', 'Mangawhai', 'Whangarei', 'Kaiwaka', 'Ruakaka', 'One Tree Point', 'Hikurangi', 'Kamo', 'Onerahi', 'Paihia', 'Opua', 'Haruru', 'Kerikeri', 'Waipapa', 'Russell',],
                'OT' => ['Alexandra', 'Arrowtown', 'Balclutha', 'Balcultha', 'Brighton', 'Clyde', 'Cromwell', 'Dunedin', 
                          'Glenorchy', 'Hampden', 'Kingston', 'Lake Hawea', 'Mosgiel', 'Oamaru', 'Port Chalmers', 'Queentown', 
                          'Queenstown', 'Tapanui', 'Wanaka', 'Waikouaiti', 'Warrington'],
                'SL' => ['Bluff', 'Dipton', 'Edendale', 'Gore', 'Invercargill', 'Otautau', 'Stewart Island', 'Te Anau', 'Tuatapere', 'Winton', 'Wyndham'],
                'TM' => ['Brightwater', 'Cable Bay', 'Collingwood', 'Golden Bay', 'Mapua', 'Motueka', 'Murchison', 'Nelson', 'Richmond', 'Takaka', 'Upper Moutere', 'Wakefield', ],
                'TK' => ['Eltham', 'Hawera', 'Inglewood', 'New Plymouth', 'Oakura', 'Stratford', 'Urenui', 'Waitara',],
                'WA' => ['Cambridge', 'Coromandel', 'Hamilton', 'Hamiilton', 'Huntly', 'Matamata', 'Morrinsville', 
                          'Otorohanga', 'Pokeno', 'Pauanui', 'Putaruru', 'Raglan', 'Taupo', 'Te Aroha', 'Te Awamutu', 'Te Kauwhata', 
                          'Thames', 'Tokoroa', 'Turangi', 'Tuakau', 'Waihi', 'Waitomo', 'Whangamata', 'Whitianga',],
                'WC' => ['Ahaura', 'Blackball', 'Charleston', 'Franz Josef Glacier', 'Greymouth', 'Haast', 'Harihari', 'Hokitika', 'Kumara', 'Ikamatua', 'Karamea', 'Runanga', 'Westport', 'Waimangaroa',],
                'WE' => ['Carterton', 'Eastbourne', 'Featherston', 'Greytown', 'Karori', 'Martinborough', 'Martinsborough', 
                          'Waikanae', 'Waikanae', 'Lower Hutt', 'Masterton', 'Otaki', 'Paraparaumu', 'Paekakariki', 'Pukerua Bay', 'Upper Hutt', 
                          'Wellington',],
                'NSW' => ['NSW', 'New South Wales', 'New South Wales 2119', 'Penshurst NSW 2210', 'Terrigal NSW'],
                'QLD ' => ['Brisbane', 'QLD', 'Qld', 'Queensland'],
                'TAS'	=> ['Tasmania', ],
                'VIC'	=> ['Vic', 'East Victoria', 'Victoria'],
                ];
    
    foreach ($regiondb as $key=>$cities){
      if( array_search(trim($value), $cities) !== false ){
        $output = $key;
        break;
      }
    }
    return $output;
  }

  protected function formatPostCode($postcode){
    return (strlen($postcode) <= 3) ? str_pad($postcode, 4, '0', STR_PAD_LEFT) : $postcode;
  }

  protected function formatDateTime($dateString){
    // $dateString : 20/1/2022
    // Expected Output: 2022-01-20 10:00:00
    if($dateString){	
      $output = DateTime::createFromFormat('m/d/y', $dateString, new DateTimeZone('UTC') );
      $newDateString = $output->format('Y-m-d') . " 10:00:00";
      return $newDateString;
    }
  }

  protected function getUserDatabyEmail($email){
    $user = get_user_by( 'email', $email );
    return $user;
  }

  protected function generateCustomEmail($subscriber_id){
    if( isset($subscriber_id) && !empty($subscriber_id) ){
      return "subscriber+" . $subscriber_id . "@lifestylepublishing.co.nz";
    }
  }

  protected function getSubscriptionID($user_id){
    $subscriptions = wcs_get_users_subscriptions($user_id);
    $subscriptions_data = [];
    foreach ($subscriptions as $subscription){
      if($subscription->get_status() == "active" || $subscription->get_status() == "pending-cancel"){
        $subscription_id = $subscription->get_id();
        $subscriptions_data[$subscription_id]['subscription_id'] = $subscription_id;
        $subscriptions_data[$subscription_id]['subscription_status'] = $subscription->get_status();
        $subscriptions_data[$subscription_id]['post_parent'] = $subscription->get_parent_id();
        foreach ( $subscription->get_items() as $line_item ) {
          $product = $line_item->get_product();
          $product_id = $product->get_id();
          $subscriptions_data[$subscription_id]['line_items'][$product_id]['product_id'] = $product_id;
          $subscriptions_data[$subscription_id]['line_items'][$product_id]['product_sku'] = $product->get_sku();
          $subscriptions_data[$subscription_id]['line_items'][$product_id]['product_name'] = $product->get_title();
          $subscriptions_data[$subscription_id]['line_items'][$product_id]['product_price'] = $product->get_price();
          $subscriptions_data[$subscription_id]['line_items'][$product_id]['product_variation'] = $product->is_type( 'variation' ) ? wc_get_formatted_variation( $product, true, true, false ) : '';
        }
      }
    }
    return $subscriptions_data;

  }

  protected function getOrderItems($interval){

    if($interval == '2'){
      // 2 months
      return 'product_id:132249|name:Print+Web - $98.50/2mnth trial|quantity:1|total:98.50|meta:|tax:0.00';
    }elseif($interval == '3'){
      // 3 months
      return 'product_id:128578|name:Print + Website - 3 issues - $28 every three months|quantity:1|total:28.00|meta:subscription-options=3 issues - $28 every three months|tax:0.00';
    }elseif($interval == '6'){
      // 6 months
      return 'product_id:128571|name:Print + Website - 6 issues - $54 every six months|quantity:1|total:54.00|meta:subscription-options=6 issues - $54 every six months|tax:0.00';
    }else{
      // 1 year
      return 'product_id:128232|name:Print + Website - 12 issues - $98.50 every year|quantity:1|total:98.50|meta:subscription-options=12 issues - $98.50 every year|tax:0.00';
    }

  }

  protected function generateOrderLineItems($customer_id){
    $orders = wc_get_orders( ['customer_id' => $customer_id, 'return' => 'objects',] );
    $line_items = '';

    foreach ($orders as $order){
      foreach ( $order->get_items() as $item_id => $item ) {
        // product_id:128571|name:Print + Website - 6 issues - $54 every six months|quantity:1|total:54.00|meta:subscription-options=6 issues - $54 every six months|tax:0.00
        $product_id = $item->get_product_id();
        $product = $item->get_product();
        $product_name = $item->get_name();
        $quantity = $item->get_quantity();
        $total = $item->get_total();

        $allmeta = $item->get_meta_data();
        $line_items_meta = "";
        foreach($allmeta as $meta){
          $line_items_meta .= $meta->key . "=" . $meta->value . "+";
        }
        $line_items_meta = "|";

        $tax = $item->get_subtotal_tax();

        $line_items .= 'product_id:' . $product_id;
        $line_items .= 'name:' . $product_name;
        $line_items .= 'quantity:' . $quantity;
        $line_items .= 'total:' . $total;
        $line_items .= 'meta:' . $line_items_meta;
        $line_items .= 'tax:' . $tax . ';';
      }
    }

    return $line_items;
  }

  protected function exportToCSV($column_headers, $results, $type){

    if ($results){	
      $filename = get_template_directory() . '/wmexport/subscriptions_' . $type . '.csv';
      $fp = fopen($filename, 'w');
      fputcsv($fp, $column_headers);
      foreach ($results as $fields) {
        fputcsv($fp, $fields);
      }
      fclose($fp);

      parent::wmlogs( "[Subscriptions] Exported to " . $filename );
    }
  }

  protected function createBillingPeriod($subtype){
    $billing = "";

    if($subtype){

      // Permanent
      $billing_complimentary_permanent = array('Complimentary - Permanent');

      // 14 months
      $billing_annualextra = array('Print + Web - Annual - Lapsed 14 Issue',
                                    'Print + Web - Annual - Lapsed 14 Issue Recurring');

      // Yearly
      $billing_annual = array('P+W - 12mth',
                          'P+W - 12mth - Recurring',
                          'P+W + Digital - Annual Recurring',
                          'Isubscribe - 12mth',											
                          'FMC - Annual Recurring',											
                          'P+W - AUS Z1 - 12mth Recurring',
                          'FMC - Annual',
                          'P+W - Europe, Canada Z4 - 12mth',
                          'P+W - USA Z5 - 12mth Recurring',
                          'P+W - Asia Z3 - 12mth recurring',
                          'P+W - 12mth Gift',
                          'P+W + Digital - AUS - 12mth Recurring',
                          'P+W - AUS Z1 - 12mth',
                          'P+W - Europe, Canada Z4 - 12mth recurring',
                          'Magshop - 12mth',
                          'P+W - USA Z5 - 12mth',
                          'P+W + Digital - Annual',
                          'P+W + Dig - USA Z5  - 12m Recurring',
                          'P+W+Dig - Asia Z3 - 12mth recurring',
                          'P+W + Digital - AUS - 12mth',
                          'Ebsco Renewal', 'Ebsco New', 'Prize', 'Complimentary - Reducing');
      
      // Every 6 months
      $billing_biannual = array('P+W - 6 mth - Recurring',
                                'iSubscribe - 6 mth',
                                'P+W - Europe, Canada Z4 - 6mth recurring',
                                'P+W - 6 mth',
                                'Magshop - 6 mth',
                                'P+W - AUS Z1 - 6 mth Recurring');

      // Every 6 months
      $billing_threemonths = array('P+W - 3mth Recurring', 'P+W - AUS Z1- 3mth Recurring');

      // Every 2 months
      $billing_twomonths = array('P+W - 2mth Recurring', 'P+W - AUS Z1 - 2mth Recurring', 'P+W - USA Z5 - 2mth Recurring');

      if (in_array($subtype, $billing_annualextra)) {
        // billing_interval = 14, billing_period = month
        $billing = ['interval' => '14', 'period' => 'month'];
      }else if (in_array($subtype, $billing_annual)) {
        // billing_interval = 1, billing_period = year
        $billing = ['interval' => '1', 'period' => 'year'];
      }else if (in_array($subtype, $billing_biannual)) {
        // billing_interval = 6, billing_period = month
        $billing = ['interval' => '6', 'period' => 'month'];
      }else if (in_array($subtype, $billing_threemonths)) {
        // billing_interval = 3, billing_period = month
        $billing = ['interval' => '3', 'period' => 'month'];
      }else if (in_array($subtype, $billing_twomonths)) {
        // billing_interval = 2, billing_period = month
        $billing = ['interval' => '2', 'period' => 'month'];
      }else if (in_array($subtype, $billing_complimentary_permanent)) {
        $billing = ['interval' => '10', 'period' => 'year'];
      }
    }
    return $billing;
  }

  protected function createStartDate($start_date, $billing_settings){

    $current_date = new DateTime();
    $start_date_new = new DateTime($start_date);

    // if startdate is in the future, update it
    if ($start_date_new > $current_date){
      // format interval date
      $interval = $billing_settings['interval'] . " " . $billing_settings['period'];
      $interval = ($billing_settings['interval'] > 1) ? $interval . 's' : $interval;
      $date_interval = DateInterval::createFromDateString($interval);

      // subtract interval from start date
      $start_date_new = $start_date_new->sub($date_interval);
      $start_date = $start_date_new->format('Y-m-d') . " 10:00:00";
    }else{
      $start_date = $start_date_new->format('Y-m-d') . " 10:00:00";
    }
    return $start_date;
  }

  protected function createNextPaymentDate($expiry_date, $billing_settings, $is_recurring){
    $next_payment_date = '';
    
    // if($is_recurring != "FALSE"){
      if(isset( $expiry_date ) && !empty($expiry_date)){

        // format expiry_date
        $expiry_date = new DateTime($expiry_date);

        // format interval date so it can be substracted to expiry_date
        // $interval = $billing_settings['interval'] . " " . $billing_settings['period'];
        // $interval = ($billing_settings['interval'] > 1) ? $interval . 's' : $interval;
        // $date_interval = DateInterval::createFromDateString($interval);

        $interval = '1 week';
        $date_interval = DateInterval::createFromDateString($interval);

        // subtract dates. next_payment_date is set `1 week` before expiry_date
        $next_payment_date = $expiry_date->sub($date_interval);
        $next_payment_date = $next_payment_date->format('Y-m-d') . " 10:00:00";
      } 
    // }
    return $next_payment_date;
  }

  protected function createPaymentMethod($is_recurring){
    $payment = ['method'=>'', 'title'=>''];
    if($is_recurring == "TRUE"){
      $payment = ['method'=>'stripe', 'title'=>'Credit card (Stripe)'];
    }else{
      $payment = ['method'=>'manual', 'title'=>'Manual Renewal'];
    }
    return $payment;
  }

  protected function createShippingMethod($country){
    
    if($country == "Australia"){
      $method = ['method'=>'method_id:flat_rate|method_title:Australia - Zone 1|total:70.50',
                  'cost'=>'70.50'];
    }elseif($country == "New Zealand"){
      $method = ['method'=>'method_id:flat_rate|method_title:Local Shipping|total:0.00',
                  'cost'=>'0.00'];
    }elseif($country == "Canada" || $country == "France" || $country == "Norway"
          || $country == "Germany" || $country == "Switzerland" || $country == " United Kingdom" || $country == "Netherlands"){
      $method = ['method'=>'method_id:flat_rate|method_title:Canada, UK, Europe - Zone 4|total:100.00',
                  'cost'=>'100.00'];
    }elseif($country == "Japan" || $country == "Malaysia" || $country == "Singapore"){
      $method = ['method'=>'method_id:flat_rate|method_title:Zone 3 Shipping|total:95.00',
                  'cost'=>'95.00'];
    }elseif($country == "United States of America"){
      $method = ['method'=>'method_id:flat_rate|method_title:USA Zone 5 Shipping|total:160.00',
                  'cost'=>'160.00'];
    }else{
      $method = ['method'=>'method_id:flat_rate|method_title:Canada, UK, Europe - Zone 4|total:100.00',
                  'cost'=>'100.00'];
    }
    
    return $method;
  }

  // parse CSV
  protected function get_subscriptions_data_csv($file){
    if (($handle = fopen($file, "r")) !== FALSE) {
      $csvs = [];
      while(! feof($handle)) {
        $csvs[] = fgetcsv($handle);
      }
      $datas = [];
      $column_names = [];
      foreach ($csvs[0] as $single_csv) {
        $column_names[] = $single_csv;
      }
      foreach ($csvs as $key => $csv) {
          if ($key === 0) {
            continue;
          }
          foreach ($column_names as $column_key => $column_name) {
            if( !isset($csv[$column_key]) ){
              parent::log( $column_key . '|' . $column_name . '|' . $csv);
            }else{
              $datas[$key-1][$column_name] = $csv[$column_key];
            }
          }
      }
      $json = json_encode($datas);
      fclose($handle);
      return $json;
    }
  }

  protected function csvToJson($fname) {
    // open csv file
    if (!($fp = fopen($fname, 'r'))) {
        die("Can't open file...");
    }
    
    //read csv headers
    $key = fgetcsv($fp,"1024",",");
    
    // parse csv rows into array
    $json = array();
        while ($row = fgetcsv($fp,"1024",",")) {
        $json[] = array_combine($key, $row);
    }
    
    // release file handle
    fclose($fp);
    
    // encode array to json
    return json_encode($json);
  }

  /**
     * Updates custom fields based on mapping between database and api object
     *
     * @param array $mappings
     * @param \stdClass $data
     * @param integer $post_id
     * @return void
     */
    protected function updateFieldsFromMappings(array $mappings, \stdClass $data, int $post_id){
      foreach ($mappings as $field => $propertyname) {
          if (strpos($propertyname, '.') !== false) {
              $value = $this->findNestedValue($data, $propertyname);
              if (!empty($value)) {
                  // update_field($field, $value, $post_id);
                  update_post_meta($post_id, $field, $value);
              }
          } elseif (!empty($propertyname) && isset($data->$propertyname)) {
              // update_field($field, $data->$propertyname, $post_id);
              update_post_meta($post_id, $field, $data->$propertyname);
          }
      }
  }

  /**
   * Search for a value within an objects properties
   *
   * @param \stdClass $object The object to search
   * @param string $path The path to the value in dot notation
   * @return mixed
   */
  protected function findNestedValue(\stdClass $object, string $path){
    $path_pieces = explode('.', $path);

    $prop = array_shift($path_pieces);

    if (isset($object->$prop) && empty($path_pieces)) {
        return $object->$prop;
    } elseif (is_object($object->$prop)) {
        return $this->findNestedValue($object->$prop, implode('.', $path_pieces));
    }
  }

  /*****************
   * mergeSubsAddresses()
   * Replaces the Subscription addresses in Woocommerce with the  
   * data from the CSV file
   */
  protected function mergeSubsAddresses($csvfile){

    if( $csvfile == null || $csvfile == ''){
      return;
    }

    parent::wmlogs("Subscription Addresses import mode...");

    // Get ACCESSDB CSV subs
    parent::wmlogs( "Opening file for import... " . $csvfile);
    $subs_from_csv = json_decode( $this->get_subscriptions_data_csv( $csvfile ) );
    // $subs_from_csv = json_decode( $this->csvToJson( $csvfile ) );
    
    if( !is_array($subs_from_csv) ){
      parent::wmlogs( "Unable to open: " . $subs_from_csv );
      parent::wmlogs( "Exiting Subscription import process... " );
      return false;
    }

    parent::wmlogs( "[Subscriber/Users] CSV Count: " . count($subs_from_csv) );

    $field_mappings = [
      '_billing_first_name'    => 'billing_first_name',
      '_billing_last_name'     => 'billing_last_name',
      '_billing_phone'         => 'billing_phone',
      '_billing_address_1'     => 'billing_address_1',
      '_billing_address_2'     => 'billing_address_2',
      '_billing_postcode'      => 'billing_postcode',
      '_billing_city'          => 'billing_city',
      '_billing_state'         => 'billing_state',
      '_billing_country'       => 'billing_country',
      '_billing_company'       => 'billing_company',
      '_shipping_first_name'   => 'shipping_first_name',
      '_shipping_last_name'    => 'shipping_last_name',
      '_shipping_address_1'    => 'shipping_address_1',
      '_shipping_address_2'    => 'shipping_address_2',
      '_shipping_postcode'     => 'shipping_postcode',
      '_shipping_city'         => 'shipping_city',
      '_shipping_state'        => 'shipping_state',
      '_shipping_country'      => 'shipping_country',
      '_shipping_company'      => 'shipping_company',
      'is_gift'                => 'is_gift',
    ];

    $subs_id_updated = [];
    $current_count = 0;
    foreach ($subs_from_csv as $subs){

      if(!isset($subs->subscription_id) || empty($subs->subscription_id)){
        continue;
      }

      $current_subsid  = $subs->subscription_id;
      $this->updateFieldsFromMappings($field_mappings, $subs, $current_subsid);  
    }

    parent::wmlogs( "Total updated subscription addresses: " . count($subs_from_csv) );
    return true;
  }
}