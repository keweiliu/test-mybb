<?php

defined('IN_MOBIQUO') or exit;

require_once MYBB_ROOT."inc/class_parser.php";
require_once MYBB_ROOT."inc/functions_post.php";
require_once MYBB_ROOT."inc/functions_indicators.php";
require_once MYBB_ROOT."inc/functions_user.php";

require_once TT_ROOT."include/get_thread_by_post.php";

function get_thread_by_unread_func($xmlrpc_params)
{
    global $db, $mybb;

    $input = Tapatalk_Input::filterXmlInput(array(
        'topic_id'          => Tapatalk_Input::STRING,
        'posts_per_request' => Tapatalk_Input::INT,
        'return_html'       => Tapatalk_Input::INT
    ), $xmlrpc_params);
	if (preg_match('/^ann_/', $input['topic_id']))
    {
        $_GET["aid"] = intval(str_replace('ann_', '', $input['topic_id']));
        return get_announcement_func($xmlrpc_params);
    }
    $thread = get_thread($input['topic_id']);
    if(!empty($thread['closed']))
    {
         $moved = explode("|", $thread['closed']);
         if($moved[0] == "moved")
         {
             $thread = get_thread($moved[1]);
         }
    }
    if(is_moderator($thread['fid']))
    {
        $visible = "AND (p.visible='0' OR p.visible='1')";
    }
    else
    {
        $visible = "AND p.visible='1'";
    }
	$cutoff = 0;
	if($mybb->settings['threadreadcut'] > 0)
	{
		$cutoff = TIME_NOW-$mybb->settings['threadreadcut']*60*60*24;
	}
	
    $query = $db->query("select min(p.pid) as pid from ".TABLE_PREFIX."posts p
        LEFT JOIN ".TABLE_PREFIX."threadsread tr on p.tid = tr.tid and tr.uid = '{$mybb->user['uid']}'
        where p.tid='{$thread['tid']}' and p.uid != '{$mybb->user['uid']}' and (p.dateline > tr.dateline or tr.dateline is null) and p.dateline > $cutoff $visible
        ");
	$pid = $db->fetch_field($query, 'pid');

    if(!$pid)
    {
        $query = $db->query("select p.pid from ".TABLE_PREFIX."posts p
                             where p.tid='{$thread['tid']}' $visible
                             order by p.dateline desc
                             limit 1");
        $pid = $db->fetch_field($query, 'pid');
    }

    return get_thread_by_post_func(new xmlrpcval(array(
        new xmlrpcval($pid, "string"),
        new xmlrpcval($input['posts_per_request'], 'int'),
        new xmlrpcval(!!$input['return_html'], 'boolean'),
    ), 'array'));
}