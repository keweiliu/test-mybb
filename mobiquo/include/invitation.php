<?php
if(!defined('IN_MOBIQUO')) exit;
error_reporting(E_ERROR);
require_once MYBB_ROOT."/inc/functions_massmail.php";
include_once TT_ROOT."lib/classTTJson.php";
if (function_exists('set_magic_quotes_runtime'))
	@set_magic_quotes_runtime(0);
@ini_set('max_execution_time', '120');
$lang->set_language($mybb->settings['cplanguage'], "admin");
$lang->load("global");
$lang->load("user_mass_mail");
$invite_response['result'] = false;
$furl = $mybb->settings['bburl'];
if(!empty($_POST['session']) && !empty($_POST['api_key']) && !empty($_POST['subject']) && !empty($_POST['body']))
{
	$error = '';
    $push_url = "http://tapatalk.com/forum_owner_invite.php?PHPSESSID=$_POST[session]&api_key=$_POST[api_key]&url=".urlencode($furl)."&action=verify";
    $response = getContentFromRemoteServer($push_url, 10, $error, 'GET');
    if($response) $result = @json_decode($response, true);
    if(empty($result) || empty($result['result']))
        if(preg_match('/\{"result":true/', $response))
            $result = array('result' => true); 
    $_POST['username'] = isset($_POST['username']) ? trim($_POST['username']) : '';
    if(isset($result) && isset($result['result']) && $result['result'])
    {
        if(!empty($_POST['username']))
        {
        	$userinfo = tt_get_user_id_by_name($_POST['username']);
        	if(empty($userinfo))
        	{
        		$invite_response['result_text'] = $lang->error_no_users;
        	}
        	else 
        	{
        		$send_result = send_mass_email($_POST['subject'], $_POST['body'],$userinfo['email']);
        	}
        }
        else 
        {
        	$send_result = send_mass_email($_POST['subject'], $_POST['body']);
        }
       	if(is_numeric($send_result))
       	{
       		$invite_response['result'] = true;
       		$invite_response['result_text'] = $lang->success_mass_mail_saved;
       		$invite_response['number'] = $send_result;
       	}
       	else 
       	{
       		$invite_response['result_text'] = $send_result;
       	}
    }
    else
    {
        $invite_response['result_text'] = $error;
    }
}
else if(!empty($_POST['email_target']))
{
    //get email targe
    $conditions = array('email' => '', 'postnum' => '', 'postnum_dir' => 'greater_than', 'username' => '', 'usergroup' => array() );
	$member_query = build_mass_mail_query($conditions);
	$query = $db->simple_select("users u", "COUNT(uid) AS num", $member_query);
	$num = $db->fetch_field($query, "num");
	echo $num;
    exit;
}

header('Content-type: application/json');
echo json_encode($invite_response);
exit;

function send_mass_email($subject,$message,$target=false)
{
	global $mybb, $db, $lang;
	
	$new_email = array(
		"uid" => 1,
		"subject" => $db->escape_string($subject),
		"message" => $db->escape_string($message),
		"htmlmessage" => '',
		"format" => 0,
		"type" => 0,
		"dateline" => TIME_NOW,
		"senddate" => 0,
		"status" => 0,
		"sentcount" => 0,
		"totalcount" => 0,
		"conditions" => "",
		"perpage" => 50
	);
	$mid = $db->insert_query("massemails", $new_email);
	
	$conditions = array('email' => '', 'postnum' => '', 'postnum_dir' => 'greater_than', 'username' => '', 'usergroup' => array());
	if($target)
		$conditions['email'] = $target;
	$member_query = build_mass_mail_query($conditions);
	$query = $db->simple_select("users u", "COUNT(uid) AS num", $member_query);
	$num = $db->fetch_field($query, "num");

	if($num == 0)
	{
		return  $lang->error_no_users;
	}
	// Got one or more results
	else
	{
		$updated_email = array(
			"totalcount" => $num,
			"conditions" => $db->escape_string(serialize($conditions))
		);
		$db->update_query("massemails", $updated_email, "mid='$mid'");
		$delivery_date = TIME_NOW;
		$updated_email = array(
			"status" => 1,
			"senddate" => $delivery_date
		);
		$db->update_query("massemails", $updated_email, "mid='$mid'");
		return $num;
	}
}
