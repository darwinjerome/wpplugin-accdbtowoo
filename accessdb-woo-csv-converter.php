<?php
/**
 * Plugin Name:     AccessDB to Woocommerce CSV Converter
 * Description:     Converts an AccessDB subscriptions data into a Woocommerce-ready format. This also compares existing subscriptions in Woo to get related data.
 * Version:         1.0
 * Author:          Darwin Jerome
 * Author URI:      http://darwin.tardio.info
 *
 * @package         AccDBToWoo
 */

 // Exit if directly accessed files.
if (!defined('ABSPATH')) {
    exit;
}

class AccDBToWoo{
        
  /**
	 * __construct()
	 * A dummy constructor to ensure AccDBToWoo is only setup once.
	 * @param	void
	 * @return	void
	 */	
	public function __construct() {
		// Require files
    require_once 'classes/wm-convert-csv.php';

    global $access_db_file;
		$access_db_file = '';

    global $wmlogs;
	}
  /**
	 * initialize()
	 * Sets up the AccDBToWoo plugin.
	 * @param	void
	 * @return	void
	 */
	public function initialize() {    
    
    // Add options page in admin menu
    if( is_admin() ){

      // Instantiate classes
      $this->ConvertCSV();
      
      add_action('acf/init', array($this, 'my_acf_op_init') );
      add_action('toplevel_page_wm-csv-convert', array($this, 'before_acf_options_page'), 1);
      add_action('toplevel_page_wm-csv-convert', array($this, 'after_acf_options_page'), 20);

      // enqueue plugin css
      add_action( 'admin_print_styles', array($this, 'initScripts'), 1 );
    }
  }
  
  public function initScripts(){		
    wp_register_style( 'mainstyle', plugins_url( 'css/style.css' , __FILE__ ) );
    wp_enqueue_style( 'mainstyle' );
	}

  function before_acf_options_page() {
		/*
			Before ACF outputs the options page content
			start an object buffer so that we can capture the output
		*/
		ob_start();
	}

  function after_acf_options_page() {
    global $wmlogs;
		/*
			After ACF finishes get the output and modify it
		*/
		$content = ob_get_clean();
		
		$count = 1; // the number of times we should replace any string
		
		// insert something before the <h1>
		$before_content = '';
		$content = str_replace('<h1', $before_content.'<h1', $content, $count);
		
		// insert something after the <h1>
		$custom_content = '';
		$content = str_replace('</h1>', '</h1>'.$custom_content, $content, $count);
		
		// insert something after the form
    $file_new_subs = get_template_directory() . '/wmexport/subscriptions_new.csv';
    $wm_button_new_class = file_exists($file_new_subs) ? 'wm-button wm-button-download' : 'wm-button wm-button-download wm-button-disabled';
    $after_content = '<a class="' . $wm_button_new_class .'" href="'. get_template_directory_uri() . '/wmexport/subscriptions_new.csv" download="new_subscriptions.csv">Download New Subscriptions CSV</a>';
    
    $file_match_subs = get_template_directory() . '/wmexport/subscriptions_match.csv';
    $wm_button_match_class = file_exists($file_match_subs) ? 'wm-button wm-button-download' : 'wm-button wm-button-download wm-button-disabled';
    $after_content .= '<a class="' . $wm_button_match_class .'" href="'. get_template_directory_uri() . '/wmexport/subscriptions_match.csv" download="matching_subscriptions.csv">Download Matched Subscriptions CSV</a>';

		$content = str_replace('</form>', '</form>'.$after_content, $content, $count);
		
		// output the new content
		echo $content;
	}

  
  public function my_acf_op_init() {

      // Check function exists.
      if( function_exists('acf_add_options_page') ) {

          // Register options page.
          $option_page = acf_add_options_page(array(
              'page_title'    => __('CSV Converter'),
              'menu_title'    => __('CSV Converter'),
              'menu_slug'     => 'csv-convert',
              'capability'    => 'manage_options',
              'update_button' => __('Generate CSVs', 'acf'),
              'icon_url'      => 'dashicons-printer',
              'position'      => '32',
              'redirect'      => false
          ));
      }

      if( function_exists('acf_add_options_sub_page') ) {
        // Add sub page.
        $child = acf_add_options_sub_page(array(
          'page_title'  => __('Sync Addresses'),
          'menu_title'  => __('Sync Addresses'),
          'parent_slug' => $option_page['menu_slug'],
        ));
      }
  }

  public function init_wm_csv_converter(){
    global $wpdb;

    if (!current_user_can('manage_options')) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    $sitename = get_bloginfo('name') ;

    echo '<div class="wrap wm-admin-dashboard">';
    echo '<h2>' . $sitename . '</h2>';
    echo '</div>';
  }

  protected function ConvertCSV() {
    global $convertcsv;
    
    // Instantiate only once.
    if( !isset($convertcsv) ) {
      $convertcsv = new ConvertCSV();
      $convertcsv->initialize();
    }
    return $convertcsv;
  }

  public function wmlogs($logs){
    global $wmlogs;
    $date = new DateTime();
    $datelog = $date->format('Y-m-d H:i:s');
    $wmlogs .= $datelog . ' : ' . $logs . '<br>';
    update_field( 'logs', $wmlogs, 'option' );
    $this->log($logs);
  }

  /*
  * Helper methods
  */
  public function console_log($output, $with_script_tags = true) {
		$js_code = 'console.log(' . json_encode($output, JSON_HEX_TAG) . ');';
		if ($with_script_tags) {
      $js_code = '<script>' . $js_code . '</script>';
		}
		echo $js_code;
	}

  public function log( $log ) {
    if ( is_array( $log ) || is_object( $log ) ) {
      error_log( print_r( $log, true ) );
    } else {
      error_log( $log );
    }
  }
}

function load_accessdb_woo_converter() {
  global $accdbwoo;
  
  // Instantiate only once.
  if( !isset($accdbwoo) ) {
    $accdbwoo = new AccDBToWoo();
    $accdbwoo->initialize();
  }
  return $accdbwoo;
}

// Instantiate.
load_accessdb_woo_converter();