<?php
/*
Plugin Name: Fresh From FriendFeed and Twitter
Plugin URI: http://wordpress.org/extend/plugins/fresh-from-friendfeed-and-twitter/
Description: Keeps your blog always fresh by automatically adding your most recent content from FriendFeed or Twitter. Content is imported as regular blog posts that you can edit and keep if you want. No external passwords required.
Version: 1.0.0
Author: Bob Hitching
Author URI: http://hitching.net/fresh-from-friendfeed-and-twitter
*/

require_once("fresh-from-friendfeed.php");
require_once("fresh-from-twitter.php");

define("_ffff_version", "1.0.0");
define("_ffff_debug", false);
define("_ffff_debug_email", "bob@hitching.net");
define("_ffff_friendfeed_bot", "FriendFeedBot"); // user agent of Friendfeed Bot - so we can hide Fresh posts and avoid crashing the internet with an infinite loop
define("_ffff_support_room", "http://friendfeed.com/rooms/fresh-from-friendfeed-and-twitter"); // where to go for help and discussion
define("_ffff_freshfrom_url", "http://hitching.net/fresh-from-friendfeed-and-twitter"); // base url used for stats and plug
define("_ffff_comments_label", "My Comments"); // label for FriendFeed Comments service
define("_ffff_media_token", "<!-- media_content -->"); // token dropped into content to be replaced with thumbnail, video, etc.
define("_ffff_twitter_pics_ttl", 86400); // cache twitter profile pics for 24 hours
define("_ffff_instructions", "This post has been generated by the Fresh From plugin. It will be automatically replaced when fresher content becomes available, or you can safely delete it anytime. You can edit this post and prevent it from being replaced by deleting this Custom Field.");
// the following are magic sauce ingredients, used to work out which FriendFeed entries are best for import
define("_ffff_weight_recency", 50); 
define("_ffff_weight_comments", 10);
define("_ffff_weight_lots_of_comments", 15);
define("_ffff_weight_media", 15);
define("_ffff_weight_quantity", 10);

// start the plugin
freshfrom::start();
// that's all folks, nothing more to see here, move along please

/**
 * Base handler for things that are common between FriendFeed and Twitter
 *
 */
class freshfrom {

	// not chosen yet in this base class
	protected $service; 
	
	// ... but we still need a title for the admin page
	protected $admin_title = "Fresh From FriendFeed &/or Twitter";
	
	// FriendFeed includes many services, e.g. Flickr and Facebook - this array stores the required mix of imported content
	protected $services = array();

	protected $username;
	
	// set to true during post loop; some filters need to know this context
	protected $is_looping = false;
	
	protected $debug_timelog = array();
	
	function __construct() {
		if ($this->service) $this->username = get_option("ffff_{$this->service}_username");
	}
	
	// return a freshfrom or derived object
	static function start() {
		// run once
		if (!get_option("ffff_version")) freshfrom::run_once();
		
		// need to check updated settings before we start
		if (is_admin() && isset($_POST["ffff_service"])) {
			if ($_POST["ffff_service"] != get_option("ffff_service")) {
				update_option("ffff_service", $_POST["ffff_service"]);
				$restart = true; // triggers post deletion and detect_username
				
			} elseif (isset($_POST["ffff_friendfeed_username"]) && $_POST["ffff_friendfeed_username"] != get_option("ffff_friendfeed_username")) {
				// username changed
				update_option("ffff_friendfeed_username", $_POST["ffff_friendfeed_username"]);
				delete_option("ffff_friendfeed_services");
				delete_option("ffff_cache_friendfeed_user");
				delete_option("ffff_cache_friendfeed_comments");
				$restart = true; // triggers post deletion and detect_username
					
			} elseif (isset($_POST["ffff_twitter_username"]) && $_POST["ffff_twitter_username"] != get_option("ffff_twitter_username")) {
				update_option("ffff_twitter_username", $_POST["ffff_twitter_username"]);
				delete_option("ffff_cache_twitter_user");
				$restart = true; // triggers post deletion and detect_username
			}
		}

		$ffff_service = get_option("ffff_service");	
		if (class_exists("freshfrom" . $ffff_service)) {
			$classname = "freshfrom" . $ffff_service;
		} else {
			$classname = "freshfrom";
		}
		
		$ffff = new $classname;
		if (isset($restart)) $ffff->restart();
		$ffff->setup_hooks();
		
		if (_ffff_debug) {
			add_action("wp_footer", array($ffff, "debug_info"));
			add_action("admin_footer", array($ffff, "debug_info"));
		}
	}

	/**
	 * Detect usernames and setup some default settings
	 *
	 */	
	static function run_once() {	
		// attempt to detect usernames
		$service = new freshfromtwitter();
		$service->detect_username();
		$service = new freshfromfriendfeed();
		$service->detect_username();
		
		// default number of posts
		update_option("ffff_total_posts", 4);
		
		// default content expiry
		update_option("ffff_freshness", "recent");
		update_option("ffff_freshness_days", "7");
		
		// default content enhancement
		update_option("ffff_twitpic", 1);
		update_option("ffff_youtube", 1);
		update_option("ffff_redirect", 1);
		update_option("ffff_redirect_hosts", "bit.ly, cli.gs, ff.im, is.gd, tinyurl.com");
		
		// update version number
		update_option("ffff_version", _ffff_version);
	}

