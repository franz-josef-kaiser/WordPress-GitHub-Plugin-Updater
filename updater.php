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
 * @copyright  © Franz Josef Kaiser 2011-2012
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
		if ( 
			! in_array( 
				 $GLOBALS['pagenow']
				,array( 'plugins.php', 'plugin-install.php' ) 
			)
		)
			return;

		$this->set_args( $config );

		// Development
		if ( defined( 'WP_DEBUG' ) AND WP_DEBUG )
		{
			// Kill update interval
			$this->config['update_interval'] = 0;
		}

		! defined( 'WP_MEMORY_LIMIT' ) AND define( 'WP_MEMORY_LIMIT', '96M' );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'api_check' ) );

		// Hook into the plugin details screen
		add_filter( 'plugins_api', array( $this, 'get_plugin_info' ), 10, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'upgrader_post_install' ), 10, 3 );

		// set timeout
		add_filter( 'http_request_timeout', array( $this, 'http_request_timeout' ) );
	}


	/**
	 * Merge args with defaults.
	 * Pay attention, as any part of the config can be overwritten with the input data
	 * 
	 * @since  1.0
	 * @param  array $config
	 * @return void
	 */
	public function set_args( $config )
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
				 // The default update check interval is set to 12 hours
				,'update_interval'    => 60*60*12
			)
		);

		// Data from GitHub
		$this->config = wp_parse_args( $this->config, array(
			 'new_version'        => $this->get_new_version()
			,'last_updated'       => $this->get_date()
		) );
		// Merge custom description w GitHub description
		// Allows to add custom tabs		
		$this->config['description'] = wp_parse_args(
			 $this->config['description']
			,array( 
				'description' => $this->get_description() 
			 )
		);

		// Data from the plugin
		$data = $this->get_plugin_data();
		$this->config = wp_parse_args( $this->config, array(
			 'plugin_name'        => $data['Name']
			,'version'            => $data['Version']
			,'author'             => $data['Author']
			,'homepage'           => $data['PluginURI']
		) );
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
	 * Get version number from main remote file plugin header comment
	 * 
	 * @return mixed string|bool $all_headers FALSE on failure, ARRAY w plugin header comment data on success
	 */
	public function get_remote_plugin_header()
	{
		// As seen in core inside `get_plugin_data()`
		$default_headers = array(
			 'Name'        => 'Plugin Name'
			,'PluginURI'   => 'Plugin URI'
			,'Version'     => 'Version'
			,'Description' => 'Description'
			,'Author'      => 'Author'
			,'AuthorURI'   => 'Author URI'
			,'TextDomain'  => 'Text Domain'
			,'DomainPath'  => 'Domain Path'
			,'Network'     => 'Network'
			 // "_sitewide"/"Site Wide Only" is deprecated in favor of Network.
			,'_sitewide'   => 'Site Wide Only'
		);

		// Call the main remote file
		$main_file   = array_pop( explode( '/', $this->config['slug'] ) );
		$remote_data = wp_remote_get(
			 "{$this->config['raw_url']}/{$main_file}" 
			,$this->config['sslverify'] 
		);
 
		if ( 
			is_wp_error( $remote_data )
			OR 200 !== wp_remote_retrieve_response_code( $remote_data )
		)
			return false;

		// Get the first 8kB (plugin header comment)
		// Pretty equal to what `get_file_data()` does in core
		$remote_data = substr( 
			 wp_remote_retrieve_body( $remote_data )
			,0
			,8192
		);
		// Get rid of unnecessary stuff
		$remote_data = str_replace( "\r", "\n", $remote_data );

		$all_headers = false;
		// Copied from core `get_file_data()`
		foreach ( $default_headers as $field => $regex )
		{
			$all_headers[ $field ] = '';
			if ( 
				preg_match( 
					 '/^[ \t\/*#@]*'.preg_quote( $regex, '/' ).':(.*)$/mi'
					,$remote_data
					,$match 
				)
				AND $match[1] 
			)
				$all_headers[ $field ] = trim( preg_replace( "/\s*(?:\*\/|\?>).*/", '', $match[1] ) );
		}

		return $all_headers;
	}


	/**
	 * Get GitHub Data
	 * 
	 * @uses WordPress HTTP API `wp_remote_get()`
	 * 
	 * @since
	 * @return object $remote_data
	 */
	public function get_remote_data() 
	{
		$remote_data = get_site_transient( "{$this->config['slug']}_remote_data" );

		if ( $remote_data )
			return $remote_data;

		$remote_data = wp_remote_get( 
			 $this->config['api_url']
			,$this->config['sslverify'] 
		);
 
		if ( 
			is_wp_error( $remote_data )
			OR 200 !== wp_remote_retrieve_response_code( $remote_data )
		)
			return false;

		$remote_body = wp_remote_retrieve_body( $remote_data );
		// Abort with WP Error on fail
		if ( is_wp_error( $remote_body ) )
			return $remote_body;

		$remote_body = json_decode( $remote_body );

		$transient = set_site_transient( 
			 "{$this->config['slug']}_remote_data"
			,$remote_body
			 // refresh every 6 hours
			,$this->config['update_interval']
		);

		return $remote_body;
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

		if ( $version )
			return $version;

		$data    = $this->get_remote_plugin_header();
		$version = (int) $data['Version'];
		set_site_transient( 
			 "{$this->config['slug']}_new_version"
			 // Versionnr. is the last update date on the GitHub repo
			,$version
			 // refresh every 6 hours
			,$this->config['update_interval']
		);

		return $version;
	}


	/**
	 * Get Date
	 * 
	 * @since
	 * @return string $date
	 */
	public function get_date() 
	{
		$data = $this->get_remote_data();
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
		return $this->get_remote_data()->description;
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
	 * @param  mixed $transient Transient value. Expected to not be SQL-escaped.
	 * @return mixed $transient
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

		// 13% Faster than using the operator in version_compare()
		if ( $update >= 0 ) 
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

		// Sections/Tabs
		! is_array( $this->config['description'] ) AND array( 'description' => $this->config['description'] );
		foreach ( $this->config['description'] as $tab => $content )
		{
			$response->sections[ $tab ] = $content;
		}

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

		$plugin_dir  = WP_PLUGIN_DIR;
		$destination = "{$plugin_dir}/{$this->config['proper_folder_name']}";
		// Move
		$wp_filesystem->move( 
			 $result['destination']
			,$destination 
		);
		$result['destination'] = $destination;
		// Activate
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