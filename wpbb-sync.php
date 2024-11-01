<?php
/*
Plugin Name: WordPress-bbPress syncronization
Plugin URI: http://bobrik.name/code/wordpress/wordpress-bbpress-syncronization/
Description: Sync your WordPress comments to bbPress forum and back.
Version: 0.8.0
Author: Ivan Babrou <ibobrik@gmail.com>
Author URI: http://bobrik.name/

Copyright 2008 Ivan Babroŭ (email : ibobrik@gmail.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the license, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; see the file COPYING.  If not, write to
the Free Software Foundation, Inc., 59 Temple Place - Suite 330,
Boston, MA 02111-1307, USA.
*/

// for version checking
$wpbb_version = 80;
$min_version = 78;

// for mode checking
$wpbb_plugin = 0;

function wpbb_add_textdomain()
{
	// setting textdomain for translation
	load_plugin_textdomain('wpbb-sync', false, 'wordpress-bbpress-syncronization');
}

function wpbb_afterpost($id)
{
	//error_log("wordpress: wpbb_afterpost");
	if (!wpbb_do_sync())
		return;
	$comment = get_comment($id);
	$post = get_post($comment->comment_post_ID);
	if (!wpbb_is_enabled_for_post($post->ID))
		return; // sync disabled for that post
	if (!wpbb_do_ping_sync($comment))
		return;
	// do not sync if not enabled for that status
	if (!wpbb_sync_that_status($comment->comment_ID))
		return;
	$row = wpbb_get_table_item('wp_post_id', $comment->comment_post_ID);
	// checking topic existance for post instead of comment counting
	if (!$row) {
		// do not create topic for unapproved comment if not enabled
		if (get_option('wpbb_create_topic_anyway') != 'enabled' && $comment->comment_approved != 1)
			return;
		wpbb_create_bb_topic($post);
		wpbb_continue_bb_topic($post, $comment);
	} elseif (wpbb_sync_that_status($comment->comment_ID))
	{
		// continuing discussion on forum
		wpbb_continue_bb_topic($post, $comment);
	}
}

function wpbb_afteredit($id)
{
	//error_log("wordpress: wpbb_afteredit");
	if (!wpbb_do_sync())
		return;
	$comment = get_comment($id);
	$post = get_post($comment->comment_post_ID);
	if (!wpbb_is_enabled_for_post($post->ID))
		return; // sync disabled for that post
	if (!wpbb_do_ping_sync($comment))
		return;
	$row = wpbb_get_table_item('wp_comment_id', $comment->comment_ID);
	if ($row)
	{
		// have it in database, must sync
		wpbb_edit_bb_post($post, $comment);
	} else
	{
		if (!wpbb_sync_that_status($comment->comment_ID))
			return;
		$row = wpbb_get_table_item('wp_post_id', $comment->comment_post_ID);
		if (!$row)
		{
			// no topic for that post
			if (get_option('wpbb_create_topic_anyway') != 'enabled' && $comment->comment_approved != 1)
				return;
			wpbb_create_bb_topic($post);
			wpbb_continue_bb_topic($post, $comment);
		} else
		{
			wpbb_continue_bb_topic($post, $comment);
		}
	}
}

function wpbb_afterdelete($id)
{
	//error_log('wordpress: wpbb_afterdelete');
	wpbb_delete_table_item('wp_comment_id', $id);
	$request = array(
		'action' => 'delete_post',
		'comment_id' => $id
	);
	$answer = wpbb_send_command($request);
	remove_action('wp_set_comment_status', 'wpbb_afteredit');
}

function wpbb_afterstatuschange($id)
{
	//error_log('wordpress: wpbb_afterstatuschange');
	if (!wpbb_do_sync())
		return;
	if (!wpbb_is_enabled_for_post($id))
		return; // sync disabled for that post
	$post = get_post($id);
	$row = wpbb_get_table_item('wp_post_id', $post->ID);
	if (!$row)
	{
		return;
	}
	if ($post->comment_status == 'open')
	{
		wpbb_open_bb_topic($row['bb_topic_id']);
	} elseif ($post->comment_status == 'closed')
	{
		wpbb_close_bb_topic($row['bb_topic_id']);
	}
}

function wpbb_afterpublish($id)
{
	// error_log('wordpress: wpbb_afterpublish');
	if (!wpbb_do_sync())
		return;
	if (!wpbb_is_enabled_for_post($id))
		return; // sync disabled for that post
	$post = get_post($id);
	// so maybe? ;)
	$row = wpbb_get_table_item('wp_post_id', $post->ID);
	if (!$row && get_option('wpbb_topic_after_posting') == 'enabled')
	{
		wpbb_create_bb_topic($post);
	}
}

function wpbb_afterpostedit($id)
{
	//error_log('wordpress: wpbb_afterpostedit');
	if (!wpbb_do_sync())
		return;
	if (!wpbb_is_enabled_for_post($id))
		return; // sync disabled for that post
	$row = wpbb_get_table_item('wp_post_id', $id);
	if ($row)
	{
		wpbb_edit_bb_tags($id, $row['bb_topic_id']);
		wpbb_edit_first_bb_post($id);
	}
}

function wpbb_get_real_comment_status($id)
{
	// FIXME: remove that shit! it must work original way fine. Really must? ;)
	global $wpdb;
	$comment = get_comment($id);
	return $wpdb->get_var('SELECT comment_approved FROM '.$wpdb->prefix.'comments WHERE comment_id = '.$id);
}

function wpbb_do_sync()
{
	if (get_option('wpbb_plugin_status') != 'enabled')
		return false;
	global $wpbb_plugin;
	if (!$wpbb_plugin)
		// we don't need endless loop ;)
		return false;
	return true; // everything is ok ;)
}

