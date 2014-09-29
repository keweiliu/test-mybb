<?php
defined('IN_MOBIQUO') or exit;
require_once MYBB_ROOT."inc/functions_post.php";
require_once MYBB_ROOT."inc/functions_user.php";
require_once MYBB_ROOT."inc/class_parser.php";
$parser = new postParser;
$verify_result = false;
$result_text = '';
// Load global language phrases
$lang->load("member");
/*if(($mybb->input['action'] == "register" || $mybb->input['action'] == "do_register") && $mybb->usergroup['cancp'] != 1)
{
	if($mybb->settings['disableregs'] == 1)
	{
		error($lang->registrations_disabled);
	}
	if($mybb->user['regdate'])
	{
		error($lang->error_alreadyregistered);
	}
	if($mybb->settings['betweenregstime'] && $mybb->settings['maxregsbetweentime'])
	{
		$time = TIME_NOW;
		$datecut = $time-(60*60*$mybb->settings['betweenregstime']);
		$query = $db->simple_select("users", "*", "regip='".$db->escape_string($session->ipaddress)."' AND regdate > '$datecut'");
		$regcount = $db->num_rows($query);
		if($regcount >= $mybb->settings['maxregsbetweentime'])
		{
			$lang->error_alreadyregisteredtime = $lang->sprintf($lang->error_alreadyregisteredtime, $regcount, $mybb->settings['betweenregstime']);
			error($lang->error_alreadyregisteredtime);
		}
	}
}
*/
if($mybb->input['action'] == "do_register" && $mybb->request_method == "post")
{
	if($mybb->settings['disableregs'] == 1)
	{
		error($lang->registrations_disabled);
	}
	
	/*if($mybb->user['regdate'])
	{
		error($lang->error_alreadyregistered);
	}*/
	if($mybb->settings['betweenregstime'] && $mybb->settings['maxregsbetweentime'])
	{
		$time = TIME_NOW;
		$datecut = $time-(60*60*$mybb->settings['betweenregstime']);
		$query = $db->simple_select("users", "*", "regip='".$db->escape_string($session->ipaddress)."' AND regdate > '$datecut'");
		$regcount = $db->num_rows($query);
		if($regcount >= $mybb->settings['maxregsbetweentime'])
		{
			$lang->error_alreadyregisteredtime = $lang->sprintf($lang->error_alreadyregisteredtime, $regcount, $mybb->settings['betweenregstime']);
			error($lang->error_alreadyregisteredtime);
		}
	}

	if($mybb->settings['regtype'] == "randompass")
	{
		$mybb->input['password'] = random_str();
		$mybb->input['password2'] = $mybb->input['password'];
	}
	// Set up user handler.
	require_once MYBB_ROOT."inc/datahandlers/user.php";
	$userhandler = new UserDataHandler("insert");
	if(isset($_POST['tt_token']) && isset($_POST['tt_code']))
	{
		$result = tt_register_verify($_POST['tt_token'], $_POST['tt_code']);   	
		if($result->result && !empty($result->email) && (empty($mybb->input['email']) || strtolower($mybb->input['email'] == strtolower($result->email))))
		{
			$verify_result = $result->result;
			$mybb->input['email'] = $result->email;
			$mybb->input['email2'] = $result->email;
		}
		else if(!$result->result && empty($mybb->input['email']) && !empty($result->email))
		{
			$mybb->input['email'] = $result->email;
			$mybb->input['email2'] = $result->email;
		}
					
	}
    $usergroup = 5;
    if($verify_result && ($mybb->settings['regtype'] != "admin"))
	{
		$usergroup = isset($mybb->settings['tapatalk_register_group']) ? $mybb->settings['tapatalk_register_group'] : 2;
	}
    
	if(!$verify_result)
	{
		tt_is_spam();
	}
	// Set the data for the new user.
	$user = array(
		"username" => $mybb->input['username'],
		"password" => $mybb->input['password'],
		"password2" => $mybb->input['password2'],
		"email" => $mybb->input['email'],
		"email2" => $mybb->input['email2'],
		"usergroup" => $usergroup,
		"referrer" => $mybb->input['referrername'],
		"timezone" => $mybb->settings['timezoneoffset'],
		"language" => $mybb->input['language'],
		"profile_fields" => $mybb->input['profile_fields'],
		"regip" => $session->ipaddress,
		"longregip" => my_ip2long($session->ipaddress),
		"coppa_user" => intval($mybb->cookies['coppauser']),
	);

	$user['options'] = array(
		"allownotices" => $mybb->input['allownotices'],
		"hideemail" => $mybb->input['hideemail'],
		"subscriptionmethod" => $mybb->input['subscriptionmethod'],
		"receivepms" => $mybb->input['receivepms'],
		"pmnotice" => $mybb->input['pmnotice'],
		"emailpmnotify" => $mybb->input['emailpmnotify'],
		"invisible" => $mybb->input['invisible'],
		"dstcorrection" => $mybb->input['dstcorrection']
	);

	$userhandler->set_data($user);

	$errors = "";

	if(!$userhandler->validate_user())
	{
		$errors = $userhandler->get_friendly_errors();
	}
	if(is_array($errors))
	{
		error($errors[0]);
	}
	else
	{
		$user_info = $userhandler->insert_user();

		if($mybb->settings['regtype'] == "randompass")
		{
			$emailsubject = $lang->sprintf($lang->emailsubject_randompassword, $mybb->settings['bbname']);
			switch($mybb->settings['username_method'])
			{
				case 0:
					$emailmessage = $lang->sprintf($lang->email_randompassword, $user['username'], $mybb->settings['bbname'], $user_info['username'], $user_info['password']);
					break;
				case 1:
					$emailmessage = $lang->sprintf($lang->email_randompassword1, $user['username'], $mybb->settings['bbname'], $user_info['username'], $user_info['password']);
					break;
				case 2:
					$emailmessage = $lang->sprintf($lang->email_randompassword2, $user['username'], $mybb->settings['bbname'], $user_info['username'], $user_info['password']);
					break;
				default:
					$emailmessage = $lang->sprintf($lang->email_randompassword, $user['username'], $mybb->settings['bbname'], $user_info['username'], $user_info['password']);
					break;
			}
			my_mail($user_info['email'], $emailsubject, $emailmessage);

			$result_text = $lang->redirect_registered_passwordsent;
		}
		else if($mybb->settings['regtype'] == "verify" && !$verify_result)
		{
			$activationcode = random_str();
			$now = TIME_NOW;
			$activationarray = array(
				"uid" => $user_info['uid'],
				"dateline" => TIME_NOW,
				"code" => $activationcode,
				"type" => "r"
			);
			$db->insert_query("awaitingactivation", $activationarray);
			$emailsubject = $lang->sprintf($lang->emailsubject_activateaccount, $mybb->settings['bbname']);
			switch($mybb->settings['username_method'])
			{
				case 0:
					$emailmessage = $lang->sprintf($lang->email_activateaccount, $user_info['username'], $mybb->settings['bbname'], $mybb->settings['bburl'], $user_info['uid'], $activationcode);
					break;
				case 1:
					$emailmessage = $lang->sprintf($lang->email_activateaccount1, $user_info['username'], $mybb->settings['bbname'], $mybb->settings['bburl'], $user_info['uid'], $activationcode);
					break;
				case 2:
					$emailmessage = $lang->sprintf($lang->email_activateaccount2, $user_info['username'], $mybb->settings['bbname'], $mybb->settings['bburl'], $user_info['uid'], $activationcode);
					break;
				default:
					$emailmessage = $lang->sprintf($lang->email_activateaccount, $user_info['username'], $mybb->settings['bbname'], $mybb->settings['bburl'], $user_info['uid'], $activationcode);
					break;
			}
			my_mail($user_info['email'], $emailsubject, $emailmessage);
			
			$lang->redirect_registered_activation = $lang->sprintf($lang->redirect_registered_activation, $mybb->settings['bbname'], $user_info['username']);


			$result_text = $lang->redirect_registered_activation;
		}
		else if($mybb->settings['regtype'] == "admin")
		{
			$lang->redirect_registered_admin_activate = $lang->sprintf($lang->redirect_registered_admin_activate, $mybb->settings['bbname'], $user_info['username']);

			$result_text = $lang->redirect_registered_admin_activate;
		}
		if(!empty($user_info['uid']))
		{
			$verify_result = true;
		}
		else 
		{
			$verify_result = false;
			$result_text = "Register fail";
		}
	}
}