	/**
	 * Delete all Fresh From posts. Called when switching between FriendFeed and Twitter.
	 *
	 */
	function restart() {
		$this->timelog("Restarting...");

		$prefix = $GLOBALS["wpdb"]->prefix;
		$posts_exist = $GLOBALS["wpdb"]->get_results("SELECT post_id FROM {$prefix}postmeta WHERE meta_key='FreshFrom'");
		if (is_array($posts_exist)) {
			foreach ($posts_exist AS $post) {
				$wp_id = $post->post_id;
				$this->timelog("Deleting post {$wp_id}");
				wp_delete_post($wp_id);
			}
		}
		delete_option("ffff_posts");
		delete_option("ffff_posts_expire");
				
		// can we now detect a username?
		$this->detect_username();
	}
	
	/**
	 * Add some WordPress hooks to trigger Fresh From code in various places
	 *
	 */
	function setup_hooks() {
		$this->timelog("Setting up some hooks");
		
		if ($this->username) {
			// hide Fresh From posts from the FriendFeed Bot so we don't break the internet
			if (strpos($_SERVER["HTTP_USER_AGENT"], _ffff_friendfeed_bot)) {
				add_filter("posts_where", array($this, "exclude_fresh_posts")); // requires 1.5
			} else {
				add_action("pre_get_posts", array($this, "pre_get_posts")); // requires 2.0
				add_action("loop_start", array($this, "loop_start")); // requires 2.0
				add_action("loop_end", array($this, "loop_end")); // requires 2.0
			}	
		}
		
		// add an Admin option for Fresh From Settings
		if (is_admin()) {
			add_action("admin_menu", array($this, "admin_menu")); // requires 1.5
		}
	}

	/**
	 * Add an alert to display on the admin screen
	 *
	 */
	function admin_alert($alert) {
		$ffff_admin_alert = get_option("ffff_admin_alert");

		if (!$ffff_admin_alert) {
			$ffff_admin_alert = array($alert);
		} else {
			$ffff_admin_alert[] = $alert;
		}
		update_option("ffff_admin_alert", $ffff_admin_alert);
	}

	/**
	 * Exclude all Fresh From posts whenever the FriendFeedBot is around
	 *
	 */	
	function exclude_fresh_posts($where) {
		$prefix = $GLOBALS["wpdb"]->prefix;
		$where .= " AND ID NOT IN (SELECT post_id FROM {$prefix}postmeta WHERE meta_key='FreshFrom') ";
		return $where;	
	}
	
	/**
	 * Setup some filters for use during the posts loop
	 *
	 */
	function loop_start() {
		$this->timelog("Loop start");
		$this->is_looping = true;
		add_filter("the_permalink", array($this, "the_permalink")); // requires 1.5
		add_filter("the_permalink_rss", array($this, "the_permalink")); // requires 2.3 but Fresh From degrades nicely without it, so does not impact minimum version
		if ($GLOBALS["wp_version"] >= "2.1") add_filter("the_title", array($this, "the_title")); // requires 1.2.1 but 2.0.x not very happy
		add_filter("the_content", array($this, "the_plug")); // requires 1.2.1
		add_filter("the_author", array($this, "the_author")); // requires 2.0
		add_filter("the_category", array($this, "the_category")); // requires 1.2.1
		add_filter("comments_number", array($this, "comments_number"), 10, 2); // requires 1.5
	}
	
	/**
	 * Setup some filters for use during the posts loop
	 *
	 */
	function loop_end() {
		$this->timelog("Loop end");
		$this->is_looping = false;
	}

	/**
	 * Add an option to the Admin Menu
	 *
	 */
	function admin_menu() {
		add_options_page("Fresh From", "Fresh From", 10, __FILE__, array($this, "admin_page"));	 
	}

