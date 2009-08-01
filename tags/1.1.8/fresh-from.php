<?php
/*
Plugin Name: Fresh From FriendFeed and Twitter
Plugin URI: http://wordpress.org/extend/plugins/fresh-from-friendfeed-and-twitter/
Description: Keeps your blog always fresh by regularly adding your latest and greatest content from FriendFeed or Twitter. Content is imported as normal blog posts that you can edit and keep if you want. No external passwords required.
Version: 1.1.8
Author: Bob Hitching
Author URI: http://hitching.net/fresh-from-friendfeed-and-twitter

Copyright (c) 2009 Bob Hitching (bob@hitching.net)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

define("_ffff_version", "1.1.8");
define("_ffff_debug", false);
define("_ffff_debug_email", "bob@hitching.net");
define("_ffff_friendfeed_bot", "FriendFeedBot"); // user agent of Friendfeed Bot - so we can hide Fresh posts and avoid crashing the internet with an infinite loop
define("_ffff_support_room", "http://friendfeed.com/rooms/fresh-from-friendfeed-and-twitter"); // where to go for help and discussion
define("_ffff_freshfrom_url", "http://hitching.net/fresh-from-friendfeed-and-twitter"); // base url used for stats and plug
define("_ffff_admin_page", "fresh-from-friendfeed-and-twitter");
define("_ffff_media_token", "<!-- media_content -->"); // token dropped into content to be replaced with thumbnail, video, etc.
define("_ffff_history", 2592000); // never go back further than 30 days
define("_ffff_unlimited", -1);
define("_ffff_busy_ttl", 60); // stop any other requests during curl or processing for this duration,
define("_ffff_friendfeed_ttl", 300); // don't hammer the friendfeed API
define("_ffff_twitter_ttl", 300); // don't hammer the Twitter API
define("_ffff_twitter_pics_ttl", 86400); // cache twitter profile pics for 24 hours
define("_ffff_max_posts", 50); 

// the following are magic sauce ingredients, used to work out which FriendFeed entries are best for import
define("_ffff_weight_recency", 30); 
define("_ffff_weight_comments", 20); // can be doubled
define("_ffff_weight_likes", 10); // can be doubled
define("_ffff_weight_media", 10);
define("_ffff_lang_domain", "fresh-from-friendfeed-and-twitter");

// PHP4 compatible XML powered by Taha Paksu
if (!function_exists("simplexml_load_string")) {
	require_once("simplexml.class.php");
	function simplexml_load_string($data) {
		$var = new simplexml;
		return $var->xml_load_data($data);
	}
}
// and PHP4 compatible JSON powered by Cesar D. Rodas
require_once("json.class.php");

// start the plugin
$ffff = new freshfrom();
// that's all folks, nothing more to see here, move along please

/**
 * Main handler
 *
 */
class freshfrom {

	// for debugging
	var $start_ts;

	// set to true during post loop; some filters need to know this context
	var $is_looping = false;
	
	// style for admin page
	var $admin_style = 21;
	
	function freshfrom() {
		$this->start_ts = time();
	
		// setup L10N
		$plugin_dir = basename(dirname(__FILE__));
		load_plugin_textdomain(_ffff_lang_domain, 'wp-content/plugins/' . $plugin_dir, $plugin_dir);

		// reset on new install or upgrade
		$ffff_version = get_option("ffff_version");
		if (!$ffff_version || version_compare($ffff_version, "1.1", "<")) {
			$this->reset();
		} elseif ($ffff_version != _ffff_version) {
			update_option("ffff_version", _ffff_version);
		}
		
		// admin layout
		if (version_compare($GLOBALS["wp_version"], "2.7", ">=")) {
			$this->admin_style = 27;
		}

		$this->setup_hooks();
	}
	
	/**
	 * Delete all Fresh From posts, reset some settings and generally start over again
	 * 
	 */
	function reset() {
		$this->timelog("Reset...");	

		// feeds, services, users
		delete_option("ffff_feed_data");
		add_option("ffff_feed_data", array(), '', 'no'); // API responses are kept here, but are not loaded on every page

		update_option("ffff_feeds", array());
		update_option("ffff_feed_key", 0);
		update_option("ffff_friendfeed_services", array());
		update_option("ffff_users", array());
		update_option("ffff_deleted", array());
		update_option("ffff_decoupled", array());
		update_option("ffff_twitter_pics", array());
		
		// import
		update_option("ffff_mode", "kif");
		update_option("ffff_total_kif", 10);
		update_option("ffff_total_kic", 5);
		update_option("ffff_kic_period", "day");
		update_option("ffff_filter", "");
		update_option("ffff_keyword", "#blog");
		update_option("ffff_twitter_noreplies", "");
		update_option("ffff_digest", "on");
		update_option("ffff_digest_type", "service");
		update_option("ffff_pubstatus", "publish");
		
		// content enhancement
		update_option("ffff_profile", 1);
		update_option("ffff_redirect", 1);
		update_option("ffff_redirect_hosts", "bit.ly, cli.gs, ff.im, is.gd, tinyurl.com");
		update_option("ffff_twitpic", 1);
		update_option("ffff_youtube", 1);
		update_option("ffff_rss", "");
		// 1.1.7 prefix options
		update_option("ffff_prefix", "");
		update_option("ffff_prefix_string", __("Fresh From", _ffff_lang_domain) . " %s");
		
		// update version number
		update_option("ffff_version", _ffff_version);
		
		// delete some settings from previous versions
		$delete_these_options = array(
			"ffff_api_twitter_timestamp", 
			"ffff_api_twitter_status", 
			"ffff_api_friendfeed_timestamp", 
			"ffff_api_friendfeed_status",
			"ffff_total_posts", 
			"ffff_freshness", 
			"ffff_freshness_days", 
			"ffff_service", 
			"ffff_friendfeed_nicknames", 
			"ffff_friendfeed_users", 
			"ffff_plug", 
			"ffff_friendfeed_username", 
			"ffff_twitter_username", 
			"ffff_cache_friendfeed_user", 
			"ffff_cache_friendfeed_comments", 
			"ffff_posts_expire", 
			"ffff_posts", 
			"ffff_titleicon");
		foreach ($delete_these_options AS $option) delete_option($option);
				
		$this->delete_all_freshfrom_posts();

		// tidy the database
		global $wpdb;
		$wpdb->get_results("OPTIMIZE TABLE {$wpdb->posts}");
		$wpdb->get_results("OPTIMIZE TABLE {$wpdb->postmeta}");
		
		// delete old class.php files
		$delete_these_files = array("fresh-from-friendfeed.php", "fresh-from-twitter.php");
		foreach ($delete_these_files AS $file) if (file_exists(dirname(__FILE__) . "/" . $file)) unlink(dirname(__FILE__) . "/" . $file);
		
		// guess usernames
		update_option("ffff_guess_friendfeed_username", "");
		update_option("ffff_guess_twitter_username", "");
		$this->detect_friendfeed_username();
		$this->detect_twitter_username();
		
		update_option("ffff_version", _ffff_version);
	}
	
	/**
	 * Delete all imported posts and related metadata
	 * 
	 */
	function delete_all_freshfrom_posts() {
		global $wpdb;
		$posts = $wpdb->get_results("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='FreshFrom' AND post_id<>0");
		if (is_array($posts)) {
			foreach ($posts AS $post) {
				$wp_id = $post->post_id;
				$this->timelog("Deleting post {$wp_id}");
				// remove this custom field so this delete does not get picked up by ffff_deleted
				delete_post_meta($wp_id, "_ffff_external_id");
				wp_delete_post($wp_id);
			}
		}		
	}
	
	/**
	 * Add some WordPress hooks to trigger Fresh From behaviour in various places
	 *
	 */
	function setup_hooks() {
		$this->timelog("Setting up some hooks");
		
		if (!is_admin()) {
			add_action("pre_get_posts", array($this, "pre_get_posts")); // requires 2.0

			// do all the external API work in the footer to be visible in subsequent pageviews, unless refreshing
			$hook = isset($_GET["ffff_refresh"]) ? "pre_get_posts" : "wp_footer";
			add_action($hook, array($this, "freshen")); // requires 2.0

			add_action("template_redirect", array($this, "template_redirect")); // requires 2.0

			add_action("loop_start", array($this, "loop_start")); // requires 2.0
			add_action("loop_end", array($this, "loop_end")); // requires 2.0
		}
		
		if (is_admin()) {
			add_action("admin_menu", array($this, "admin_menu")); // requires 1.5
			add_action('admin_menu', array($this, 'add_custom_box')); // requires 1.5

			add_action("delete_post", array($this, "delete_post")); // requires 2.2
			add_action("edit_post", array($this, "edit_post")); // requires 1.2.1
			
			add_filter("plugin_action_links", array($this, "plugin_action_links"), 10, 2); // optional
			
			// first timers
			$ffff_feeds = get_option("ffff_feeds");
			if (version_compare($GLOBALS["wp_version"], '2.5', '>=') && empty($ffff_feeds) && !isset($_POST["add_feed"])) {
				add_action('admin_notices', array($this, "settings_please"));
			}
		}
		
		// add debug footer
		if (_ffff_debug) {
			add_action("wp_footer", array($this, "debug_info"));
			add_action("admin_footer", array($this, "debug_info"));
		}
	}

	/**
	 * Show reminder to add a feed
	 *
	 */
	function settings_please() {
		$settings = '<a href="' . get_option('siteurl') . '/wp-admin/options-general.php?page=' . _ffff_admin_page . '">' . __("Fresh From settings", _ffff_lang_domain) . '</a>';	
		echo '<div class="error"><p>' . sprintf(__("Please update your %s - start by adding a Feed", _ffff_lang_domain), $settings) . '</p></div>';
	}
	
	/**
	 * Add an option to the Admin Menu
	 *
	 */
	function admin_menu() {
		add_options_page("Fresh From", "Fresh From", 10, _ffff_admin_page, array($this, "admin_page"));	 
	}

	/**
	 * Adds a custom section to the "advanced" Post edit screens
	 *
	 */
	function add_custom_box() {
		if (isset($_GET["action"]) && $_GET["action"] == "edit") {
			$custom_fields = get_post_custom($_GET["post"]);	
			if (isset($custom_fields["FreshFrom"])) {
				if (function_exists("add_meta_box")) {
					add_meta_box('ffff_sectionid', __('Fresh From FriendFeed and Twitter', _ffff_lang_domain), array($this, 'inner_custom_box'), 'post', 'advanced');
				} else {
					add_action('dbx_post_advanced', array($this, 'old_custom_box'));
				}
			}
		}
	}
	
