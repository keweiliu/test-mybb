<?php
define('IN_MYBB', 1);
require_once '../global.php';
error_reporting(E_ALL & ~E_NOTICE);

$return_status = tt_do_post_request(array('test' => 1 , 'key' => $mybb->settings['tapatalk_push_key']),true);
if(empty($mybb->settings['tapatalk_push_key']))
	$return_status = 'Please set Tapatalk API Key at forum option/setting';
$return_ip = tt_do_post_request(array('ip' => 1),true);
$board_url = $mybb->settings['bburl'];
$option_status = 'On';

echo '<b>Tapatalk Push Notification Status Monitor</b><br/>';
echo '<br/>Push notification test: ' . (($return_status == '1') ? '<b>Success</b>' : '<font color="red">Failed('.$return_status.')</font>');
echo '<br/>Current server IP: ' . $return_ip;
echo '<br/>Current forum url: ' . $board_url;

echo '<br/><br/><a href="http://tapatalk.com/api/api.php" target="_blank">Tapatalk API for Universal Forum Access</a> | <a href="http://tapatalk.com/mobile.php" target="_blank">Tapatalk Mobile Applications</a><br>
    For more details, please visit <a href="http://tapatalk.com" target="_blank">http://tapatalk.com</a>';

