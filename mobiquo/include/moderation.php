<?php

defined('IN_MOBIQUO') or exit;

require_once MYBB_ROOT."inc/class_parser.php";
require_once MYBB_ROOT."inc/functions_post.php";
require_once MYBB_ROOT."inc/functions_indicators.php";
require_once MYBB_ROOT."inc/functions_user.php";
require_once MYBB_ROOT."inc/functions_modcp.php";
require_once MYBB_ROOT."inc/functions_post.php";
require_once MYBB_ROOT."inc/functions_upload.php";
require_once MYBB_ROOT."inc/class_parser.php";
require_once MYBB_ROOT."inc/class_moderation.php";

function mod_setup(){
    global $input, $post, $thread, $forum, $pid, $tid, $fid, $modlogdata,
     $db, $lang, $theme, $plugins, $mybb, $session, $settings, $cache, $time, $mybbgroups, $moderation, $parser;

     $parser = new postParser;
     $moderation = new Moderation;
     $lang->load("moderation");

    if(!empty($input['post_id']))
        $pid = $input['post_id'];

    if($pid)
    {
        $post = get_post($pid);
        $tid = $post['tid'];
        if(!$post['pid'])
        {
            tt_error($lang->error_invalidpost);
        }
    }

    if(empty($tid) && !empty($input['topic_id']))
        $tid = $input['topic_id'];

    if($tid)
    {
        $thread = get_thread($tid);
        $fid = $thread['fid'];
        if(!$thread['tid'])
        {
            tt_error($lang->error_invalidthread);
        }
    }

    if($fid)
    {
        $modlogdata['fid'] = $fid;
        $forum = get_forum($fid);
    }

    if($tid)
    {
        $modlogdata['tid'] = $tid;
    }

    $permissions = forum_permissions($fid);

    if($fid)
        tt_check_forum_password($forum['fid']);
}


function m_login_mod_func($xmlrpc_params){
    return xmlrespfalse("Moderator login not supported");
}

function m_stick_topic_func($xmlrpc_params)
{
    global $input, $post, $thread, $forum, $pid, $tid, $fid, $modlogdata,
     $db, $lang, $theme, $plugins, $mybb, $session, $settings, $cache, $time, $mybbgroups, $moderation, $parser;

    $input = Tapatalk_Input::filterXmlInput(array(
        'topic_id'  => Tapatalk_Input::INT,
        'mode'      => Tapatalk_Input::INT,
    ), $xmlrpc_params);

    mod_setup();

    if(!is_moderator($fid, "canmanagethreads"))
    {
        return tt_no_permission();
    }

    if($input['mode'] == 2)
    {
        $stuckunstuck = $lang->unstuck;
        $moderation->unstick_threads($tid);
    }
    else
    {
        $stuckunstuck = $lang->stuck;
        $moderation->stick_threads($tid);
    }

    $lang->mod_process = $lang->sprintf($lang->mod_process, $stuckunstuck);
    log_moderator_action($modlogdata, $lang->mod_process);

    $response = new xmlrpcval(array(
        'result'        => new xmlrpcval(true, 'boolean'),
        'is_login_mod'  => new xmlrpcval(true, 'boolean'),
        'result_text'   => new xmlrpcval("", 'base64')
    ), 'struct');

    return new xmlrpcresp($response);
}

function m_close_topic_func($xmlrpc_params)
{
    global $input, $post, $thread, $forum, $pid, $tid, $fid, $modlogdata,
     $db, $lang, $theme, $plugins, $mybb, $session, $settings, $cache, $time, $mybbgroups, $moderation, $parser;

    $input = Tapatalk_Input::filterXmlInput(array(
        'topic_id'  => Tapatalk_Input::INT,
        'mode'      => Tapatalk_Input::INT,
    ), $xmlrpc_params);

    mod_setup();


    if(!is_moderator($fid, "canopenclosethreads"))
    {
        return tt_no_permission();
    }

    if($input['mode'] == 1)
    {
        $openclose = $lang->opened;
        $moderation->open_threads($tid);
    }
    else
    {
        $openclose = $lang->closed;
        $moderation->close_threads($tid);
    }

    $lang->mod_process = $lang->sprintf($lang->mod_process, $openclose);
    log_moderator_action($modlogdata, $lang->mod_process);

    $response = new xmlrpcval(array(
        'result'        => new xmlrpcval(true, 'boolean'),
        'is_login_mod'  => new xmlrpcval(true, 'boolean'),
        'result_text'   => new xmlrpcval("", 'base64')
    ), 'struct');

    return new xmlrpcresp($response);
}

function m_close_report_func($xmlrpc_params)
{
	global $input, $post, $thread, $forum, $pid, $tid, $fid, $modlogdata,
     $db, $lang, $theme, $plugins, $mybb, $session, $settings, $cache, $time, $mybbgroups, $moderation, $parser;

    $input = Tapatalk_Input::filterXmlInput(array(
        'post_ids'  => Tapatalk_Input::STRING,
    ), $xmlrpc_params);
    
	$lang->load("modcp");
    mod_setup();

	if($mybb->user['uid'] == 0 || $mybb->usergroup['canmodcp'] != 1)
	{
		error_no_permission();
	}
	
	if(empty($input['post_ids']))
	{
		error($lang->error_noselected_reports);
	}
	
	$pids = "'0','{$input['post_ids']}'";

	$sql = "pid IN ({$pids})";

	$plugins->run_hooks("modcp_do_reports");

	$db->update_query("reportedposts", array('reportstatus' => 1), "{$sql}");
	$cache->update_reportedposts();
	$response = new xmlrpcval(array(
        'result'        => new xmlrpcval(true, 'boolean'),
        'result_text'   => new xmlrpcval("", 'base64')
    ), 'struct');
    return new xmlrpcresp($response);
}

function m_delete_topic_func($xmlrpc_params)
{
    global $input, $post, $thread, $forum, $pid, $tid, $fid, $modlogdata,
     $db, $lang, $theme, $plugins, $mybb, $session, $settings, $cache, $time, $mybbgroups, $moderation, $parser;

    $input = Tapatalk_Input::filterXmlInput(array(
        'topic_id'  => Tapatalk_Input::INT,
        'mode'      => Tapatalk_Input::INT,
        'reason_text' => Tapatalk_Input::STRING,
    ), $xmlrpc_params);

    mod_setup();

    if(!is_moderator($fid, "candeleteposts"))
    {
        if($permissions['candeletethreads'] != 1 || $mybb->user['uid'] != $thread['uid'])
        {
            return tt_no_permission();
        }
    }

    $modlogdata['thread_subject'] = $thread['subject'];

    $thread['subject'] = $db->escape_string($thread['subject']);
    $lang->thread_deleted = $lang->sprintf($lang->thread_deleted, $thread['subject']);
    log_moderator_action($modlogdata, $lang->thread_deleted);

    $moderation->delete_thread($tid);

    mark_reports($tid, "thread");

    $response = new xmlrpcval(array(
        'result'        => new xmlrpcval(true, 'boolean'),
        'is_login_mod'  => new xmlrpcval(true, 'boolean'),
        'result_text'   => new xmlrpcval("", 'base64')
    ), 'struct');

    return new xmlrpcresp($response);
}