	/**
	 * Get data from remote API or cache if API is down
	 *
	 */
	function ffff_curl($url, $cache_key=null) {
		$this->timelog("CURL: " . $url);

		// get external data
		$curl_handle = curl_init();
		curl_setopt($curl_handle, CURLOPT_URL, $url);
		curl_setopt($curl_handle, CURLOPT_ENCODING, "");
		curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, TRUE);
		$data = curl_exec($curl_handle);
		$http_code = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);
		curl_close($curl_handle);
		
		// update admin status page
		if (strstr($url, "friendfeed.com")) $api = "friendfeed";
		elseif (strstr($url, "twitter.com")) $api = "twitter";
		if (isset($api)) {
			update_option("ffff_api_{$api}_timestamp", time());
			$status = $data ? 1 : 0;
			update_option("ffff_api_{$api}_status", $status);
		}
		
		if ($cache_key) {
			// API down? return cached version
			if (!$status) {
				$data = get_option("ffff_cache_{$this->service}_{$cache_key}");
			} else {
				update_option("ffff_cache_{$this->service}_{$cache_key}", $data);
			}
		}
				
		return $data;
	}
	
	/**
	 * Import some Fresh posts
	 *
	 * Action for 'pre_get_posts' hook
	 *
	 * @return
	 */
	function pre_get_posts() {

		if (!isset($_GET["ffff_refresh"])) {		
			// check cache expiry
			$ffff_posts_expire = get_option("ffff_posts_expire");
			if ($ffff_posts_expire && time() < $ffff_posts_expire) return;
		}

		$this->timelog("Checking for fresher content");
		
		// array of wp id => external id
		$old_ffff_posts = get_option("ffff_posts");
		if (!$old_ffff_posts) {
			$old_ffff_posts = array();
			$old_posts_deleted = array();
		} else {
			// have any posts been deleted?
			$prefix = $GLOBALS["wpdb"]->prefix;
			$posts_exist = $GLOBALS["wpdb"]->get_results("SELECT ID FROM {$prefix}posts WHERE ID IN (" . implode(",", array_keys($old_ffff_posts)) . ")");
			foreach ($posts_exist AS &$post) $post = $post->ID;
			$old_posts_deleted = array_diff(array_keys($old_ffff_posts), $posts_exist);
			foreach ($old_posts_deleted AS &$post) $post = $old_ffff_posts[$post];
		}
		
		// stop date is recent post / days ago, e.g. 2009-01-06 13:05:33
		$ffff_freshness = get_option("ffff_freshness");
		if ($ffff_freshness == "recent") {
			$stop_date = $this->get_recent_post_date();

		} else {
			$ffff_freshness_days = get_option("ffff_freshness_days");
			$stop_date = date("Y-m-d H:i:s", time() - 86400*$ffff_freshness_days);
		}
		
		// get an array of WordPress post objects
		$new_posts = $this->get_posts($stop_date);
		
		// update expiry
		update_option("ffff_posts_expire", time()+$this->ttl);
		
		// safety net: trap bad API response
		if (!is_array($new_posts)) return;
		
		// sort posts by magic formulae (Twitter is mostly by date, FriendFeed more interesting)
		uasort($new_posts, array($this, "sort_posts"));
		 
		// limit to total_posts
		$total_posts = get_option("ffff_total_posts");

		// build array to replace old_ffff_posts
		$ffff_posts = array();
		
		foreach ($new_posts AS $post) {
			$external_id = $post->meta["_ffff_external_id"];
		
			// check mix
			$service_name = $post->meta["_ffff_service_name"];
			if (!$this->services[$service_name]["mix"]) continue;
			
			// existing post
			if (in_array($external_id, $old_ffff_posts)) {
				$wp_id = array_search($external_id, $old_ffff_posts);		
			} else {
				
				// enhance content here, so video can trump thumbnails
				$post->post_content = $this->transform_content($post);

				// do not import if this has previously been edited / decoupled?
				$prefix = $GLOBALS["wpdb"]->prefix;
				$post_decoupled = $GLOBALS["wpdb"]->get_results("SELECT post_id FROM {$prefix}postmeta WHERE meta_key='_ffff_external_id' AND meta_value='{$external_id}'");
				if (is_array($post_decoupled) && count($post_decoupled)) {
					$this->timelog("Skipped post with external id={$external_id}");
					continue;
				}
				
				// add post
				$post->post_content = addslashes($post->post_content); // for Wordpress 2.0.x database insert
				$wp_id = wp_insert_post($post);
				$this->timelog("Added post {$wp_id}");
				
				// add meta data			
				if (isset($post->meta)) {
					foreach ($post->meta AS $key=>$value) {
						add_post_meta($wp_id, $key, $value);
					}
				}
				
				// add instructions
				add_post_meta($wp_id, "FreshFrom", _ffff_instructions);
			}

			$ffff_posts[$wp_id] = $external_id;

			// keep going until we have total_posts not exceeding the desired mixture, and excluding anything that has been manually deleted
			if (!in_array($external_id, $old_posts_deleted)) {
				$this->services[$service_name]["mix"]--;

				$total_posts--;
				if (!$total_posts) break;
			}
		}	
		
		$this->timelog("Found " . count($ffff_posts) . " posts");
		
		// remove any old posts
		$remove_posts = array_diff($old_ffff_posts, $ffff_posts);
		foreach ($remove_posts AS $external_id) {
			$wp_id = array_search($external_id, $old_ffff_posts);

			// do not delete if meta["FreshFrom"]
			if (get_post_meta($wp_id, "FreshFrom", true)) {
				$this->timelog("Deleting post {$wp_id}");
				wp_delete_post($wp_id);
			}
		}	
		
		update_option("ffff_posts", $ffff_posts);
	}

	// generic 
	function get_post($post_date) {
		$obj = new stdClass();
		$obj->post_author = 1; // default author 
		$obj->post_date = $post_date;
		$obj->post_date_gmt = $post_date;
		$obj->post_category = 0;
		$obj->post_excerpt = "";
		$obj->post_status = "publish";
		$obj->comment_status = "";
		$obj->ping_status = "";
		$obj->post_password = "";
		$obj->post_name = "";
		$obj->to_ping = "";
		$obj->pinged = "";
		$obj->post_modified = $post_date;
		$obj->post_modified_gmt = $post_date;
		$obj->post_content_filtered = "";
		$obj->post_parent = 0;
		$obj->menu_order = 0;
		$obj->post_type = "post";
		$obj->post_mime_type = "";
		$obj->media_content = "";
		return $obj;
	}
	
	
	/**
	 * Get a Twitter profile pic and cache it
	 *
	 */
	function get_twitter_pic($username) {
		$twitter_pics = get_option("ffff_twitter_pics");
		
		// cache hit?
		if (isset($twitter_pics[$username])) {
			if (time() < $twitter_pics[$username]["expiry"]) return $twitter_pics[$username]["url"];
		}
		
		// miss
		$url = "http://twitter.com/users/show/{$username}.xml";
		$data = $this->ffff_curl($url);
		if ($data) {
			$xml = simplexml_load_string($data);
			if ($xml && isset($xml->profile_image_url)) {
				$profile_image_url = (string) $xml->profile_image_url;
				$twitter_pics[$username] = array("expiry"=>time()+_ffff_twitter_pics_ttl, "url"=>$profile_image_url);
				update_option("ffff_twitter_pics", $twitter_pics);
				return $profile_image_url;
			}
		}
	}
	
	/**
	 * Get the most recent post date, excluding Fresh posts
	 *
	 */
	function get_recent_post_date() {
		$prefix = $GLOBALS["wpdb"]->prefix;
		$posts = $GLOBALS["wpdb"]->get_results("SELECT post_date FROM {$prefix}posts WHERE post_type='post' AND post_status='publish' 
			AND ID NOT IN (SELECT post_id FROM {$prefix}postmeta WHERE meta_key='FreshFrom')
			ORDER BY post_date DESC LIMIT 1");
		if (isset($posts[0]) && $posts[0]->post_date) return $posts[0]->post_date;
	}
	
	/**
	 * uasort function to order WordPress post objects - default is by post date, FriendFeed does something more interesting
	 *
	 * @param object $a WordPress post object
	 * @param object $b WordPress post object
	 * @return
	 */
	function sort_posts($a, $b) {
		return strcmp($b->post_date, $a->post_date);
	}	
	
	function _make_url_clickable_cb($matches) {
		$ret = '';
		$url = $matches[2];
		$url = clean_url($url);
		if ( empty($url) ) return $matches[0];
		// removed trailing [.,;:] from URL
		if ( in_array(substr($url, -1), array('.', ',', ';', ':')) === true ) {
			$ret = substr($url, -1);
			$url = substr($url, 0, strlen($url)-1);
		}
		return $matches[1] . "<a href=\"$url\">$url</a>" . $ret;
	}

	/**
	 * Subset of wp2.6.5 function, and we don't need wp2.6.5
	 *
	 */
	function make_clickable($ret) {
		$ret = ' ' . $ret;
		// in testing, using arrays here was found to be faster
		$ret = preg_replace_callback('#([\s>])([\w]+?://[\w\#$%&~/.\-;:=,?@\[\]+]*)#is', array($this, '_make_url_clickable_cb'), $ret);
		
		// this one is not in an array because we need it to run last, for cleanup of accidental links within links
		$ret = preg_replace("#(<a( [^>]+?>|>))<a [^>]+?>([^>]+?)</a></a>#i", "$1$3</a>", $ret);
		$ret = trim($ret);
		return $ret;
	}
	
	/**
	 * Add some filters to enhance content
	 *
	 */
	function transform_content(&$post) {
	
		$content = $post->post_content;
	
		// make links clickable if they aren't already
		$content = $this->make_clickable($content);
		
		// transform @hitching into a link to a Twitter profile page
		$content = preg_replace("/@([A-Za-z0-9_]+) /", '@<a href="http://twitter.com/$1">$1</a> ', $content);
		
		// and #tags into a search.Twitter link
		$content = preg_replace("/#([A-Za-z0-9_]+) /", '#<a href="http://search.twitter.com/search?q=%23$1">$1</a> ', $content);
		
		// get URLs; include media content in case there's any linked thumbnails in there
		preg_match_all("/a[\s]+[^>]*?href[\s]?=[\s\"\']+(.*?)[\"\']+.*?>([^<]+|.*?)?<\/a>/", $content . " " . $post->media_content, $matches);
		$urls = $matches[1];
		
		// follow shortened URLs
		if (get_option("ffff_redirect")) {
			$ffff_redirect_host_array = explode(",", str_replace(" ", "", get_option("ffff_redirect_hosts")));
		
			foreach ($urls AS $url) {
				if (in_array(parse_url($url, PHP_URL_HOST), $ffff_redirect_host_array)) {
					
					// just get the redirect header, not the whole page
					$ch = curl_init();
					curl_setopt($ch, CURLOPT_URL, $url);
					curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
					curl_setopt($ch, CURLOPT_HEADER, true);
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
					$headers = curl_exec($ch);
		
					if ($headers) {
						foreach (split("\n", $headers) AS $header) {
							if (strpos($header, "Location:") === 0) {
								$redirected_url = substr($header, 10, -1);
								$content = str_replace(">{$url}<", " title=\"{$redirected_url}\">{$url}<", $content);
								// add redirected url to list of those we scan for youtube and twitpic
								$urls[] = $redirected_url;
							}
						}
					}
				}
			}
		}
		
		// add media content
		$ffff_twitpic = get_option("ffff_twitpic");
		$ffff_youtube = get_option("ffff_youtube");
		foreach ($urls AS $url) {
			if ($ffff_twitpic && strpos(parse_url($url, PHP_URL_HOST), "twitpic.com") !== false) {
				if ($twitpic = file_get_contents($url)) {
					preg_match("/\"http:\/\/s3.amazonaws.com\/twitpic\/photos\/large.*?\"/", $twitpic, $matches);
					if (count($matches)) $post->media_content = "<img src={$matches[0]} width=\"400\" />";
				}
			}
			
			if ($ffff_youtube && strpos(parse_url($url, PHP_URL_HOST), "youtube.com") !== false) {
				$youtube_query = parse_url($url, PHP_URL_QUERY);
				parse_str($youtube_query, $youtube_params);
				if ($youtube_params["v"]) {
					$post->media_content = <<<EOF
<object width="480" height="295"><param name="movie" value="http://www.youtube.com/v/{$youtube_params["v"]}&hl=en&fs=1"></param><param name="allowFullScreen" value="true"></param><param name="allowscriptaccess" value="always"></param><embed src="http://www.youtube.com/v/{$youtube_params["v"]}&hl=en&fs=1" type="application/x-shockwave-flash" allowscriptaccess="always" allowfullscreen="true" width="480" height="295"></embed></object>
EOF;
				}				
			}
			
			// please submit a feature request if you want to see other content enhancements!
		}
		
		// show the bestest enhancement - video scores over thumbnail
		if ($post->media_content) {
			// media content
			if (strpos($post->post_content, _ffff_media_token) !== false) {
				$content = str_replace(_ffff_media_token, "<br/><br/>" . $post->media_content, $post->post_content);
			} else {
				$content .= "<br/><br/>" . $post->media_content;
			}
		}			
		
		return $content;
	}
	
	/**
	 * Filter to add service to Fresh titles
	 *
	 */
	function the_title($title) {
		$custom_fields = get_post_custom();
		if ($this->is_looping && isset($custom_fields["FreshFrom"])) {
			$title = "<img src=\"" . $custom_fields["_ffff_iconUrl"][0] . "\" align=\"baseline\" /> " . $title;
		}
		return $title;
	}
	
	/**
	 * Filter to add a Fresh Feed plug to the About page and some stats
	 *
	 */
	function the_plug($content) {
		// add a plug to the About page
		if (is_page("2")) {
			$content .= "<p>This blog is kept fresh by the <a href=\"" . _ffff_freshfrom_url . "\">Fresh From FriendFeed and Twitter</a> plugin.</p>";
		}
		
		// insert stats image once per page so plugin usage can be tracked
		$custom_fields = get_post_custom();
		if (isset($custom_fields["FreshFrom"])) {
			if (!isset($GLOBALS["ffff_plugged"])) {
				$domain = parse_url(get_option("siteurl"), PHP_URL_HOST);
				
				$stats = _ffff_freshfrom_url . "/stats";
				$stats .= "?siteurl=" . urlencode(get_option("siteurl"));
				$stats .= "&wpv=" . $GLOBALS["wp_version"];
				$stats .= "&ffv=" . _ffff_version;
				$stats .= "&ts=" . microtime(true);
		
				$content .= "<img src=\"{$stats}\" width=\"1\" height=\"1\" />";			
				$GLOBALS["ffff_plugged"] = true;
			}
		}
		return $content;
	}
	
	/**
	 * Filter to display the author for Fresh posts
	 *
	 */
	function the_author($author) {
		$custom_fields = get_post_custom();
		if ($this->is_looping && isset($custom_fields["FreshFrom"])) {
			$author = $custom_fields["_ffff_author"][0]; 	
		}
		return $author;
	}
	
	/**
	 * Filter to display an external service link instead of a category link
	 *
	 */
	function the_category($category) {
		$custom_fields = get_post_custom();
		
		if ($this->is_looping && isset($custom_fields["FreshFrom"])) {
			$url = $custom_fields["_ffff_profileUrl"][0];
			$name = $custom_fields["_ffff_service_name"][0]; 
			// correct FriendFeed url
			if ($name == "FriendFeed" || $name == _ffff_comments_label) { 
				$url = "http://friendfeed.com/" . get_option('ffff_friendfeed_username');
				$name = "FriendFeed";
			}
			$category = "<a href=\"{$url}\">{$name}</a>";
		}

		return $category;
	}
	
	/**
	 * Get an array of Fresh From status information
	 *
	 */
	function get_status() {
		$status = array();
		$status["PHP"] = array('info', "Version: " . phpversion());
		$status["MySQL"] = array('info', function_exists('mysql_get_client_info') ? "Version: " . mysql_get_client_info() : 'Not found. Very strange, as WordPress requires MySQL.');
		$status["WordPress"] = array('info', "Version: " . $GLOBALS["wp_version"]);
		$status["Fresh From"] = array('info', "Version: " . _ffff_version);

		if (extension_loaded('suhosin')) {
			$status["Libcurl"] = array(false, 'Hardened php (suhosin) extension active -- curl version checking skipped.');
		} else {
			$curl_message = '';
			if (function_exists('curl_version')) {
				$curl_version = curl_version();
				if (isset($curl_version['version'])) $curl_message = "Version: " . $curl_version['version'];
			} else {
				$curl_message =	"This PHP installation does not have support for libcurl which is required by the Fresh From plugin.";
			}
			$status["Libcurl"] = array(isset($curl_version), $curl_message);
		}
		
		// API status
		if ($this->service) {
			$timestamp = get_option("ffff_api_{$this->service}_timestamp");
			$api_status = get_option("ffff_api_{$this->service}_status");
		
			$api_message = "This plugin uses the {$this->service} API to import Fresh content into your blog. You do not need to enter any external passwords for the plugin to work.";
		
			if ($timestamp) {
				$ago = number_format(time() - $timestamp);			
				if ($api_status) {
					$api_message .= " The API responded normally <strong>" . $ago . "</strong> seconds ago. API responses are cached for <strong>{$this->ttl}</strong> seconds to speed the display of Fresh content, and to respect the API limits imposed by {$this->service}.";
				} else {
					$api_message .= " The API failed to respond <strong>" . $ago . "</strong> seconds ago. But that's okay, because cached content will be used until the API recovers.";
				}
				$status[$this->service] = array($api_status == 1, $api_message);
			}
		}	
		
		return $status;
	}

	/**
	 * Get an array of Fresh From options for debug
	 *
	 */
	function get_ffff_options() {
		$prefix = $GLOBALS["wpdb"]->prefix;
		$ffff_options = $GLOBALS["wpdb"]->get_results("SELECT option_name, LEFT(option_value, 1024) AS option_value FROM {$prefix}options WHERE option_name LIKE 'ffff_%' OR option_name IN ('siteurl','active_plugins','current_theme')");
		return $ffff_options;
	}
	
	/**
	 * Display and handle Admin settings
	 *
	 */
	function admin_page() {

		// save changes
		if (isset($_POST['ffff_total_posts'])) {

			update_option("ffff_total_posts", $_POST["ffff_total_posts"]);

			// update mix - scan for ffff_mix0 and ffff_service0
			if (count($this->services) > 1) {
				foreach ($_POST AS $key=>$value) {
					if (strpos($key, "ffff_mix_{$this->service}_{$this->username}") === 0) {
						$service_id = array_pop(explode("_", $key));
						$service_name = $_POST["ffff_service_" . $service_id];
						$this->services[$service_name]["mix"] = $value;
					}
				}
				update_option("ffff_friendfeed_services", $this->services);
			}
			
			// stop date
			update_option("ffff_freshness", $_POST["ffff_freshness"]);
			update_option("ffff_freshness_days", $_POST["ffff_freshness_days"]);
			
			// advanced options
			update_option("ffff_redirect", $_POST["ffff_redirect"]);
			update_option("ffff_redirect_hosts", $_POST["ffff_redirect_hosts"]);
			update_option("ffff_twitpic", $_POST["ffff_twitpic"]);
			update_option("ffff_youtube", $_POST["ffff_youtube"]);

			$this->admin_alert("Settings have been saved.");
		}

		// status
		$ffff_status = 1;
		$status_details = $this->get_status();
		foreach ($status_details AS $status_item) {
			if (!$status_item[0]) $ffff_status = 0;
		}
		update_option("ffff_status", $ffff_status);
		$status = "<p>Status: ";
		if ($ffff_status) {
			$status .= " <strong>All good!</strong> <small>(<a href=\"javascript:void(null);\" onClick=\"document.getElementById('ffff_status_details').style.display='block';return false;\">See details</a>)</small></p>";
			$status_detail_display = "none";
		} else {
			$status .= " <span style='color:red;font-weight:bold;'>FAIL!</span> There are problems with your installation of Fresh From:</p>";
			$status_detail_display = "block";
		}
		$status .= "<div id=\"ffff_status_details\" style=\"display: {$status_detail_display};\"><table><tbody>";
		foreach ($status_details AS $status_key=>$status_item) {
			if ($status_item[0] === false) {
				$light = "<span style='color:red;'>[FAIL]</span>";
			} elseif ($status_item[0] === true) {
				$light = "<span style='color:green;'>[OK]</span>";
			} else {
				$light = "<span style='color:grey;'>[INFO]</span>";
			}	
			$status .= "<tr><td align=\"center\"><strong>{$light}</strong></td><td width=\"120\"><strong>" . $status_key . "</strong></td><td>" . $status_item[1] . "</td></tr>\n";
		}
		$status .= "</tbody></table></div>";

		// send debug report
		$admin_email = get_option("admin_email");
		if (isset($_POST['debug_report'])) {
			$report = "";
			if (isset($_POST["debug_comment"])) $report .= $_POST["debug_comment"] . "\n\n";
			
			$report .= "Fresh From Status: " . print_r($status_details, true);

			$ffff_options = $this->get_ffff_options();
			$report .= "\nFresh From Settings: " . print_r($ffff_options, true);
			
			$headers = "From: {$admin_email}";
			if (isset($_POST["debug_cc_admin"])) $headers .= "\r\nCc: {$admin_email}";
			
			// send mail
			mail(_ffff_debug_email, "Fresh From Debug Report", $report, $headers, "-f{$admin_email}");

			$this->admin_alert("Debug Report has been sent.");
		}
		
		// service picker
		$checked = array("friendfeed"=>"", "twitter"=>"");
		$onclick = array("friendfeed"=>'onClick="this.form.submit();"', "twitter"=>'onClick="this.form.submit();"');
		if ($this->service) {
			$checked[$this->service] = 'checked="checked" ';
			$onclick[$this->service] = "";
		}

		// banner
		if ($this->service == "friendfeed") {
			$banner = '<img src="http://friendfeed.com/static/images/logo-api.png" width="160" height="34" alt="FriendFeed" style="padding:0; border:0; margin:0"/>';
			$password_label = "Remote key:";
		} elseif ($this->service == "twitter") {
			$banner = '<img src="http://assets1.twitter.com/images/twitter_logo_s.png" alt="Twitter" style="padding:0; border:0; margin:0"/>';
			$password_label = "Password:";
		}
		
		// total posts
		$ffff_total_posts = get_option("ffff_total_posts");
		$total_posts = "<select name=\"ffff_total_posts\">";
		for ($i = 1; $i <= 10; $i++) {
			$total_posts .= "<option value=\"{$i}\"" . ($i == $ffff_total_posts ? " selected" : "") . ">{$i}</option>";
		}
		$total_posts .= "</select>";
		
		// friendfeed mixer
		$mixers = "";
		if (count($this->services) > 1) {
			$mixers .= "<tr><td colspan=2 style=\"padding: 5px\"><u>Maximum posts per Service</u></td></tr>\n";
			
			$service_id = 0;
			foreach ($this->services AS $service_name=>$service) {
				$selector = "<select name=\"ffff_mix_{$this->service}_{$this->username}_{$service_id}\">";
				foreach (array("None", 1, 2, 3, 4, 5) AS $val=>$label) {
					$selector .= "<option value=\"$val\"" . ($val == $service["mix"] ? " selected" : "") . ">{$label}</option>\n";
				}
				$selector .= "</select>";
					
				$mixers .= "<tr><td style=\"padding: 5px\" nowrap><img src=\"{$service["iconUrl"]}\" /> {$service_name}</td>\n";
				$mixers .= "<td style=\"padding: 5px\">{$selector}<input type=\"hidden\" name=\"ffff_service_{$service_id}\" value=\"{$service_name}\"></td></tr>\n";
				
				$service_id++;
			}
		}
		
		// stop date
		$ffff_freshness = get_option("ffff_freshness");
		$ffff_freshness_days = get_option("ffff_freshness_days");
		$stop_date = $this->get_recent_post_date();
		$freshness_control = "<p><input type=\"radio\" name=\"ffff_freshness\" value=\"recent\" " . ($ffff_freshness == "recent" ? 'checked="checked"' : "") . "/> Show Fresh content since my latest post";
		if ($stop_date) $freshness_control .= " (currently {$stop_date})</p>";
		$freshness_control .= "<p><input type=\"radio\" name=\"ffff_freshness\" value=\"days\" " . ($ffff_freshness == "days" ? 'checked="checked"' : "") . "/> Show Fresh content from the last <select name=\"ffff_freshness_days\">";
		for ($i = 1; $i <= 10; $i++) {
			$freshness_control .= "<option value=\"{$i}\"" . ($i == $ffff_freshness_days ? " selected" : "") . ">{$i}</option>\n";
		}
		$freshness_control .= "</select> days</p>";

		// twitpic control
		$ffff_twitpic = get_option("ffff_twitpic");
		$twitpic_control = "<input type=\"checkbox\" name=\"ffff_twitpic\" " . ($ffff_twitpic ? "checked=\"checked\" " : "") . "/>";

		$ffff_youtube = get_option("ffff_youtube");
		$youtube_control = "<input type=\"checkbox\" name=\"ffff_youtube\" " . ($ffff_youtube ? "checked=\"checked\" " : "") . "/>";	
		
		// redirect controls
		$ffff_redirect = get_option("ffff_redirect");
		$ffff_redirect_hosts = get_option("ffff_redirect_hosts");
		$redirect_control = "<input type=\"checkbox\" name=\"ffff_redirect\" " . ($ffff_redirect ? "checked=\"checked\" " : "") . "/>";
		$redirect_host_control = "<input type=\"text\" name=\"ffff_redirect_hosts\" value=\"{$ffff_redirect_hosts}\" style=\"width:300px;\" />";

		// help!
		$support_room = _ffff_support_room;

		// display alerts
		$alerts = "";
		$ffff_admin_alert = get_option("ffff_admin_alert");
		if ($ffff_admin_alert) {
			$alerts = "<p><strong><ul><li>" . implode("</li>\n<li>", $ffff_admin_alert) . "</li></ul></strong></p>\n";
			
			// wipe alerts
			delete_option("ffff_admin_alert");
		}
		
		echo <<<EOF
<div class="wrap">
<div id="icon-options-general" class="icon32"><br/></div>
<h2>{$this->admin_title}</h2>
<div class="updated">
{$alerts}
{$status}
</div>
<form method="post" name="options">
<p><table><tr><td>Show content Fresh From:</td><td width="40" align="right"><input type="radio" {$checked["friendfeed"]} value="friendfeed" name="ffff_service" id="ffff_service_friendfeed" {$onclick["friendfeed"]} /></td>
<td width="120"><label for="ffff_service_friendfeed"><img src="http://friendfeed.com/static/images/icons/internal.png" align=\"baseline\" /> FriendFeed</label></td></tr>
<tr><td></td><td width="40" align="right"><input type="radio" {$checked["twitter"]} value="twitter" name="ffff_service" id="ffff_service_twitter" {$onclick["twitter"]} /></td>
<td width="120"><label for="ffff_service_twitter"><img src="http://friendfeed.com/static/images/icons/twitter.png" align=\"baseline\" /> Twitter</label></td></tr>
</table></p>
<table><tr><td valign="top">
EOF;

		if ($this->service) echo <<<EOF
<p><table style="border-collapse: collapse; border-spacing: 0; padding: 0; margin: 0; font-family: Arial, sans-serif; border: 4px solid #6797d3; color: #222222">
<tr>
  <td style="background-color: #ecf2fa; padding: 3px; padding-left: 5px; padding-top: 5px; border: 0; border-bottom: 1px solid #6797d3"><img src="{$this->banner}" alt="{$this->service}" style="padding:0; border:0; margin:0"/></td>
</tr>
<tr>
  <td style="background-color: white; padding: 15px; border: 0" colspan="2">
	<table style="border-collapse: collapse; border-spacing: 0; border: 0; padding: 0; margin: 0">
  <tr>
	<td style="padding: 5px">Username:</td>
	<td style="padding: 5px"><input type="text" name="ffff_{$this->service}_username" value="{$this->username}" style="width:120px;" /></td>
  </tr>
  <tr>
	<td style="padding: 5px">{$this->password_label}</td>
	<td style="padding: 5px">Not required</td>
  </tr>
  
   <tr>
	<td style="padding: 5px">Total posts:</td>
	<td style="padding: 5px">{$total_posts}</td>
  </tr> 
{$mixers}
</table>
  </td>
</tr>
</table></p><br/>

</td><td width=10></td><td valign="top">

<h3>Content Expiry</h3>
{$freshness_control}

<h3>Content Enhancement</h3>
<p>{$redirect_control} Show full URL hints for these URL shorteners: {$redirect_host_control}</p>
<p>{$twitpic_control} Show images from Twitpic links</p>
<p>{$youtube_control} Show videos from Youtube links</p>
EOF;

		echo <<<EOF
<p class="submit">
<input type="submit" name="Submit" value="Update Options &raquo;" />
</p>

</form>
<hr/>

<h4>Help!</h4>
<p>Please visit the <a href="{$support_room}" target=_blank>Support Room</a> for help and support and FAQs, and to request features.</p>
<p>If Bob asks you to submit a Debug Report for diagnosis, please enter some details here: 
<form method="post" name="options">
Comments: <input type="text" name="debug_comment" /> <input type="checkbox" name="debug_cc_admin" id="debug_cc_admin" /> <label for="debug_cc_admin">cc: {$admin_email}</label> <input type="submit" name="debug_report" value="Send &raquo;" />
</form>

</td></tr></table>

</div>
EOF;
	
	}
	
	// timer array stores timestamps to calculate mysql execution times, and error/warning/notice messages
	//
	function timelog($comment) {
		$this->debug_timelog[] = array(round(microtime(true) - $_SERVER["REQUEST_TIME"], 4), $comment);
	}

	// return table of mysql execution times and variable context for tuning, debugging and error reporting
	//
	function debug_info() {
		$rv = "<h3>Debug :: Script Timelog</h3>\n<table border=1 cellpadding=3>";
		foreach ($this->debug_timelog AS $event) {
			if (isset($prior)) {
				$duration = round((float) $event[0] - (float) $prior, 4);
				$rv .= "<td" . ($duration > 0.05 ? " style=\"background:red;color:white;\"" : "") . ">{$duration}</td></tr>\n";
			}
			if ($event[1] == "done") unset($prior);
			else {
				$rv .= "<tr><td>{$event[0]}</td><td>{$event[1]}</td>";				
				$prior = $event[0];
			}
		}
		$rv .= "</tr></table>\n";
		$rv .= "<h3>Debug :: Wordpress Options</h3>\n<pre>" . print_r($this->get_ffff_options(), true) . "</pre>\n";
		$rv .= "<h3>Debug :: Script Environment</h3>\n<pre>" . print_r(array("_GET"=>$_GET, "_POST"=>$_POST, "_COOKIE"=>$_COOKIE, "_SERVER"=>$_SERVER), true) . "</pre>\n";
		echo $rv;
	}
}