<?php
/**
 * @author: budiartoa
 * @copyright Copyright © Luxbet Pty Ltd. All rights reserved. http://www.luxbet.com/
 * @license http://www.opensource.org/licenses/BSD-3-Clause
 */

include_once('toc_generator.php');

class StaticGenerator {

	const FILENAME_SEPARATOR = "#@#"; //hopefully #@#is unique enough that no one would use this as the post name, we need this later for Wagerplayer to derrive the file from the URL
	const PATH_URL_SEPARATOR = "/";

	private $error_code = false; //collection of WP_Error objects

	public function __construct() {

	}

	/**
	 * returns the error code of the last error encountered
	 * @return bool
	 */
	public function get_error_code() {
		if (!$this->error_code) {
			return false;
		}
		return $this->error_code;
	}

	/**
	 * Save the error code
	 * @param $error
	 */
	private function register_error($error) {
		$this->error_code = $error;
	}

	/**
	 * Generates static post
	 * This turns post data based on the post_id into physical file
	 * The file name generation also take into account the post category hierarchy, using one-category only plugin
	 * we are able to ensure that the first category is indeed the youngest child of the categories
	 * @param $post_id
	 */
	public function generate_static_post($post_id) {
		$current_post = get_post($post_id);

		$filename_prefix = $this->generate_post_prefix($post_id);

		$filename = $filename_prefix . self::FILENAME_SEPARATOR . sanitize_title_with_dashes($current_post->post_title);

		if ($filename) {
			$filename .= ".html"; //yes prefix with html
			$this->write_to_static_directory($current_post->post_content, $filename, '', true);
		}

		//yes also save the state for the editor, so we know which editor to kick in
		$this->save_html_lock_state($post_id);

		//we also need to get the category to construct the filename
		$this->clean_up_static_post_category($post_id, $filename_prefix);
	}

	/**
	 * This will try to clean up the category where the static post resides
	 * in effect cleaning orphaned files for the category
	 * Either put in just the post_id or specify filename prefix and target category id
	 * @param int $post_id - optional
	 * @param string $filename_prefix - optional
	 * @param int $target_category_id - optional - to force the category id to clean
	 */
	public function clean_up_static_post_category($post_id = false, $filename_prefix = false, $target_category_id = false) {

		//if no filename prefix is supplied then we need to search it from the post ID
		if (!$filename_prefix) {
			$filename_prefix = $this->generate_post_prefix($post_id);
		}

		if (!$target_category_id) {
			//we also need to get the category to construct the filename
			$current_post_categories = get_the_category($post_id);

			//now, we assume the one category restriction is on
			$current_post_category = $current_post_categories[0];

			$target_category_id = $current_post_category->cat_ID;
		}

		$this->clean_up_static_files($target_category_id, "posts", $filename_prefix);
	}

	/**
	 * Clean static post files
	 * @param int $post_id - required
	 * @param string $subdirectory - optional
	 * Arthur Kusumadjaja, 22 September 2014
	 */
	public function clean_up_static_posts($post_id, $subdirectory = "") {

		//if no post id then abort
		if (!$post_id) {
			return false;
		}

		$filenames = $this->generate_files($post_id);

		if ($filenames) {
			//get directory
			$options = get_option(MakeItStatic::CONFIG_TABLE_FIELD);
			$static_target_directory = $options["fs_static_directory"];

			//get sub directory if any
			if ($subdirectory) {
				$static_target_directory .= $subdirectory . "/";
			}

			$directory_handle = opendir($static_target_directory);

			//go thru files in directory
			while (false !== ($current_filename = readdir($directory_handle))) {

				//check current filename if invalid or directory
				if ($current_filename != "." && $current_filename != ".." && !is_dir($static_target_directory . $current_filename)) {

					if (in_array($current_filename, $filenames)) {
						//current filename match with our array therefore need to delete
						unlink($static_target_directory . $current_filename);
					}

				}

			}

			return true;
		}
	}

	/**
	 * Save the HTML editor lock for the current post id
	 * @param $post_id
	 */
	public function save_html_lock_state($post_id) {
		$make_it_static_html_lock = $_POST["make_it_static_html_lock"];
		$lock_options = get_option(MakeItStatic::CONFIG_TABLE_FIELD_HTML_LOCK);
		$lock_options[$post_id]  = $make_it_static_html_lock;
		update_option(MakeItStatic::CONFIG_TABLE_FIELD_HTML_LOCK, $lock_options);
	}