function wpbb_sync_that_status($id)
{
	if (wpbb_get_real_comment_status($id) == 1 || get_option('wpbb_sync_all_comments') == 'enabled')
		return true;
	else
		return false;
}

function wpbb_do_ping_sync(&$comment)
{
	if ($comment->comment_type != '') // not a normal comment(pingback or trackback)
	{
		if (get_option('wpbb_pings') == 'disabled' || !get_option('wpbb_pings'))
			return false;
		if (get_option('wpbb_pings') == 'show_url')
			$comment->comment_author = 'Ping: '.preg_replace('/.*?:\/\/([^\/]*)\/.*/', '${1}', $comment->comment_author_url);
		return true;
	}
	return true;
}

function wpbb_is_enabled_for_post($post_id)
{
	if (get_post_meta($post_id, 'wpbb_sync_comments', true) == 'yes')
		return true; // sync enabled for that post
	elseif (get_option('wpbb_sync_by_default') == 'enabled' && get_post_meta($post_id, 'wpbb_sync_comments', true) != 'no')
		return true; // sync enabled for that post
	return false; // sync disabled for that post
}

// ===== start of bb functions =====

function wpbb_first_topic_post_text($post)
{
	$per_post_type = get_post_meta($post->ID, 'wpbb_first_post_type', true);

	if ($per_post_type == 'first_paragraphs')
	{
		$count = get_post_meta($post->ID, 'wpbb_first_post_paragraphs', true);
		$content = str_replace("\r", '', $post->post_content);
		$paragraphs = explode("\n\n", $content);

		if ($count > 0 && count($paragraphs) > $count)
			$content = implode("\n\n", array_slice($paragraphs, 0, $count));
		else
			$content = $post->post_content;
	} else {
		$type = get_option('wpbb_first_post_type');

		if ($type == 'excerpt' && !empty($post->post_excerpt)) // excerpt cannot be empty
		{
			$content = $post->post_excerpt;
		} elseif ($type == 'full')
		{
			$content = $post->post_content;
		} else // default if option not set
		{
			if (strpos($post->post_content, '<!--more-->') === false)
				$content = $post->post_content;
			else
				$content = substr($post->post_content, 0, strpos($post->post_content, '<!--more-->'));
		}
	}

	if (get_option('wpbb_quote_first_post') == 'enabled')
		$content = '<blockquote>'.$content.'</blockquote>';

	return $content;
}

function wpbb_create_bb_topic(&$post)
{
	$tags = array();
	foreach (wp_get_post_tags($post->ID) as $tag)
	{
		$tags[] = $tag->name;
	}
	$categories = array();
	foreach (get_the_category($post->ID) as $cat)
	{
		$categories[] = $cat->term_id;
	}
	$post_content = wpbb_first_topic_post_text($post);
	$post_content .= '<br/><a href="'.get_permalink($post->ID).'">'.$post->post_title.'</a>';
	$request = array(
		'action' => 'create_topic',
		'topic' => apply_filters('the_title', $post->post_title),
		'post_content' => wpbb_correct_links(apply_filters('the_content', $post_content)),
		'user' => $post->post_author,
		'tags' => implode(', ', $tags),
		'categories' => serialize($categories),
		'post_id' => $post->ID,
		'comment_id' => 0,
		'comment_approved' => 1
	);
	$answer = wpbb_send_command($request);
	$data = unserialize($answer);
	return wpbb_add_table_item($post->ID, 0, $data['topic_id'], $data['post_id']);
}

function wpbb_continue_bb_topic(&$post, &$comment)
{
	$request = array(
		'action' => 'continue_topic',
		'post_content' => wpbb_correct_links(apply_filters('comment_text', $comment->comment_content)),
		'post_id' => $post->ID,
		'comment_id' => $comment->comment_ID,
		'user' => $comment->user_id,
		'comment_author' => $comment->comment_author,
		'comment_author_email' => $comment->comment_author_email,
		'comment_author_url' => $comment->comment_author_url,
		'comment_approved' => wpbb_get_real_comment_status($comment->comment_ID)
	);
	$answer = wpbb_send_command($request);
	$data = unserialize($answer);
	wpbb_add_table_item($post->ID, $comment->comment_ID, $data['topic_id'], $data['post_id']);
}

function wpbb_edit_bb_post(&$post, &$comment)
{
	$request = array(
		'action' => 'edit_post',
		'post_content' => wpbb_correct_links(apply_filters('comment_text', $comment->comment_content)),
		'post_id' => $post->ID,
		'comment_id' => $comment->comment_ID,
		'user' => $comment->user_id,
		'comment_author' => $comment->comment_author,
		'comment_author_email' => $comment->comment_author_email,
		'comment_author_url' => $comment->comment_author_url,
		'comment_approved' => wpbb_get_real_comment_status($comment->comment_ID)
	);
	wpbb_send_command($request);
}

function wpbb_edit_first_bb_post($post_id)
{
	$post = get_post($post_id);
	$post_content = wpbb_first_topic_post_text($post);
	$post_content .= '<br/><a href="'.get_permalink($post->ID).'">'.$post->post_title.'</a>';
	$request = array(
		'action' => 'edit_post',
		'get_row_by' => 'bb_post_id',
		'bb_post_id' => wpbb_get_bb_topic_first_post($post->ID),
		'topic_title' => apply_filters('the_title', $post->post_title),
		'post_content' => wpbb_correct_links(apply_filters('the_content', $post_content)),
		'post_id' => $post->ID,
		'comment_id' => 0, // post, not a comment
		'user' => $comment->user_id,
		'comment_approved' => 1 // approved
	);
	wpbb_send_command($request);
}

function wpbb_close_bb_topic($topic)
{
	$request = array(
		'action' => 'close_bb_topic',
		'topic_id' => $topic,
	);
	wpbb_send_command($request);
}