	/**
	 * Add a link to the Settings on the plugin page
	 *
	 */
	function plugin_action_links($links, $file){
		if (!isset($GLOBALS["ffff_plugin"])) $GLOBALS["ffff_plugin"] = plugin_basename(__FILE__);
		
		if ($file == $GLOBALS["ffff_plugin"]) {
			$settings_link = '<a href="options-general.php?page=' . _ffff_admin_page . '">' . __('Settings', _ffff_lang_domain) . '</a>';
			array_unshift($links, $settings_link);
		}
		return $links;
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
	 * Trap any 404s resulting from stale Fresh From pingbacks and redirect to homepage
	 *
	 */
	function template_redirect() {
		$slug = strtolower(str_replace(" ", "-", __("Fresh From", _ffff_lang_domain)));
		if (is_404() && strstr($_SERVER["REQUEST_URI"], "/" . $slug)) wp_redirect(get_option("siteurl"), 301);
	}
	
	/**
	 * hide Fresh From posts from the FriendFeed Bot so we don't break the internet
	 * hide from RSS feeds so that WordPress => FeedBurner etc. => FriendFeed doesn't break the internet
	 *
	 */
	function pre_get_posts() {
		if (strpos($_SERVER["HTTP_USER_AGENT"], _ffff_friendfeed_bot) || (is_feed() && !get_option("ffff_rss"))) {
			add_filter("posts_where", array($this, "exclude_fresh_posts")); // requires 1.5
		}	
	}

	/**
	 * Exclude all Fresh From posts whenever the FriendFeedBot is around
	 *
	 */	
	function exclude_fresh_posts($where) {
		global $wpdb;
		$where .= " AND ID NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='FreshFrom') ";
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
		add_filter("the_permalink_rss", array($this, "the_permalink")); // requires 2.3
		add_filter("the_content", array($this, "the_content")); // requires 1.2.1
		add_filter("the_author", array($this, "the_author")); // requires 2.0
		add_filter("the_category", array($this, "the_category")); // requires 1.2.1
		add_filter("comments_number", array($this, "comments_number"), 10, 2); // requires 1.5
	}
	
	/**
	 * Disable those filters after the loop
	 *
	 */
	function loop_end() {
		$this->timelog("Loop end");
		$this->is_looping = false;
	}

	/**
	 * Keep a note of deleted posts so we don't re-import
	 *
	 */
	function delete_post($post_id) {
		$custom_fields = get_post_custom($post_id);
		if (isset($custom_fields["_ffff_external_id"]) && strpos($custom_fields["_ffff_external_id"], "digest.") !== 0) {
			$ffff_deleted = get_option("ffff_deleted");
			$ffff_deleted[] = $custom_fields["_ffff_external_id"][0];
			update_option("ffff_deleted", $ffff_deleted);
		}
	}

	/**
	 * Keep a note of decoupled posts so we don't re-import
	 *
	 */
	function edit_post($post_id) {	
		// verify this came from our screen and with proper authorization, because save_post can be triggered at other times
		if (!wp_verify_nonce($_POST['ffff_noncename'], plugin_basename(__FILE__))) return $post_id;
		if (!current_user_can('edit_post', $post_id)) return $post_id;

		if (isset($_POST["ffff_decouple"])) {
			// decouple
			delete_post_meta($post_id, "FreshFrom");

			// remember individual items that have been decoupled so we do not re-import them
			$custom_fields = get_post_custom($post_id);		
			if (isset($custom_fields["_ffff_external_id"]) && strpos($custom_fields["_ffff_external_id"][0], "digest.") !== 0) {
				$ffff_decoupled = get_option("ffff_decoupled");
				$ffff_decoupled[] = $custom_fields["_ffff_external_id"][0];
				update_option("ffff_decoupled", $ffff_decoupled);
			}
		}
	}
	
	/**
	 * Get data from remote API
	 *
	 */
	function ffff_curl($url, $feed_key=null) {
		$this->timelog("CURL: " . $url);

		// v1.0.1 check for curl support - fail will be reported on the Admin page
		if (!is_callable('curl_init')) return;
		
		// get external data
		$curl_handle = curl_init();
		curl_setopt($curl_handle, CURLOPT_URL, $url);
		curl_setopt($curl_handle, CURLOPT_ENCODING, "");
		curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, TRUE);
		$data = curl_exec($curl_handle);
		$http_code = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);
		curl_close($curl_handle);
		
		$status = $data && $http_code == 200 ? 1 : 0;

		if ($feed_key !== null) {
			// update timestamp and status
			$ffff_feeds = get_option("ffff_feeds");
			$ffff_feeds[$feed_key]["ts"] = time();
			$ffff_feeds[$feed_key]["status"] = $status;
			update_option("ffff_feeds", $ffff_feeds);			

			// store the response
			if ($status) {
				$ffff_feed_data = get_option("ffff_feed_data");
				$ffff_feed_data[$feed_key] = $data;
				update_option("ffff_feed_data", $ffff_feed_data);			
			}			
		}
				