	/**
	 * Return the category hierarchy of this post as an array
	 * @param $post_id
	 * @return array
	 */
	private function get_categories_path_array($post_id) {
		//we also need to get the category to construct the filename
		$current_post_categories = get_the_category($post_id);

		//now, we assume the one category restriction is on
		$current_post_category = $current_post_categories[0];

		//now get the parents
		$current_category_path = get_category_parents($current_post_category->cat_ID, false, self::FILENAME_SEPARATOR, true);

		//make sure we return false if this is an error object
		if (is_wp_error($current_category_path)) {
			return false;
		}

		//we need to reverse this as wordpress
		return explode(self::FILENAME_SEPARATOR, $current_category_path);
	}

	/**
	 * Create shortlinks based on the category hierarchy of this particular post
	 * @param $post_id
	 * @return string
	 */
	public function generate_shortlink($post_id) {
		$current_post = get_post($post_id);

		$categories_array = $this->get_categories_path_array($post_id);

		if (!$categories_array) {
			return ''; //return empty string if no array yet
		}

		//unset the last element as we don't need this
		unset($categories_array[count($categories_array) - 1]);

		//now we generate the file name
		$shortlink = implode(self::PATH_URL_SEPARATOR, $categories_array);
		$shortlink .= self::PATH_URL_SEPARATOR . sanitize_title_with_dashes($current_post->post_title) . ".html";

		$options = get_option(MakeItStatic::CONFIG_TABLE_FIELD);
		$shortlink = $options["ws_address"] . $shortlink;//the web accessible path

		return $shortlink;
	}

	/**
	 * Create static file of the page with the page id
	 * This also rebuilds the static index file
	 * @param $page_id
	 */
	public function generate_static_page($page_id) {
		$current_page = get_page($page_id);
		$page_ancestors = get_ancestors($page_id, 'page');

		$filename = "";
		$page_ancestors = array_reverse($page_ancestors); //reverse this as we want the parent to children
		//so get the hierarchy and generate the post name here so the front controller can later tokenize the following.
		foreach ($page_ancestors as $page_ancestor) {
			$current_page_ancestor = get_page($page_ancestor);
			$filename .= $current_page_ancestor->post_name . self::FILENAME_SEPARATOR;
		}

		$filename .= $current_page->post_name . ".html";
		//append post header as h1
		$file_contents = "<h1>" . $current_page->post_title . "</h1>";

		//let's generate the TOC for the static directory
		$toc_generator = new MakeItStaticTOC();

		//if the page content has TOC (starting from that page) then display the toc instead of blank page
		//ok update this so that TOC can be replaced by the table of content instead of killing the entire page contents
		$file_contents .= $current_page->post_content;
		if (substr_count($current_page->post_content, "[TOC]")) {
			//we actually need to convert the file contents with nl2br but we don't need this for the TOC
			$file_contents = str_replace("[TOC]", $toc_generator->generate_toc($page_id), nl2br($file_contents));
			$nl2br  = false;
		} else {
			$nl2br  = true;
		}

		$this->write_to_static_directory($file_contents, $filename, 'pages', $nl2br);

		$toc_generator = new MakeItStaticTOC();
		$toc_html = $toc_generator->generate_toc();

		//now write the static contents
		$this->write_to_static_directory($toc_html, 'toc.html', 'pages', false);
	}

	/**
	 * Generating filename(s) from all selected categories
	 * Returns array of filename(s)
	 * Arthur Kusumadjaja, 22 September 2014
	 */
	public function generate_files($post_id) {
		// filename will be an array
		$filenames = array();
		$current_post = get_post($post_id);

		// get filename prefix based on selected categories
		$filename_prefix = $this->generate_filename_prefix($post_id);

		// build filename
		$count_prefix = count($filename_prefix);
		if ($count_prefix > 0) {
			for ($i = 0; $i < $count_prefix; $i++) {
				$filenames[] = $filename_prefix[$i] . sanitize_title_with_dashes($current_post->post_title);
			}
		}

		// write file based on filename
		$count_filename = count($filenames);
		if ($count_filename > 0) {
			for ($i = 0; $i < $count_filename; $i++) {
				$filenames[$i] .= ".html"; //yes prefix with html
				$this->write_to_static_directory($current_post->post_content, $filenames[$i], '', true);
			}
		}

		//yes also save the state for the editor, so we know which editor to kick in
		$this->save_html_lock_state($post_id);

		return $filenames;
	}

