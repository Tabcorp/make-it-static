<?php
/*
Plugin Name: make-it-static.php
Plugin URI: http://www.luxbet.com/
Description: A plugin to disperse blog posts as static contents to different servers
Version: 1.0
Author: Arvy Budiarto
Author URI: http://www.luxbet.com
*/

/**
 * Class MakeItStatic
 * Mostly contains static functions that calls the appropriate controller when more complex things need to be instantiated
 * User: budiartoa
 * Date: 15/03/12
 * Time: 3:37 PM
 * @copyright Copyright © Luxbet Pty Ltd. All rights reserved. http://www.luxbet.com/
 * @license http://www.opensource.org/licenses/BSD-3-Clause
 */
include_once ("controller/display_options.php");
include_once ("controller/static_generator.php");

class MakeItStatic {
	const CONFIG_TABLE_FIELD = "make_it_static_config";
	const CONFIG_PAGE_NAME = "make_it_static_plugin";
	const ERROR_NO_PERMISSION = 88;
	const ERROR_NO_HANDLE = 89;

	public $error_code = "";

	/**
	 * instantiate all the hooks
	 * not much logic here just the wordpress events hook.
	 */
	public function __construct() {
		register_activation_hook(__FILE__, array($this, 'make_it_static_install'));
		register_deactivation_hook(__FILE__, array($this, 'make_it_static_uninstall'));

		//only generate this if this is admin area
		if (is_admin()) {
			add_action('admin_menu', array($this, 'generate_admin_menu'));
		}

		//add_action('admin_init', 'test_dbg');
		//now, we have to tell Wordpress to actually static this properly
		//add the hook to post save!
		add_action('publish_post', array($this, 'convert_post_to_static'));
		add_action('publish_page', array($this, 'convert_page_to_static'));

		//append the after-save messages list with our own error messages
		add_filter('post_updated_messages', array($this, 'custom_message_filter'));

		remove_filter('the_content', 'wpautop');
		remove_filter('the_content', 'wptexturize');

		add_filter('the_content', array($this, 'preserve_codes'), 99);

		//we need an add_action for NGGallery so we can sync static servers
		add_action('ngg_added_new_image', array($this, 'nggallery_image_upload_hook'));
	}

	/**
	 * @static
	 * creates an option entry in wordpress
	 */
	public static function make_it_static_install() {
		add_option(self::CONFIG_TABLE_FIELD);
	}

	/**
	 * @static
	 * remove the option entry from wordpress
	 */
	public static function make_it_static_uninstall() {
		delete_option(self::CONFIG_TABLE_FIELD);
	}

	/**
	 * @static
	 * add the settings menu options so admin can setup the static directories
	 */
	public function generate_admin_menu() {
		//instantiate the option display controller
		$make_it_static_display_options = new MakeItStaticDisplayOptions('make_it_static_options_group');

		//upon clicking the settings menu, the callback display_options from make it static controller will be fired
		add_options_page("Make-it-static!", "Make-it-static", "administrator", "make-it-static", array($make_it_static_display_options, 'display_options'));
	}

	/**
	 * Convert the new post data display into static physical file
	 * This is to be called as a hook
	 * @param $post_id
	 */
	public function convert_post_to_static($post_id) {
		$static_generator = new StaticGenerator();
		$static_generator->generate_static_post($post_id);
		$error_code = $static_generator->get_error_code();

		if ($error_code) {
			$this->error_code = $error_code;
			add_filter('redirect_post_location', array($this, 'custom_error_message_redirect'));
		}

	}

	/**
	 * Converts the page data into static physical file
	 * This is to be called as a hook
	 * @param $page_id
	 */
	public function convert_page_to_static($page_id) {
		$static_generator = new StaticGenerator();
		$static_generator->generate_static_page($page_id);
		$error_code = $static_generator->get_error_code();

		if ($error_code) {
			$this->error_code = $error_code;
			add_filter('redirect_post_location', array($this, 'custom_error_message_redirect'));
		}
	}

	/**
	 * Override default wordpress redirection based on our error code
	 * @param $location
	 * @return string
	 */
	public function custom_error_message_redirect($location) {

		remove_filter('redirect_post_location', __FUNCTION__, $this->error_code);
		$location = add_query_arg('message', $this->error_code, $location);
		return $location;
	}

	/**
	 * This function defines our custom error messages for the makeit static
	 * @param $messages
	 * @return
	 */
	public function custom_message_filter($messages) {
		$messages['post'][self::ERROR_NO_PERMISSION] = 'Changes saved to database, however file cannot be generated, make sure you set the correct permission for static directory, check the make-it-static options';
		$messages['post'][self::ERROR_NO_HANDLE] = 'Changes saved to database, however file handle cannot be created,  make sure you set the correct permission for static directory, check the make-it-static options';

		$messages['page'][self::ERROR_NO_PERMISSION] = 'Changes saved to database, however file cannot be generated, make sure you set the correct permission for static directory, check the make-it-static options';
		$messages['page'][self::ERROR_NO_HANDLE] = 'Changes saved to database, however file handle cannot be created,  make sure you set the correct permission for static directory, check the make-it-static options';

		return $messages;
	}

	/**
	 * prevent escaping any characters within this raw code
	 * @param $content
	 * @return string
	 */
	public function preserve_codes($content) {
		$new_content = '';
		$pattern_full = '{(\[static_code\].*?\[/static_code\])}is';
		$pattern_contents = '{\[static_code\](.*?)\[/static_code\]}is';
		$pieces = preg_split($pattern_full, $content, -1, PREG_SPLIT_DELIM_CAPTURE);

		foreach ($pieces as $piece) {
			if (preg_match($pattern_contents, $piece, $matches)) {
				$new_content .= html_entity_decode($matches[1]);
			} else {
				$new_content .= wptexturize(wpautop($piece));
			}
		}

		return $new_content;
	}

	public function nggallery_image_upload_hook($image_info) {
		//get the gallery path gallerypath
		$nggdb = new nggdb();
		$current_gallery = $nggdb->find_gallery($image_info['galleryID']);
		$current_filename = $image_info["filename"];
		//now get the absolute path
		$ws_image_path = $options = get_option('siteurl') . "/" . $current_gallery->path . "/" . $current_filename;

		$static_generator = new StaticGenerator();
		$options = get_option(MakeItStatic::CONFIG_TABLE_FIELD);
		$callback_urls = $options["nggallery_callback_url"];
		$static_generator->callback_file($ws_image_path, $callback_urls, $current_filename, 'nggallery_image');
	}
}

$make_it_static = new MakeItStatic();

?>