function wpbb_open_bb_topic($topic)
{
	$request = array(
		'action' => 'open_bb_topic',
		'topic_id' => $topic,
	);
	wpbb_send_command($request);
}

function wpbb_check_bb_settings()
{
	$answer = wpbb_send_command(array('action' => 'check_bb_settings'));
	$data = unserialize($answer);
	return $data;
}

function wpbb_set_bb_plugin_status($status)
{
	// when enabling in WordPress
	$request = array(
		'action' => 'set_bb_plugin_status',
		'status' => $status,
	);
	$answer = wpbb_send_command($request);
	$data = unserialize($answer);
	return $data;
}

function wpbb_edit_bb_tags($wp_post, $bb_topic)
{
	$tags = array();
	foreach (wp_get_post_tags($wp_post) as $tag)
	{
		$tags[] = $tag->name;
	}
	$request = array(
		'action' => 'edit_bb_tags',
		'topic' => $bb_topic,
		'tags' => implode(', ', $tags)
	);
	wpbb_send_command($request);
}

function wpbb_get_bb_topic_first_post($post_id)
{
	global $wpdb;
	return $wpdb->get_var("SELECT bb_post_id FROM ".$wpdb->prefix."wpbb_ids WHERE wp_post_id = $post_id AND wp_comment_id = 0 LIMIT 1");
}

// ===== end of bb functions =====

// ===== start of wp functions =====

function wpbb_get_categories()
{
	$categories = array();
	foreach (get_categories(array('hide_empty' => false)) as $cat)
		$categories[$cat->term_id] = $cat->cat_name;
	echo serialize($categories);
}

function wpbb_edit_wp_comment()
{
	$comment = get_comment($id);
	if (!wpbb_is_enabled_for_post($comment->comment_post_ID))
		return; // sync disabled for that post
	$new_info = array(
		'comment_ID' => $_POST['comment_id'],
		'comment_content' => $_POST['post_text'],
		'comment_approved' => wpbb_status_bb2wp($_POST['post_status'])
	);
	remove_all_filters('comment_save_pre');
	wp_update_comment($new_info);
}

function wpbb_add_wp_comment()
{
	// NOTE: wordpress have something very strange with users
	// everyone cant have an registered id and different display_name
	// and other info for posts. strange? i think so ;)
	if (!wpbb_is_enabled_for_post($_POST['wp_post_id']))
		return; // sync disabled for that post
	global $current_user;
	get_currentuserinfo();
	$info = array(
		'comment_content' => $_POST['post_text'],
		'comment_post_ID' => $_POST['wp_post_id'],
		'user_id' => $_POST['user'],
		'comment_author_email' => $current_user->user_email,
		'comment_author_url' => $current_user->user_url,
		'comment_author' => $current_user->display_name,
		'comment_agent' => 'wordpress-bbpress-syncronization plugin by bobrik (http://bobrik.name)'
	);
	$comment_id = wp_insert_comment($info);
	wp_set_comment_status($comment_id, wpbb_status_bb2wp($_POST['post_status']));
	wpbb_add_table_item($_POST['wp_post_id'], $comment_id, $_POST['topic_id'], $_POST['post_id']);
	$data = serialize(array('comment_id' => $comment_id));
	echo $data;
}

function wpbb_close_wp_comments()
{
	if (!wpbb_is_enabled_for_post($_POST['post_id']))
		return; // sync disabled for that post
	global $wpdb;
	$wpdb->query('UPDATE '.$wpdb->prefix.'posts SET comment_status = \'closed\' WHERE ID = '.$_POST['post_id']);
}

function wpbb_open_wp_comments()
{
	if (!wpbb_is_enabled_for_post($_POST['post_id']))
		return; // sync disabled for that post
	global $wpdb;
	$wpdb->query('UPDATE '.$wpdb->prefix.'posts SET comment_status = \'open\' WHERE ID = '.$_POST['post_id']);
}

function wpbb_set_wp_plugin_status()
{
	// to be call through http request
	$status = $_POST['status'];
	if ((wpbb_check_wp_settings() == 0 && $status == 'enabled') || $status == 'disabled')
	{
		update_option('wpbb_plugin_status', $status);
	} else
	{
		$status = 'disabled';
		update_option('wpbb_plugin_status', $status);
	}
	$data = serialize(array('status' => $status));
	echo $data;
}

function wpbb_check_wp_settings()
{
	if (!wpbb_test_pair())
		return 1; // cannot establish connection to bb
	if (!wpbb_secret_key_equal())
		return 2; // secret keys are not equal
	if (!wpbb_check_bbwp_version())
		return 3; // too old bbPress part version
	return 0; // everything is ok
}

function wpbb_status_error($code)
{
	if ($code == 0)
		return __('Everything is ok!', 'wpbb-sync');
	if ($code == 1)
		return __('Cannot establish connection to bbPress part', 'wpbb-sync');
	elseif ($code == 2)
		return __('Invalid secret key', 'wpbb-sync');
	elseif ($code == 3)
		return __('Too old bbPress part plugin version', 'wpbb-sync');
}

function wpbb_edit_wp_tags()
{
	wp_set_post_tags($_POST['post'], unserialize((str_replace('\"', '"', $_POST['tags']))));
}

// ===== end of wp functions =====

// action => answer
// for actions that only return data and don't change their arguments
$wpbb_cachable_requests = array(
	'test' => '',
	'keytest' => '',
	'get_wpbb_version' => '',
	'check_bb_settings' => ''
);