	/**
	 * Generating filename prefix of all selected categories
	 * Returns array of filename prefixes
	 * Arthur Kusumadjaja, 22 September 2014
	 */
	public function generate_filename_prefix($post_id) {
		$category = get_the_category($post_id);

		$count = count($category);
		$name_raw = array();

		// array not null
		if ($count > 0) {
			for ($i = 0; $i < $count; $i++) {
				$name = '';

				// get parent id if exist
				if ($category[$i]->{'parent'} || $category[$i]->{'parent'} != '0') {
					$name .= $category[$i]->{'parent'}."#@#" ;
				}

				// get id
				$name .= $category[$i]->{'term_id'}."#@#";

				// put all together
				$name_raw[] = $name;
			}
		}

		return $this->generate_name_from_id($name_raw);
	}

	/**
	 * Convert all of the ids in the array into respective names
	 * Returns array of filename ready
	 * Arthur Kusumadjaja, 22 September 2014
	 */
	public function generate_name_from_id($name_raw) {
		// container for converted name
		$name_converted = array();

		$count_raw = count($name_raw);
		if ($count_raw > 0) {
			//go thru all raw data
			for ($i = 0; $i < $count_raw; $i++) {
				//get each section
				$name_explode = explode('#@#', $name_raw[$i]);

				$count_explode = count($name_explode);
				for ($y = 0; $y < $count_explode-1; $y++) {
					$cat_id = $name_explode[$y];

					//get name from wp
					$name_explode[$y] = get_cat_name($cat_id);
				}

				//glue names and save to container
				$name_converted[] = implode('#@#', $name_explode);
			}
		}

		return $name_converted;
	}

	/**
	 * creates post prefix string based on the category names
	 * @param $post_id
	 * @return string
	 */
	public function generate_post_prefix($post_id) {
		//we also need to get the category to construct the filename
		$current_post_categories = get_the_category($post_id);

		//now, we assume the one category restriction is on
		$current_post_category = $current_post_categories[0];

		//now get the parents
		$current_category_path = get_category_parents($current_post_category->cat_ID, false, self::FILENAME_SEPARATOR, true);

		//we need to reverse this as wordpress
		$categories_array = $this->get_categories_path_array($post_id);

		if (!$categories_array) {
			return ''; //empty if there is no categories yet
		}

		//unset the last element as we don't need this
		unset($categories_array[count($categories_array) - 1]);

		//now we generate the file name
		$filename_prefix = implode(self::FILENAME_SEPARATOR, $categories_array);

		return $filename_prefix;
	}

	/**
	 * @param $current_parent_id
	 * @param $type - page or post
	 * @param $filename_prefix - prefix of the filename which is the parent directories concatenated saves us looping again
	 * @param string $subdirectory - optional
	 */
	public function clean_up_static_files($current_parent_id, $type, $filename_prefix = "", $subdirectory = "") {
		//need to clean up the directory after writing static contents
		$regenerate_prefix = false;
		if ($filename_prefix == "") {
			//empty we must generate prefix for each of the posts, this also means that
			//this is a maintenance run which scans all dir. This won't run per post
			$regenerate_prefix = true;
		}
		$expected_filenames = array();
		if ($type == "posts") {
			//get all the posts
			if ($current_parent_id) {
				$all_posts_in_category = get_posts(array("category" => $current_parent_id, 'numberposts' => -1));
			} else {
				$all_posts_in_category = get_posts(array('numberposts' => -1));
			}

			foreach ($all_posts_in_category as $post_in_category) {
				if ($regenerate_prefix) {
					$filename_prefix = $this->generate_post_prefix($post_in_category->ID);
				}

				$filename = $filename_prefix . self::FILENAME_SEPARATOR . sanitize_title_with_dashes($post_in_category->post_title);
				$expected_filenames[] = $filename . ".html";
			}
		} else { //pages
			//TODO: we need to handle pages too, but at this point this is not required yet
		}

		//if filename prefix does not exist do not proceed, we might end up deleting the whole thing
		if (!$filename_prefix) {
			return false;
		}

		$options = get_option(MakeItStatic::CONFIG_TABLE_FIELD);
		$static_target_directory = $options["fs_static_directory"];

		if ($subdirectory) {
			$static_target_directory .= $subdirectory . "/";
		}

		$directory_handle = opendir($static_target_directory);

		while (false !== ($current_filename = readdir($directory_handle))) {

			if ($current_filename != "." && $current_filename != ".." && !is_dir($static_target_directory . $current_filename)) {

				//if we specify prefix and it doesn't match with our current item, don't do anything
				//alternatively if filename prefix does not exist, don't bother trying to delete as we might delete other things this way
				if (!$filename_prefix || !substr_count($current_filename, $filename_prefix)) {
					continue;
				}
				if (!in_array($current_filename, $expected_filenames)) {
					//not in array therefore we must remove!!!
					unlink($static_target_directory . $current_filename);
				}
			}
		}
	}

