<?php
// Prevent loading this file directly - Busted!
! defined( 'ABSPATH' ) AND exit;



if ( ! class_exists( 'wp_github_updater' ) )
{

/**
 * GitHub Plugin Update Class
 * 
 * @author     Franz Josef Kaiser - forked from Joachim Kudish
 * @license    GNU GPL 2
 * @copyright  © Franz Josef Kaiser, Joachim Kudish 2011-2012
 * 
 * @version    2012-06-29.1158
 * @link       https://github.com/franz-josef-kaiser/WordPress-GitHub-Plugin-Updater
 * 
 * @package    WordPress
 * @subpackage Github Plugin Updater
 */
class wp_github_updater 
{
	/**
	 * Configuration
	 * @access public
	 * @var    array
	 */
	public $config;


	/**
	 * Construct
	 * 
	 * @since
	 * @param array $config
	 * @return void
	 */
	public function __construct( $config = array() ) 
	{
		global $wp_version;

		$host = 'github.com';
		$http = 'https://';
		$name = 'franz-josef-kaiser';
		$repo = 'WordPress-GitHub-Plugin-Updater';
		// Default Data
		$this->config = wp_parse_args( 
			 $config
			,array(
				 'slug'               => plugin_basename( __FILE__ )
				,'proper_folder_name' => plugin_basename( __FILE__ )
				,'api_url'            => "{$http}api.{$host}/repos/{$name}/{$repo}"
				,'raw_url'            => "{$http}raw.{$host}/{$name}/{$repo}/master"
				,'github_url'         => "{$http}{$host}/{$name}/{$repo}"
				,'zip_url'            => "{$http}{$host}/{$name}/{$repo}/zipball/master"
				,'sslverify'          => true
				,'requires'           => $wp_version
				,'tested'             => $wp_version
				,'readme_file'        => 'readme.md'
			)
		);

		// Data from GitHub
		$this->config = wp_parse_args( $this->config, array(
			 'new_version'        => $this->get_new_version()
			,'last_updated'       => $this->get_date()
			,'description'        => $this->get_description()
		) );

		// Data from the plugin
		$data = $this->get_plugin_data();
		$this->config = wp_parse_args( $this->config, array(
			 'plugin_name'        => $data['Name']
			,'version'            => $data['Version']
			,'author'             => $data['Author']
			,'homepage'           => $data['PluginURI']
		) );

		defined( 'WP_DEBUG' ) AND WP_DEBUG AND add_action( 'init', array( $this, 'delete_transients' ) );

		! defined( 'WP_MEMORY_LIMIT' ) AND define( 'WP_MEMORY_LIMIT', '96M' );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'api_check' ) );

		// Hook into the plugin details screen
		add_filter( 'plugins_api', array( $this, 'get_plugin_info' ), 10, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'upgrader_post_install' ), 10, 3 );

		// set timeout
		add_filter( 'http_request_timeout', array( $this, 'http_request_timeout' ) );
	}


	/**
	 * Callback fn for the http_request_timeout filter
	 * 
	 * @since
	 * @return int $timeout
	 */
	public function http_request_timeout() 
	{
		return 2;
	}


	/**
	 * Delete transients
	 * For testing purpose, the site transient will be reset on each page load
	 * 
	 * @since
	 * @return void
	 */
	public function delete_transients() 
	{
		delete_site_transient( 'update_plugins' );
		delete_site_transient( "{$this->config['slug']}_new_version" );
		delete_site_transient( "{$this->config['slug']}_github_data" );
		delete_site_transient( "{$this->config['slug']}_changelog" );
	}


	/**
	 * Get New Version
	 * 
	 * @since
	 * @return int $version
	 */
	public function get_new_version() 
	{
		$version = get_site_transient( "{$this->config['slug']}_new_version" );

		if ( empty( $version )  ) 
		{
			$data = $this->get_github_data();
			set_site_transient( 
				 "{$this->config['slug']}_new_version"
				 // Versionnr. is the last update date on the GitHub repo
				,preg_replace( '/[^\D\s]/', '', $data->updated_at )
				 // refresh every 6 hours
				,60*60*6 
			);
		}

		return $version;
	}


	/**
	 * Get GitHub Data
	 * 
	 * @uses WordPress HTTP API `wp_remote_get()`
	 * 
	 * @since
	 * @return object $github_data
	 */
	public function get_github_data() 
	{
		$github_data = get_site_transient( "{$this->config['slug']}_github_data" );

		if ( empty( $github_data ) )
		{		
			$github_data = wp_remote_get( 
				 $this->config['api_url']
				,$this->config['sslverify'] 
			);

			if ( is_wp_error( $github_data ) )
				return false;

			$github_data = json_decode( $github_data['body'] );

			$transient = set_site_transient( 
				 "{$this->config['slug']}_github_data"
				,$github_data
				 // refresh every 6 hours
				,60*60*6 
			);
		}

		return $github_data;			
	}


	/**
	 * Get Date
	 * 
	 * @since
	 * @return string $date
	 */
	public function get_date() 
	{
		$data = $this->get_github_data();
		$date = $data->updated_at;
		return date( 'Y-m-d', strtotime( $date ) );
	}


	/**
	 * Get description
	 * 
	 * @since
	 * @return string $description
	 */
	public function get_description() 
	{
		$description = $this->get_github_data();
		return $description->description;
	}


	/**
	 * Get Plugin Data
	 * 
	 * @since  
	 * @return object Plugin Data
	 */
	public function get_plugin_data() 
	{
		include_once( ABSPATH.'/wp-admin/includes/plugin.php' );
		return get_plugin_data( trailingslashit( WP_PLUGIN_DIR )."{$this->config['slug']}" );
	}


	/**
	 * API Check
	 * Hook into the plugin update check
	 * 
	 * @since
	 * @param  object $transient
	 * @return object $transient
	 */
	public function api_check( $transient ) 
	{
		// Check if the transient contains the 'checked' information
		// If not, just return its value without hacking it
		if ( empty( $transient->checked ) )
			return $transient;
		
		// check the version and make sure it's new
		$update = version_compare( 
			 $this->config['new_version']
			,$this->config['version'] 
		);
		if ( 1 === $update ) 
		{
			$response = new stdClass;
			$response->new_version = $this->config['new_version'];
			$response->slug        = $this->config['slug'];		
			$response->url         = $this->config['github_url'];
			$response->package     = $this->config['zip_url'];

			// If response is false, don't alter the transient
			false !== $response AND $transient->response[ $this->config['slug'] ] = $response;
		}

		return $transient;
	}


	/**
	 * Get Plugin info
	 * 
	 * @since
	 * @param  bool         $bool
	 * @param  string       $action
	 * @param  array|object $args
	 * @return object       $response
	 */
	public function get_plugin_info( $bool, $action, $args ) 
	{
		$plugin_slug = plugin_basename( __FILE__ );

		// Check if this plugins API is about this plugin
		if ( $args->slug != $this->config['slug'] )
			return false;

		$response = new stdClass;
		$response->slug          = $this->config['slug'];
		$response->plugin_name   = $this->config['plugin_name'];
		$response->version       = $this->config['new_version'];
		$response->author        = $this->config['author'];
		$response->homepage      = $this->config['homepage'];
		$response->requires      = $this->config['requires'];
		$response->tested        = $this->config['tested'];
		$response->downloaded    = 0;
		$response->last_updated  = $this->config['last_updated'];
		$response->sections      = array(
			'description' => $this->config['description']
		);
		$response->download_link = $this->config['zip_url'];

		return $response;
	}


	/**
	 * Upgrader/Updater
	 * Move & activate the plugin, echo the update message
	 * 
	 * @since
	 * @param  boolean $true
	 * @param  unknown_type $hook_extra
	 * @param  unknown_type $result
	 * @return unknown_type $result
	 */
	public function upgrader_post_install( $true, $hook_extra, $result ) 
	{
		global $wp_filesystem;

		// Move & Activate
		$plugin_dir = WP_PLUGIN_DIR;
		$proper_destination = "{$plugin_dir}/{$this->config['proper_folder_name']}";
		$wp_filesystem->move( 
			 $result['destination']
			,$proper_destination 
		);
		$result['destination'] = $proper_destination;
		$activate = activate_plugin( "{$plugin_dir}/{$this->config['slug']}" );

		// Output the update message
		$fail    = __( 
			 'The plugin has been updated, but could not be reactivated. Please reactivate it manually.'
			,'git_up_textdomain' 
		);
		$success = __( 
			 'Plugin reactivated successfully.'
			,'git_up_textdomain' 
		);

		echo is_wp_error( $activate ) ? $fail : $success;

		return $result;
	}
} // END Class

} // endif;