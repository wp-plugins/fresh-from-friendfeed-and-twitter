<?php

/**
 * Handles all things specific to FriendFeed
 *
 */
class freshfromfriendfeed extends freshfrom {

	var $service = "friendfeed";
	var $admin_title = "Fresh From FriendFeed";

	// cache time in seconds
	var $ttl = 300;

	var $banner = "http://friendfeed.com/static/images/logo-api.png";
	var $password_label = "Remote key";

	function __construct() {
		parent::__construct();
		
		$this->services = get_option("ffff_friendfeed_services");
		if (is_admin() && isset($_POST["ffff_service"]) && $this->username) {
			$this->refresh_services();
		}
	}

	/**
	 * Guess username from admin email address or link URLs
	 *
	 */
	function detect_username() {
		$this->timelog("Detecting username");

		// FriendFeed API translates email to FriendFeed nickname
		if (!$this->username) {
			$url = "http://friendfeed.com/api/feed/user?format=xml&num=1&emails=" . get_option("admin_email");
			$data = $this->ffff_curl($url);
			if ($data) {
				$xml = simplexml_load_string($data);
				if ($xml) {
					foreach ($xml->entry AS $entry) {
						$this->username = (string) $entry->user->nickname;
						$this->admin_alert("FriendFeed nickname '{$this->username}' has been found.");
						$this->refresh_services();
						update_option("ffff_service", $this->service);
						break;
					}
				}
			}
		}

		// look for friendfeed.com in WordPress links
		if (!$ffff_friendfeed_username) {
			$prefix = $GLOBALS["wpdb"]->prefix;
			$services = $GLOBALS["wpdb"]->get_results("SELECT link_url FROM {$prefix}links WHERE link_url LIKE '%friendfeed.com%'");
			if ($services && count($services)) {
				foreach ($services AS $service) {
					$link_url = $service->link_url;
					$link_parts = split('[/.?&]', substr($link_url, strpos($link_url, "friendfeed.com")+15));
					if (count($link_parts)) {
						$this->username = $link_parts[0];
						$this->admin_alert("FriendFeed nickname '{$this->username}' has been found.");
						$this->refresh_services();
						update_option("ffff_service", $this->service);
						break;
					}
				}
			}		
		}

		update_option("ffff_friendfeed_username", $this->username);
	}

	/**
	 * Returns an array of FriendFeed service name / icon / mix (0-5)
	 *
	 * Mix is used to limit the number of post items
	 *
	 */
	function refresh_services() {
		$this->timelog("Refreshing FriendFeed services");

		// list / mix might already exist
		$ffff_friendfeed_services = get_option("ffff_friendfeed_services");

		// mix zero for posts from this blog to avoid duplication
		$siteurl = get_option("siteurl");
		
		$url = "http://friendfeed.com/api/user/{$this->username}/profile?format=xml&include=services";
		$data = $this->ffff_curl($url);
		if ($data) {
			$xml = simplexml_load_string($data);
			if ($xml && isset($xml->service)) {
				
				// alert if new services found
				if (!$ffff_friendfeed_services || 1+count($xml->service) != count($ffff_friendfeed_services)) {
					$this->admin_alert(count($xml->service) . " FriendFeed services have been found.");
				}
				
				foreach ($xml->service AS $service) {
					$service_name = $this->get_service_name($service);
					
					// new service
					if (!isset($ffff_friendfeed_services[$service_name])) {
						if ($service->profileUrl == $siteurl) $mix = 0; // none of these in case we break the internet
						elseif (in_array($service->name, array("Twitter", "Facebook"))) $mix = 2; // heavyweights
						else $mix = 1; // default for others
						
						$ffff_friendfeed_services[$service_name] = array("mix"=>$mix, "iconUrl"=>(string) $service->iconUrl);
					}
				}
				
				// add Comments 
				if (!isset($ffff_friendfeed_services[_ffff_comments_label])) {
					$ffff_friendfeed_services[_ffff_comments_label] = array("mix"=>3, "iconUrl"=>"http://friendfeed.com/static/images/icons/internal.png");
				}				
			}
		}		

		update_option("ffff_friendfeed_services", $ffff_friendfeed_services);
		$this->services = $ffff_friendfeed_services;
	}
	