function wpbb_send_command($pairs)
{
	global $wpbb_cachable_requests;
	if (isset($wpbb_cachable_requests[$pairs['action']]) && !empty($wpbb_cachable_requests[$pairs['action']]))
		return $wpbb_cachable_requests[$pairs['action']];
	$url = get_option('wpbb_bbpress_url').'my-plugins/wordpress-bbpress-syncronization/bbwp-sync.php';
	preg_match('@https?://([\-_\w\.]+)+(:(\d+))?/(.*)@', $url, $matches);
	if (!$matches)
		return;
	// setting user
	if (!isset($pairs['user']))
	{
		global $user_ID;
		global $user_login;
		get_currentuserinfo();
		if ($user_ID)
		{
			$pairs['user'] = $user_ID;
			$pairs['username'] = $user_login;
		} else
		{
			// anonymous user
			$pairs['user'] = 0;
		}
	}
	$answer = '';
	if (substr($url, 0, 5) == 'https')
	{
		// must use php-curl to work with https
		// FIXME: really works? :)
		$ch = curl_init($url);
		curl_setopt ($ch, CURLOPT_POST, 1);
		curl_setopt ($ch, CURLOPT_POSTFIELDS, $pairs);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
		$answer = curl_exec($ch);
		curl_close($ch);
	} else
	{
		$port = $matches[3] ? $matches[3] : 80;
		global $wp_version;
		$request = '';
		foreach ($pairs as $key => $data)
			$request .= $key.'='.urlencode(stripslashes($data)).'&';
		$http_request  = "POST /$matches[4] HTTP/1.0\r\n";
		$http_request .= "Host: $matches[1]\r\n";
		$http_request .= "Content-Type: application/x-www-form-urlencoded; charset=" . get_option('blog_charset') . "\r\n";
		$http_request .= "Content-Length: " . strlen($request) . "\r\n";
		$http_request .= "User-Agent: WordPress/$wp_version | WordPress-bbPress	syncronization\r\n";
		$http_request .= "\r\n";
		$http_request .= $request;
		$response = '';
		if( false != ( $fs = @fsockopen($matches[1], $port, $errno, $errstr, 10) ) ) {
			fwrite($fs, $http_request);

			while ( !feof($fs) )
				$response .= fgets($fs, 1160); // One TCP-IP packet
			fclose($fs);
			$response = explode("\r\n\r\n", $response, 2);
		}
		$answer = $response[1];
	}
	// f*cking windows dirty hacks. hate you, dumb idiots from micro$oft!
	$answer = trim(trim($answer, "\xEF\xBB\xBF"));
	$wpbb_cachable_requests[$pairs['action']] = $answer;
	return $answer;
}

function wpbb_test_pair()
{
	$answer = wpbb_send_command(array('action' => 'test'));
	// return 1 if test passed, 0 otherwise
	// TODO: check configuration!
	$data = unserialize($answer);
	return $data['test'] == 1 ? 1 : 0;
}

function wpbb_secret_key_equal()
{
	$answer = wpbb_send_command(array('action' => 'keytest', 'secret_key' => md5(get_option('wpbb_secret_key'))));
	$data = unserialize($answer);
	return $data['keytest'];
}

function wpbb_compare_keys_local()
{
	return $_POST['secret_key'] == md5(get_option('wpbb_secret_key')) ? 1 : 0;
}

function wpbb_set_global_plugin_status($status)
{
	// FIXME: fix something here.
	$bb_settings = wpbb_check_bb_settings();
	if (($bb_settings['code'] == 0 && wpbb_check_wp_settings() == 0 && $status == 'enabled') || $status == 'disabled')
	{
		$bb_status = wpbb_set_bb_plugin_status($status);
		if ($bb_status['status'] == $status)
		{
			update_option('wpbb_plugin_status', $status);
			return;
		}
	}
	// disable everything, something wrong
	$status = 'disabled';
	$wp_status = wpbb_set_bb_plugin_status($status);
	update_option('wpbb_plugin_status', $status);		
}

function wpbb_check_settings()
{
	$bb_settings = wpbb_check_bb_settings();
	$wp_code = wpbb_check_wp_settings();
	$wp_message = wpbb_status_error($wp_code);
	# it's better to check bbPress ability to connect first
	if ($bb_settings['code'] == 1)
	{
		$data['code'] = 1;
		$data['message'] = '[bbPress part] '.$bb_settings['message'];
	} elseif ($wp_code != 0)
	{
		$data['code'] = $wp_code;
		$data['message'] = '[WordPress part] '.$wp_message;
	} elseif ($bb_settings['code'] != 0)
	{
		$data['code'] = $bb_settings['code'];
		$data['message'] = '[bbPress part] '.$bb_settings['message'];
	} else
	{
		$data['code'] = 0;
		$data['message'] = __('Everything is ok!', 'wpbb-sync');
	}
	return $data;
}

if (isset($_REQUEST['wpbb-listener']))
{
	// define redirection if request have wpbb-listener key
	add_action('template_redirect', 'wpbb_listener');
} else
{
	// work as truly plugin
	global $wpbb_plugin;
	$wpbb_plugin = 1;
}

