<?php

defined('IN_MOBIQUO') or exit;

require_once MYBB_ROOT."inc/functions_modcp.php";
require_once MYBB_ROOT."inc/class_parser.php";
include_once TT_ROOT."lib/classTTJson.php";
$parser = new postParser;


function get_contact_func($xmlrpc_params)
{
    global $db, $lang, $theme, $plugins, $mybb, $session, $settings, $cache, $time, $mybbgroups, $parser, $displaygroupfields;

    $lang->load("member");

    $input = Tapatalk_Input::filterXmlInput(array(
        'user_id' => Tapatalk_Input::STRING,
    ), $xmlrpc_params);



    if (isset($input['user_id']) && !empty($input['user_id'])) {
        $uid = $input['user_id'];
    }  else {
        $uid = $mybb->user['uid'];
    }

    if($mybb->user['uid'] != $uid)
    {
        $member = get_user($uid);
    }
    else
    {
        $member = $mybb->user;
    }
	
    if(!$member['uid'])
    {
        error($lang->error_nomember);
    }
    
	// Guests or those without permission can't email other users
	if($mybb->usergroup['cansendemail'] == 0 || !$mybb->user['uid'])
	{
		error_no_permission();
	}
	
	
	if($member['hideemail'] != 0)
	{
		error($lang->error_hideemail);
	}
	
	
	$user_info = array(
    	'result'             => new xmlrpcval(true, 'boolean'),
        'user_id'            => new xmlrpcval($member['uid']),
        'display_name'       => new xmlrpcval(basic_clean($member['username']), 'base64'),
		'enc_email'          => new xmlrpcval(base64_encode(encrypt($member['email'], loadAPIKey()))),
    );
    
    $xmlrpc_user_info = new xmlrpcval($user_info, 'struct');
    return new xmlrpcresp($xmlrpc_user_info);
}

function keyED($txt,$encrypt_key)
{
    $encrypt_key = md5($encrypt_key);
    $ctr=0;
    $tmp = "";
    for ($i=0;$i<strlen($txt);$i++)
    {
        if ($ctr==strlen($encrypt_key)) $ctr=0;
        $tmp.= substr($txt,$i,1) ^ substr($encrypt_key,$ctr,1);
        $ctr++;
    }
    return $tmp;
}
 
function encrypt($txt,$key)
{
    srand((double)microtime()*1000000);
    $encrypt_key = md5(rand(0,32000));
    $ctr=0;
    $tmp = "";
    for ($i=0;$i<strlen($txt);$i++)
    {
        if ($ctr==strlen($encrypt_key)) $ctr=0;
        $tmp.= substr($encrypt_key,$ctr,1) .
        (substr($txt,$i,1) ^ substr($encrypt_key,$ctr,1));
        $ctr++;
    }
    return keyED($tmp,$key);
}

function loadAPIKey()
{
    global $mybb;
    $mobi_api_key = $mybb->settings['tapatalk_push_key'];
    if(empty($mobi_api_key))
    {   
        $boardurl = $mybb->settings['bburl'];
        $boardurl = urlencode($boardurl);
        $response = getContentFromRemoteServer("http://directory.tapatalk.com/au_reg_verify.php?url=$boardurl", 10, $error);
        if($response)
        {
            $result = json_decode($response, true);
            if(isset($result) && isset($result['result']))
            {
                $mobi_api_key = $result['api_key'];
                return $mobi_api_key;
            }
        } 
        return false;    
    }
    return $mobi_api_key;
}