	/**
	 * Returns the correct service name to use
	 *
	 * @param SimpleXMLObject $service
	 * @return string Service name
	 */
	function get_service_name(&$service) {
		$service_name = (string) $service->name;
		if ($service->id == "blog") $service_name = (string) $service->profileUrl;
		return $service_name;
	}	
	
	/**
	 * Import the latest content from FriendFeed into an array of WordPress post objects
	 *
	 * This function may return more posts than required if the services mix adds up to more than ffff_total_posts. 
	 * The calling function will trim the result to the number specified by ffff_total_posts
	 *
	 * @param string $stop_date [e.g. 2009-01-06 13:05:33]
	 * @return array List of posts
	 */
	function get_posts($stop_date) {
	
		$entries = array();
	
		// user feed
		$url = "http://friendfeed.com/api/feed/user/{$this->username}?num=50&format=xml";
		$data = $this->ffff_curl($url, "user");
		if ($data) {
			$xml = simplexml_load_string($data);
			if ($xml) {		
				// add user items
				foreach ($xml AS $entry) {				
					// safety net: if API has unexpected response
					if (!isset($entry->id)) continue;
					// proof that this is an expected response
					elseif (!isset($new_posts)) $new_posts = array();
				
					// ff date format 2009-01-25T09:28:22Z, wp 2009-01-06 13:05:33
					$post_date = str_replace(array("T", "Z"), " ", $entry->published);
					if ($post_date < $stop_date) continue;

					// generate a WP object
					$entry_id = (string) $entry->id;
					if (!in_array($entry_id, $entries)) {
						$new_posts[] = $this->get_post($post_date, $entry);
						$entries[] = $entry_id;
					}
				}
			}
		}	

		// comments feed
		$url = "http://friendfeed.com/api/feed/user/{$this->username}/comments?num=50&format=xml";
		$data = $this->ffff_curl($url, "comments");
		if ($data) {
			$xml = simplexml_load_string($data);
			if ($xml) {
				// add comment items
				foreach ($xml AS $entry) {		

					// safety net: if API has responded with additional
					if (!isset($entry->id)) continue;
					// proof that this is an expected response
					elseif (!isset($new_posts)) $new_posts = array();
					
					// ff date format 2009-01-25T09:28:22Z, wp 2009-01-06 13:05:33		
					$post_date = str_replace(array("T", "Z"), " ", $entry->published);
					if ($post_date < $stop_date) continue;

					// only add if posted by a different user and we are commenting
					$entry_id = (string) $entry->id;
					if (!in_array($entry_id, $entries)) {
						$new_posts[] = $this->get_post($post_date, $entry, _ffff_comments_label);
						$entries[] = $entry_id;
					}
				}
			}
		}
		
		// if we haven't seen a genuine API response, use previous posts
		if (!isset($new_posts)) return;
			
		return $new_posts;
	}
	