	/**
	 * writes physical file into the static directory
	 * this function requires filename as it doesn't construct filenames
	 * @param $content
	 * @param $filename
	 * @param $subdirectory - optional - this is to specify subdirectory after the defined static content FS
	 * @param $use_nl2br - optional
	 */
	public function write_to_static_directory($content, $filename, $subdirectory='', $use_nl2br = true) {
		//we need to strip the shortcodes!
		//ok now we need to avoid things inside static codes from being nl2br'ed
		//this means extracting those inside the static code shortcode and putting it back after nl2br
		$regex_rule = "/\[static_code\](.+?)\[\/static_code\]/is";
		preg_match_all($regex_rule, $content, $matches);
		$matches_with_tag = $matches[0];
		$matches_content = $matches[1];

		$placeholder_array = array();
		$match_count = 0;
		if (count($matches_with_tag)) {
			foreach ($matches_with_tag as $match_with_tag) {
				$placeholder_array[] = "__placeholder_$match_count";
				$match_count++;
			}

			$content = str_replace($matches_with_tag, $placeholder_array, $content);
		}

		//conver newlines to br
		if ($use_nl2br) {
			$content = nl2br($content);
		}

		//now put the escaped contents back
		$match_count = 0;
		if (count($matches_with_tag)) {

			$content = str_replace($placeholder_array, $matches_content, $content);

		}

		$options = get_option(MakeItStatic::CONFIG_TABLE_FIELD);
		//get the set static directory first
		$static_target_directory = $options["fs_static_directory"];

		$original_paths = preg_split("/[\r\n]+/", $options["original_paths"], -1, PREG_SPLIT_NO_EMPTY);
		$target_paths = preg_split("/[\r\n]+/", $options["target_paths"], -1, PREG_SPLIT_NO_EMPTY);
		if (count($original_paths) && (count($original_paths) == count($target_paths))) {

			$content = str_replace($original_paths, $target_paths, $content);
		}

		$target_fs_filename = $static_target_directory . $filename;
		$ws_filepath = $options["ws_static_url"] . $filename;//the web accessible path

		$content_type = 'post';
		if ($subdirectory) {
			$content_type = 'page';
			//redefine the fs filename as we need to insert the subdirectory
			$target_fs_filename = $static_target_directory . $subdirectory . "/";
			if (!is_dir($target_fs_filename)) {
				//create the directory
				mkdir($target_fs_filename);
			}
			$target_fs_filename .= $filename; //complete the path with filename as we want to use this of writing
			$ws_filepath = $options["ws_static_url"] . $subdirectory . "/" . $filename;//the web accessible path
		}


		//now attempt to write into the static directory

		$file_handle = fopen($target_fs_filename, 'w');

		if (!$file_handle) {
			$this->register_error(MakeItStatic::ERROR_NO_HANDLE);
		}

		if (fwrite($file_handle, $content) === FALSE) {
			///return error code here
			$this->register_error(MakeItStatic::ERROR_NO_PERMISSION);
		} else {
			//should we send email to reviewer? let's check the options
			//use $target_fs_filename
			$options = get_option(MakeItStatic::CONFIG_TABLE_FIELD);

			if ($options["reviewer_enabled"] == "y" && $options["reviewer_email"]) {
				$attachments = array($target_fs_filename);
				wp_mail($options["reviewer_email"], "New Contents Published", "The newly published content is in the attachment", array(), $attachments);
			}
		}

		$callback_urls = $options["callback_url"];
		//now we need to do the callback!
		//we assume that the subdirectory is different content type as this is what we use to segregate pages and posts
		$this->callback_file($ws_filepath, $callback_urls, $filename, $content_type);
	}

	public function get_post_language() {

	}

	/**
	 * Takes care of the callback via curl
	 * by default it submits the file_url, filename, contetn_type
	 * @param $ws_filepath
	 * @param $callback_urls
	 * @param $filename
	 * @param $content_type
	 */
	public function callback_file($ws_filepath, $callback_urls, $filename, $content_type) {
		if ($callback_urls) {
			$callback_urls_array = explode(";", $callback_urls);

			foreach ($callback_urls_array as $callback_url) {
				$callback_url = trim($callback_url);
				$curl_connection = curl_init($callback_url);
				curl_setopt($curl_connection, CURLOPT_CONNECTTIMEOUT, 30);
				curl_setopt($curl_connection, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($curl_connection, CURLOPT_POSTFIELDS, "file_url=$ws_filepath&filename=$filename&content_type=$content_type");
				curl_exec($curl_connection);
				curl_close($curl_connection);
			}
		}
	}

}
?>