if($mybb->input['action'] == "do_lostpw" && $mybb->request_method == "post")
{
	$plugins->run_hooks("member_do_lostpw_start");

	$username = $db->escape_string(trim($_POST['username']));
	$query = $db->simple_select("users", "*", "username='".$username."'");
	$user = $db->fetch_array($query);
	if(empty($user))
	{
		error("Username does not exist");
	}
	else
	{
		$result = tt_register_verify($_POST['tt_token'], $_POST['tt_code']);   	
		if($result->result && ($user['email'] == $result->email))
		{
			$verify_result = true;
			$verified = true;
		}
		else 
		{
			$verify_result = true;
			$verified = false;
			
			$db->delete_query("awaitingactivation", "uid='{$user['uid']}' AND type='p'");
			$user['activationcode'] = random_str();
			$now = TIME_NOW;
			$uid = $user['uid'];
			$awaitingarray = array(
				"uid" => $user['uid'],
				"dateline" => TIME_NOW,
				"code" => $user['activationcode'],
				"type" => "p"
			);
			$db->insert_query("awaitingactivation", $awaitingarray);
			$username = $user['username'];
			$email = $user['email'];
			$activationcode = $user['activationcode'];
			$emailsubject = $lang->sprintf($lang->emailsubject_lostpw, $mybb->settings['bbname']);
			switch($mybb->settings['username_method'])
			{
				case 0:
					$emailmessage = $lang->sprintf($lang->email_lostpw, $username, $mybb->settings['bbname'], $mybb->settings['bburl'], $uid, $activationcode);
					break;
				case 1:
					$emailmessage = $lang->sprintf($lang->email_lostpw1, $username, $mybb->settings['bbname'], $mybb->settings['bburl'], $uid, $activationcode);
					break;
				case 2:
					$emailmessage = $lang->sprintf($lang->email_lostpw2, $username, $mybb->settings['bbname'], $mybb->settings['bburl'], $uid, $activationcode);
					break;
				default:
					$emailmessage = $lang->sprintf($lang->email_lostpw, $username, $mybb->settings['bbname'], $mybb->settings['bburl'], $uid, $activationcode);
					break;
			}
			my_mail($email, $emailsubject, $emailmessage);
			$plugins->run_hooks("member_do_lostpw_end");
			$result_text = $lang->redirect_lostpwsent;
		}
		
	}
}
