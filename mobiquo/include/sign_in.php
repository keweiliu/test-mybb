<?php
defined('IN_MOBIQUO') or exit;
require_once MYBB_ROOT."inc/functions_post.php";
require_once MYBB_ROOT."inc/functions_user.php";
require_once MYBB_ROOT."inc/class_parser.php";
function sign_in_func()
{
	global $db, $lang, $theme, $plugins, $mybb, $session, $settings, $cache, $time, $mybbgroups, $mobiquo_config,$user,$register;
	// Load global language phrases
	$lang->load("member");
	$parser = new postParser;
	$token = trim($_POST['token']);
	$code = trim($_POST['code']);
	$username = $mybb->input['username'];
	$password = $mybb->input['password'];
	$post_email = $mybb->input['email'];
	$status = '';
	if(!empty($token) && !empty($code))
	{
		$result = tt_register_verify($token, $code);
	
		if($result->result && !empty($result->email))
		{
			$email = $result->email;
		    if(!empty($post_email) && $post_email != $email)
			{
				$status = 3;
			}
			else if($user = tt_get_user_by_email($email))
			{
				if(!empty($username) && strtolower($username) != strtolower($user['username']))
				{
					$status = 3;
				}
				else 
				{
					$register = 0;
					return tt_login_success();
				}		
			}
			else if(!empty($username) && !empty($email))
			{
				$profile = $result->profile;
				if($mybb->settings['disableregs'] == 1)
				{
					error($lang->registrations_disabled);
				}
				
				// Set up user handler.
				require_once MYBB_ROOT."inc/datahandlers/user.php";
				$userhandler = new UserDataHandler("insert");
				
				$birthday_arr = explode('-', $profile->birthday);
				$bday = array(
					"day" => $birthday_arr[2],
					"month" => $birthday_arr[1],
					"year" => $birthday_arr[0],
				);
				$user_field = array(
					'fid3' => ucfirst($profile->gender),
					'fid1' => $profile->location,
					'fid2' => $profile->description,
				);
				
				if( $mybb->settings['regtype'] == "admin" )
				{
					$usergroup = 5;
				}
				else 
				{
					$usergroup = isset($mybb->settings['tapatalk_register_group']) ? $mybb->settings['tapatalk_register_group'] : 2;
				}
				// Set the data for the new user.
				$user = array(
					"username" => $mybb->input['username'],
					"password" => $mybb->input['password'],
					"password2" => $mybb->input['password'],
					"email" => $email,
					"email2" => $email,
					"usergroup" => $usergroup,
					"referrer" => '',
					"timezone" => $mybb->settings['timezoneoffset'],
					"language" => '',
					"regip" => $session->ipaddress,
					"longregip" => my_ip2long($session->ipaddress),
					"coppa_user" => 0,
					"birthday" => $bday,
					"website" => $profile->link,
					"user_fields" => $user_field,
					"signature" => $profile->signature,
					"option" => array(),
					"regdate" => TIME_NOW,
					"lastvisit" => TIME_NOW
				);						
				
				if(!empty($profile->avatar_url))
				{
					$updated_avatar = tt_update_avatar_url($profile->avatar_url);
				}

				$userhandler->set_data($user);
				$userhandler->verify_birthday();
				$userhandler->verify_options();
				if($userhandler->verify_username_exists())
				{
					$status = 1;
				}
				else if(!$userhandler->verify_password() || !$userhandler->verify_username())
				{
					$errors = $userhandler->get_friendly_errors();
					error($errors[0]);
				}
				else
				{
					$userhandler->set_validated(true);
					$user = $userhandler->insert_user();
					if(!empty($updated_avatar))
					{
						$db->update_query("users", $updated_avatar, "uid='".$user['uid']."'");
					}	
					$register = 1;			
					return tt_login_success();
				}
			}
			else 
			{
				$status = 2;
			}
		}
		else if(!$result->result)
		{
			if(!empty($result->result_text))
			{
				error($result->result_text);
			}
			else 
			{
				error("Tapatalk ID verify faile!");
			}
		}
		
		if(!empty($status))
		{
			$response = new xmlrpcval(array(
		        'result'            => new xmlrpcval(0, 'boolean'),
		        'result_text'       => new xmlrpcval('', 'base64'),
			 	'status'          => new xmlrpcval($status, 'string'),
			 ), 'struct');
			return new xmlrpcresp($response);
		}
	}
	else
	{
		error("Invlaid params!");
	}
}

function tt_update_avatar_url($avatar_url)
{
	global $mybb,$user,$db;
	$avatar_url = preg_replace("#script:#i", "", $avatar_url);
	$avatar_url = preg_replace("/^(https)/", 'http', $avatar_url);
	$ext = get_extension($avatar_url);

	// Copy the avatar to the local server (work around remote URL access disabled for getimagesize)
	$file = fetch_remote_file($avatar_url);

	if(!$file)
	{
		return false;
	}
	else
	{
		$tmp_name = $mybb->settings['avataruploadpath']."/remote_".md5(random_str());
		$fp = @fopen($tmp_name, "wb");
		if(!$fp)
		{
			return false;
		}
		else
		{
			fwrite($fp, $file);
			fclose($fp);
			list($width, $height, $type) = @getimagesize($tmp_name);
			@unlink($tmp_name);
			if(!$type)
			{
				return false;
			}
		}
	}

	
	if($width && $height && $mybb->settings['maxavatardims'] != "")
	{
		list($maxwidth, $maxheight) = explode("x", my_strtolower($mybb->settings['maxavatardims']));
		if(($maxwidth && $width > $maxwidth) || ($maxheight && $height > $maxheight))
		{
			return false;
		}
	}

	if($width > 0 && $height > 0)
	{
		$avatar_dimensions = intval($width)."|".intval($height);
	}
	
	$updated_avatar = array(
		"avatar" => $db->escape_string($avatar_url.'?dateline='.TIME_NOW),
		"avatardimensions" => $avatar_dimensions,
		"avatartype" => "remote"
	);
	return $updated_avatar;
}

/*function tt_log_signin($token,$code,$user,$new)
{
	global $mybb;
	$url = 'https://directory.tapatalk.com/au_log_signin.php';
	if(!empty($mybb->settings['tapatalk_push_key']))
	{
		$data['key'] = $mybb->settings['tapatalk_push_key'];
	}
	$board_url = $mybb->settings['bburl'];
	$error_msg = '';
	$data['token'] = $token;
	$data['code'] = $code;
	$data['uid'] = $user['uid'];
	$data['username'] = $user['username'];
	$data['new'] = $new;
	$data['url'] = $board_url;
	getContentFromRemoteServer($url,0,$error_msg,'POST',$data);	
}*/