		return $data;
	}
	
	/**
	 * Return day or week key for Keep it Coming
	 *
	 */
	function get_kic_key($post_date, $kic_period) {
		if ($kic_period == "day") $format = "Ymd";
		else $format = "YW";
		return "kic." . date($format, strtotime($post_date));
	}
	
	/**
	 * Here's where all the hard work gets done. Access external APIs and import some Fresh posts
	 *
	 */
	function freshen() {
		global $wpdb;
	
		// are we busy doing API stuff on another request?
		if (time() < get_option("ffff_busy_until")) return;
		$ffff_feeds = get_option("ffff_feeds");

		// ffff_refresh will refresh all feeds
		if (isset($_GET["ffff_refresh"])) {
			// cycle through all feeds
			foreach ($ffff_feeds AS $ffff_feed_key=>$feed) {
				// stay busy
				update_option("ffff_busy_until", time() + _ffff_busy_ttl);
				
				// access external API
				$this->ffff_curl($feed["api"], $ffff_feed_key);
			}
			update_option("ffff_busy_until", 0);
		
		} else {
			// next feed
			$ffff_feed_key = get_option("ffff_feed_key");
			
			if (isset($ffff_feeds[$ffff_feed_key])) {
				$feed = $ffff_feeds[$ffff_feed_key];
			
				// calculate expiry
				if (strstr($feed["api"], "friendfeed.com")) $ttl = _ffff_friendfeed_ttl;
				elseif (strstr($feed["api"], "twitter.com")) $ttl = _ffff_twitter_ttl;

				// is it time yet?
				if (time() < $feed["ts"] + $ttl) return;

				// here we go
				update_option("ffff_busy_until", time() + _ffff_busy_ttl);

				// access external API
				$this->ffff_curl($feed["api"], $ffff_feed_key);
				
				// ready for the next one
				$ffff_feed_key++;
				update_option("ffff_feed_key", $ffff_feed_key);
				update_option("ffff_busy_until", 0);

				// enough for now? if this is the last feed, continue onwards
				if ($ffff_feed_key < count($ffff_feeds)) return;
			}
		}

		// time to process all those feeds
		update_option("ffff_busy_until", time() + _ffff_busy_ttl);
		update_option("ffff_feed_key", 0);
		
		// load the big one
		$ffff_feed_data = get_option("ffff_feed_data");
		
		// gather posts
		$posts = array();
		foreach (array_keys($ffff_feeds) AS $feed_key) {
			$posts = array_merge($posts, $this->get_posts($ffff_feeds[$feed_key], $ffff_feed_data[$feed_key]));
		}
		
		// unload the big one
		unset($ffff_feed_data);

		// safety net: trap bad API responses
		if (!count($posts)) return;
		
		// sort posts by magic formula (Twitter is mostly by date, FriendFeed more interesting)
		uasort($posts, array($this, "sort_posts_by_score"));

		// limits: total_kif / _kic and services and users
		$ffff_mode = get_option("ffff_mode");
		$ffff_kic_period = get_option("ffff_kic_period");		
		$total = get_option("ffff_total_" . $ffff_mode);
		$ffff_friendfeed_services = get_option("ffff_friendfeed_services");
		$ffff_users = get_option("ffff_users");
		
		// checks
		$ffff_deleted = get_option("ffff_deleted");
		$ffff_decoupled = get_option("ffff_decoupled");
		$ffff_filter = get_option("ffff_filter");
		$ffff_keyword = get_option("ffff_keyword");
		$ffff_twitter_noreplies = get_option("ffff_twitter_noreplies");
		
		$keepers = array();
		$limits = array();
		$digest_title = array();
		foreach($posts AS $post) {
		
			// check out of history
			if (time() - strtotime($post->post_date) > _ffff_history) {
				$this->timelog("Skipping pre-history post " . $post->meta["_ffff_external_id"]);
				continue;
			}
		
			// check deleted previously
			if (in_array($post->meta["_ffff_external_id"], $ffff_deleted)) {
				$this->timelog("Skipping deleted post " . $post->meta["_ffff_external_id"]);
				continue;
			}

			// check decoupled previously
			if (in_array($post->meta["_ffff_external_id"], $ffff_decoupled)) {
				$this->timelog("Skipping decoupled post " . $post->meta["_ffff_external_id"]);
				continue;
			}
			
			// check filter for #blog
			if ($ffff_filter && $ffff_keyword && strpos($post->post_content, $ffff_keyword) === false) {
				$this->timelog("{$ffff_keyword} not found; skipping post " . $post->meta["_ffff_external_id"]);
				continue;
			}
			
			// check ignoring Twitter @replies
			$service_name = $post->meta["_ffff_service_name"];
			if ($ffff_twitter_noreplies && isset($post->reply)) {
				$this->timelog("Skipping Twitter @reply " . $post->meta["_ffff_external_id"]);
				continue;
			}
			
			// have we changed kic_period and need to reset the limits?
			if ($ffff_mode == "kic") {
				$period_key = $this->get_kic_key($post->post_date, $ffff_kic_period);
			} else {
				$period_key = "kif";
			}
			
			// setup limits
			if (!isset($limits[$period_key])) {
				$limits[$period_key]["total"] = $total;
				$limits[$period_key]["services"] = $ffff_friendfeed_services;
				$limits[$period_key]["users"] = $ffff_users;
				$limits[$period_key]["external_id"] = array(); // keep a list of external IDs here for later
			}
			
			// check total limits
			if ($limits[$period_key]["total"] < 1) {
				$this->timelog("{$period_key} total exhausted; skipping " . $post->meta["_ffff_external_id"]);
				if ($ffff_mode == "kif") break; // we're done
				else continue; // might be some other periods available
			}
			
			// check service limits
			$count_service = false;
			if ($post->meta["_ffff_service"] == "friendfeed" && isset($limits[$period_key]["services"][$service_name])) {
				if ($limits[$period_key]["services"][$service_name]["mix"] != _ffff_unlimited) {
					if ($limits[$period_key]["services"][$service_name]["mix"] < 1) {
						$this->timelog("{$period_key} {$service_name} exhausted; skipping " . $post->meta["_ffff_external_id"]);
						continue;
					}
					$count_service = true;
				}
			} else {
				// create temporary pass for this service
				$limits[$period_key]["services"][$service_name]["mix"] = 0;
			}
			
			// check user limits
			$count_user = false;
			$username = $post->meta["_ffff_service"] . "_" . $post->meta["_ffff_username"];
			if (isset($limits[$period_key]["users"][$username]) && $limits[$period_key]["users"][$username] != _ffff_unlimited) {
				if ($limits[$period_key]["users"][$username] < 1) {
					$this->timelog("{$period_key} {$username} exhausted; skipping " . $post->meta["_ffff_external_id"]);
					continue;				
				}
				$count_user = true;
			}			
					
			$this->timelog("Keeper! {$period_key} {$service_name} " . $post->meta["_ffff_external_id"]);
			$keepers[] = $post;
			
			// count
			$limits[$period_key]["total"]--;
			if ($count_service) $limits[$period_key]["services"][$service_name]["mix"]--;
			if ($count_user) $limits[$period_key]["users"][$username]--;
			$limits[$period_key]["external_id"][$post->meta["_ffff_external_id"]] = $post->meta["_ffff_external_id"];
			
			if ($post->meta["_ffff_service"] == "friendfeed") $digest_title["FriendFeed"] = 1;
			elseif ($post->meta["_ffff_service"] == "twitter") $digest_title["Twitter"] = 1;
		}

		// generated digest posts: by service / by user / all together
		$ffff_digest_type = get_option("ffff_digest_type");
		if (get_option("ffff_digest")) {
			// order by date desc
			uasort($keepers, array($this, "sort_posts_by_date"));
			
			$posts = array();		
			foreach ($keepers AS $post) {
				
				// unique key for this digest
				if ($ffff_digest_type == "service") {
					$digest_key = "digest.service." . $post->meta["_ffff_service_name"];
					$post_title = $post->post_title;
				} elseif ($ffff_digest_type == "user") {
					$digest_key = "digest.user." . $post->meta["_ffff_service"] . "_" . $post->meta["_ffff_username"];
					// 1.1.7 overwrite title prefix
					if (get_option("ffff_prefix")) $post_title = str_replace("%s", $post->meta["_ffff_service"] . ".com/" . $post->meta["_ffff_username"], get_option("ffff_prefix_string"));
					else $post_title = __("Fresh From", _ffff_lang_domain) . " " . $post->meta["_ffff_service"] . ".com/" . $post->meta["_ffff_username"];
				} else {
					$digest_key = "digest.all";
					// 1.1.7 overwrite title prefix
					if (get_option("ffff_prefix")) $post_title = str_replace("%s", implode(" and ", array_keys($digest_title)), get_option("ffff_prefix_string"));
					else $post_title = __("Fresh From", _ffff_lang_domain) . " " . implode(" and ", array_keys($digest_title));
				}

				// keep it coming - need to add date key into unique key
				if ($ffff_mode == "kic") {
					$digest_key .= "." . $this->get_kic_key($post->post_date, $ffff_kic_period);
					if ($ffff_kic_period == "day") $post_title .= " " . __("today", _ffff_lang_domain);
					else $post_title .= " " . __("this week", _ffff_lang_domain);
				}
				
				if (!isset($posts[$digest_key])) {
					// need a new post
					$this->timelog("Creating " . $digest_key);
					$digest_post = $post;
					
					// adjust title for digest.user or digest.all
					$digest_post->post_title = $post_title;
					
					// author becomes an array in case we have more than one in the digest
					$digest_post->meta["_ffff_author"] = array($digest_post->meta["_ffff_author"]);
					$digest_post->meta["_ffff_service_name"] = array($digest_post->meta["_ffff_service_name"]);
					
					// enhance content
					$digest_post->post_content = $this->transform_content($digest_post);

					$digest_post->meta["_ffff_digested"] = array($digest_post->meta["_ffff_external_id"]);
					$digest_post->meta["_ffff_external_id"] = $digest_key;

					$posts[$digest_key] = $digest_post;
				
				} else {
					// append to digest_post->post_content					
					$this->timelog("Adding {$post->meta["_ffff_external_id"]} to " . $digest_key);
					
					$posts[$digest_key]->post_content .= "<br clear=\"both\" />" . $this->transform_content($post);

					$posts[$digest_key]->tags_input = implode(",", array_unique(array_merge(explode(",", $posts[$digest_key]->tags_input), explode(",", $post->tags_input))));

					$posts[$digest_key]->meta["_ffff_author"] = array_unique(array_merge($posts[$digest_key]->meta["_ffff_author"], array($post->meta["_ffff_author"])));
					$posts[$digest_key]->meta["_ffff_service_name"] = array_unique(array_merge($posts[$digest_key]->meta["_ffff_service_name"], array($post->meta["_ffff_service_name"])));
					
					$posts[$digest_key]->meta["_ffff_digested"][] = $post->meta["_ffff_external_id"];
				}		
			}
		} else {
			// single posts - add rss_subtitle of first two words
			$posts = array();
			foreach ($keepers AS $post) {
				// add rss_subtitle of first two words
				$sub_words = explode(" ", trim(strip_tags($post->post_content)));
				
				// 1.1.7 adapt if empty prefix string
				if (get_option("ffff_prefix") && !get_option("ffff_prefix_string")) {
					$subtitle_words = 5;
				} else {
					$subtitle_words = 3;
					$post->post_title .= ": ";
				}
				$rss_subtitle = implode(" ", array_slice($sub_words, 0, $subtitle_words)) . (count($sub_words) > $subtitle_words ? " ..." : "");
				$post->post_title .= $rss_subtitle;
				
				// enhance content here, so video can trump thumbnails
				$post->post_content = $this->transform_content($post);

				$posts[$post->meta["_ffff_external_id"]] = $post;
			}
		}
	
		// get current posts;  array of external_id => post_id
		$result = $wpdb->get_results("SELECT meta_value, post_id, post_date FROM {$wpdb->posts}
			JOIN {$wpdb->postmeta} ON {$wpdb->posts}.ID={$wpdb->postmeta}.post_id AND meta_key = '_ffff_external_id'
			WHERE post_id IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'FreshFrom')");
		$old_posts = array();
		$old_period = array();
		foreach ($result AS $post) {
			$old_posts[$post->meta_value] = $post->post_id;
			$period_key = $this->get_kic_key($post->post_date, $ffff_kic_period);
			if (!isset($old_period[$period_key])) $old_period[$period_key] = array();
			$old_period[$period_key][$post->meta_value] = $post->post_id;
		}
	
		// keep it fresh!
		if ($ffff_mode == "kif") {
			// compare current posts/digests with new posts/digests to work out which ones to leave/update/add/delete
			$id_delete = $this->array_diff_key($old_posts, $posts);	
			$posts_insert = $this->array_diff_key($posts, $old_posts);
			$posts_update = $this->array_intersect_key($posts, $old_posts);
				
		} else {
			// keep it coming - this is more complicated to work out what to add/update/delete, here goes...
			
			// singles
			if (!get_option("ffff_digest")) {

				$id_delete = array(); 
				$posts_insert = array();
				$posts_update = array();
				$periods_insert = array_keys($this->array_diff_key($limits, $old_period)); 
				$periods_update = array_keys($this->array_intersect_key($limits, $old_period)); 

				// we'll have all new periods
				foreach ($periods_insert AS $period_key) {
					foreach ($limits[$period_key]["external_id"] AS $external_id) {
						$this->timelog("New period: adding " . $external_id);
						$posts_insert[] = $posts[$external_id];
					}
				}

				// need to examine these period groups
				foreach ($periods_update AS $period_key) {
					$old_period_count = count($old_period[$period_key]);
					$period_count = $total - $limits[$period_key]["total"];
				
					// do not update if there's now less items available
					if ($period_count < count($old_period[$period_key])) {
						$this->timelog("Archiving " . $period_key);
					} else {
						// more available so go with the new
						$id_delete = array_merge($id_delete, $this->array_diff_key($old_period[$period_key], $limits[$period_key]["external_id"]));	
						foreach (array_keys($this->array_diff_key($limits[$period_key]["external_id"], $old_period[$period_key])) AS $external_id) {
							$this->timelog("KIC adding " . $external_id);
							$posts_insert[] = $posts[$external_id];
						}
						foreach (array_keys($this->array_intersect_key($limits[$period_key]["external_id"], $old_period[$period_key])) AS $external_id) {
							$this->timelog("KIC updating " . $external_id);
							$posts_update[] = $posts[$external_id];
						}
					}
				}
				
			} else {
				// digest
				$id_delete = array(); // never delete a kic digest
				$posts_insert = $this->array_diff_key($posts, $old_posts);
				$posts_update = $this->array_intersect_key($posts, $old_posts);
				
				foreach ($posts_update AS $key=>$post) {
					$post_id = $old_posts[$post->meta["_ffff_external_id"]];
					$custom_fields = get_post_custom($post_id);
					$old_digested = $custom_fields["_ffff_digested"];
					$digested = $post->meta["_ffff_digested"];
					
					// do not update if there's now less items available in the digest
					if (count($digested) < count($old_digested)) {
						$this->timelog("Archiving " . $post->meta["_ffff_external_id"]);
						unset($posts_update[$key]);
					}
				}				
			}
		}

		// delete what we need to delete
		foreach ($id_delete AS $external_id=>$post_id) {
			// remove this custom field so this delete does not get picked up by ffff_deleted
			delete_post_meta($post_id, "_ffff_external_id");
			wp_delete_post($post_id);
			$this->timelog("Deleted post {$external_id} => " . $post_id);
		}
		
		// disable revisions
		remove_action('pre_post_update', 'wp_save_post_revision');
		
		// remove kses
		remove_filter('content_save_pre', 'wp_filter_post_kses');
		
		// add what needs to be added
		foreach ($posts_insert AS $post) {
			// add post
//			$post->post_content = addslashes(stripslashes($post->post_content)); // for Wordpress 2.0.x database insert

			$post_id = wp_insert_post($post);

			// add meta data			
			if (isset($post->meta)) {
				foreach ($post->meta AS $key=>$value) {
					if (!is_array($value)) add_post_meta($post_id, $key, $value);
					else foreach ($value AS $val) add_post_meta($post_id, $key, $val);
				}
			}

			// add instructions
			add_post_meta($post_id, "FreshFrom", 1);			
			
			$this->timelog("Added post {$post->meta["_ffff_external_id"]} => {$post_id}");
		}
		
		// and update these ones
		foreach ($posts_update AS $post) {
			$post_id = $old_posts[$post->meta["_ffff_external_id"]];
			
			// update post
//			$post->post_content = addslashes(stripslashes($post->post_content)); // for Wordpress 2.0.x database insert
			$post->ID = $post_id;
			$rv = wp_update_post($post);
			
			// update meta data	
			if (isset($post->meta)) {
				foreach ($post->meta AS $key=>$value) {
					delete_post_meta($post_id, $key);
					if (!is_array($value)) add_post_meta($post_id, $key, $value);
					else foreach ($value AS $val) add_post_meta($post_id, $key, $val);
				}
			}			
			
			$this->timelog("Updated post {$post->meta["_ffff_external_id"]} => {$post_id}");
		}		
		
		// cleanup any inherit revisions if removing wp_save_post_revision didn't work
		$result = $wpdb->get_results("SELECT ID FROM {$wpdb->posts}
			WHERE post_status='inherit'
			AND post_parent IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'FreshFrom')");
		foreach ($result AS $post) {
			$post_id = $post->ID;
			wp_delete_post($post_id);
			$this->timelog("Deleted post revision " . $post_id);			
		}
		$wpdb->get_results("OPTIMIZE TABLE {$wpdb->posts}");
		$wpdb->get_results("OPTIMIZE TABLE {$wpdb->postmeta}");
		
		// phew, all done, some other request can have a go now
		update_option("ffff_busy_until", 0);
	}

	/**
	 * PHP4 version of the PHP5 favourite
	 *
	 */
	function array_diff_key() {
        $arrs = func_get_args();
        $result = array_shift($arrs);
        foreach ($arrs as $array) {
            foreach ($result as $key => $v) {
                if (array_key_exists($key, $array)) {
                    unset($result[$key]);
                }
            }
        }
        return $result;
	}

	/**
	 * PHP4 version of the PHP5 favourite
	 *
	 */
	function array_intersect_key() {
        $arrs = func_get_args();
        $result = array_shift($arrs);
        foreach ($arrs as $array) {
            foreach ($result as $key => $v) {
                if (!array_key_exists($key, $array)) {
                    unset($result[$key]);
                }
            }
        }
        return $result;
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
	 * uasort function to order WordPress post objects by post date
	 *
	 * @param object $a WordPress post object
	 * @param object $b WordPress post object
	 * @return
	 */
	function sort_posts_by_date($a, $b) {
		return strcmp($b->post_date, $a->post_date);
	}	
	
	/**
	 * uasort function to order WordPress post objects by latest and greatest
	 *
	 * @param object $a WordPress post object
	 * @param object $b WordPress post object
	 * @return
	 */
	function sort_posts_by_score($a, $b) {	
		return ($b->score > $a->score);
	}	

	/**
	 * From wp2.6.5 - callback to add clickable links
	 *
	 */
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
	 * Add some filters to enhance content in wonderful ways
	 *
	 */
	function transform_content($post) {
	
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
				$url_components = parse_url($url);
				if (in_array($url_components["host"], $ffff_redirect_host_array)) {
					
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
			$url_components = parse_url($url);
			if ($ffff_twitpic && strpos($url_components["host"], "twitpic.com") !== false) {
				$twitpic_img = str_replace("twitpic.com", "twitpic.com/show/thumb", $url) . ".jpg";
				$post->media_content = "<a href=\"{$url}\"><img src=\"{$twitpic_img}\" style=\"border:1px solid #CCCCCC;padding:1px;\" alt=\"twitpic photo\" /></a>";
			}
			
			if ($ffff_youtube && strpos($url_components["host"], "youtube.com") !== false) {
				$youtube_query = $url_components["query"];
				parse_str($youtube_query, $youtube_params);
				if ($youtube_params["v"]) {
					$post->media_content = <<<EOF
<object width="480" height="295"><param name="movie" value="http://www.youtube.com/v/{$youtube_params["v"]}&hl=en&fs=1"></param><param name="allowFullScreen" value="true"></param><param name="allowscriptaccess" value="always"></param><embed src="http://www.youtube.com/v/{$youtube_params["v"]}&hl=en&fs=1" type="application/x-shockwave-flash" allowscriptaccess="always" allowfullscreen="true" width="480" height="295"></embed></object>
EOF;
				}				
			}
			
			// bit.ly thumbnails - easter egg - doesn't work on custom bit.ly URLs yet
			if (strpos($url_components["host"], "bit.ly") !== false) {
				if (!isset($GLOBALS["_ffff_bitly_content_length"][$url])) {
					$bitly_img = str_replace("bit.ly", "s.bit.ly/bitly", $url) . "/thumbnail_medium.png";
					
					// just get the header, content length 
					$this->timelog("Checking " . $bitly_img);
					$ch = curl_init();
					curl_setopt($ch, CURLOPT_URL, $bitly_img);
					curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
					curl_setopt($ch, CURLOPT_HEADER, true);
					curl_setopt($ch, CURLOPT_NOBODY, true);
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
					$headers = curl_exec($ch);
					$GLOBALS["_ffff_bitly_content_length"][$url] = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
					curl_close($ch);
				}
				
				if ($GLOBALS["_ffff_bitly_content_length"][$url] > 1000) {
					$post->media_content = "<a href=\"{$url}\"><img src=\"{$bitly_img}\" style=\"border:1px solid #CCCCCC;padding:1px;\" alt=\"{$alt}\" /></a>";
				}
			}
			
			// please submit a feature request if you want to see other content enhancements!
		}
		
		// show the bestest enhancement - video scores over thumbnail
		if ($post->media_content) {
			// media content	
			if (strpos($post->post_content, _ffff_media_token) !== false) {
				$content = str_replace(_ffff_media_token, $post->media_content . "<br clear=\"both\" />", $post->post_content);
			} else {
				$content .= $post->media_content . "<br clear=\"both\" />";
			}
		}			
		
		return $content;
	}
	
	/**
	 * Filter to use external links for Fresh content
	 *
	 */
	function the_permalink($permalink) {
		$custom_fields = get_post_custom();
		if ($this->is_looping && isset($custom_fields["FreshFrom"])) {
			if ($custom_fields["_ffff_service"][0] == "friendfeed") {
				if (strpos($custom_fields["_ffff_external_id"][0], "digest") === 0) {
					if (count($custom_fields["_ffff_digested"]) == 1) $permalink = "http://friendfeed.com/e/" . $custom_fields["_ffff_digested"][0];
					else $permalink = "http://friendfeed.com/" . $custom_fields["_ffff_username"][0];
				} else $permalink = "http://friendfeed.com/e/" . $custom_fields["_ffff_external_id"][0];
			} else {
				if (strpos($custom_fields["_ffff_external_id"][0], "digest") === 0) {
					if (count($custom_fields["_ffff_digested"]) == 1) $permalink = $custom_fields["_ffff_profileUrl"][0] . "/statuses/" . $custom_fields["_ffff_digested"][0];
					else $permalink = "http://twitter.com/" . $custom_fields["_ffff_username"][0];
				} else $permalink = $custom_fields["_ffff_profileUrl"][0] . "/statuses/" . $custom_fields["_ffff_external_id"][0];
			}
		}
		return $permalink;
	}


	/**
	 * Filter to display external links for comments on Fresh posts
	 *
	 */
	function comments_number($comment_text, $number) {
		$custom_fields = get_post_custom();
		if ($this->is_looping && isset($custom_fields["FreshFrom"])) {
		
			if (strpos($custom_fields["_ffff_external_id"][0], "digest") === 0) $external_id = $custom_fields["_ffff_digested"][0];
			else $external_id = $custom_fields["_ffff_external_id"][0];
		
			if ($custom_fields["_ffff_service"][0] == "friendfeed") {
				$comment_text = "</a><a href=\"http://friendfeed.com/e/{$external_id}/?comment={$external_id}\">" . str_replace($number, $custom_fields["_ffff_comment_count"][0], $comment_text);
			} else {
				$comment_text = "</a><a href=\"http://twitter.com/home?status=@{$custom_fields["_ffff_username"][0]}%20&in_reply_to_status_id={$external_id}&in_reply_to={$custom_fields["_ffff_username"][0]}\">" . $comment_text;
			}
		}
		return $comment_text; 
	}

	/**
	 * Filter to add a Fresh Feed plug to the About page and some stats
	 *
	 */
	function the_content($content) {
		// add a plug to the About page
		if (is_page("2")) {
			$link = "<a href=\"" . _ffff_freshfrom_url . "\">Fresh From FriendFeed and Twitter</a>";
			$content .= "<p>" . sprintf(__("This blog is kept fresh by the %s plugin.", _ffff_lang_domain), $link) . "</p>";
		}
		
		$custom_fields = get_post_custom();
		if (isset($custom_fields["FreshFrom"])) {
			// close comments
			if (is_single() || (strpos($custom_fields["_ffff_external_id"][0], "digest") === 0 && count($custom_fields["_ffff_digested"]) > 1)) $GLOBALS["post"]->comment_status = "closed";
			
			// insert stats image once per page so plugin usage can be tracked
			if (!isset($GLOBALS["ffff_tracked"])) {
				$url_components = parse_url(get_option("siteurl"));
				$domain = $url_components["host"];
				
				$stats = _ffff_freshfrom_url . "/stats";
				$stats .= "?siteurl=" . urlencode(get_option("siteurl"));
				$stats .= "&wpv=" . $GLOBALS["wp_version"];
				$stats .= "&ffv=" . _ffff_version;
				$stats .= "&ts=" . microtime(true);
		
				$content .= "<img src=\"{$stats}\" width=\"1\" height=\"1\" />";			
				$GLOBALS["ffff_tracked"] = true;
			}

			// add a plug
			if (!isset($GLOBALS["ffff_plugged"]) && strpos($custom_fields["_ffff_external_id"][0], "digest") === 0 && count($custom_fields["_ffff_digested"]) > 1) {
				$link = "<a href=\"" . _ffff_freshfrom_url . "\" style=\"color:#CCCCCC\">Fresh From</a>";
				$content .= "<div style=\"font-size:80%;color:#CCCCCC;margin-top:5px;\">" . sprintf(__("Powered by %s", _ffff_lang_domain), $link) . "</div>";				
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
			$author = implode(", ", $custom_fields["_ffff_author"]);
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
			
			// more than a single service or more than a single user: digest Category doesn't make sense so here's a plug instead
			if (count(array_unique($custom_fields["_ffff_service_name"])) > 1 || count(array_unique($custom_fields["_ffff_author"])) > 1) {
				$url = _ffff_freshfrom_url;
				$name = "Fresh From";
			
			} elseif ($name == "FriendFeed") { 
				// correct FriendFeed url
				$url = "http://friendfeed.com/" . $custom_fields["_ffff_username"][0];
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
		$status["Fresh From"] = array('info', "Version: " . _ffff_version);
		$status["PHP"] = array(version_compare(phpversion(), "4.3", ">="), "Version: " . phpversion(), " Requires 4.3 - please ask your hosting provider to install PHP5.x");
		if (function_exists('mysql_get_client_info')) $status["MySQL"] = array(version_compare(mysql_get_client_info(), "4.0", ">="), "Version: " . mysql_get_client_info(), " Requires 4.0 - please ask your hosting provider to install MySQL5.x");
		$status["WordPress"] = array(version_compare($GLOBALS["wp_version"], "2.3", ">="), "Version: " . $GLOBALS["wp_version"], ' Requires 2.3 - please install the latest version of <a href="http://wordpress.org">WordPress</a>');

		// CURL
		$curl_message = '';
		if (is_callable('curl_init')) {
			if (extension_loaded('suhosin') || !function_exists('curl_version')) {
				$curl_message = "Installed.";
			} else {
				$curl_version = curl_version();
				if (isset($curl_version['version'])) $curl_message = "Version: " . $curl_version['version'];
			}
			$status["Libcurl"] = array(true, $curl_message);
			
		} else {
			$status["Libcurl"] = array(false, "Not found!", "Please ask your hosting provider to enable CURL on your PHP installation");
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
	 * Convert url to API url
	 *
	 */
	function get_api($url) {
		if (strpos($url, "friendfeed.com")) {
			$api = str_replace(
				array("friendfeed.com/", "/user/rooms/", "/user/search?"), 
				array("friendfeed.com/api/feed/user/", "/room/", "/search?"), 
				$url
			);
			$api .= (strpos($api, "?") ? "&" : "?") . "num=99&format=xml";
			
		} elseif (strpos($url, "search.twitter.com")) {
			$api = str_replace("search?", "search.json?", $url);
		
		} elseif (strpos($url, "twitter.com")) {
			$api = str_replace("twitter.com/", "twitter.com/statuses/user_timeline/", $url) . ".xml";
			
		} else {
			$api = "";
		}
		
		return $api;
	}
	
	/**
	 * Display and handle Admin settings
	 *
	 */
	function admin_page() {

		// reset and start again
		if (isset($_POST["reset"])) {
			$this->reset();
		}
	
		// add a new feed
		$ffff_feeds = get_option("ffff_feeds");
		$ffff_feed_data = get_option("ffff_feed_data");

		if (isset($_POST['add_feed'])) {
			$ffff_feed_url = $_POST["ffff_feed_url"];
			$api = $this->get_api($ffff_feed_url);
			if ($api) {
				// test it out!
				$feed_key = count($ffff_feeds);
				$ffff_feeds[$feed_key] = array("url"=>$ffff_feed_url, "api"=>$api);
				update_option("ffff_feeds", $ffff_feeds);
				
				$data = $this->ffff_curl($api, $feed_key);
				$ffff_feeds = get_option("ffff_feeds");
				$ffff_feed_data = get_option("ffff_feed_data");
				
				if ($ffff_feeds[$feed_key]["status"]) {
					// get posts - so we calculate ffff_friendfeed_services / etc.
					$this->get_posts($ffff_feeds[$feed_key], $ffff_feed_data[$feed_key]);
					$ffff_feed_url = ""; // done, all good
					update_option("ffff_feed_key", 1 + $ffff_feed_key); // got data for this feed already
					$this->admin_alert("Feed added: " . $ffff_feeds[$feed_key]["url"]);
				} else {
					// report error and remove feed
					$this->admin_alert("API error: " . strip_tags($data));
					array_splice($ffff_feeds, $feed_key, 1);
					array_splice($ffff_feed_data, $feed_key, 1);
				}
			} else {
				// error
				$this->admin_alert("Cannot find API for {$ffff_feed_url}");
			}
		}

		// delete a feed
		foreach ($_POST AS $key=>$val) if (strpos($key, "delete_feed") === 0) {
			$feed_key = str_replace("delete_feed_", "", $key);
			$gone = array_splice($ffff_feeds, $feed_key, 1);
			array_splice($ffff_feed_data, $feed_key, 1);
			// remove services and users if no feeds remaining
			if (!count($ffff_feeds)) {
				update_option("ffff_friendfeed_services", array());
				update_option("ffff_users", array());
			}
			$this->admin_alert($gone[0]["url"] . " " . __("has been deleted.", _ffff_lang_domain));
		}
		update_option("ffff_feeds", $ffff_feeds);
		update_option("ffff_feed_data", $ffff_feed_data);
		
		// update mix - scan for ffff_mix0 and ffff_service0
		$ffff_friendfeed_services = get_option("ffff_friendfeed_services");
		foreach ($_POST AS $key=>$value) {
			if (strpos($key, "ffff_friendfeed_service_mix") === 0) {
				$service_id = array_pop(explode("_", $key));
				$service_name = $_POST["ffff_friendfeed_service_name_" . $service_id];
				if (isset($ffff_friendfeed_services[$service_name])) $ffff_friendfeed_services[$service_name]["mix"] = $value;
			}
		}
		update_option("ffff_friendfeed_services", $ffff_friendfeed_services);
	
		// and users
		$ffff_users = get_option("ffff_users");
		foreach ($_POST AS $key=>$value) {
			if (strpos($key, "ffff_user_mix") === 0) {
				$service_nickname = substr($key, 14);
				if (isset($ffff_users[$service_nickname])) $ffff_users[$service_nickname] = $value;
			}
		}
		update_option("ffff_users", $ffff_users);
		
		// import and content options
		if (isset($_POST["ffff_mode"])) {
			$dirty = false;
			if (update_option("ffff_mode", $_POST["ffff_mode"])) $dirty = true;
			if (update_option("ffff_kic_period", $_POST["ffff_kic_period"])) $dirty = true;
			if (update_option("ffff_digest", $_POST["ffff_digest"])) $dirty = true;
			if (update_option("ffff_digest_type", $_POST["ffff_digest_type"])) $dirty = true;

			if ($dirty) {
				$this->delete_all_freshfrom_posts();
				$this->admin_alert("Settings saved. Fresh From has removed all imported content.");
			}
			
			update_option("ffff_total_kif", $_POST["ffff_total_kif"]);
			update_option("ffff_total_kic", $_POST["ffff_total_kic"]);
			update_option("ffff_filter", $_POST["ffff_filter"]);
			update_option("ffff_keyword", $_POST["ffff_keyword"]);
			update_option("ffff_twitter_noreplies", $_POST["ffff_twitter_noreplies"]);
			update_option("ffff_pubstatus", $_POST["ffff_pubstatus"]);
		
			update_option("ffff_profile", $_POST["ffff_profile"]);
			update_option("ffff_redirect", $_POST["ffff_redirect"]);
			update_option("ffff_redirect_hosts", $_POST["ffff_redirect_hosts"]);
			update_option("ffff_twitpic", $_POST["ffff_twitpic"]);
			update_option("ffff_youtube", $_POST["ffff_youtube"]);
			update_option("ffff_rss", $_POST["ffff_rss"]);
			update_option("ffff_prefix", $_POST["ffff_prefix"]);
			update_option("ffff_prefix_string", $_POST["ffff_prefix_string"]);
		}

		$ffff_mode = get_option("ffff_mode");
		$ffff_total_kif = get_option("ffff_total_kif");
		$ffff_total_kic = get_option("ffff_total_kic");
		$ffff_kic_period = get_option("ffff_kic_period");
		$ffff_filter = get_option("ffff_filter");
		$ffff_keyword = get_option("ffff_keyword");
		$ffff_twitter_noreplies = get_option("ffff_twitter_noreplies");
		$ffff_digest = get_option("ffff_digest");
		$ffff_digest_type = get_option("ffff_digest_type");
		$ffff_pubstatus = get_option("ffff_pubstatus");
		
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
			$status .= "<tr><td align=\"center\"><strong>{$light}</strong></td><td width=\"120\"><strong>" . $status_key . "</strong></td><td width=\"120\">" . $status_item[1] . "</td>" . (!$status_item[0] ? "<td>{$status_item[2]}</td>" : "") . "</tr>\n";
		}
		$status .= "</tbody></table></div>";

		// send debug report
		$admin_email = get_option("admin_email");
		if (isset($_POST['debug'])) {
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
		
		// help!
		$support_room = _ffff_support_room;
		$refresh_url = get_option("siteurl") . "?ffff_refresh=on";
		
		// display alerts
		$alerts = "";
		$ffff_admin_alert = get_option("ffff_admin_alert");
		if ($ffff_admin_alert) {
			$alerts = "<p><strong>" . implode("</strong></p>\n<p><strong>", $ffff_admin_alert) . "</strong></p>\n";
			
			// wipe alerts
			delete_option("ffff_admin_alert");
		}
		
		// feed javascript
		$feed_types = array(
			"FriendFeed User" => "http://friendfeed.com/USERNAME", 
			"FriendFeed Comments" => "http://friendfeed.com/USERNAME/comments",
			"FriendFeed Likes" => "http://friendfeed.com/USERNAME/likes",
			"FriendFeed Discussion" => "http://friendfeed.com/USERNAME/discussion",
			"FriendFeed Room" => "http://friendfeed.com/rooms/ROOM",
			"FriendFeed Search" => "http://friendfeed.com/search?from=USERNAME&q=KEYWORD",
			"Twitter User" => "http://twitter.com/USERNAME",
			"Twitter Search"  => "http://search.twitter.com/search?q=from:USERNAME+KEYWORD" // JSON
		);
		$control["feed_js"] = "<script type=\"text/javascript\">\n<!--\nvar feed_url = new Array();\nfeed_url[0] = '';\n";
		$type_key = 1;
		foreach ($feed_types AS $type_url) {
			if (strpos($type_url, "friendfeed.com") && get_option("ffff_guess_friendfeed_username")) {
				$username = get_option("ffff_guess_friendfeed_username");
			} elseif (strpos($type_url, "twitter.com") && get_option("ffff_guess_twitter_username")) {
				$username = get_option("ffff_guess_twitter_username");
			} else {
				$username = "USERNAME";
			}
			$url = str_replace("USERNAME", $username, $type_url);
			$control["feed_js"] .= "feed_url[{$type_key}] = '{$url}';\n";
			$type_key++;
		}
		
		$control["feed_js"] .= <<<EOF
function ffff_feed_type_url(feed_type) {
	feed_type.form.ffff_feed_url.value=feed_url[feed_type.selectedIndex];
	feed_type.form.ffff_feed_url.focus();
	feed_type.form.ffff_feed_url.select();
}	
//-->
</script>		
EOF;
				
		// array of url (API url is calculated)
		$control["ffff_feeds"] = "";
		$feed_count = 0;
		foreach ($ffff_feeds AS $feed) {
			$control["ffff_feeds"] .= "<p class=\"submit\" style=\"text-align:left;padding:5px;\"><input type=\"submit\" name=\"delete_feed_{$feed_count}\" value=\"" . __("Delete", _ffff_lang_domain) . "\" /> <a href=\"{$feed["url"]}\" title=\"{$feed["api"]}\" style=\"color:#333333;\" target=_blank>{$feed["url"]}</a>";
			if (!$feed["status"]) $control["ffff_feeds"] .= " <span style='color:red;font-weight:bold;'>" . __("FAIL", _ffff_lang_domain) . "</span>";
			else $control["ffff_feeds"] .= " <span style='color:green;font-weight:bold;'>" . __("OK", _ffff_lang_domain) . "</span>";
			$control["ffff_feeds"] .= " " . sprintf(__("%s seconds ago", _ffff_lang_domain), number_format(time() - $feed["ts"])) . "</p>";
			$feed_count++;
		}
		
		$type = "<select name=\"ffff_feed_type\" onChange=\"ffff_feed_type_url(this);\">";
		$type .= "<option value=\"\">==" . __("Select feed type", _ffff_lang_domain) . "==</option>\n";
		foreach (array_keys($feed_types) AS $type_key) {
			$type .= "<option>{$type_key}</option>\n";
		}
		$type .= "</select>"; 
	
		if ($ffff_feed_url) $style = 'style="border: 4px solid #FF3333;"';
		else $style = '';
		$url = "<input {$style} type=\"text\" size=\"60\" name=\"ffff_feed_url\" value=\"{$ffff_feed_url}\" />";
		$url .= " <input type=\"submit\" name=\"add_feed\" value=\"" . __("Add", _ffff_lang_domain) . "\" />";

		$control["ffff_feeds_add"] = "<p class=\"submit\" style=\"text-align:left;\">{$type} URL: {$url}</p>\n";

		// friendfeed services mix
		$control["services"] = "<table>";
		$id = 0;
		foreach ($ffff_friendfeed_services AS $service_name=>$service) {
			$selector = "<select name=\"ffff_friendfeed_service_mix_{$id}\">";
			foreach (array("None", 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, _ffff_unlimited=>__("Unlimited", _ffff_lang_domain)) AS $val=>$label) {
				$selector .= "<option value=\"$val\"" . ($val == $service["mix"] ? " selected" : "") . ">{$label}</option>\n";
			}
			$selector .= "</select>";
			
			$control["services"] .= "<tr><td style=\"padding: 5px\" nowrap><img src=\"{$service["iconUrl"]}\" /> {$service_name}</td>\n";
			$control["services"] .= "<td style=\"padding: 5px\">{$selector}<input type=\"hidden\" name=\"ffff_friendfeed_service_name_{$id}\" value=\"{$service_name}\"></td></tr>\n";
			$id++;
		}
		$control["services"] .= "</table>";

		// friendfeed + twitter user mix
		$control["users"] = "<table>";
		foreach ($ffff_users AS $service_username=>$mix) {
			$selector = "<select name=\"ffff_user_mix_{$service_username}\">";
			foreach (array("None", 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, _ffff_unlimited=>__("Unlimited", _ffff_lang_domain)) AS $val=>$label) {
				$selector .= "<option value=\"$val\"" . ($val == $mix ? " selected" : "") . ">{$label}</option>\n";
			}
			$selector .= "</select>";

			list($service, $username) = explode("_", $service_username, 2);
			if ($service == "friendfeed") {
				$icon = "<img src=\"http://friendfeed.com/static/images/icons/internal.png\" />";
				$profile = "<img src=\"http://friendfeed.com/{$username}/picture?size=small\" />";
			} elseif ($service == "twitter") {
				$icon = "<img src=\"http://friendfeed.com/static/images/icons/twitter.png\" />";
				if ($profile_image_url = $this->get_twitter_pic($username)) {
					$profile = "<img src=\"{$profile_image_url}\" width=\"25\" />";
				} else {
					$profile = "";
				}
			}

			$control["users"] .= "<tr><td style=\"padding: 5px\">{$icon}</td>";
			$control["users"] .= "<td style=\"padding: 5px\">{$profile}</td>";
			$control["users"] .= "<td style=\"padding: 5px\">{$username}</td>";
			$control["users"] .= "<td style=\"padding: 5px\">{$selector}</td></tr>\n";			
		}
		$control["users"] .= "</table>";

		// keep it fresh!
		$control["total_kif"] = "<select name=\"ffff_total_kif\">";
		for ($i = 1; $i <= _ffff_max_posts ; $i++) {
			$control["total_kif"] .= "<option value=\"{$i}\"" . ($i == $ffff_total_kif ? " selected" : "") . ">{$i}</option>";
		}
		$control["total_kif"] .= "</select>";

		$control["mode_kif"] = "<p><input type=\"radio\" id=\"ffff_mode_kif\" name=\"ffff_mode\" value=\"kif\" " . ($ffff_mode == "kif" ? 'checked="checked"' : "") . " onChange=\"alert('" . __("Changing this setting will delete all posts previously imported by Fresh From.", _ffff_lang_domain) . "');\" /> <label for=\"ffff_mode_kif\"><b>" . __("Keep it Fresh", _ffff_lang_domain) . ":</b></label> ";
		$control["mode_kif"] .= sprintf(__("Simply show my %s latest and greatest items, regularly refreshed. Less is more.", _ffff_lang_domain), $control["total_kif"]);

		// keep it coming!
		$control["total_kic"] = "<select name=\"ffff_total_kic\">";
		for ($i = 1; $i <= _ffff_max_posts ; $i++) {
			$control["total_kic"] .= "<option value=\"{$i}\"" . ($i == $ffff_total_kic ? " selected" : "") . ">{$i}</option>";
		}
		$control["total_kic"] .= "</select>";

		$control["kic_period"] = "<select name=\"ffff_kic_period\" onChange=\"alert('" . __("Changing this setting will delete all posts previously imported by Fresh From.", _ffff_lang_domain) . "');\" >";
		foreach (array(__("day", _ffff_lang_domain), __("week", _ffff_lang_domain)) AS $period) {
			$control["kic_period"] .= "<option value=\"{$period}\"" . ($period == $ffff_kic_period ? " selected" : "") . ">{$period}</option>\n";
		}
		$control["kic_period"] .= "</select>";

		$control["mode_kic"] = "<p><input type=\"radio\" id=\"ffff_mode_kic\" name=\"ffff_mode\" value=\"kic\" " . ($ffff_mode == "kic" ? 'checked="checked"' : "") . " onChange=\"alert('" . __("Changing this setting will delete all posts previously imported by Fresh From.", _ffff_lang_domain) . "');\" /> <label for=\"ffff_mode_kic\"><b>" . __("Keep it Coming", _ffff_lang_domain) . ":</b></label> ";
		$control["mode_kic"] .= sprintf(__("Import up to %s items every %s into my blog.", _ffff_lang_domain), $control["total_kic"], $control["kic_period"]);
		
		// filter
		$checkbox = sprintf('<input type="checkbox" id="ffff_filter" name="ffff_filter" %s />', $ffff_filter ? 'checked="checked"' : '');	
		$keyword = sprintf('<input type="text" name="ffff_keyword" value="%s" />', $ffff_keyword);
		$control["filter"] = "<p>{$checkbox} <label for=\"ffff_filter\">" . __("Only import items containing", _ffff_lang_domain) . "</label> {$keyword}</p>";
		
		// exclude @replies
		$checkbox = sprintf('<input type="checkbox" id="ffff_twitter_noreplies" name="ffff_twitter_noreplies" %s />', $ffff_twitter_noreplies ? 'checked="checked"' : '');	
		$control["twitter_noreplies"] = "<p>{$checkbox} <label for=\"ffff_twitter_noreplies\">" . __("Exclude @replies from Twitter", _ffff_lang_domain) . "</label></p>";

		// digest
		$checkbox = sprintf("<input type=\"checkbox\" id=\"ffff_digest\" name=\"ffff_digest\" %s onChange=\"alert('" . __("Changing this setting will delete all posts previously imported by Fresh From.", _ffff_lang_domain) . "');\" />", $ffff_digest ? 'checked="checked"' : '');	
		$selector = "<select name=\"ffff_digest_type\" onChange=\"alert('" . __("Changing this setting will delete all posts previously imported by Fresh From.", _ffff_lang_domain) . "');\">";
		foreach (array("service"=>__("per service", _ffff_lang_domain), "user"=>__("per user", _ffff_lang_domain), "all"=>__("all together", _ffff_lang_domain)) AS $key=>$value) {
			$selector .= "<option value=\"{$key}\"" . ($key == $ffff_digest_type ? " selected" : "") . ">{$value}</option>";
		}
		$selector .= '</select>';
		$control["digest"] = "<p>{$checkbox} <label for=\"ffff_digest\">" . __("Combine my items into a single digest post ", _ffff_lang_domain) . "</label> {$selector}</p>";
		
		// pubstatus
		$selector = "<select name=\"ffff_pubstatus\">";
		foreach (array("publish"=>__("Published", _ffff_lang_domain), "draft"=>__("Draft", _ffff_lang_domain)) AS $pubkey=>$pubval) {
			$selector .= "<option value=\"{$pubkey}\"" . ($pubkey == $ffff_pubstatus ? " selected" : "") . ">{$pubval}</option>";
		}
		$selector .= "</select>";
		$control["pubstatus"] = "<p>" .sprintf(__("Imported posts are %s", _ffff_lang_domain), $selector) . "</p>";
		
		// content enhancement
		$control["profile"] = "<p><input type=\"checkbox\" id=\"ffff_profile\" name=\"ffff_profile\" " . (get_option("ffff_profile") ? "checked=\"checked\" " : "") . "/> <label for=\"ffff_profile\">" . __("Show profile pictures", _ffff_lang_domain) . "</label></p>";
		$control["redirect"] = "<p><input type=\"checkbox\" id=\"ffff_redirect\" name=\"ffff_redirect\" " . (get_option("ffff_redirect") ? "checked=\"checked\" " : "") . "/> <label for=\"ffff_redirect\">" . __("Show full URL hints for these URL shorteners:", _ffff_lang_domain) . "</label> <input type=\"text\" name=\"ffff_redirect_hosts\" value=\"" . get_option("ffff_redirect_hosts") . "\" style=\"width:300px;\" /></p>";
		$control["twitpic"] = "<p><input type=\"checkbox\" id=\"ffff_twitpic\" name=\"ffff_twitpic\" " . (get_option("ffff_twitpic") ? "checked=\"checked\" " : "") . "/> <label for=\"ffff_twitpic\">" . __("Show images from Twitpic links", _ffff_lang_domain) . "</label></p>";		
		$control["youtube"] = "<p><input type=\"checkbox\" id=\"ffff_youtube\" name=\"ffff_youtube\" " . (get_option("ffff_youtube") ? "checked=\"checked\" " : "") . "/> <label for=\"ffff_youtube\">" . __("Show videos from Youtube links", _ffff_lang_domain) . "</label></p>";
		$control["rss"] = "<p><input type=\"checkbox\" id=\"ffff_rss\" name=\"ffff_rss\" " . (get_option("ffff_rss") ? "checked=\"checked\" " : "") . "/> <label for=\"ffff_rss\">" . __("Show Fresh From posts in my RSS feeds", _ffff_lang_domain) . "</label></p>";
		
		// 1.1.7 new prefix options
		$control["prefix"] = "<p><input type=\"checkbox\" id=\"ffff_prefix\" onChange=\"if(this.checked && !this.form.ffff_prefix_string.value){this.form.ffff_prefix_string.value='Fresh From %s'}\" name=\"ffff_prefix\" " . (get_option("ffff_prefix") ? "checked=\"checked\" " : "") . "/> <label for=\"ffff_prefix\">" . __("Overwrite post title prefix:", _ffff_lang_domain) . "</label> <input type=\"text\" name=\"ffff_prefix_string\" value=\"" . get_option("ffff_prefix_string") . "\" style=\"width:300px;\" /> %s = service name (e.g. Twitter)</p>";

		echo <<<EOF
<div class="wrap">
<div id="icon-options-general" class="icon32"><br/></div>
<h2>Fresh From FriendFeed and Twitter</h2>
<div class="updated">
{$alerts}
{$status}
</div>
<div id="poststuff" class="metabox-holder has-right-sidebar">
EOF;
		echo <<<EOF
	<div class="inner-sidebar">
		<div id="side-sortables" class="meta-box-sortabless" style="position:relative;">
EOF;

		$this->HtmlPrintBoxHeader('ffff_section_help', __('Help', _ffff_lang_domain));
		$link = "<a href=\"{$support_room}\" target=_blank>" . __("Support Room", _ffff_lang_domain) . "</a>";
		echo "<p>" . sprintf(__("Please visit the %s for help and support and FAQs, and to request features.", _ffff_lang_domain), $link) . "</p>";
		$this->HtmlPrintBoxFooter();

		$this->HtmlPrintBoxHeader('ffff_section_refresh', __('Refresh', _ffff_lang_domain));
		echo "<p>" . __("Fresh From normally does a little bit of its work each time someone visits your site.", _ffff_lang_domain) . "</p>";
		$link = "<a href=\"{$refresh_url}\" target=\"_ffff\">" . __("refresh", _ffff_lang_domain) . "</a>";
		echo "<p>" . sprintf(__("After changing some settings, you might want to %s all your feeds in a single hit.", _ffff_lang_domain), $link) . "</p>";
		echo "<p>" . __("This may take a few seconds if you have a lot of feeds.", _ffff_lang_domain) . "</p>";
		$this->HtmlPrintBoxFooter();

		$this->HtmlPrintBoxHeader('ffff_section_reset', __('Reset', _ffff_lang_domain));
		echo "<form method=\"post\" name=\"reset\">\n";
		if (function_exists('wp_nonce_field')) wp_nonce_field();
		echo '<div class="submit" style="float:right;padding:0pt;"><input type="submit" name="reset" value="' . __("Reset", _ffff_lang_domain) . " &raquo;\" onClick=\"return confirm('" . __("Are you sure you want to reset Fresh From and start again?", _ffff_lang_domain) . "');\" /></div>";
		echo "<p>" . __("Remove all your Fresh From feeds and imported content:", _ffff_lang_domain) . "</p></form>";
		$this->HtmlPrintBoxFooter();

		$this->HtmlPrintBoxHeader('ffff_section_debug', __('Debug', _ffff_lang_domain));
		echo "<p>" . __("If Bob asks you to submit a Debug Report for diagnosis, please enter some details:", _ffff_lang_domain) . "</p>";
		echo "<form method=\"post\" name=\"debug\">\n";
		if (function_exists('wp_nonce_field')) wp_nonce_field();
		echo '<div class="submit" style="float:right;padding:0pt;"><input type="submit" name="debug" value="' . __("Send", _ffff_lang_domain) . ' &raquo;" /></div>';
		echo '<p><input type="text" name="debug_comment" value="' . __("Help Me..!", _ffff_lang_domain) . '" />';
		echo "<br/><input type=\"checkbox\" name=\"debug_cc_admin\" id=\"debug_cc_admin\" /> <label for=\"debug_cc_admin\">cc: {$admin_email}</label></p></form>";
		$this->HtmlPrintBoxFooter();
		echo "</div></div>";
		
		echo <<<EOF
<form method="post" name="options">
EOF;
		if (function_exists('wp_nonce_field')) wp_nonce_field();
		echo <<<EOF
	<div class="has-sidebar sm-padded" >
		<div id="post-body-content" class="has-sidebar-content">
			<div class="meta-box-sortabless">
EOF;
	
		$this->HtmlPrintBoxHeader('ffff_section_feeds', __('Feeds', _ffff_lang_domain));
		echo $control["feed_js"];
		echo "<p>" . __("You can mashup as many feeds as you like. No passwords required.", _ffff_lang_domain) . "</p>";
		if (count($ffff_feeds) > 2) $control["ffff_feeds"] = "<div style=\"height:100px;overflow:auto;\">{$control["ffff_feeds"]}</div>";
		echo $control["ffff_feeds"];
		echo $control["ffff_feeds_add"];
		$this->HtmlPrintBoxFooter();
		
		if (count($ffff_friendfeed_services)) {
			$this->HtmlPrintBoxHeader('ffff_section_services', __('Services', _ffff_lang_domain));
			echo "<p>" . __("You can limit the number of items imported from each of these services:", _ffff_lang_domain) . "</p>";
			echo "<div style=\"height:100px;overflow:auto;margin-bottom:10px;\">{$control["services"]}</div>";
			$this->HtmlPrintBoxFooter();
		}

		if (count($ffff_users) > 1) {
			$this->HtmlPrintBoxHeader('ffff_section_users', __('Users', _ffff_lang_domain));
			echo "<p>" . __("You can limit the number of items imported from each of these users:", _ffff_lang_domain) . "</p>";
			echo "<div style=\"height:100px;overflow:auto;margin-bottom:10px;\">{$control["users"]}</div>";
			$this->HtmlPrintBoxFooter();
		}

		$this->HtmlPrintBoxHeader('ffff_section_import', __('Import', _ffff_lang_domain));
		echo $control["mode_kif"];
		echo $control["mode_kic"];
		echo $control["filter"];
		echo $control["twitter_noreplies"];
		echo $control["digest"];
		echo $control["pubstatus"];
		$this->HtmlPrintBoxFooter();
			
		$this->HtmlPrintBoxHeader('ffff_section_content', __('Content', _ffff_lang_domain));
		echo $control["profile"];
		echo $control["redirect"];
		echo $control["twitpic"];
		echo $control["youtube"];
		echo $control["rss"];
		echo $control["prefix"];
		$this->HtmlPrintBoxFooter();

		echo '</div></div><p class="submit"><input type="submit" name="Submit" value="' . __("Update Options", _ffff_lang_domain) . ' &raquo;" /></p></div></form>';

		echo "</div></div>";
	}
	
	function HtmlPrintBoxHeader($id, $title, $right = false) {
		if($this->admin_style == 27) {
			?>
			<div id="<?php echo $id; ?>" class="postbox">
				<h3 class="hndle"><span><?php echo $title ?></span></h3>
				<div class="inside">
			<?php
		} else {
			?>
			<fieldset id="<?php echo $id; ?>" class="dbx-box">
				<?php if(!$right): ?><div class="dbx-h-andle-wrapper"><?php endif; ?>
				<h3 class="dbx-handle"><?php echo $title ?></h3>
				<?php if(!$right): ?></div><?php endif; ?>
				
				<?php if(!$right): ?><div class="dbx-c-ontent-wrapper"><?php endif; ?>
					<div class="dbx-content">
			<?php
		}
	}
	
	function HtmlPrintBoxFooter( $right = false) {
			if($this->admin_style == 27) {
			?>
				</div>
			</div>
			<?php
		} else {
			?>
					<?php if(!$right): ?></div><?php endif; ?>
				</div>
			</fieldset>
			<?php
		}
	}

	/**
	 * Prints the inner fields for the custom post/page section
	 *
	 */ 
	function inner_custom_box() {
		echo "<p>" . __("This post will be automatically replaced if fresher content becomes available or if you Reset the Fresh From plugin.", _ffff_lang_domain) . "</p>";

		// Use nonce for verification
		echo '<input type="hidden" name="ffff_noncename" id="ffff_noncename" value="' . wp_create_nonce(plugin_basename(__FILE__)) . '" />';
		echo '<input type="checkbox" name="ffff_decouple" id="ffff_decouple" /> ';
		echo '<label for="ffff_decouple">' . __("Do not replace this post, I want to keep it!", _ffff_lang_domain ) . '</label> ';
	}

	/**
	 * Prints the edit form for pre-WordPress 2.5 post/page
	 *
	 */
	function old_custom_box() {
		echo '<div class="dbx-b-ox-wrapper">' . "\n";
		echo '<fieldset id="myplugin_fieldsetid" class="dbx-box">' . "\n";
		echo '<div class="dbx-h-andle-wrapper"><h3 class="dbx-handle">' . __( 'Fresh From FriendFeed and Twitter', _ffff_lang_domain ) . "</h3></div>";   
		echo '<div class="dbx-c-ontent-wrapper"><div class="dbx-content">';

		// output editing form
		$this->inner_custom_box();

		// end wrapper
		echo "</div></div></fieldset></div>\n";
	}
	
	/**
	 * Default post
	 *
	 */
	function get_post($post_date) {
		$post_status = get_option("ffff_pubstatus");
		if (!$post_status) $post_status = "publish";
	
		$obj = new stdClass();
		$obj->post_author = 1; // default author 
		$obj->post_date = $post_date;
		$obj->post_date_gmt = $post_date;
		$obj->post_category = 0;
		$obj->post_excerpt = "";
		$obj->post_status = $post_status;
		$obj->comment_status = "open";
		$obj->ping_status = "closed";
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
	 * Guess username from admin email address or link URLs
	 *
	 */
	function detect_friendfeed_username() {
		$username = get_option("ffff_guess_friendfeed_username");
		
		// FriendFeed API translates email to FriendFeed nickname
		if (!$username) {
			$url = "http://friendfeed.com/api/feed/user?format=xml&num=1&emails=" . get_option("admin_email");
			$data = $this->ffff_curl($url);
			if ($data) {
				$xml = simplexml_load_string($data);
				if ($xml) {
					foreach ($xml AS $entry) {
						$username = (string) $entry->user->nickname;
						$this->admin_alert("FriendFeed nickname '{$username}' has been found.");
						break;
					}
				}
			}
		}

		// look for friendfeed.com in WordPress links
		if (!$username) {
			global $wpdb;
			$services = $GLOBALS["wpdb"]->get_results("SELECT link_url FROM {$wpdb->links} WHERE link_url LIKE '%friendfeed.com%'");
			if ($services && count($services)) {
				foreach ($services AS $service) {
					$link_url = $service->link_url;
					$link_parts = split('[/.?&]', substr($link_url, strpos($link_url, "friendfeed.com")+15));
					if (count($link_parts)) {
						$username = $link_parts[0];
						$this->admin_alert("FriendFeed nickname '{$username}' has been found.");
						break;
					}
				}
			}		
		}

		update_option("ffff_guess_friendfeed_username", $username);
	}
	
	/**
	 * Guess username from admin email address or link URLs
	 *
	 */
	function detect_twitter_username() {
		$username = get_option("ffff_guess_twitter_username");
	
		// twitter API translates email to Twitter screen name
		if (!$username) {
			$url = "http://twitter.com/users/show.xml?email=" . get_option("admin_email");
			$data = $this->ffff_curl($url);
			if ($data) {
				$xml = simplexml_load_string($data);
				if ($xml && isset($xml->screen_name)) {
					$username = (string) $xml->screen_name;
					$this->admin_alert("Twitter screen name '{$username}' has been found.");
				}
			}
		}

		// look for twitter.com in WordPress links
		if (!$username) {
			$prefix = $GLOBALS["wpdb"]->prefix;
			$services = $GLOBALS["wpdb"]->get_results("SELECT link_url FROM {$prefix}links WHERE link_url LIKE '%twitter.com%'");
			if ($services && count($services)) {
				foreach ($services AS $service) {
					$link_url = $service->link_url;
					$link_parts = split('[/.?&]', substr($link_url, strpos($link_url, "twitter.com")+12));
					if (count($link_parts)) {
						$username = $link_parts[0];
						$this->admin_alert("Twitter screen name '{$username}' has been found.");
						break;
					}					
				}
			}
		}

		update_option("ffff_guess_twitter_username", $username);
	}

	/**
	 * Get some posts
	 *
	 */
	function get_posts($feed, $feed_data) {
		$this->timelog("Processing " . $feed["api"]);
		
		$url_components = parse_url($feed["api"]);
		$domain = $url_components["host"];
		
		if (strpos($domain, "friendfeed.com") !== false) {
			return $this->get_posts_friendfeed($feed, $feed_data);
		} elseif (strpos($domain, "search.twitter.com") !== false) {
			return $this->get_posts_search_twitter($feed, $feed_data);
		} elseif (strpos($domain, "twitter.com") !== false) {
			return $this->get_posts_twitter($feed, $feed_data);
		}
	}
	
	/**
	 * Returns the correct service name to use
	 *
	 * @param SimpleXMLObject $service
	 * @return string Service name
	 */
	function get_service_name($service) {
		$service_name = (string) $service->name;
		if ($service->id == "blog") $service_name = (string) $service->profileUrl;
		return $service_name;
	}	
	
	/**
	 * Get some posts from FriendFeed
	 *
	 */
	function get_posts_friendfeed($feed, $feed_data) {
	
		$posts = array();
		if (!$feed_data) return $posts;
	
		$xml = simplexml_load_string($feed_data); 
		if (!$xml) return $posts;
		
		// adjust if this is PHP4
		if (isset($xml->entry)) $xml = $xml->entry;
	
		$ffff_friendfeed_services = get_option("ffff_friendfeed_services");	
		$ffff_users = get_option("ffff_users");

		// add items
		foreach ($xml AS $entry) {				
			// safety net: if API has unexpected response
			if (!isset($entry->id)) continue;
		
			// generate a WP object
			$post = $this->get_friendfeed_post($entry, $ffff_users);
			
			// add to ffff_friendfeed_services if this is a new service (only with User API calls)
			if ($post->meta["_ffff_service_name"] == "FriendFeed" || (strpos($feed["api"], "/user/") && !strpos($feed["api"], "/", 36)) || strpos($feed["api"], "/room/")) {
				if (!isset($ffff_friendfeed_services[$post->meta["_ffff_service_name"]])) {
					if ($post->meta["_ffff_profileUrl"] == get_option("siteurl")) $mix = 0; // none of these in case we break the internet
					elseif (in_array($post->meta["_ffff_service_name"], array("Twitter", "Facebook"))) $mix = 2; // heavyweights
					elseif (strpos($feed["api"], "/room/")) $mix = _ffff_unlimited;
					else $mix = 1; // default for others
					
					$ffff_friendfeed_services[$post->meta["_ffff_service_name"]] = array("mix"=>$mix, "iconUrl"=>$post->meta["_ffff_iconUrl"]);
				}
			}

			// add to ffff_users if this is a new user  (only with User API calls)
			if ((strpos($feed["api"], "/user/") && !strpos($feed["api"], "/", 36)) || strpos($feed["api"], "/room/")) {
				if (!isset($ffff_users["friendfeed_" . $post->nickname])) {
					$ffff_users["friendfeed_" . $post->nickname] = _ffff_unlimited; // unlimited
				}
			}
			
			$posts[] = $post;
		}		

		update_option("ffff_friendfeed_services", $ffff_friendfeed_services);
		update_option("ffff_users", $ffff_users);
		
		return $posts;
	}

	/**
	 * Get a WordPress post object from a FriendFeed entry
	 *
	 * @return object WordPress post object, extended with some meta data used in Fresh From
	 */
	function get_friendfeed_post($entry, $ffff_users) {
		// ff date format 2009-01-25T09:28:22Z, wp 2009-01-06 13:05:33
		$post_date = str_replace(array("T", "Z"), " ", $entry->published);

		$obj = $this->get_post($post_date);

		$service_name = $this->get_service_name($entry->service);
		
		// 1.1.7 overwrite title prefix
		if (get_option("ffff_prefix")) $obj->post_title = str_replace("%s", $service_name, get_option("ffff_prefix_string"));
		else $obj->post_title = __("Fresh From", _ffff_lang_domain) . " " . $service_name;
	
		$entry->author = (string) $entry->user->name;
		$obj->nickname = (string) $entry->user->nickname;
		$obj->guid = "http://friendfeed.com/e/" . $entry->id;
		
		// generate content
		$iconUrl = (string) $entry->service->iconUrl;
		if (!$link = (string) $entry->link) $link = $obj->guid;
		$service_icon = "<a href=\"{$link}\"><img src=\"{$iconUrl}\" align=\"baseline\" border=\"0\" /></a>";
		
		// check against known ffff_users
		$title = (string) $entry->title;
		if ($service_name == "Twitter" && substr($title, 0, 1) == "@") {
			$obj->reply = true;
		}
		if (!isset($ffff_users["friendfeed_" . $obj->nickname])) {
			$title = "<font color=\"#999999\">" . $title . "</font>";
		}

		if (get_option("ffff_profile")) {
			$service_icon = "<span style=\"position:absolute;left:0px;top:0px;\">{$service_icon}</span>";
			$link = "http://friendfeed.com/" . $obj->nickname;
			$tooltip = (string) $entry->user->name;
			$media = "<img src=\"http://friendfeed.com/{$obj->nickname}/picture?size=medium\" border=\"0\" align=\"left\" style=\"margin-right:5px;margin-bottom:5px;\" />";	
			$profile_pic = "<a href=\"{$link}\" title=\"{$tooltip}\">{$media}</a>";
			$content = "<span style=\"position:relative;float:left;\">{$profile_pic}{$service_icon}{$title}</span><br clear=\"both\" />";		
		} else {
			$content = $service_icon . " " . $title . "<br clear=\"both\" />";
		}

		// extract any media thumbnail
		if ($entry->media) {
			if ($entry->media->thumbnail) {
				// adjust for PHP4 single comment
				$thumbnails = class_exists("simplexml") && $entry->media->thumbnail->url ? array($entry->media->thumbnail) : $entry->media->thumbnail;
				foreach ($thumbnails AS $thumbnail) {
					$url = (string) $thumbnail->url;
					break;
				}
			} elseif ($entry->media->content) {
				// adjust for PHP4 single comment
				$media_contents = class_exists("simplexml") && $entry->media->content->url ? array($entry->media->content) : $entry->media->content;
				foreach ($media_contents AS $media_content) {
					if (!$media_content->type) {
						$url = (string) $media_content->url;
						break;
					}
				}
			} elseif ($entry->media->enclosure) {
				// adjust for PHP4 single comment
				$enclosures = class_exists("simplexml") && $entry->media->enclosure->url ? array($entry->media->enclosure) : $entry->media->enclosure;
				foreach ($enclosures AS $enclosure) {
					if ($enclosure->type && strpos((string) $enclosure->type, "image") === 0) {
						// e.g. stumbleupon
						$url = (string) $enclosure->url;	
						break;
					}
				}
			}

			if (isset($url)) {
				$media = "<img src=\"{$url}\" style=\"border:1px solid #CCCCCC;padding:1px;\" alt=\"Image\" />";

				if ($link = $entry->media->link) {
					$media = "<a href=\"{$link}\">{$media}</a>";
				}
			
				// store it here for now. it might get trumped by a video embed
				$obj->media_content = $media;
				$content .= _ffff_media_token;
			}
		}

		// spin through comments
		if (isset($entry->comment)) {
			// adjust for PHP4 single comment
			$comments = class_exists("simplexml") && isset($entry->comment->id) ? array($entry->comment) : $entry->comment;
			foreach ($comments AS $comment) {
				if (!isset($comment->id)) continue;
				if (isset($ffff_users["friendfeed_" . $comment->user->nickname])) {
					$content .= "<img src=\"http://friendfeed.com/static/images/comment-friend.png\" align=\"baseline\" style=\"margin:0px;float:none;\" /> " . $comment->body . "<br/>";

					// update author to commenter
					$entry->author = (string) $comment->user->name;
					
					// update date to latest comment?
					$post_date = str_replace(array("T", "Z"), " ", $comment->date);
				}
			}

			$obj->comment_count = isset($entry->comment->id) ? 1 : count($entry->comment);
		} else {
			$obj->comment_count = 0;		
		}

		$obj->post_content = $content;
		
		$obj->tags_input = "fresh";
		if ((string) $entry->service->id == "blog") $obj->tags_input .= ", friendfeed";
		else $obj->tags_input .= "," . strtolower($service_name);
		
		// quality score
		if (isset($entry->like)) {
			$obj->like_count = count($entry->like); 
		}
		
		// recency scores high		
		$obj->score = _ffff_weight_recency - pow(max(0, (time() - strtotime($obj->post_date)) / 86400), 2);

		// comments score high
		$obj->score += ($obj->comment_count ? _ffff_weight_comments : 0);
		$obj->score += ($obj->comment_count > 9 ? _ffff_weight_comments : 0);			
		
		// likes are good
		$obj->score += ($obj->like_count ? _ffff_weight_likes : 0);
		$obj->score += ($obj->like_count > 9 ? _ffff_weight_likes : 0);

		// media_content is good
		$obj->score += ($obj->media_content ? _ffff_weight_media : 0);
		
		// some custom fields that we'll need later - start with a _ to hide from admin screen
		$obj->meta = array(
			"_ffff_service"=>"friendfeed", 
			"_ffff_username"=>$obj->nickname, 
			"_ffff_external_id"=>(string) $entry->id, 
			"_ffff_author"=>$entry->author, 
			"_ffff_iconUrl"=>$iconUrl, 
			"_ffff_service_name"=>$service_name, 
			"_ffff_profileUrl"=>(string) $entry->service->profileUrl,
			"_ffff_comment_count"=>$obj->comment_count); // because Wordpress 2.0.x ignores post->comment_count

		return $obj;
	}	
	
	/**
	 * Get a WordPress post object from a Twitter JSON search result
	 *
	 * @return object WordPress post object, extended with some meta data used in Fresh From
	 */
	function get_posts_search_twitter($feed, $feed_data) {
		$posts = array();
		if (!$feed_data) return $posts;

		$json = new JSON;
		$search = $json->unserialize($feed_data);
		if (!$search) return $posts;
		
		foreach ($search->results AS $entry) {
			// safety net: if API has unexpected response
			if (!isset($entry->id)) continue;			
			
			$entry->post_date = date("Y-m-d H:i:s", strtotime($entry->created_at));
			$entry->screen_name = (string) $entry->from_user;			
			$entry->author = $entry->screen_name;
			
			// generate a WP object
			$post = $this->get_twitter_post($entry);
			$posts[] = $post;
		}
		
		return $posts;
	}
	
	/**
	 * Get a WordPress post object from a Twitter API response
	 *
	 * @return object WordPress post object, extended with some meta data used in Fresh From
	 */
	function get_posts_twitter($feed, $feed_data) {
		$posts = array();
		if (!$feed_data) return $posts;
	
		$xml = simplexml_load_string($feed_data); 
		if (!$xml) return $posts;
	
		// adjust if this is PHP4
		if (isset($xml->status)) $xml = $xml->status;
	
		$ffff_users = get_option("ffff_users");
		$twitter_pics = get_option("ffff_twitter_pics");
	
		// add user items
		foreach ($xml AS $entry) {				
			// safety net: if API has unexpected response
			if (!isset($entry->id)) continue;

			// twitter date format  Thu Feb 05 10:01:56 +0000 2009, wp 2009-01-06 13:05:33
			// remove timezone offset for PHP4 strtotime
			$time_parts = explode(" ", $entry->created_at);
			array_splice($time_parts, 4, 1);
			$time_str = implode(" ", $time_parts);
			$entry->post_date = date("Y-m-d H:i:s", strtotime($time_str));

			// generate content - including profile thumbnail
			$entry->screen_name = (string) $entry->user->screen_name;
			$entry->profile_image_url = (string) $entry->user->profile_image_url;
			$entry->author = (string) $entry->user->name;

			// add to ffff_users if this is a new user_error (only with User API calls) - and grab profile photo while we're here
			if (strpos($feed["api"], "/user_timeline/") && !isset($ffff_users["twitter_" . $entry->screen_name])) {
				$ffff_users["twitter_" . $entry->screen_name] = _ffff_unlimited; // unlimited
				if (!isset($twitter_pics[(string) $entry->screen_name])) {
					$twitter_pics[(string) $entry->screen_name] = array("expiry"=>time()+_ffff_twitter_pics_ttl, "url"=>(string) $entry->profile_image_url);
					update_option("ffff_twitter_pics", $twitter_pics);
				}
			}

			// generate a WP object
			$post = $this->get_twitter_post($entry);
			$posts[] = $post;
		}		

		update_option("ffff_users", $ffff_users);
		
		return $posts;	
	}
	
	/**
	 * Get a WordPress post object from a Twitter something
	 *
	 * @return object WordPress post object, extended with some meta data used in Fresh From
	 */
	function get_twitter_post($entry) {
		$obj = $this->get_post($entry->post_date);

		// 1.1.7 overwrite title prefix
		if (get_option("ffff_prefix")) $obj->post_title = str_replace("%s", "Twitter", get_option("ffff_prefix_string"));
		else $obj->post_title = __("Fresh From", _ffff_lang_domain) . " Twitter";

		$obj->guid = "http://twitter.com/" . $entry->screen_name . "/statuses/" . $entry->id;
		$obj->comment_count = 0;
		$obj->tags_input = "fresh,twitter";
		
		$service_icon = "<a href=\"{$obj->guid}\"><img src=\"http://friendfeed.com/static/images/icons/twitter.png\" align=\"baseline\" border=\"0\" /></a>";
		$tweet = (string) $entry->text;
		
		if (get_option("ffff_profile")) {
			$service_icon = "<span style=\"position:absolute;left:0px;top:0px;\">{$service_icon}</span>";
			$link = "http://twitter.com/" . $entry->screen_name;
			$media = "<img src=\"{$entry->profile_image_url}\" border=\"0\" width=\"48\" align=\"left\" style=\"margin-right:5px;margin-bottom:5px\" />";	
			$profile_pic = "<a href=\"{$link}\" title=\"" . $entry->screen_name . "\">{$media}</a>";
			$content = "<span style=\"position:relative;float:left;\">{$profile_pic}{$service_icon}{$tweet}</span><br clear=\"both\" />";		
		} else {
			$content = $service_icon . " " . $tweet;
		}
		
		$obj->post_content = $content;
		
		if (substr($tweet, 0, 1) == "@") {
			$obj->reply = true;
		}

		// recency scores high		
		$obj->score = 50 - pow(max(0, (time() - strtotime($obj->post_date)) / 86400), 2);

		// some custom fields that we'll need later - start with a _ to hide from admin screen
		$obj->meta = array(
			"_ffff_service"=>"twitter", 
			"_ffff_username"=>$entry->screen_name, 
			"_ffff_external_id"=>(string) $entry->id, 
			"_ffff_author"=>$entry->author, 
			"_ffff_iconUrl"=>"http://friendfeed.com/static/images/icons/twitter.png", 
			"_ffff_service_name"=>"Twitter", 
			"_ffff_profileUrl"=>"http://twitter.com/" . $entry->screen_name);

		return $obj;
	}
	
	/**
	 * timer array stores timestamps to calculate mysql execution times, and error/warning/notice messages
	 *
	 */
	function timelog($comment) {
		if (_ffff_debug) {
			list($usec, $sec) = explode(" ", microtime());
			$GLOBALS["ffff_timelog"][] = array(round((float)$usec + (float)$sec - $this->start_ts, 4), $comment);
		}
	}

	/**
	 * return table of mysql execution times and variable context for tuning, debugging and error reporting
	 *
	 */
	function debug_info() {
		$this->timelog("done");
		$rv = "<h3>Debug :: Script Timelog</h3>\n<table border=1 cellpadding=3>";
		foreach ($GLOBALS["ffff_timelog"] AS $log_event) {
			if (isset($prior)) {
				$duration = round((float) $log_event[0] - (float) $prior, 4);
				$rv .= "<td" . ($duration > 0.05 ? " style=\"background:red;color:white;\"" : "") . ">{$duration}</td></tr>\n";
			}
			if ($log_event[1] == "done") unset($prior);
			else {
				$rv .= "<tr><td>{$log_event[0]}</td><td>{$log_event[1]}</td>";				
				$prior = $log_event[0];
			}
		}
		$rv .= "</tr></table>\n";
		$rv .= "<h3>Debug :: Wordpress Options</h3>\n<pre>" . print_r($this->get_ffff_options(), true) . "</pre>\n";
		$rv .= "<h3>Debug :: Script Environment</h3>\n<pre>" . print_r(array("_GET"=>$_GET, "_POST"=>$_POST, "_COOKIE"=>$_COOKIE, "_SERVER"=>$_SERVER), true) . "</pre>\n";
		echo $rv;
	}
}