	/**
	 * Get a WordPress post object from a FriendFeed entry
	 *
	 * @param bool $is_new_post
	 * @param string $post_date [e.g. 2009-01-06 13:05:33]
	 * @param object $entry SimpleXMLObject of FriendFeed entry
	 * @return object WordPress post object, extended with some meta data used in Fresh From
	 */
	function get_post($post_date, &$entry, $service_name=null) {

		if (!$service_name) $service_name = $this->get_service_name($entry->service);
	
		$entry->author = (string) $entry->user->name;
		$nickname = (string) $entry->user->nickname;

		$obj = parent::get_post($post_date);
		$obj->post_title = sprintf(__("Fresh From %s", _ffff_lang_domain), $this->get_service_name($entry->service));

		// generate content
		$content = "";
		
		if ($nickname == $this->username) {
			$content .= (string) $entry->title;
		} else {
			$profile_image_url = "";
			$media = "<img src=\"http://friendfeed.com/{$nickname}/picture?size=medium\" border=\"0\" align=\"left\" style=\"margin-right:10px;\" />";	
			$link = "http://friendfeed.com/" . $nickname;
			$content .= "<a href=\"{$link}\">{$media}</a>";
			$content .= "<font color=\"#999999\">" . $entry->title . " - " . (string) $entry->user->name . "</font>";
		}
		
		// extract any media thumbnail
		if (isset($entry->media)) {
			if (isset($entry->media->thumbnail)) {		
				$url = (string) $entry->media->thumbnail->url;
			} elseif (isset($entry->media->content->url) && !isset($entry->media->content->type)) {
				$url = (string) $entry->media->content->url;
			}

			if (isset($url)) {
				$media = "<img src=\"{$url}\" border=\"0\" />";
				
				if ($link = $entry->media->link) {
					$media = "<a href=\"{$link}\">{$media}</a>";
				}
			
				// store it here for now. it might get trumped by a video embed
				$obj->media_content = $media;
				$content .= _ffff_media_token;
			}
		}
			
		// spin through comments
		if (isset($entry->comment) && count($entry->comment)) {
			$content .= "<br clear=\"both\" />";
			foreach ($entry->comment AS $comment) {
				if ((string) $comment->user->nickname == $this->username) {
					$content .= "<br/><img src=\"http://friendfeed.com/static/images/comment-friend.png\" align=\"baseline\" /> " . $comment->body;

					// update author to commenter
					$entry->author = (string) $comment->user->name;
					
					// update date to latest comment?
					$post_date = str_replace(array("T", "Z"), " ", $comment->date);;
				}
			}
			$comment_count = count($entry->comment);
		} else {
			$comment_count = 0;		
		}

		$obj->post_content = $content;
		$obj->guid = "http://friendfeed.com/e/" . $entry->id;
		$obj->comment_count = 0;
		
		$obj->tags_input = "fresh from";
		if ($service_name == _ffff_comments_label || (string) $entry->service->id == "blog") $obj->tags_input .= ", friendfeed";
		else $obj->tags_input .= ", " . strtolower($service_name);
		
		// some custom fields that we'll need later - start with a _ to hide from admin screen
		$obj->meta = array("_ffff_service"=>"friendfeed", 
			"_ffff_external_id"=>(string) $entry->id, 
			"_ffff_author"=>$entry->author, 
			"_ffff_iconUrl"=>(string) $entry->service->iconUrl, 
			"_ffff_service_name"=>$service_name, 
			"_ffff_profileUrl"=>(string) $entry->service->profileUrl,
			"_ffff_comment_count"=>$comment_count); // because Wordpress 2.0.x ignores post->comment_count

		return $obj;
	}	
	
	/**
	 * uasort function to order WordPress post objects by secret proprietary magic formulae, wait this is open source... recency + comments + media + quantity
	 *
	 * @param object $a WordPress post object
	 * @param object $b WordPress post object
	 * @return
	 */
	function sort_posts($a, $b) {
		
		$scores = array();
		
		foreach (array($a, $b) AS $post) {			
			// recency scores high good		
			$score = _ffff_weight_recency - pow(max(0, (time() - strtotime($post->post_date)) / 86400), 2);
			
			// comments score high
			$score += ($post->comment_count ? _ffff_weight_comments : 0);
			$score += ($post->comment_count > 9 ? _ffff_weight_lots_of_comments : 0);			
			
			// media_content is good
			$score += ($post->media_content ? _ffff_weight_media : 0);
			
			// length of post_content is good
			$score += strlen($post->post_content) > 50 ? _ffff_weight_quantity : 0;
		
			$scores[] = $score;
		}
		
		return ($scores[1] > $scores[0]);
	}	
	
	/**
	 * Filter to use external links for Fresh content
	 *
	 */
	function the_permalink($permalink) {
		$custom_fields = get_post_custom();
		if ($this->is_looping && isset($custom_fields["FreshFrom"])) {
			$permalink = "http://friendfeed.com/e/" . $custom_fields["_ffff_external_id"][0];
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
			$comment_text = "</a><a href=\"http://friendfeed.com/e/{$custom_fields["_ffff_external_id"][0]}/?comment={$custom_fields["_ffff_external_id"][0]}\">" . str_replace($number, $custom_fields["_ffff_comment_count"][0], $comment_text);
		}
		return $comment_text; 
	}
}
?>