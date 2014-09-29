<?php
define('IN_MOBIQUO',1);
define("IN_MYBB", 1);
$forum_root = dirname(dirname(__FILE__));
$mobiquo_root = dirname(__FILE__);
if(!$forum_root)
{
	$forum_root = '.';
}
if(!$mobiquo_root)
{
	$mobiquo_root = '.';
}

require_once $forum_root . '/global.php';
require_once $mobiquo_root . '/mobiquo_common.php';


if(!empty($_GET['user_id']))
{
	$uid = intval($_GET['user_id']);
}
else if(!empty($_GET['username']))
{
	$_GET['username'] = base64_decode($_GET['username']);
	$_GET['username'] = $db->escape_string($_GET['username']);	
	$query = $db->simple_select("users", "uid", "username='{$_GET['username']}'");
    $uid = $db->fetch_field($query, "uid");
}
else 
{
	die('Invalid params!');
}
$user_info = get_user($uid);
$icon_url = absolute_url($user_info['avatar']);
header("Location:$icon_url");