function m_undelete_topic_func($xmlrpc_params)
{
    return xmlrespfalse("Threads cannot be undeleted in MyBB");
}


function m_get_report_post_func($xmlrpc_params){
    global $input, $post, $thread, $forum, $pid, $tid, $fid, $modlogdata,
     $db, $lang, $theme, $plugins, $mybb, $session, $settings, $cache, $time, $mybbgroups, $moderation, $parser;
	if(version_compare($mybb->version, '1.8.0','>='))
	{
		return m_get_new_report_func($xmlrpc_params);
	}
    $input = Tapatalk_Input::filterXmlInput(array(
        'start_num' => Tapatalk_Input::INT,
        'last_num'  => Tapatalk_Input::INT,
    ), $xmlrpc_params);

    mod_setup();

    list($start, $limit) = process_page($input['start_num'], $input['last_num']);

    // Load global language phrases
    $lang->load("modcp");

    if($mybb->user['uid'] == 0 || $mybb->usergroup['canmodcp'] != 1)
    {
        return tt_no_permission();
    }

    $errors = '';
    // SQL for fetching items only related to forums this user moderates
    $moderated_forums = array();
    if($mybb->usergroup['issupermod'] != 1)
    {
        $query = $db->simple_select("moderators", "*", "id='{$mybb->user['uid']}' AND isgroup = '0'");
        while($forum = $db->fetch_array($query))
        {
            $flist .= ",'{$forum['fid']}'";

            $children = get_child_list($forum['fid']);
            if(!empty($children))
            {
                $flist .= ",'".implode("','", $children)."'";
            }
            $moderated_forums[] = $forum['fid'];
        }
        if($flist)
        {
            $tflist = " AND t.fid IN (0{$flist})";
            $flist = " AND fid IN (0{$flist})";
        }
    }
    else
    {
        $flist = $tflist = '';
    }

    $forum_cache = $cache->read("forums");

    $query = $db->simple_select("reportedposts", "COUNT(rid) AS count", "reportstatus ='0'");
    $report_count = $db->fetch_field($query, "count");

    $query = $db->simple_select("forums", "fid, name");
    while($forum = $db->fetch_array($query))
    {
        $forums[$forum['fid']] = $forum['name'];
    }

    $reports = '';
    $query = $db->query("
        SELECT r.*, u.username, r.reason as report_reason, r.uid as report_uid, u.username as report_username, up.username AS postusername, up.uid AS postuid, t.subject AS threadsubject, p.dateline as postdateline, up.avatar, p.message as postmessage, p.subject as postsubject, t.views, 
        t.replies, IF(b.lifted > UNIX_TIMESTAMP() OR b.lifted = 0, 1, 0) as isbanned, p.visible, p.tid AS ptid
        FROM ".TABLE_PREFIX."reportedposts r
        LEFT JOIN ".TABLE_PREFIX."posts p ON (r.pid=p.pid)
        LEFT JOIN ".TABLE_PREFIX."threads t ON (p.tid=t.tid)
        LEFT JOIN ".TABLE_PREFIX."users u ON (r.uid=u.uid)
        LEFT JOIN ".TABLE_PREFIX."banned b ON (b.uid = p.uid)
        LEFT JOIN ".TABLE_PREFIX."users up ON (p.uid = up.uid)
        WHERE r.reportstatus='0'
        ORDER BY r.dateline DESC
        LIMIT $start, $limit
    ");

    $post_list = array();
    while($post = $db->fetch_array($query))
    {
        $post['threadsubject'] = $parser->parse_badwords($post['threadsubject']);

        $forumpermissions = forum_permissions($post['fid']);
        $can_delete = 0;
        if($mybb->user['uid'] == $post['uid'])
        {
            if($forumpermissions['candeletethreads'] == 1 && $post['replies'] == 0)
            {
                $can_delete = 1;
            }
            else if($forumpermissions['candeleteposts'] == 1 && $post['replies'] > 0)
            {
                $can_delete = 1;
            }
        }
        $can_delete = (is_moderator($post['fid'], "candeleteposts") || $can_delete == 1) && $mybb->user['uid'] != 0;
		
        $post_list[] = new xmlrpcval(array(
            'forum_id'          => new xmlrpcval($post['fid'], 'string'),
            'forum_name'        => new xmlrpcval(basic_clean($forums[$post['fid']]), 'base64'),
            'topic_id'          => new xmlrpcval($post['ptid'], 'string'),
            'topic_title'       => new xmlrpcval($post['threadsubject'], 'base64'),
            'post_id'           => new xmlrpcval($post['pid'], 'string'),
            'post_title'        => new xmlrpcval($post['postsubject'], 'base64'),
            'post_author_name'  => new xmlrpcval($post['postusername'], 'base64'),
            'icon_url'          => new xmlrpcval(absolute_url($post['avatar']), 'string'),
            'post_time'         => new xmlrpcval(mobiquo_iso8601_encode($post['postdateline']), 'dateTime.iso8601'),
            'short_content'     => new xmlrpcval(process_short_content($post['postmessage'], $parser), 'base64'),
            'reply_number'      => new xmlrpcval($post['replies'], 'int'),
            'view_number'       => new xmlrpcval($post['views'], 'int'),
			
            'can_delete'        => new xmlrpcval($can_delete, 'boolean'),
            'can_approve'       => new xmlrpcval(is_moderator($post['fid'], "canmanagethreads"), 'boolean'),
            'can_move'          => new xmlrpcval(is_moderator($post['fid'], "canmovetononmodforum"), 'boolean'),
            'can_ban'           => new xmlrpcval($mybb->usergroup['canmodcp'] == 1, 'boolean'),
            'is_ban'            => new xmlrpcval($post['isbanned'], 'boolean'),
            'is_approved'       => new xmlrpcval($post['visible'], 'boolean'),
            'is_deleted'        => new xmlrpcval(false, 'boolean'),
        	'reported_by_id'    => new xmlrpcval($post['report_uid']),
        	'reported_by_name'  => new xmlrpcval($post['report_username'], 'base64'),
        	'report_reason'     => new xmlrpcval($post['report_reason'], 'base64'),
        ), "struct");
    }

    $result = new xmlrpcval(array(
        'total_report_num'  => new xmlrpcval($report_count, 'int'),
        'reports'           => new xmlrpcval($post_list, 'array'),
    ), 'struct');

    return new xmlrpcresp($result);
}

function m_get_new_report_func($xmlrpc_params)
{
	global $input, $post, $thread, $forum, $pid, $tid, $fid, $modlogdata,
     $db, $lang, $theme, $plugins, $mybb, $session, $settings, $cache, $time, $mybbgroups, $moderation, $parser;

    $input = Tapatalk_Input::filterXmlInput(array(
        'start_num' => Tapatalk_Input::INT,
        'last_num'  => Tapatalk_Input::INT,
    ), $xmlrpc_params);

    mod_setup();

    list($start, $limit) = process_page($input['start_num'], $input['last_num']);
	
	$query = $db->simple_select("moderators", "*", "(id='{$mybb->user['uid']}' AND isgroup = '0') OR (id='{$mybb->user['usergroup']}' AND isgroup = '1')");

	$numreportedposts = 0;
	while($m_forum = $db->fetch_array($query))
	{
		// For Reported posts
		if($m_forum['canmanagereportedposts'] == 1)
		{
			$flist_reports .= ",'{$m_forum['fid']}'";

			$children = get_child_list($m_forum['fid']);
			if(!empty($children))
			{
				$flist_reports .= ",'".implode("','", $children)."'";
			}
			++$numreportedposts;
		}
	}
    // Load global language phrases
	if($mybb->usergroup['canmanagereportedcontent'] == 0)
	{
		error_no_permission();
	}

	if($numreportedposts == 0 && $mybb->usergroup['issupermod'] != 1)
	{
		error($lang->you_cannot_view_reported_posts);
	}

	$lang->load('report');
	add_breadcrumb($lang->mcp_nav_report_center, "modcp.php?action=reports");

	$perpage = $limit;
	if(!$perpage)
	{
		$perpage = 20;
	}
	$query = $db->simple_select("forums", "fid, name");
    while($forum = $db->fetch_array($query))
    {
        $forums[$forum['fid']] = $forum['name'];
    }
	// Multipage
	if($mybb->usergroup['cancp'] || $mybb->usergroup['issupermod'])
	{
		$query = $db->simple_select("reportedcontent", "COUNT(rid) AS count", "reportstatus ='0'");
		$report_count = $db->fetch_field($query, "count");
	}
	else
	{
		$query = $db->simple_select('reportedcontent', 'id3', "reportstatus='0' AND (type = 'post' OR type = '')");

		$report_count = 0;
		while($fid = $db->fetch_field($query, 'id3'))
		{
			if(is_moderator($fid, "canmanagereportedposts"))
			{
				++$report_count;
			}
		}
		unset($fid);
	}


	$plugins->run_hooks("modcp_reports_start");
	if($flist_reports)
	{
		$wflist_reports = "WHERE r.id3 IN (0{$flist_reports})";
		$tflist_reports = " AND r.id3 IN (0{$flist_reports})";
		$flist_reports = " AND id3 IN (0{$flist_reports})";
	}
	// Reports
	$reports = '';
	$query = $db->query("
		SELECT r.*, u.username
		FROM ".TABLE_PREFIX."reportedcontent r
		LEFT JOIN ".TABLE_PREFIX."users u ON (r.uid = u.uid)
		WHERE r.reportstatus = '0'{$tflist_reports}
		ORDER BY r.reports DESC
		LIMIT {$start}, {$perpage}
	");

	if(!$db->num_rows($query))
	{
		// No unread reports
		//eval("\$reports = \"".$templates->get("modcp_reports_noreports")."\";");
		$reportcache = array();
	}
	else
	{
		$reportedcontent = $cache->read("reportedcontent");
		$reportcache = $usercache = $postcache = array();

		while($report = $db->fetch_array($query))
		{
			if($report['type'] == 'profile' || $report['type'] == 'reputation')
			{
				// Profile UID is in ID
				if(!isset($usercache[$report['id']]))
				{
					$usercache[$report['id']] = $report['id'];
				}

				// Reputation comment? The offender is the ID2
				if($report['type'] == 'reputation')
				{
					if(!isset($usercache[$report['id2']]))
					{
						$usercache[$report['id2']] = $report['id2'];
					}
					if(!isset($usercache[$report['id3']]))
					{
						// The user who was offended
						$usercache[$report['id3']] = $report['id3'];
					}
				}
			}
			else if(!$report['type'] || $report['type'] == 'post')
			{
				// This (should) be a post
				$postcache[$report['id']] = $report['id'];
			}

			// Lastpost info - is it missing (pre-1.8)?
			$lastposter = $report['uid'];
			if(!$report['lastreport'])
			{
				// Last reporter is our first reporter
				$report['lastreport'] = $report['dateline'];
			}

			if($report['reporters'])
			{
				$reporters = my_unserialize($report['reporters']);

				if(is_array($reporters))
				{
					$lastposter = end($reporters);
				}
			}

			if(!isset($usercache[$lastposter]))
			{
				$usercache[$lastposter] = $lastposter;
			}

			$report['lastreporter'] = $lastposter;
			$reportcache[$report['id']] = $report;
		}
		// Report Center gets messy
		// Find information about our users (because we don't log it when they file a report)
		if(!empty($usercache))
		{
			$sql = implode(',', array_keys($usercache));
			$query = $db->simple_select("users", "uid, username", "uid IN ({$sql})");

			while($user = $db->fetch_array($query))
			{
				$usercache[$user['uid']] = $user;
			}
		}
		
		// Messy * 2
		// Find out post information for our reported posts
		if(!empty($postcache))
		{
			$sql = implode(',', array_keys($postcache));
			$query = $db->query("
				SELECT p.pid, p.uid, p.username, p.tid, p.subject as postsubject,p.username as postusername,t.subject,t.fid,up.avatar,p.dateline as postdateline,
				p.message as postmessage,t.replies,t.views,IF(b.lifted > UNIX_TIMESTAMP() OR b.lifted = 0, 1, 0) as isbanned,p.visible
				FROM ".TABLE_PREFIX."posts p
				LEFT JOIN ".TABLE_PREFIX."threads t ON (p.tid = t.tid)
				LEFT JOIN ".TABLE_PREFIX."users up ON (p.uid = up.uid)
				LEFT JOIN ".TABLE_PREFIX."banned b ON (b.uid = p.uid)
				WHERE p.pid IN ({$sql})
			");
			while($post = $db->fetch_array($query))
			{						
				$can_delete = 0;
				$forumpermissions = forum_permissions($post['fid']);
		        if($mybb->user['uid'] == $post['uid'])
		        {
		            if($forumpermissions['candeletethreads'] == 1 && $post['replies'] == 0)
		            {
		                $can_delete = 1;
		            }
		            else if($forumpermissions['candeleteposts'] == 1 && $post['replies'] > 0)
		            {
		                $can_delete = 1;
		            }
		        }
		        $can_delete = (is_moderator($post['fid'], "candeleteposts") || $can_delete == 1) && $mybb->user['uid'] != 0;
				$post_list[] = new xmlrpcval(array(
		            'forum_id'          => new xmlrpcval($post['fid'], 'string'),
		            'forum_name'        => new xmlrpcval(basic_clean($forums[$post['fid']]), 'base64'),
		            'topic_id'          => new xmlrpcval($post['tid'], 'string'),
		            'topic_title'       => new xmlrpcval($post['subject'], 'base64'),
		            'post_id'           => new xmlrpcval($post['pid'], 'string'),
		            'post_title'        => new xmlrpcval($post['postsubject'], 'base64'),
		            'post_author_name'  => new xmlrpcval($post['postusername'], 'base64'),
		            'icon_url'          => new xmlrpcval(absolute_url($post['avatar']), 'string'),
		            'post_time'         => new xmlrpcval(mobiquo_iso8601_encode($post['postdateline']), 'dateTime.iso8601'),
		            'short_content'     => new xmlrpcval(process_short_content($post['postmessage'], $parser), 'base64'),
		            'reply_number'      => new xmlrpcval($post['replies'], 'int'),
		            'view_number'       => new xmlrpcval($post['views'], 'int'),
					
		            'can_delete'        => new xmlrpcval($can_delete, 'boolean'),
		            'can_approve'       => new xmlrpcval(is_moderator($post['fid'], "canmanagethreads"), 'boolean'),
		            'can_move'          => new xmlrpcval(is_moderator($post['fid'], "canmovetononmodforum"), 'boolean'),
		            'can_ban'           => new xmlrpcval($mybb->usergroup['canmodcp'] == 1, 'boolean'),
		            'is_ban'            => new xmlrpcval($post['isbanned'], 'boolean'),
		            'is_approved'       => new xmlrpcval($post['visible'], 'boolean'),
		            'is_deleted'        => new xmlrpcval(false, 'boolean'),
		        	'reported_by_id'    => new xmlrpcval($reportcache[$post['pid']]['uid']),
		        	'reported_by_name'  => new xmlrpcval($reportcache[$post['pid']]['username'], 'base64'),
		        	'report_reason'     => new xmlrpcval($reportcache[$post['pid']]['reason'], 'base64'),
		        ), "struct");
		    }
		}
	
	    $result = new xmlrpcval(array(
	        'total_report_num'  => new xmlrpcval(count($reportcache), 'int'),
	        'reports'           => new xmlrpcval($post_list, 'array'),
	    ), 'struct');
	
	    return new xmlrpcresp($result);
		
	}
}
function m_move_topic_func($xmlrpc_params)
{
    global $input, $post, $thread, $forum, $pid, $tid, $fid, $modlogdata,
     $db, $lang, $theme, $plugins, $mybb, $session, $settings, $cache, $time, $mybbgroups, $moderation, $parser;

    $input = Tapatalk_Input::filterXmlInput(array(
        'topic_id'   => Tapatalk_Input::INT,
        'forum_id'   => Tapatalk_Input::INT,
    ), $xmlrpc_params);

    mod_setup();

    $moveto = $input['forum_id'];

    if(!is_moderator($fid, "canmanagethreads"))
    {
        return tt_no_permission();
    }
    // Check if user has moderator permission to move to destination
    if(!is_moderator($moveto, "canmanagethreads") && !is_moderator($fid, "canmovetononmodforum"))
    {
        return tt_no_permission();
    }
    $newperms = forum_permissions($moveto);
    if($newperms['canview'] == 0 && !is_moderator($fid, "canmovetononmodforum"))
    {
        return tt_no_permission();
    }

    $query = $db->simple_select("forums", "*", "fid='$moveto'");
    $newforum = $db->fetch_array($query);
    if($newforum['type'] != "f")
    {
        return xmlrespfalse($lang->error_invalidforum);
    }
    if($thread['fid'] == $moveto)
    {
        return xmlrespfalse($lang->error_movetosameforum);
    }
    $newtid = $moderation->move_thread($tid, $moveto);

    //log_moderator_action($modlogdata, $lang->thread_moved);

    $response = new xmlrpcval(array(
        'result'        => new xmlrpcval(true, 'boolean'),
        'is_login_mod'  => new xmlrpcval(true, 'boolean'),
        'result_text'   => new xmlrpcval("", 'base64')
    ), 'struct');

    return new xmlrpcresp($response);
}

function m_merge_post_func($xmlrpc_params)
{
	global $input, $post, $thread, $forum, $pid, $tid, $fid, $modlogdata,
    $db, $lang, $theme, $plugins, $mybb, $session, $settings, $cache, $time, $mybbgroups, $moderation, $parser;
    
    $input = Tapatalk_Input::filterXmlInput(array(
        'post_ids' => Tapatalk_Input::STRING,
        'post_id'   => Tapatalk_Input::STRING,
    ), $xmlrpc_params);
    $post = get_post($input['post_id']);
   	$tid = $post['tid'];
    $post_ids = explode(',', $input['post_ids']);
    
    mod_setup();
    
	array_push($post_ids, $input['post_id']);
	$postlist = array_unique($post_ids);
	if(count($postlist) <= 1)
	{
		error($lang->error_nomergeposts);
	}
	if(!is_moderator_by_pids($postlist, "canmanagethreads"))
	{
		error_no_permission();
	}

	foreach($postlist as $pid)
	{
		$pid = intval($pid);
		$plist[] = $pid;
	}

	$masterpid = $moderation->merge_posts($plist, $tid, 'hr');

	mark_reports($plist, "posts");
	log_moderator_action($modlogdata, $lang->merged_selective_posts);
	$response = new xmlrpcval(array(
        'result'        => new xmlrpcval(true, 'boolean'),
        'is_login_mod'  => new xmlrpcval(true, 'boolean'),
        'result_text'   => new xmlrpcval("", 'base64')
    ), 'struct');

    return new xmlrpcresp($response);
}

function m_merge_topic_func($xmlrpc_params)
{
    global $input, $post, $thread, $forum, $pid, $tid, $fid, $modlogdata,
     $db, $lang, $theme, $plugins, $mybb, $session, $settings, $cache, $time, $mybbgroups, $moderation, $parser;

    $input = Tapatalk_Input::filterXmlInput(array(
        'topic_id_a' => Tapatalk_Input::STRING,
        'topic_id'   => Tapatalk_Input::STRING,
    ), $xmlrpc_params);

    mod_setup();

    if(!is_moderator($fid, "canmanagethreads"))
    {
        return tt_no_permission();
    }

    $mergetid = $input['topic_id_a'];
    $query = $db->simple_select("threads", "*", "tid='".intval($mergetid)."'");
    $mergethread = $db->fetch_array($query);
    if(!$mergethread['tid'])
    {
        return xmlrespfalse($lang->error_badmergeurl);
    }
    if($mergetid == $tid)
    {
        return xmlrespfalse($lang->error_mergewithself);
    }
    if(!is_moderator($mergethread['fid'], "canmanagethreads"))
    {
        return tt_no_permission();
    }

    $subject = $thread['subject'];

    $moderation->merge_threads($mergetid, $tid, $subject);

    log_moderator_action($modlogdata, $lang->thread_merged);

    $response = new xmlrpcval(array(
        'result'        => new xmlrpcval(true, 'boolean'),
        'is_login_mod'  => new xmlrpcval(true, 'boolean'),
        'result_text'   => new xmlrpcval("", 'base64')
    ), 'struct');

    return new xmlrpcresp($response);
}

function m_approve_topic_func($xmlrpc_params)
{
    global $input, $post, $thread, $forum, $pid, $tid, $fid, $modlogdata,
     $db, $lang, $theme, $plugins, $mybb, $session, $settings, $cache, $time, $mybbgroups, $moderation, $parser;

    $input = Tapatalk_Input::filterXmlInput(array(
        'topic_id'  => Tapatalk_Input::INT,
        'mode'      => Tapatalk_Input::INT,
    ), $xmlrpc_params);

    mod_setup();

    if(!is_moderator($fid, "canopenclosethreads"))
    {
        return tt_no_permission();
    }

    if($input['mode'] == 1)
    {
        $lang->thread_approved = $lang->sprintf($lang->thread_approved, $thread['subject']);
        log_moderator_action($modlogdata, $lang->thread_approved);
        $moderation->approve_threads($tid, $fid);
    }
    else
    {
        $lang->thread_unapproved = $lang->sprintf($lang->thread_unapproved, $thread['subject']);
        log_moderator_action($modlogdata, $lang->thread_unapproved);
        $moderation->unapprove_threads($tid, $fid);
    }

    $response = new xmlrpcval(array(
        'result'        => new xmlrpcval(true, 'boolean'),
        'is_login_mod'  => new xmlrpcval(true, 'boolean'),
        'result_text'   => new xmlrpcval("", 'base64')
    ), 'struct');

    return new xmlrpcresp($response);
}


function m_rename_topic_func($xmlrpc_params)
{
    global $db, $lang, $theme, $plugins, $mybb, $session, $settings, $cache, $time, $mybbgroups, $modlogdata;
		
	$lang->load("editpost");

	$input = Tapatalk_Input::filterXmlInput(array(
		'topic_id' => Tapatalk_Input::INT,
		'title' => Tapatalk_Input::STRING,
		'prefix' => Tapatalk_Input::INT,
	), $xmlrpc_params);  
	
	$parser = new postParser;

	// Get post info
	$thread = get_thread($input['topic_id']);

	if(!$thread['tid'])
	{
		return xmlrespfalse($lang->error_invalidthread);
	}

	$forumpermissions = forum_permissions($thread['fid']);
	// No permission for guests
	if($mybb->user['uid'] == 0)
	{
		return tt_no_permission();
	}
	// Get forum info
	$fid = $thread['fid'];
	$forum = get_forum($fid);
	if(!$forum || $forum['type'] != "f")
	{
		return xmlrespfalse($lang->error_closedinvalidforum);
	}
	if($forum['open'] == 0 || $mybb->user['suspendposting'] == 1)
	{
		return tt_no_permission();
	}

	if(!is_moderator($fid, "caneditposts"))
	{
		if($thread['closed'] == 1)
		{
			return xmlrespfalse($lang->redirect_threadclosed);
		}
		if($forumpermissions['caneditposts'] == 0)
		{
			return tt_no_permission();
		}
		if($mybb->user['uid'] != $thread['uid'])
		{
			return tt_no_permission();
		}
		// Edit time limit
		$time = TIME_NOW;
		if($mybb->settings['edittimelimit'] != 0 && $thread['dateline'] < ($time-($mybb->settings['edittimelimit']*60)))
		{
			$lang->edit_time_limit = $lang->sprintf($lang->edit_time_limit, $mybb->settings['edittimelimit']);
			return xmlrespfalse($lang->edit_time_limit);
		}
	}
		
	// Check if this forum is password protected and we have a valid password
	tt_check_forum_password($forum['fid']);

	// Set up posthandler.
	require_once MYBB_ROOT."inc/datahandlers/post.php";
	$posthandler = new PostDataHandler("update");
	$posthandler->action = "post";
	// Set the post data that came from the input to the $post array.
	$post = array(
		"pid" => $thread['firstpost'],
		"subject" => $input['title'],
	    "prefix" => $input['prefix']
	);
	
	$posthandler->set_data($post);

	// Now let the post handler do all the hard work.
	if(!$posthandler->validate_post())
	{
		$post_errors = $posthandler->get_friendly_errors();		
		return xmlrespfalse(implode(" :: ", $post_errors));
	}
	// No errors were found, we can call the update method.
	else
	{
		$postinfo = $posthandler->update_post();
		$response = new xmlrpcval(array(
        'result'        => new xmlrpcval(true, 'boolean'),
        'is_login_mod'  => new xmlrpcval(true, 'boolean'),
        'result_text'   => new xmlrpcval("", 'base64')
   		), 'struct');

        return new xmlrpcresp($response);
	}
	
}

// POST ACTION

function m_delete_post_func($xmlrpc_params)
{
    global $input, $post, $thread, $forum, $pid, $tid, $fid, $modlogdata,
     $db, $lang, $theme, $plugins, $mybb, $session, $settings, $cache, $time, $mybbgroups, $moderation, $parser;

    $input = Tapatalk_Input::filterXmlInput(array(
        'post_id'       => Tapatalk_Input::INT,
        'mode'          => Tapatalk_Input::INT,
        'reason_text'   => Tapatalk_Input::STRING,
    ), $xmlrpc_params);

    // Load global language phrases
    $lang->load("editpost");

    $plugins->run_hooks("editpost_start");

    // No permission for guests
    if(!$mybb->user['uid'])
    {
        error_no_permission();
    }

    // Get post info
    $pid = intval($input['post_id']);
    $query = $db->simple_select("posts", "*", "pid='$pid'");
    $post = $db->fetch_array($query);

    if(!$post['pid'])
    {
        error($lang->error_invalidpost);
    }

    // Get thread info
    $tid = $post['tid'];
    $thread = get_thread($tid);

    if(!$thread['tid'])
    {
        error($lang->error_invalidthread);
    }

    // Get forum info
    $fid = $post['fid'];
    $forum = get_forum($fid);
    if(!$forum || $forum['type'] != "f")
    {
        error($lang->error_closedinvalidforum);
    }
    if($forum['open'] == 0 || $mybb->user['suspendposting'] == 1)
    {
        error_no_permission();
    }

    $forumpermissions = forum_permissions($fid);

    if(!is_moderator($fid, "candeleteposts"))
    {
        if($thread['closed'] == 1)
        {
            error($lang->redirect_threadclosed);
        }
        if($forumpermissions['candeleteposts'] == 0)
        {
            error_no_permission();
        }
        if($mybb->user['uid'] != $post['uid'])
        {
            error_no_permission();
        }
    }

    // Check if this forum is password protected and we have a valid password
    check_forum_password($forum['fid']);

    $plugins->run_hooks("editpost_deletepost");


    $modlogdata['fid'] = $fid;
    $modlogdata['tid'] = $tid;

    $query = $db->simple_select("posts", "pid", "tid='{$tid}'", array("limit" => 1, "order_by" => "dateline", "order_dir" => "asc"));
    $firstcheck = $db->fetch_array($query);
    if($firstcheck['pid'] == $pid)
    {
        if($forumpermissions['candeletethreads'] == 1 || is_moderator($fid, "candeletethreads"))
        {
            delete_thread($tid);
            mark_reports($tid, "thread");
            log_moderator_action($modlogdata, $lang->thread_deleted);
        }
        else
        {
            error_no_permission();
        }
    }
    else
    {
        if($forumpermissions['candeleteposts'] == 1 || is_moderator($fid, "candeleteposts"))
        {
            // Select the first post before this
            delete_post($pid, $tid);
            mark_reports($pid, "post");
            log_moderator_action($modlogdata, $lang->post_deleted);
        }
        else
        {
            error_no_permission();
        }
    }

    $response = new xmlrpcval(array(
        'result'        => new xmlrpcval(true, 'boolean'),
        'is_login_mod'  => new xmlrpcval(true, 'boolean'),
        'result_text'   => new xmlrpcval("", 'base64')
    ), 'struct');

    return new xmlrpcresp($response);
}

function m_undelete_post_func($xmlrpc_params)
{
    return xmlrespfalse("Posts cannot be undeleted in MyBB");
}

function m_move_post_func($xmlrpc_params)
{
    global $input, $post, $thread, $forum, $pid, $tid, $fid, $modlogdata,
     $db, $lang, $theme, $plugins, $mybb, $session, $settings, $cache, $time, $mybbgroups, $moderation, $parser;

    $input = Tapatalk_Input::filterXmlInput(array(
        'post_id2'    => Tapatalk_Input::STRING, // 2 so topic_id isn't overridden
        'topic_id'  => Tapatalk_Input::INT,
        'topic_title'  => Tapatalk_Input::STRING,
        'forum_id'   => Tapatalk_Input::INT,
    ), $xmlrpc_params);

    mod_setup();

    if(!is_moderator($fid, "canmanagethreads"))
    {
        return tt_no_permission();
    }
	
    $pid = $input['post_id2'];
    $pids = explode(',', $pid);
    if(empty($pids))
    {
    	return xmlrespfalse($lang->error_invalidpost);
    }
    foreach ($pids as $pid) 
    {
	    $post = get_post($pid);
	    if(!$post['pid'])
	    {
	        return xmlrespfalse($lang->error_invalidpost);
	    }
    }

    $query = $db->simple_select("posts", "COUNT(*) AS totalposts", "tid='{$post['tid']}'");
    $count = $db->fetch_array($query);

    if($count['totalposts'] == 1)
    {
        return xmlrespfalse($lang->error_cantsplitonepost);
    }

    if(!empty($input['forum_id']))
    {
        $moveto = $input['forum_id'];
    }
    else
    {
        $moveto = $thread['fid'];
    }
    $query = $db->simple_select("forums", "fid", "fid='$moveto'", array('limit' => 1));
    if($db->num_rows($query) == 0)
    {
        return xmlrespfalse($lang->error_invalidforum);
    }

    mark_reports($pid, "post");
    $newtid = $moderation->split_posts($pids, $post['tid'], $moveto, $input['topic_title'], $input['topic_id']);

    log_moderator_action($modlogdata, $lang->thread_split);

    $response = new xmlrpcval(array(
        'result'        => new xmlrpcval(true, 'boolean'),
        'is_login_mod'  => new xmlrpcval(true, 'boolean'),
        'result_text'   => new xmlrpcval("", 'base64')
    ), 'struct');

    return new xmlrpcresp($response);
}

function m_approve_post_func($xmlrpc_params)
{
    global $input, $post, $thread, $forum, $pid, $tid, $fid, $modlogdata,
     $db, $lang, $theme, $plugins, $mybb, $session, $settings, $cache, $time, $mybbgroups, $moderation, $parser;

    $input = Tapatalk_Input::filterXmlInput(array(
        'post_id'    => Tapatalk_Input::INT,
        'mode'       => Tapatalk_Input::INT,
    ), $xmlrpc_params);

    mod_setup();

    if(!is_moderator($post['fid'], "canmanagethreads"))
    {
        return tt_no_permission();
    }

    if($input['mode'] == 1){
        $moderation->approve_posts(array($pid));
        log_moderator_action($modlogdata, $lang->multi_approve_posts);
    } else {
        $moderation->unapprove_posts(array($pid));
        log_moderator_action($modlogdata, $lang->multi_unapprove_posts);
    }

    $response = new xmlrpcval(array(
        'result'        => new xmlrpcval(true, 'boolean'),
        'is_login_mod'  => new xmlrpcval(true, 'boolean'),
        'result_text'   => new xmlrpcval("", 'base64')
    ), 'struct');

    return new xmlrpcresp($response);
}

function m_ban_user_func($xmlrpc_params)
{
    global $input, $post, $thread, $forum, $pid, $tid, $fid, $modlogdata,
     $db, $lang, $theme, $plugins, $mybb, $session, $settings, $cache, $time, $mybbgroups, $moderation, $parser;

    $input = Tapatalk_Input::filterXmlInput(array(
        'user_name'   => Tapatalk_Input::STRING,
        'mode'        => Tapatalk_Input::INT,
        'reason_text' => Tapatalk_Input::STRING,
    	'expired'     => Tapatalk_Input::INT,
    ), $xmlrpc_params);
	
    $ban_time = '---';
    if(!empty($input['expired']))
    {
    	$expired = intval($input['expired']);
    	$year = date("Y",$expired) - date("Y",time());
    	$month = date("m",$expired) - date("m",time());
    	$day = date("d",$expired) - date("d",time());
    	if($year < 0)
    	{
    		$year = 0;
    		$month = 0;
    		$day = 0;
    	}
    	if($month < 0)
    	{
    		$month = 0;
    	}
    	if($day < 0)
    	{
    		$day = 0;
    	}
    	$ban_time = $day.'-'.$month.'-'.$year;
    }
    
    mod_setup();
   
    $lang->load("modcp");

    // Get the users info from their Username
    $query = $db->simple_select("users", "uid, username,usergroup, additionalgroups, displaygroup", "username = '{$input['user_name_esc']}'", array('limit' => 1));
    $user = $db->fetch_array($query);
    if(!$user['uid'])
    {
        return xmlrespfalse($lang->invalid_username);
    }


    if($user['uid'] == $mybb->user['uid'])
    {
        return xmlrespfalse($lang->error_cannotbanself);
    }

    // Have permissions to ban this user?
    if(!modcp_can_manage_user($user['uid']))
    {
        return xmlrespfalse($lang->error_cannotbanuser);
    }

    // Check for an incoming reason
    if(empty($input['reason_text']))
    {
        return xmlrespfalse($lang->error_nobanreason);
    }
	
    // Check banned group
    $query = $db->simple_select("usergroups", "gid", "isbannedgroup=1", array('limit' => 1));
    $gid = $db->fetch_field($query, "gid");
    if(!$gid)
    {
        return xmlrespfalse($lang->error_nobangroup);
    }

    // If this is a new ban, we check the user isn't already part of a banned group
    $query = $db->simple_select("banned", "uid", "uid='{$user['uid']}'");
    if($db->fetch_field($query, "uid"))
    {
        return xmlrespfalse($lang->error_useralreadybanned);
    }

    $insert_array = array(
        'uid' => $user['uid'],
        'gid' => $gid,
        'oldgroup' => $user['usergroup'],
        'oldadditionalgroups' => $user['additionalgroups'],
        'olddisplaygroup' => $user['displaygroup'],
        'admin' => intval($mybb->user['uid']),
        'dateline' => TIME_NOW,
        'bantime' => $ban_time,
        'lifted' => 0,
        'reason' => $input['reason_text_esc']
    );

    $db->insert_query('banned', $insert_array);

    // Move the user to the banned group
    $update_array = array(
        'usergroup' => $gid,
        'displaygroup' => 0,
        'additionalgroups' => '',
    );
    $db->update_query('users', $update_array, "uid = {$user['uid']}");

    // soft delete (unapprove) posts if necessary
    if($input['mode'] == 2){
        $db->update_query('posts', array("visible" => 0), "uid = {$user['uid']}");
        $db->update_query('threads', array("visible" => 0), "uid = {$user['uid']}");
    }


    $cache->update_banned();
	log_moderator_action(array("uid" => $user['uid'], "username" => $user['username']), $lang->banned_user);

    $response = new xmlrpcval(array(
        'result'        => new xmlrpcval(true, 'boolean'),
        'is_login_mod'  => new xmlrpcval(true, 'boolean'),
        'result_text'   => new xmlrpcval("", 'base64')
    ), 'struct');

    return new xmlrpcresp($response);
}


// Moderation Queue

function m_get_moderate_topic_func($xmlrpc_params)
{
    global $input, $post, $thread, $forum, $pid, $tid, $fid, $modlogdata,
     $db, $lang, $theme, $plugins, $mybb, $session, $settings, $cache, $time, $mybbgroups, $moderation, $parser;

    $input = Tapatalk_Input::filterXmlInput(array(
        'start_num' => Tapatalk_Input::INT,
        'last_num'  => Tapatalk_Input::INT,
    ), $xmlrpc_params);

    mod_setup();

    list($start, $limit) = process_page($input['start_num'], $input['last_num']);

    // Load global language phrases
    $lang->load("modcp");

    if($mybb->user['uid'] == 0 || $mybb->usergroup['canmodcp'] != 1)
    {
        return tt_no_permission();
    }

    $errors = '';
    // SQL for fetching items only related to forums this user moderates
    $moderated_forums = array();
    if($mybb->usergroup['issupermod'] != 1)
    {
        $query = $db->simple_select("moderators", "*", "id='{$mybb->user['uid']}' AND isgroup = '0'");
        while($forum = $db->fetch_array($query))
        {
            $flist .= ",'{$forum['fid']}'";

            $children = get_child_list($forum['fid']);
            if(!empty($children))
            {
                $flist .= ",'".implode("','", $children)."'";
            }
            $moderated_forums[] = $forum['fid'];
        }
        if($flist)
        {
            $tflist = " AND t.fid IN (0{$flist})";
            $flist = " AND fid IN (0{$flist})";
        }
    }
    else
    {
        $flist = $tflist = '';
    }

    $forum_cache = $cache->read("forums");

    $query = $db->simple_select("threads", "COUNT(tid) AS unapprovedthreads", "visible=0 {$flist}");
    $unapproved_threads = $db->fetch_field($query, "unapprovedthreads");

    $query = $db->query("
        SELECT t.*, p.message AS postmessage, u.avatar, f.name as forumname, IF(b.lifted > UNIX_TIMESTAMP() OR b.lifted = 0, 1, 0) as isbanned
        FROM ".TABLE_PREFIX."threads t
        LEFT JOIN ".TABLE_PREFIX."posts p ON (p.pid=t.firstpost)
        LEFT JOIN ".TABLE_PREFIX."users u ON (u.uid=t.uid)
        LEFT JOIN ".TABLE_PREFIX."banned b ON (b.uid = t.uid)
        LEFT JOIN ".TABLE_PREFIX."forums f on f.fid = t.fid
        WHERE t.visible='0' {$tflist}
        ORDER BY t.lastpost DESC
        LIMIT $start, $limit
    ");
    /*
    Not reliable enough...
        LEFT JOIN ".TABLE_PREFIX."moderatorlog l ON t.tid=l.tid AND l.action LIKE 'Thread Approved%'
        LEFT JOIN ".TABLE_PREFIX."users lu on l.uid = lu.uid
    */
    $topic_list = array();

    while($thread = $db->fetch_array($query))
    {
    	if(substr($thread['closed'], 0, 5) == "moved")
        {
            continue;
        }
        $thread['subject'] = $parser->parse_badwords($thread['subject']);

        $topic_list[] = new xmlrpcval(array(
            'forum_id'          => new xmlrpcval($thread['fid'], 'string'),
            'forum_name'        => new xmlrpcval(basic_clean($thread['forumname']), 'base64'),
            'topic_id'          => new xmlrpcval($thread['tid'], 'string'),
            'topic_title'       => new xmlrpcval($thread['subject'], 'base64'),
            'topic_author_name' => new xmlrpcval($thread['username'], 'base64'),
            'short_content'     => new xmlrpcval(process_short_content($thread['postmessage'], $parser), 'base64'),
            'icon_url'          => new xmlrpcval(absolute_url($thread['avatar']), 'string'),
            'post_time'         => new xmlrpcval(mobiquo_iso8601_encode($thread['lastpost']), 'dateTime.iso8601'),
            'reply_number'      => new xmlrpcval($thread['replies'], 'int'),
            'view_number'       => new xmlrpcval($thread['views'], 'int'),

            'can_delete'        => new xmlrpcval(is_moderator($thread['fid'], "candeleteposts"), 'boolean'),
            'can_close'         => new xmlrpcval(is_moderator($thread['fid'], "canopenclosethreads"), 'boolean'),
            'can_approve'       => new xmlrpcval(is_moderator($thread['fid'], "canopenclosethreads"), 'boolean'),
            'can_stick'         => new xmlrpcval(is_moderator($thread['fid'], "canmanagethreads"), 'boolean'),
            'can_move'          => new xmlrpcval(is_moderator($thread['fid'], "canmovetononmodforum"), 'boolean'),
			'can_merge'         => new xmlrpcval(is_moderator($thread['fid'], "canmanagethreads"), 'boolean'),
			'can_merge_post'    => new xmlrpcval(is_moderator($thread['fid'], "canmanagethreads"), 'boolean'),
            'can_ban'           => new xmlrpcval($mybb->usergroup['canmodcp'] == 1, 'boolean'),
            'can_rename'        => new xmlrpcval(false, 'boolean'),
            'is_ban'            => new xmlrpcval($thread['isbanned'], 'boolean'),
            'is_sticky'         => new xmlrpcval($thread['sticky'], 'boolean'),
            'is_approved'       => new xmlrpcval(false, 'boolean'),
            'is_deleted'        => new xmlrpcval(false, 'boolean'),
        ), "struct");
    }


    $result = new xmlrpcval(array(
        'total_topic_num'   => new xmlrpcval($unapproved_threads, 'int'),
        'topics'            => new xmlrpcval($topic_list, 'array'),
    ), 'struct');

    return new xmlrpcresp($result);
}

function m_get_moderate_post_func($xmlrpc_params)
{
    global $input, $post, $thread, $forum, $pid, $tid, $fid, $modlogdata,
     $db, $lang, $theme, $plugins, $mybb, $session, $settings, $cache, $time, $mybbgroups, $moderation, $parser;

    $input = Tapatalk_Input::filterXmlInput(array(
        'start_num' => Tapatalk_Input::INT,
        'last_num'  => Tapatalk_Input::INT,
    ), $xmlrpc_params);

    mod_setup();

    list($start, $limit) = process_page($input['start_num'], $input['last_num']);

    // Load global language phrases
    $lang->load("modcp");

    if($mybb->user['uid'] == 0 || $mybb->usergroup['canmodcp'] != 1)
    {
        return tt_no_permission();
    }

    $errors = '';
    // SQL for fetching items only related to forums this user moderates
    $moderated_forums = array();
    if($mybb->usergroup['issupermod'] != 1)
    {
        $query = $db->simple_select("moderators", "*", "id='{$mybb->user['uid']}' AND isgroup = '0'");
        while($forum = $db->fetch_array($query))
        {
            $flist .= ",'{$forum['fid']}'";

            $children = get_child_list($forum['fid']);
            if(!empty($children))
            {
                $flist .= ",'".implode("','", $children)."'";
            }
            $moderated_forums[] = $forum['fid'];
        }
        if($flist)
        {
            $tflist = " AND t.fid IN (0{$flist})";
            $flist = " AND fid IN (0{$flist})";
        }
    }
    else
    {
        $flist = $tflist = '';
    }

    $forum_cache = $cache->read("forums");

    $query = $db->query("
        SELECT COUNT(pid) AS unapprovedposts
        FROM  ".TABLE_PREFIX."posts p
        LEFT JOIN ".TABLE_PREFIX."threads t ON (t.tid=p.tid)
        WHERE p.visible='0' {$tflist} AND t.firstpost != p.pid
    ");
    $unapproved_posts = $db->fetch_field($query, "unapprovedposts");

    $query = $db->query("
        SELECT p.pid, p.subject, p.message, t.subject AS threadsubject, t.tid, u.username, p.uid, t.fid, p.dateline, u.avatar, t.views, t.replies, IF(b.lifted > UNIX_TIMESTAMP() OR b.lifted = 0, 1, 0) as isbanned
        FROM  ".TABLE_PREFIX."posts p
        LEFT JOIN ".TABLE_PREFIX."threads t ON (t.tid=p.tid)
        LEFT JOIN ".TABLE_PREFIX."users u ON (u.uid=p.uid)
        LEFT JOIN ".TABLE_PREFIX."banned b ON (b.uid = p.uid)
        left join ".TABLE_PREFIX."forums f on f.fid = t.fid
        WHERE p.visible='0' {$tflist} AND t.firstpost != p.pid
        ORDER BY p.dateline DESC
        LIMIT {$start}, {$limit}
    ");

    $forumcache = $cache->read("forums");

    $post_list = array();
    while($post = $db->fetch_array($query))
    {
        $post['threadsubject'] = $parser->parse_badwords($post['threadsubject']);

        $forumpermissions = forum_permissions($post['fid']);
        $can_delete = 0;
        if($mybb->user['uid'] == $post['uid'])
        {
            if($forumpermissions['candeletethreads'] == 1 && $post['replies'] == 0)
            {
                $can_delete = 1;
            }
            else if($forumpermissions['candeleteposts'] == 1 && $post['replies'] > 0)
            {
                $can_delete = 1;
            }
        }
        $can_delete = (is_moderator($post['fid'], "candeleteposts") || $can_delete == 1) && $mybb->user['uid'] != 0;

        $post_list[] = new xmlrpcval(array(
            'forum_id'          => new xmlrpcval($post['fid'], 'string'),
            'forum_name'        => new xmlrpcval(basic_clean($forumcache[$post['fid']]['name']), 'base64'),
            'topic_id'          => new xmlrpcval($post['tid'], 'string'),
            'topic_title'       => new xmlrpcval($post['threadsubject'], 'base64'),
            'post_id'           => new xmlrpcval($post['pid'], 'string'),
            'post_title'        => new xmlrpcval($post['subject'], 'base64'),
            'post_author_name'  => new xmlrpcval($post['username'], 'base64'),
            'icon_url'          => new xmlrpcval(absolute_url($post['avatar']), 'string'),
            'post_time'         => new xmlrpcval(mobiquo_iso8601_encode($post['dateline']), 'dateTime.iso8601'),
            'short_content'     => new xmlrpcval(process_short_content($post['message'], $parser), 'base64'),
            'reply_number'      => new xmlrpcval($post['replies'], 'int'),
            'view_number'       => new xmlrpcval($post['views'], 'int'),

            'can_delete'        => new xmlrpcval($can_delete, 'boolean'),
            'can_approve'       => new xmlrpcval(is_moderator($post['fid'], "canmanagethreads"), 'boolean'),
            'can_move'          => new xmlrpcval(is_moderator($post['fid'], "canmovetononmodforum"), 'boolean'),
            'can_ban'           => new xmlrpcval($mybb->usergroup['canmodcp'] == 1, 'boolean'),
            'is_ban'            => new xmlrpcval($post['isbanned'], 'boolean'),
            'is_approved'       => new xmlrpcval(false, 'boolean'),
            'is_deleted'        => new xmlrpcval(false, 'boolean'),
        ), "struct");
    }

    $result = new xmlrpcval(array(
        'total_post_num'    => new xmlrpcval($unapproved_posts, 'int'),
        'posts'             => new xmlrpcval($post_list, 'array'),
    ), 'struct');

    return new xmlrpcresp($result);
}

function is_moderator_by_pids($posts, $permission='')
{
	global $db, $mybb;

	// Speedy determination for supermods/admins and guests
	if($mybb->usergroup['issupermod'])
	{
		return true;
	}
	elseif(!$mybb->user['uid'])
	{
		return false;
	}
	// Make an array of threads if not an array
	if(!is_array($posts))
	{
		$posts = array($posts);
	}
	// Validate input
	$posts = array_map('intval', $posts);
	$posts[] = 0;
	// Get forums
	$posts_string = implode(',', $posts);
	$query = $db->simple_select("posts", "DISTINCT fid", "pid IN ($posts_string)");
	while($forum = $db->fetch_array($query))
	{
		if(!is_moderator($forum['fid'], $permission))
		{
			return false;
		}
	}
	return true;
}