function wpbb_listener()
{
	if (empty($_POST['action']))
	{
		echo 'If you see that, plugin must connect well. bbPress test response (must be a:1:{s:4:"test";i:1;}): '.wpbb_send_command(array('action' => 'test'));
		exit;
	}
	set_current_user($_POST['user']);
	// error_log("GOT COMMAND for WordPress part: ".$_POST['action']);
	if ($_POST['action'] == 'test')
	{
		echo serialize(array('test' => 1));
		exit;
	} elseif ($_POST['action'] == 'keytest')
	{
		echo serialize(array('keytest' => wpbb_compare_keys_local()));
		exit;
	}
	// here we need secret key, only if not checking settings
	if (!wpbb_secret_key_equal() && $_POST['action'] != 'check_wp_settings')
	{
		// go away, damn cheater!
		exit;
	}
	if ($_POST['action'] == 'set_wp_plugin_status')
	{
		wpbb_set_wp_plugin_status();
	} elseif ($_POST['action'] == 'check_wp_settings')
	{
		$code = wpbb_check_wp_settings();
		echo serialize(array('code' => $code, 'message' => wpbb_status_error($code)));
	} elseif ($_POST['action'] == 'get_wpbb_version')
	{
		global $wpbb_version;
		echo serialize(array('version' => $wpbb_version));
	} elseif ($_POST['action'] == 'get_categories')
	{
		wpbb_get_categories();
	}
	// we need enabled plugins for next actions
	if (get_option('wpbb_plugin_status') != 'enabled')
	{
		// stop sync
		exit;
	}
	if ($_POST['action'] == 'edit_comment')
	{
		wpbb_edit_wp_comment();
	} elseif ($_POST['action'] == 'add_comment')
	{
		wpbb_add_wp_comment();
	} elseif ($_POST['action'] == 'close_wp_comments')
	{
		wpbb_close_wp_comments();
	} elseif ($_POST['action'] == 'open_wp_comments')
	{
		wpbb_open_wp_comments();
	} elseif ($_POST['action'] == 'edit_wp_tags')
	{
		wpbb_edit_wp_tags();
	} elseif ($_POST['action'] == 'get_post_link')
	{
		wpbb_post_link();
	}
	exit;
}

function wpbb_install()
{
	// create table at first install
	global $wpdb;
	$wpbb_sync_db_version = 0.2;
	$table = $wpdb->prefix.'wpbb_ids';
	$sql = 'CREATE TABLE '.$table.' (
		`wp_comment_id` INT UNSIGNED NOT NULL,
		`wp_post_id` INT UNSIGNED NOT NULL,
		`bb_topic_id` INT UNSIGNED NOT NULL,
		`bb_post_id` INT UNSIGNED NOT NULL
	);';
	if ($wpdb->get_var('SHOW TABLES LIKE \'$table_name\'') != $table_name) 
	{
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
		add_option('wpbb_sync_db_version', $wpbb_sync_db_version);
	}
	$installed_version = get_option('wpbb_sync_db_version');
	// upgrade table if necessary
	if ($installed_version != $wpbb_sync_db_version)
	{
		require_once(ABSPATH.'wp-admin/includes/upgrade.php');
		dbDelta($sql);
		update_option('wpbb_sync_db_version', $wpbb_sync_db_version);
	}
	if (!get_option('wpbb_quote_first_post'))
	{
		update_option('wpbb_quote_first_post', 'enabled');
		update_option('wpbb_comments_to_show', -1);
		update_option('wpbb_max_comments_with_form', -1);
		update_option('wpbb_quote_first_post', 'enabled');
		update_option('wpbb_sync_by_default', 'enabled');
		update_option('wpbb_sync_all_comments', 'disabled');
		update_option('wpbb_point_to_forum', 'enabled');
		update_option('wpbb_create_topic_anyway', 'disabled');
		update_option('wpbb_topic_after_posting', 'disabled');
	}
	if (!get_option('wpbb_pings'))
		update_option('wpbb_pings', 'disabled');
	if (get_option('wpbb_quote_first_post') == 'enabled')
		update_option('wpbb_first_post_type', 'quoted_more_tag');
	if (!get_option('wpbb_regards'))
		update_option('wpbb_regards', 'enabled');
	// next options must be cheched by another conditions!
}

function wpbb_deactivate()
{
	// deactivate on disabling
	wpbb_set_global_plugin_status('disabled');
}

