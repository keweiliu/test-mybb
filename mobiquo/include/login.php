<?php

defined('IN_MOBIQUO') or exit;

require_once MYBB_ROOT."inc/functions_post.php";
require_once MYBB_ROOT."inc/functions_user.php";
require_once MYBB_ROOT."inc/class_parser.php";

function login_func($xmlrpc_params)
{
    global $db, $lang, $theme, $plugins, $mybb, $session, $settings, $cache, $time, $mybbgroups, $mobiquo_config,$user,$register;

    $lang->load("member");

    $input = Tapatalk_Input::filterXmlInput(array(
        'username'  => Tapatalk_Input::STRING,
        'password'  => Tapatalk_Input::STRING,
        'anonymous' => Tapatalk_Input::INT,
        'push'      => Tapatalk_Input::STRING,
    ), $xmlrpc_params);

    $logins = login_attempt_check(1);
    $login_text = '';
	
    if(!username_exists($input['username']))
    {
        my_setcookie('loginattempts', $logins + 1);
        $status = 2;
    	$response = new xmlrpcval(array(
	        'result'          => new xmlrpcval(0, 'boolean'),
	        'result_text'     => new xmlrpcval(strip_tags($lang->error_invalidpworusername), 'base64'),
		 	'status'          => new xmlrpcval($status, 'string'),
	    ), 'struct');
	    return new xmlrpcresp($response);
    }

    $query = $db->simple_select("users", "loginattempts", "LOWER(username)='".my_strtolower($input['username_esc'])."'", array('limit' => 1));
    $loginattempts = $db->fetch_field($query, "loginattempts");

    $errors = array();
    $user = validate_password_from_username($input['username'], $input['password']);
    $correct = false;
    if(!$user['uid'])
    {
        if(validate_email_format($input['username']))
        {
        	$mybb->settings['username_method'] = 1;
        	$user = validate_password_from_username($input['username'], $input['password']);
        }
        if(!$user['uid'])
        {
        	my_setcookie('loginattempts', $logins + 1);
	        $db->update_query("users", array('loginattempts' => 'loginattempts+1'), "LOWER(username) = '".my_strtolower($input['username_esc'])."'", 1, true);
	
	        if($mybb->settings['failedlogincount'] != 0 && $mybb->settings['failedlogintext'] == 1)
	        {
	            $login_text = $lang->sprintf($lang->failed_login_again, $mybb->settings['failedlogincount'] - $logins);
	        }
	
	        $errors[] = $lang->error_invalidpworusername.$login_text;
        }
        else 
        {
        	 $correct = true;
        }
        
    }
    else
    {
        $correct = true;
    }

    if(!empty($errors))
    {
        return xmlrespfalse(implode(" :: ", $errors));
    }
    else if($correct)
    {
    	$register = 0;
        return tt_login_success();
    }

    return xmlrespfalse("Invalid login details");
}

