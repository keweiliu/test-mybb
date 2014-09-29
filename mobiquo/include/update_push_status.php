<?php
defined('IN_MOBIQUO') or exit;
require_once MYBB_ROOT."inc/functions_post.php";
require_once MYBB_ROOT."inc/functions_user.php";
require_once MYBB_ROOT."inc/class_parser.php";

function update_push_status_func($xmlrpc_params)
{
    global $db, $lang, $theme, $plugins, $mybb, $session, $settings, $cache, $time, $mybbgroups, $mobiquo_config;

    $lang->load("member");

    $input = Tapatalk_Input::filterXmlInput(array(
        'settings'  => Tapatalk_Input::RAW,
        'username'  => Tapatalk_Input::STRING,
        'password'  => Tapatalk_Input::STRING,
    ), $xmlrpc_params);
    
    $userid = $mybb->user['uid'];
    $status = true;
    
 	if ($userid)
    {
        $data = array(
            'url'  => $mybb->settings['bburl'],
            'key'  => (!empty($mybb->settings['tapatalk_push_key']) ? $mybb->settings['tapatalk_push_key'] : ''),
            'uid'  => $userid,
            'data' => base64_encode(serialize($input['settings'])),
        );
            
        $url = 'https://directory.tapatalk.com/au_update_push_setting.php';
        getContentFromRemoteServer($url, 0, $error_msg, 'POST', $data);
    }
   
    
    return new xmlrpcresp(new xmlrpcval(array(
        'result' => new xmlrpcval($status, 'boolean'),
    ), 'struct'));
}