function wpbb_add_table_item($wp_post, $wp_comment, $bb_topic, $bb_post)
{
	global $wpdb;
	return $wpdb->query('INSERT INTO '.$wpdb->prefix."wpbb_ids (wp_post_id, wp_comment_id, bb_topic_id, bb_post_id)
		VALUES ($wp_post, $wp_comment, $bb_topic, $bb_post)");
}

function wpbb_get_table_item($field, $value)
{
	global $wpdb;
	return $wpdb->get_row('SELECT * FROM '.$wpdb->prefix."wpbb_ids WHERE $field = $value LIMIT 1", ARRAY_A);
}

function wpbb_delete_table_item($field, $value)
{
	global $wpdb;
	$wpdb->query('DELETE FROM '.$wpdb->prefix."wpbb_ids WHERE $field = $value");
}

function wpbb_status_bb2wp($status)
{
	// return WordPres comment status equal to bbPress post status
	if ($status == 0)
		return 1; // hold
	if ($status == 1)
		return 0; // approved
	if ($status == 2)
		return 'spam'; // spam
}

function wpbb_options_page()
{
	if (function_exists('add_submenu_page'))
	{
		add_submenu_page('plugins.php', __('bbPress syncronization', 'wpbb-sync'), __('bbPress syncronization', 'wpbb-sync'), 'manage_options', 'wpbb-config', 'wpbb_config');
	}
}

function wpbb_config()
{
	if (isset($_POST['stage']) && $_POST['stage'] == 'process')
	{
		if (function_exists('current_user_can') && !current_user_can('manage_options'))
			die(__('Cheatin&#8217; uh?'));
		update_option('wpbb_bbpress_url', $_POST['bbpress_url']);
		update_option('wpbb_secret_key', $_POST['secret_key']);
		update_option('wpbb_comments_to_show', (int) $_POST['comments_to_show'] >= -1 ? (int) $_POST['comments_to_show'] : -1);
		update_option('wpbb_max_comments_with_form', (int) $_POST['max_comments_with_form'] >= -1 ? (int) $_POST['max_comments_with_form'] : -1);
		wpbb_set_global_plugin_status($_POST['plugin_status'] == 'on' ? 'enabled' : 'disabled');
		update_option('wpbb_quote_first_post', $_POST['enable_quoting'] == 'on' ? 'enabled' : 'disabled');
		update_option('wpbb_sync_by_default', $_POST['sync_by_default'] == 'on' ? 'enabled' : 'disabled');
		update_option('wpbb_sync_all_comments', $_POST['sync_all_comments'] == 'on' ? 'enabled' : 'disabled');
		update_option('wpbb_point_to_forum', $_POST['point_to_forum'] == 'on' ? 'enabled' : 'disabled');
		update_option('wpbb_create_topic_anyway', $_POST['create_topic_anyway'] == 'on' ? 'enabled' : 'disabled');
		update_option('wpbb_topic_after_posting', $_POST['topic_after_posting'] == 'on' ? 'enabled' : 'disabled');
		update_option('wpbb_pings', $_POST['pings']);
		update_option('wpbb_first_post_type', $_POST['first_post_type']);
	}

?>
<div class="wrap">
	<h2><?php _e('bbPress syncronization', 'wpbb-sync'); ?></h2>
	<div style="padding:10px;border-bottom:1px dotted #aaa"><span style="margin:10px auto;font-weight:bold;">Please <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=ibobrik%40gmail%2ecom&lc=US&item_name=WordPress%2dbbPress%20syncronization%20plugin&currency_code=USD&bn=PP%2dDonationsBF%3abtn_donate_LG%2egif%3aNonHostedGuest">donate</a> if you like this plugin, or at least <a href="http://bobrik.name/code/wordpress/wordpress-bbpress-syncronization/">give feedback</a>, it's important to developers.</span> <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=ibobrik%40gmail%2ecom&lc=US&item_name=WordPress%2dbbPress%20syncronization%20plugin&currency_code=USD&bn=PP%2dDonationsBF%3abtn_donate_LG%2egif%3aNonHostedGuest" style="float:right;"><img src="https://www.paypal.com/en_US/i/btn/btn_donate_LG.gif" alt="Donate!" /></a></div>
	<form name="form1" method="post" action="">
	<input type="hidden" name="stage" value="process" />
	<table width="100%" cellspacing="2" cellpadding="5" class="form-table">
		<tr valign="baseline">
			<th scope="row"><?php _e("bbPress url", 'wpbb-sync'); ?></th>
			<td>
				<input type="text" name="bbpress_url" value="<?php echo get_option('wpbb_bbpress_url'); ?>" />
				<?php
				$err = wpbb_check_wp_settings(); // only one error at once, let's show other if only previous was fixed
				if (!get_option('wpbb_bbpress_url') && wpbb_test_pair())
				{
					_e('bbPress url (we\'ll add <em>my-plugins/wordpress-bbpress-syncronization/bbwp-sync.php</em> to your url)', 'wpbb-sync');
				} else
				{
					if (wpbb_test_pair())
					{
						_e('Everything is ok!', 'wpbb-sync');
					} else
					{
						echo  '<b>'.__('URL is incorrect or connection error, please verify it (full variant): ', 'wpbb-sync').
							'<a href="'.get_option('wpbb_bbpress_url').'my-plugins/wordpress-bbpress-syncronization/bbwp-sync.php">'.
							get_option('wpbb_bbpress_url').'my-plugins/wordpress-bbpress-syncronization/bbwp-sync.php</a></b>';
					}
				}
				?>
			</td>
		</tr>
		<tr valign="baseline">
			<th scope="row"><?php _e('Secret key', 'wpbb-sync');  ?></th>
			<td>
				<input type="text" name="secret_key" value="<?php echo get_option('wpbb_secret_key', 'wpbb-sync'); ?>" />
				<?php
				if (!get_option('wpbb_secret_key') || ($err != 0 && $err != 2))
				{
					_e('We need it for secure communication between your systems', 'wpbb-sync');
				} else
				{
					if (wpbb_secret_key_equal())
					{
						_e('Everything is ok!', 'wpbb-sync');
					} else
					{
						echo '<b>'.__('Error! Not equal secret keys in WordPress and bbPress', 'wpbb-sync').'</b>';
					}
				}
				?>
			</td>
		</tr>
		<tr valign="baseline">
			<th scope="row"><?php _e('Sync comments by default', 'wpbb-sync'); ?></th>
			<td>
				<input type="checkbox" name="sync_by_default"<?php echo (get_option('wpbb_sync_by_default') == 'enabled') ? ' checked="checked"' : '';?> /> (<?php _e('Also will be used for posts without any sync option value', 'wpbb-sync'); ?>)
			</td>
		</tr>
		<tr valign="baseline">
			<th scope="row"><?php _e('Create topic on posting', 'wpbb-sync'); ?></th>
			<td>
				<input type="checkbox" name="topic_after_posting"<?php echo (get_option('wpbb_topic_after_posting') == 'enabled') ? ' checked="checked"' : '';?> /> (<?php _e('Will create topic in bbPress after WordPress post publishing', 'wpbb-sync'); ?>)
			</td>
		</tr>
		<tr valign="baseline">
			<th scope="row"><?php _e('Sync all comments', 'wpbb-sync'); ?></th>
			<td>
				<input type="checkbox" name="sync_all_comments"<?php echo (get_option('wpbb_sync_all_comments') == 'enabled') ? ' checked="checked"' : '';?> /> (<?php _e('Sync comment even if not approved. Comment will have the same status at forum', 'wpbb-sync'); ?>)
			</td>
		</tr>
		<tr valign="baseline">
			<th scope="row"><?php _e('Create topic anyway', 'wpbb-sync'); ?></th>
			<td>
				<input type="checkbox" name="create_topic_anyway"<?php echo (get_option('wpbb_create_topic_anyway') == 'enabled') ? ' checked="checked"' : '';?> /> (<?php _e('Create topic even if comment not approved. Will create topic <strong>without</strong> unapproved comment, only first post', 'wpbb-sync'); ?>)
			</td>
		</tr>
		<tr valign="baseline">
			<th scope="row"><?php _e('First post type', 'wpbb-sync'); ?></th>
			<td>
				<select name="first_post_type">
					<option value="full"<?php echo (get_option('wpbb_first_post_type') == 'full' ? ' selected="selected"':''); ?>><?php _e('Full post', 'wpbb-sync'); ?></option>
					<option value="more_tag"<?php echo (get_option('wpbb_first_post_type') == 'more_tag' ? ' selected="selected"':''); ?>><?php _e('Post before &lt;!--more--&gt; tag', 'wpbb-sync'); ?></option>
					<option value="excerpt"<?php echo (get_option('wpbb_first_post_type') == 'excerpt' ? ' selected="selected"':''); ?>><?php _e('Excerpt', 'wpbb-sync'); ?></option>
					<option value="first_paragraphs"<?php echo (get_option('wpbb_first_post_type') == 'first_paragraphs' ? ' selected="selected"':''); ?>><?php _e('First paragraphs', 'wpbb-sync'); ?></option>
				</select> (<?php _e('Select what text for the first post will be displayed in forum topic', 'wpbb-sync'); ?>)
			</td>
		</tr>
		<tr valign="baseline">
			<th scope="row"><?php _e('Enable quoting', 'wpbb-sync'); ?></th>
			<td>
				<input type="checkbox" name="enable_quoting"<?php echo (get_option('wpbb_quote_first_post') == 'enabled') ? ' checked="checked"' : '';?> /> (<?php _e('If enabled, first post summary in bbPress will be blockquoted', 'wpbb-sync'); ?>)
			</td>
		</tr>
		<tr valign="baseline">
			<th scope="row"><?php _e('Amount of comments to show', 'wpbb-sync'); ?></th>
			<td>
				<input type="text" name="comments_to_show" value="<?php echo get_option('wpbb_comments_to_show'); ?>" /> (<?php _e('Set to <em>-1</em> to show all comments', 'wpbb-sync'); ?>)
			</td>
		</tr>
		<tr valign="baseline">
			<th scope="row"><?php _e('Point to forum in latest comment', 'wpbb-sync'); ?></th>
			<td>
				<input type="checkbox" name="point_to_forum"<?php echo (get_option('wpbb_point_to_forum') == 'enabled') ? ' checked="checked"' : ''; ?> /> (<?php _e('If enabled, last comment will have link to forum discussion. Don\'t set previous option to 0 to use that. It is better to use template functions', 'wpbb-sync'); ?>)
			</td>
		</tr>
		<tr valign="baseline">
			<th scope="row"><?php _e('Max comments with form', 'wpbb-sync'); ?></th>
			<td>
				<input type="text" name="max_comments_with_form" value="<?php echo get_option('wpbb_max_comments_with_form'); ?>" /> (<?php _e('Set to <em>-1</em> to show new comment form with any comments count', 'wpbb-sync'); ?>)
			</td>
		</tr>
		<tr valign="baseline">
			<th scope="row"><?php _e('PingBacks & Trackbacks', 'wpbb-sync'); ?></th>
			<td>
				<select name="pings">
					<option value="disabled"<?php echo (get_option('wpbb_pings') == 'disabled' ? ' selected="selected"':''); ?>><?php _e('Disable', 'wpbb-sync'); ?></option>
					<option value="show_url"<?php echo (get_option('wpbb_pings') == 'show_url' ? ' selected="selected"':''); ?>><?php _e('Show site url as username', 'wpbb-sync'); ?></option>
				</select>
				(<?php _e('Select what to do with pings. URLs will be shorten to domain name', 'wpbb-sync'); ?>)
			</td>
		</tr>
		<tr valign="baseline">
			<th scope="row" style="font-weight:bold"><?php _e('Enable plugin', 'wpbb-sync'); ?></th>
			<td><?php $check = wpbb_check_settings(); if ($check['code'] != 0) wpbb_set_global_plugin_status('disabled'); ?>
				<input type="checkbox" name="plugin_status"<?php echo (get_option('wpbb_plugin_status') == 'enabled') ? ' checked="checked"' : ''; echo ($check['code'] == 0) ? '' : ' disabled="disabled"'; ?> /> (<?php echo ($check['code'] == 0) ? __('Allowed by both parts', 'wpbb-sync') : __('Not allowed: ', 'wpbb-sync').$check['message'] ?>)
			</td>
		</tr>
	</table>
	<p class="submit">
		<input class="button-primary" type="submit" name="Submit" value="<?php _e('Save Changes', 'wpbb-sync'); ?>" />
	</p>
	</form>
</div>
<?php
}

function wpbb_post_options()
{
	global $post;
	echo '<div class="postbox"><h3>'.__('bbPress syncronization', 'wpbb-sync').'</h3><div class="inside"><p>'.__('Syncronize post comments with bbPress?', 'wpbb-sync').'  ';
	if (get_post_meta($post->ID, 'wpbb_sync_comments', true) == 'no') 
	{
		$checked = '';
	} else 
	{
		$checked = 'checked="checked"';
	}
	echo '<input type="checkbox" name="wpbb_sync_comments" '.$checked.' />';

	if (get_option('wpbb_first_post_type') == 'first_paragraphs')
	{
		echo '<br/>';
		echo '<input type="hidden" name="wpbb_first_post_type" value="first_paragraphs" />';
		echo __('Paragraphs count to start new topic with: ').'<input type="text" size=1 name="wpbb_first_post_paragraphs" value="2" />';
	}
 
	// additional checks for checkbox above presenсe
	echo '<input type="hidden" name="wpbb_sync_comments_presenсe" value="yes" />';
	echo '</p></div></div>';
}

function wpbb_store_post_options($post_id)
{
	$post = get_post($post_id);
	// we must change value only if showed checkbox
	if (isset($_POST['wpbb_sync_comments_presenсe']))
	{
		$value = $_POST['wpbb_sync_comments'] == 'on' ? 'yes' : 'no';
		update_post_meta($post_id, 'wpbb_sync_comments', $value);
		if (isset($_POST['wpbb_first_post_type']))
			update_post_meta($post_id, 'wpbb_first_post_type', $_POST['wpbb_first_post_type']);
		if (isset($_POST['wpbb_first_post_paragraphs']))
			update_post_meta($post_id, 'wpbb_first_post_paragraphs', (int) $_POST['wpbb_first_post_paragraphs']);
	}
}


function wpbb_comments_array_count($comments)
{
	global $post;
	if (get_option('wpbb_plugin_status') != 'enabled')
		return $comments; // plugin disabled
	if (!wpbb_is_enabled_for_post($post->ID))
		return $comments;
	$maxform = get_option('wpbb_max_comments_with_form');
	global $post;
	if ($maxform != -1 and count($comments) > $maxform)
		$post->comment_status = 'closed';
	if (count($comments) == 0)
		return $comments; // we have nothing to change
	$max = get_option('wpbb_comments_to_show');
	if (get_option('wpbb_point_to_forum') == 'enabled' && $max != 0)
	{
		$row = wpbb_get_table_item('wp_post_id', $post->ID);
		if ($row)
		{
			$topic_id = $row['bb_topic_id'];
			$answer = unserialize(wpbb_send_command(array('action' => 'get_topic_link', 'topic_id' => $topic_id)));
			$link = $answer['link'];
		}
		// FIXME: dirty hack to get last array element
		$comments[count($comments)-1]->comment_content .= '<br/><p class="wpbb_continue_discussion">'.
			__('Please continue discussion on the forum: ', 'wpbb-sync')."<a href='$link'> link</a></p>";
	}
	if ($max == -1)
		return $comments;
	$i = count($comments);
	while ($i > $max)
	{
		array_shift($comments);
		--$i;
	}
	return $comments;
}


function wpbb_check_bbwp_version()
{
	$answer = unserialize(wpbb_send_command(array('action' => 'get_bbwp_version')));
	global $min_version;
	return ($answer['version'] < $min_version) ? 0 : 1;
}

function wpbb_correct_links($text)
{
	$siteurl = preg_replace('|(://[^/]+/)(.*)|', '${1}', get_option('siteurl'));
	if (substr($siteurl, -1) != '/')
		$siteurl .= '/';
	$current_url = substr($siteurl, 0, -1).preg_replace('|(.*/)[^/]*|', '${1}', $_SERVER['REQUEST_URI']);
	if (substr($current_url, -1) != '/')
		$current_url .= '/';
	// ':' is for protocol handling, must be replaced by '(://)', but doesn't work :-(
	// for absolute links with starting '/'
	$text = preg_replace('/(href|src)=(["\'])\/([^"\':]+)\2/', '${1}=${2}'.$siteurl.'${3}${2}', $text);
	// for links not starting with '/'
	return preg_replace('/(href|src)=(["\'])([^"\':]+)\2/', '${1}=${2}'.$current_url.'${3}${2}', $text);
}

function wpbb_forum_thread_exists()
{
	if (get_option('wpbb_plugin_status') != 'enabled')
		return false; // plugin disabled
	global $post;
	$row = wpbb_get_table_item('wp_post_id', $post->ID);
	if ($row)
		return true;
	else
		return false;
}

function wpbb_forum_thread_url()
{
	global $post;
	$row = wpbb_get_table_item('wp_post_id', $post->ID);
	$answer = unserialize(wpbb_send_command(array('action' => 'get_topic_link', 'topic_id' => $row['bb_topic_id'])));
	return $answer['link'];
}

function wpbb_post_link()
{
	echo serialize(array('link' => get_permalink($_POST['post_id'])));
}

function wpbb_footer()
{
	if (!get_option('wpbb_regards') || get_option('wpbb_regards') == 'enabled')
		echo '<p style="text-align:center;">[ bbPress <a href="http://bobrik.name/code/wordpress/wordpress-bbpress-syncronization/">synchronization</a> by <a href="http://bobrik.name/cv">bobrik</a> ]</p>';
}

function wpbb_warning()
{
	if (get_option('wpbb_plugin_status') != 'enabled' && !isset($_POST['Submit']))
		echo '<div class="updated fade"><p><strong>'.__('Synchronization with bbPress is not enabled.', 'wpbb-sync').'</strong> '.sprintf(__('You must <a href="%1$s">check options and enable plugin</a> to make it work.', 'wpbb-sync'), 'plugins.php?page=wpbb-config').'</p></div>';
}


add_action('init', 'wpbb_add_textdomain');
register_deactivation_hook(__FILE__, 'wpbb_deactivate');
register_activation_hook(__FILE__, 'wpbb_install');
add_action('comment_post', 'wpbb_afterpost');
add_action('edit_comment', 'wpbb_afteredit');
add_action('delete_comment', 'wpbb_afterdelete');
add_action('wp_set_comment_status', 'wpbb_afteredit');
add_action('edit_post', 'wpbb_afterpostedit');
add_action('edit_post', 'wpbb_afterstatuschange');
add_action('wp_set_comment_status', 'wpbb_afteredit');
add_action('admin_menu', 'wpbb_options_page');
add_action('edit_form_advanced', 'wpbb_post_options');
add_action('draft_post', 'wpbb_store_post_options');
add_action('publish_post', 'wpbb_store_post_options');
add_action('save_post', 'wpbb_store_post_options');
add_action('publish_post', 'wpbb_afterpublish');
add_filter('comments_array', 'wpbb_comments_array_count');
add_action('admin_notices', 'wpbb_warning');
add_action('wp_footer', 'wpbb_footer');

?>
