<?php 

/**
 * Handles all things specific to Twitter
 *
 */
class freshfromtwitter extends freshfrom {

	var $service = "twitter";
	var $admin_title = "Fresh From Twitter";

	// cache time in seconds
	var $ttl = 300;
	
	var $banner = "http://assets1.twitter.com/images/twitter_logo_s.png";
	var $password_label = "Password";

	function __construct() {
		parent::__construct();
		
		$this->services = array("Twitter"=>array("mix"=>get_option("ffff_total_posts")));
	}

	/**
	 * Guess username from admin email address or link URLs
	 *
	 */
	function detect_username() {
		$this->timelog("Detecting username");

		// twitter API translates email to Twitter screen name
		if (!$this->username) {
			$url = "http://twitter.com/users/show.xml?email=" . get_option("admin_email");
			$data = $this->ffff_curl($url);
			if ($data) {
				$xml = simplexml_load_string($data);
				if ($xml && isset($xml->screen_name)) {
					$this->username = (string) $xml->screen_name;
					$this->admin_alert("Twitter screen name '{$this->username}' has been found.");
					update_option("ffff_service", $this->service);
				}
			}
		}

		// look for twitter.com in WordPress links
		if (!$this->username) {
			$prefix = $GLOBALS["wpdb"]->prefix;
			$services = $GLOBALS["wpdb"]->get_results("SELECT link_url FROM {$prefix}links WHERE link_url LIKE '%twitter.com%'");
			if ($services && count($services)) {
				foreach ($services AS $service) {
					$link_url = $service->link_url;
					$link_parts = split('[/.?&]', substr($link_url, strpos($link_url, "twitter.com")+12));
					if (count($link_parts)) {
						$this->username = $link_parts[0];
						$this->admin_alert("Twitter screen name '{$this->username}' has been found.");
						update_option("ffff_service", $this->service);
						break;
					}					
				}
			}
		}

		update_option("ffff_twitter_username", $this->username);
	}
	
	/**
	 * Import the latest content from Twitter into an array of WordPress post objects
	 *
	 * This function may return more posts than required if the services mix adds up to more than ffff_total_posts. 
	 * The calling function will trim the result to the number specified by ffff_total_posts
	 *
	 * @param string $stop_date [e.g. 2009-01-06 13:05:33]
	 * @return array List of posts
	 */
	function get_posts($stop_date) {
		$url = "http://twitter.com/statuses/user_timeline/{$this->username}.xml";
		$data = $this->ffff_curl($url, "user");
		if ($data) {
			$xml = simplexml_load_string($data);
			if ($xml) {
				// add user items
				foreach ($xml AS $entry) {
					// safety net: if API has responded with additional
					if (!isset($entry->id)) continue;
					// proof that this is an expected response
					elseif (!isset($new_posts)) $new_posts = array();

				
					// twitter date format  Thu Feb 05 10:01:56 +0000 2009, wp 2009-01-06 13:05:33
					$post_date = date("Y-m-d H:i:s", strtotime($entry->created_at));
					if ($post_date < $stop_date) continue;

					// get a WP object
					$new_posts[] = $this->get_post($post_date, $entry);
				}
			}
		}
		
		// if we haven't seen a genuine API response, use previous posts
		if (!isset($new_posts)) return;
		
		return $new_posts;
	}

	/**
	 * Get a WordPress post object from a Twitter entry
	 *
	 * @param bool $is_new_post
	 * @param string $post_date [e.g. 2009-01-06 13:05:33]
	 * @param object $entry SimpleXMLObject of Twitter entry
	 * @return object WordPress post object, extended with some meta data used in Fresh From
	 */
	function get_post($post_date, &$entry) {

		// generate content - including profile thumbnail
		$profile_image_url = (string) $entry->user->profile_image_url;
		
		$content = $entry->text;
		
		// replying to someone? show their profile pic - no easy way to get it yet so we need another API call
		// http://groups.google.com/group/twitter-development-talk/browse_thread/thread/7f1a5485740da931/04c38853037ef46c?pli=1
		$twitter_pics = get_option("ffff_twitter_pics");
		if ($twitter_username = (string) $entry->in_reply_to_screen_name) {
			if ($profile_image_url = $this->get_twitter_pic($twitter_username)) {
				$media = "<img src=\"{$profile_image_url}\" border=\"0\" align=\"left\" style=\"margin-right:10px;\" />";	
				$link = "http://twitter.com/" . $twitter_username;
				$content = "<a href=\"{$link}\">{$media}</a>" . $content;
			}
		}

		$obj = parent::get_post($post_date);
		$obj->post_title = __("Fresh From Twitter", _ffff_lang_domain);
		$obj->post_content = $content;
		$obj->guid = "http://twitter.com/{$this->username}/statuses/" . $entry->id;
		$obj->comment_count = 0;
		$obj->tags_input = "fresh from, twitter";
		
		// some custom fields that we'll need later - start with a _ to hide from admin screen
		$obj->meta = array("_ffff_service"=>"twitter", 
			"_ffff_external_id"=>(string) $entry->id, 
			"_ffff_author"=>(string) $entry->user->name, 
			"_ffff_iconUrl"=>"http://friendfeed.com/static/images/icons/twitter.png", 
			"_ffff_service_name"=>"Twitter", 
			"_ffff_profileUrl"=>"http://twitter.com/{$this->username}");

		return $obj;
	}
	
	/**
	 * Filter to use external links for Fresh content
	 *
	 */
	function the_permalink($permalink) {
		$custom_fields = get_post_custom();
		if ($this->is_looping && isset($custom_fields["FreshFrom"])) {
			$permalink = "http://twitter.com/{$this->username}/statuses/" . $custom_fields["_ffff_external_id"][0];
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
			$comment_text = "</a><a href=\"http://twitter.com/home?status=@{$this->username}%20&in_reply_to_status_id={$custom_fields["_ffff_external_id"][0]}&in_reply_to={$this->username}\">" . $comment_text;
		}
		return $comment_text; 
	}
}
?>