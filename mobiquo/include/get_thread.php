<?php

defined('IN_MOBIQUO') or exit;

require_once MYBB_ROOT."inc/class_parser.php";
require_once MYBB_ROOT."inc/functions_post.php";
require_once MYBB_ROOT."inc/functions_indicators.php";
require_once MYBB_ROOT."inc/functions_user.php";
require_once MYBB_ROOT."inc/functions_modcp.php";
require_once TT_ROOT.'parser.php';
function get_thread_func($xmlrpc_params)
{
    global $db, $lang, $mybb, $position, $plugins, $pids;
	global $pforumcache, $currentitem, $forum_cache, $navbits, $base_url, $archiveurl;
	
    $input = Tapatalk_Input::filterXmlInput(array(
        'topic_id'    => Tapatalk_Input::STRING,
        'start_num'   => Tapatalk_Input::INT,
        'last_num'    => Tapatalk_Input::INT,
        'return_html' => Tapatalk_Input::INT
    ), $xmlrpc_params);
	
	if (preg_match('/^ann_/', $input['topic_id']))
    {
        $_GET["aid"] = intval(str_replace('ann_', '', $input['topic_id']));
        return get_announcement_func($xmlrpc_params);
    }
    $lang->load("showthread");
	global $parser;
    $parser = new Tapatalk_Parser;

    // Get the thread details from the database.
    $thread = get_thread($input['topic_id']);
	if(!empty($thread['closed']))
    {
         $moved = explode("|", $thread['closed']);
         if($moved[0] == "moved")
         {
             $thread = get_thread($moved[1]);
         }
    }
    // Get thread prefix if there is one.
    $thread['threadprefix'] = '';
    $thread['displayprefix'] = '';
    if($thread['prefix'] != 0)
    {
        $threadprefix = build_prefixes($thread['prefix']);

        if($threadprefix['prefix'])
        {
            $thread['threadprefix'] = $threadprefix['prefix'].'&nbsp;';
            $thread['displayprefix'] = $threadprefix['displaystyle'].'&nbsp;';
        }
    }


    $thread['subject'] = $parser->parse_badwords($thread['subject']);
    $tid = $thread['tid'];
    $fid = $thread['fid'];
	
    if(!$thread['username'])
    {
        $thread['username'] = $lang->guest;
    }

    $visibleonly = "AND visible='1'";

    // Is the currently logged in user a moderator of this forum?
    if(is_moderator($fid))
    {
        $visibleonly = " AND (visible='1' OR visible='0')";
        $ismod = true;
    }
    else
    {
        $ismod = false;
    }

    $forumpermissions = forum_permissions($thread['fid']);

    // Does the user have permission to view this thread?
    if($forumpermissions['canview'] != 1 || $forumpermissions['canviewthreads'] != 1)
    {
        error_no_permission();
    }

    if($forumpermissions['canonlyviewownthreads'] == 1 && $thread['uid'] != $mybb->user['uid'])
    {
        error_no_permission();
    }

    // Make sure we are looking at a real thread here.
    if(!$thread['tid'] || ($thread['visible'] == 0 && $ismod == false) || ($thread['visible'] > 1 && $ismod == true))
    {
        return xmlrespfalse($lang->error_invalidthread);
    }


    // Does the thread belong to a valid forum?
    $forum = get_forum($fid);
    if(!$forum || $forum['type'] != "f")
    {
        return xmlrespfalse($lang->error_invalidforum);
    }

    tt_check_forum_password($forum['fid']);


    if($thread['firstpost'] == 0)
    {
        update_first_post($tid);
    }

    // Mark this thread as read
    mark_thread_read($tid, $fid);

    // Increment the thread view.
    if($mybb->settings['delayedthreadviews'] == 1)
    {
        $db->shutdown_query("INSERT INTO ".TABLE_PREFIX."threadviews (tid) VALUES('{$tid}')");
    }
    else
    {
        $db->shutdown_query("UPDATE ".TABLE_PREFIX."threads SET views=views+1 WHERE tid='{$tid}'");
    }
    ++$thread['views'];


    // Work out if we are showing unapproved posts as well (if the user is a moderator etc.)
    if($ismod)
    {
        $visible = "AND (p.visible='0' OR p.visible='1')";
    }
    else
    {
        $visible = "AND p.visible='1'";
    }


    // Fetch the ignore list for the current user if they have one
    $ignored_users = array();
    if($mybb->user['uid'] > 0 && $mybb->user['ignorelist'] != "")
    {
        $ignore_list = explode(',', $mybb->user['ignorelist']);
        foreach($ignore_list as $uid)
        {
            $ignored_users[$uid] = 1;
        }
    }

    list($start, $limit) = process_page($input['start_num'], $input['last_num']);

    // Recount replies if user is a moderator to take into account unapproved posts.
    if($ismod)
    {
        $query = $db->simple_select("posts p", "COUNT(*) AS replies", "p.tid='$tid' $visible");
        $thread['replies'] = $db->fetch_field($query, 'replies')-1;
    }
    $postcount = intval($thread['replies'])+1;


    $pids = "";
    $comma = '';
    $query = $db->simple_select("posts p", "p.pid", "p.tid='$tid' $visible", array('order_by' => 'p.dateline', 'limit_start' => $start, 'limit' => $limit));
    while($getid = $db->fetch_array($query))
    {
        // Set the ID of the first post on page to $pid if it doesn't hold any value
        // to allow this value to be used for Thread Mode/Linear Mode links
        // and ensure the user lands on the correct page after changing view mode
        if(!$pid)
        {
            $pid = $getid['pid'];
        }
        // Gather a comma separated list of post IDs
        $pids .= "$comma'{$getid['pid']}'";
        $comma = ",";
    }

    if($pids)
    {
        $pids = "pid IN($pids)";

        global $attachcache;
        $attachcache = array();
        if($thread['attachmentcount'] > 0)
        {
            // Now lets fetch all of the attachments for these posts.
            $query = $db->simple_select("attachments", "*", $pids);
            while($attachment = $db->fetch_array($query))
            {
                $attachcache[$attachment['pid']][$attachment['aid']] = $attachment;
            }
        }
    }
    else
    {
        // If there are no pid's the thread is probably awaiting approval.
        return xmlrespfalse($lang->error_invalidthread);
    }

    $post_list = array();

    // Get the actual posts from the database here.
    $posts = '';
    $query = $db->query("
        SELECT u.*, u.username AS userusername, p.*, f.*, eu.username AS editusername, IF(b.lifted > UNIX_TIMESTAMP() OR b.lifted = 0, 1, 0) as isbanned
        FROM ".TABLE_PREFIX."posts p
        LEFT JOIN ".TABLE_PREFIX."users u ON (u.uid=p.uid)
        LEFT JOIN ".TABLE_PREFIX."userfields f ON (f.ufid=u.uid)
        LEFT JOIN ".TABLE_PREFIX."users eu ON (eu.uid=p.edituid)
        LEFT JOIN ".TABLE_PREFIX."banned b ON (b.uid = p.uid)
        WHERE $pids
        ORDER BY p.dateline
    ");
    
	//can_rename topic
    $can_rename = (is_moderator($fid, "caneditposts") || ($forumpermissions['caneditposts'] == 1 && $mybb->user['uid'] == $thread['uid'])) && $mybb->user['uid'] != 0;

    while($post = $db->fetch_array($query))
    {
        if($thread['firstpost'] == $post['pid'] && $thread['visible'] == 0)
        {
            $post['visible'] = 0;
        }
        //$posts .= build_postbit($post);
        $parser_options = array();
        $parser_options['allow_html'] = false;
        $parser_options['allow_mycode'] = true;
        $parser_options['allow_smilies'] = false;
        $parser_options['allow_imgcode'] = true;
        $parser_options['allow_videocode'] = true;
        $parser_options['nl2br'] = (boolean)$input['return_html'];
        $parser_options['filter_badwords'] = 1;

        if(!$post['username'])
        {
            $post['username'] = $lang->guest;
        }

        if($post['userusername'])
        {
            $parser_options['me_username'] = $post['userusername'];
        }
        else
        {
            $parser_options['me_username'] = $post['username'];
        }

        $post['subject'] = $parser->parse_badwords($post['subject']);
        $post['author'] = $post['uid'];

        if($post['userusername'])
        { // This post was made by a registered user

            $post['username'] = $post['userusername'];
        }

        // Eidt Option
        $can_edit = (is_moderator($fid, "caneditposts") || ($forumpermissions['caneditposts'] == 1 && $mybb->user['uid'] == $post['uid'])) && $mybb->user['uid'] != 0;

        // Quick Delete Option
        $can_delete = 0;
        if($mybb->user['uid'] == $post['uid'])
        {
            if($forumpermissions['candeletethreads'] == 1 && $postcounter == 1)
            {
                $can_delete = 1;
            }
            else if($forumpermissions['candeleteposts'] == 1 && $postcounter != 1)
            {
                $can_delete = 1;
            }
        }
        $can_delete = (is_moderator($fid, "candeleteposts") || $can_delete == 1) && $mybb->user['uid'] != 0;

        // User Online status
        $is_online = false;
        $timecut = TIME_NOW - $mybb->settings['wolcutoff'];
        if($post['lastactive'] > $timecut && ($post['invisible'] != 1 || $mybb->usergroup['canviewwolinvis'] == 1) && $post['lastvisit'] != $post['lastactive'])
        {
            $is_online = true;
        }
        $post['message'] = post_bbcode_clean($post['message']);
        
        $plugins->remove_hook('parse_message','mybbirckeditor_parser');
        // Post content and attachments
        $post['message'] = $parser->parse_message($post['message'], $parser_options);
       
        $attachment_list = process_post_attachments($post['pid'], $post);
        // add for thank/like support
        $post = $plugins->run_hooks("postbit", $post);

        if(is_array($ignored_users) && $post['uid'] != 0 && $ignored_users[$post['uid']] == 1){
            $show_spoiler = "[spoiler]".$post['message']."[/spoiler]";
            $post['message'] = $lang->sprintf($lang->postbit_currently_ignoring_user, $post['username']) . $show_spoiler;
        }
		
        $post_xmlrpc = array(
            'post_id'           => new xmlrpcval($post['pid'], 'string'),
            'post_title'        => new xmlrpcval(basic_clean($post['subject']), 'base64'),
            'post_content'      => new xmlrpcval(process_post($post['message'], $input['return_html']), 'base64'),
            'post_author_id'    => new xmlrpcval($post['uid'], 'string'),
            'post_author_name'  => new xmlrpcval(basic_clean($post['username']), 'base64'),
            'icon_url'          => new xmlrpcval(absolute_url($post['avatar']), 'string'),
            'post_time'         => new xmlrpcval(mobiquo_iso8601_encode($post['dateline']), 'dateTime.iso8601'),
            'timestamp'         => new xmlrpcval($post['dateline'], 'string'),
            'attachments'       => new xmlrpcval($attachment_list, 'array'),
        );

        if (!$post['visible'])  $post_xmlrpc['is_approved']     = new xmlrpcval(false, 'boolean'); // default as true
        if ($post['smilieoff']) $post_xmlrpc['allow_smilies']   = new xmlrpcval(false, 'boolean'); // default as true
        if ($post['isbanned'])  $post_xmlrpc['is_ban']          = new xmlrpcval(true, 'boolean');
        if ($is_online)         $post_xmlrpc['is_online']       = new xmlrpcval(true, 'boolean');
        if ($can_edit)          $post_xmlrpc['can_edit']        = new xmlrpcval(true, 'boolean');
        if ($can_delete)        $post_xmlrpc['can_delete']      = new xmlrpcval(true, 'boolean');
        if (is_moderator($fid, 'canmanagethreads')) $post_xmlrpc['can_approve'] = new xmlrpcval(true, 'boolean');
        if (is_moderator($fid, 'canmanagethreads')) $post_xmlrpc['can_move']    = new xmlrpcval(true, 'boolean');
        if ($mybb->usergroup['canmodcp'] == 1)      $post_xmlrpc['can_ban']     = new xmlrpcval(true, 'boolean');
		
        if($post['edituid'])
        {
	        //add edit info
	        $edit_info = array(
	        	'editor_id'   => new xmlrpcval($post['edituid'],'string'),
	        	'editor_name' => new xmlrpcval($post['editusername'],'base64'),
	        	'edit_time'   => new xmlrpcval($post['edittime'],'string'),
	        );
        	if(!empty($post['editreason']))
	        {
	        	$edit_info['edit_reason'] = new xmlrpcval($post['editreason'],'base64');
	        }
	        $post_xmlrpc = array_merge($post_xmlrpc,$edit_info);
        }
        
        // add for thank/like support
        if (isset($post['button_tyl']) && $mybb->user['uid'])
        {
            global $mobiquo_config, $g33k_pcache;
            $thlprefix = $mobiquo_config['thlprefix'];
            
            $tyled = false;
            $tyl_list = array();
            if ($mybb->settings[$thlprefix.'enabled'] == "1")
            {
                if($post['thankyoulike'] && isset($g33k_pcache[$post['pid']]))
                {
                    foreach($g33k_pcache[$post['pid']] AS $tyl)
                    {
                        if ($tyl['uid'] == $mybb->user['uid']) $tyled = true;
                        $tyl_list[] = new xmlrpcval(array(
                            'userid'    => new xmlrpcval($tyl['uid'], 'string'),
                            'username'  => new xmlrpcval(basic_clean($tyl['username']), 'base64'),
                        ), 'struct');
                    }
                }
                
                if ($mybb->settings[$thlprefix.'thankslike'] == "like")
                {
                    if ($post['button_tyl']) $post_xmlrpc['can_like'] = new xmlrpcval(true, 'boolean');
                    if ($tyled) $post_xmlrpc['is_liked'] = new xmlrpcval(true, 'boolean');
                    if ($tyl_list) $post_xmlrpc['likes_info'] = new xmlrpcval($tyl_list, 'array');
                }
                else if ($mybb->settings[$thlprefix.'thankslike'] == "thanks")
                {
                    if ($post['button_tyl']) $post_xmlrpc['can_thank'] = new xmlrpcval(true, 'boolean');
                    if ($mybb->settings[$thlprefix.'removing'] == 1) $post_xmlrpc['can_remove_thank'] = new xmlrpcval(true, 'boolean');
                    if ($tyled) $post_xmlrpc['is_thanked'] = new xmlrpcval(true, 'boolean');
                    if ($tyl_list) $post_xmlrpc['thanks_info'] = new xmlrpcval($tyl_list, 'array');
                }
            }
        }

        $post_list[] = new xmlrpcval($post_xmlrpc, 'struct');
    }

    $query = $db->simple_select("threadsubscriptions", "tid", "tid='".intval($tid)."' AND uid='".intval($mybb->user['uid'])."'", array('limit' => 1));
    $subscribed = (boolean)$db->fetch_field($query, 'tid');

    $query = $db->simple_select("banned", "uid", "uid='{$thread['uid']}'");
    $isbanned = !!$db->fetch_field($query, "uid");

    $can_reply = $forumpermissions['canpostreplys'] != 0 && $mybb->user['suspendposting'] != 1 && ($thread['closed'] != 1 || is_moderator($fid)) && $forum['open'] != 0;
    
	build_tt_breadcrumb($fid);
    $navgation_arr = $navbits;
	if(is_array($navgation_arr) && count($navgation_arr) > 1)
    {
    	unset($navgation_arr[0]);
        foreach ($navgation_arr as $navigation)
        {
        	$forum_id = $navigation['fid'];
        	$sub_only = false;
        	if($navigation['type'] != 'f')
        	{
        		$sub_only = true;
        	}
            $breadcrumb[] = new xmlrpcval(array(
                'forum_id'    => new xmlrpcval($forum_id, 'string'),
                'forum_name'  => new xmlrpcval($navigation['name'], 'base64'),
				'sub_only' => new xmlrpcval($sub_only, 'boolean'),
                ), 'struct');
        }
    }
    
    $is_poll = !empty($thread['poll']) ? true : false;
    $result = array(
        'total_post_num'  => new xmlrpcval($postcount, 'int'),
        'forum_id'        => new xmlrpcval($thread['fid'], 'string'),
        'forum_name'      => new xmlrpcval(basic_clean($forum['name']), 'base64'),
        'topic_id'        => new xmlrpcval($thread['tid'], 'string'),
        'topic_title'     => new xmlrpcval(basic_clean($thread['subject']), 'base64'),
        'can_upload'      => new xmlrpcval($forumpermissions['canpostattachments'] != 0, 'boolean'),
    	'can_report'      => new xmlrpcval(true,'boolean'),
    	'can_reply'       => new xmlrpcval($can_reply, 'boolean'),
    	'is_poll'         => new xmlrpcval($is_poll, 'boolean'),
    	'view_number'     => new xmlrpcval(intval($thread['views']), 'int'),
        //'can_subscribe'   => new xmlrpcval(true, 'boolean'), // default as true, so don't need to return
    );
	if($forumpermissions['canview'] == 0 || $forumpermissions['canviewthreads'] == 0)
	{
		$new_topic['can_subscribe']  = new xmlrpcval(false, 'boolean');
	}
	else 
	{
		$new_topic['can_subscribe']  = new xmlrpcval(true, 'boolean');
	}
    if ($thread['prefix'])  $result['prefix']        = new xmlrpcval(basic_clean($thread['displayprefix']), 'base64');
    if (!$thread['visible'])$result['is_approved']   = new xmlrpcval(false, 'boolean'); // default as true
    if ($thread['closed'])  $result['is_closed']     = new xmlrpcval(true, 'boolean');
    if ($thread['sticky'])  $result['is_sticky']     = new xmlrpcval(true, 'boolean');
    if ($subscribed)        $result['is_subscribed'] = new xmlrpcval(true, 'boolean');
    else                    $result['is_subscribed'] = new xmlrpcval(false, 'boolean');
    if ($isbanned)          $result['is_ban']        = new xmlrpcval(true, 'boolean');
    if ($position)          $result['position']      = new xmlrpcval(intval($position), 'int');
    if (is_moderator($fid, "canopenclosethreads"))  $result['can_close']    = new xmlrpcval(true, 'boolean');
    if (is_moderator($fid, "candeleteposts"))       $result['can_delete']   = new xmlrpcval(true, 'boolean');
    if (is_moderator($fid, "canmanagethreads"))     $result['can_stick']    = new xmlrpcval(true, 'boolean');
    if (is_moderator($fid, "canmanagethreads")) 
	{
		$result['can_move']      = new xmlrpcval(true, 'boolean');
		$result['can_merge']     = new xmlrpcval(true, 'boolean');
		$result['can_merge_post']     = new xmlrpcval(true, 'boolean');
	}
    if (is_moderator($fid, "canopenclosethreads"))  $result['can_approve']  = new xmlrpcval(true, 'boolean');
    if ($can_rename)         $result['can_rename']   = new xmlrpcval(true, 'boolean');
    if ($mybb->usergroup['canmodcp'] == 1)          $result['can_ban']      = new xmlrpcval(true, 'boolean');
    if (!empty($breadcrumb))
    {
    	$result['breadcrumb'] = new xmlrpcval($breadcrumb, 'array');
    }
    $result['posts'] = new xmlrpcval($post_list, 'array');

    return new xmlrpcresp(new xmlrpcval($result, 'struct'));
}

if (!function_exists('build_prefixes'))
{
    define('OLD_PREFIX', 1);

    function build_prefixes($pid=0)
    {
        global $db;
        static $prefixes_cache;

        if (isset($prefixes_cache[$pid])) return $prefixes_cache[$pid];

        $query = $db->simple_select('threadprefixes', 'prefix, displaystyle', "pid='{$pid}'");
        $threadprefix = $db->fetch_array($query);

        $prefixes_cache[$pid] = $threadprefix;

        return $threadprefix;
    }
}

function build_tt_breadcrumb($fid, $multipage=array())
{
	global $pforumcache, $currentitem, $forum_cache, $navbits, $lang, $base_url, $archiveurl;

	if(!$pforumcache)
	{
		if(!is_array($forum_cache))
		{
			cache_forums();
		}

		foreach($forum_cache as $key => $val)
		{
			$pforumcache[$val['fid']][$val['pid']] = $val;
		}
	}

	if(is_array($pforumcache[$fid]))
	{
		foreach($pforumcache[$fid] as $key => $forumnav)
		{
			
			if($fid == $forumnav['fid'])
			{
				if($pforumcache[$forumnav['pid']])
				{
					build_tt_breadcrumb($forumnav['pid']);
				}
				
				$navsize = count($navbits);
				// Convert & to &amp;
				$navbits[$navsize]['name'] =  $forumnav['name'];
				$navbits[$navsize]['type'] = $pforumcache[$fid][$forumnav['pid']]['type'];
				if(IN_ARCHIVE == 1)
				{
					// Set up link to forum in breadcrumb.
					if($pforumcache[$fid][$forumnav['pid']]['type'] == 'f' || $pforumcache[$fid][$forumnav['pid']]['type'] == 'c')
					{
						$navbits[$navsize]['fid'] = $forumnav['fid'];
					}
					else
					{
						$navbits[$navsize]['fid'] = "";
					}
				}
				else
				{
					$navbits[$navsize]['fid'] = $forumnav['fid'];
				}
			}
		}
	}

	return 1;
}

function get_announcement_func($xmlrpc_params)
{
	global $db, $lang, $mybb, $position, $plugins, $pids,$groupscache;
	$input = Tapatalk_Input::filterXmlInput(array(
        'topic_id'    => Tapatalk_Input::STRING,
        'start_num'   => Tapatalk_Input::INT,
        'last_num'    => Tapatalk_Input::INT,
        'return_html' => Tapatalk_Input::INT
    ), $xmlrpc_params);
	
    $parser = new Tapatalk_Parser;
	// Load global language phrases
	$lang->load("announcements");
	
	$aid = intval($_GET['aid']);
	
	// Get announcement fid
	$query = $db->simple_select("announcements", "fid", "aid='$aid'");
	$announcement = $db->fetch_array($query);
	
	$plugins->run_hooks("announcements_start");
	
	if(!$announcement)
	{
		error($lang->error_invalidannouncement);
	}
	
	// Get forum info
	$fid = $announcement['fid'];
	if($fid > 0)
	{
		$forum = get_forum($fid);
	
		if(!$forum)
		{
			error($lang->error_invalidforum);
		}
	
		// Make navigation
		build_forum_breadcrumb($forum['fid']);
	
		// Permissions
		$forumpermissions = forum_permissions($forum['fid']);
	
		if($forumpermissions['canview'] == 0 || $forumpermissions['canviewthreads'] == 0)
		{
			error_no_permission();
		}
	
		// Check if this forum is password protected and we have a valid password
		check_forum_password($forum['fid']);
	}
	add_breadcrumb($lang->nav_announcements);
	
	$archive_url = build_archive_link("announcement", $aid);
	
	// Get announcement info
	$time = TIME_NOW;
	
	$query = $db->query("
		SELECT u.*, u.username AS userusername, a.*, f.*
		FROM ".TABLE_PREFIX."announcements a
		LEFT JOIN ".TABLE_PREFIX."users u ON (u.uid=a.uid)
		LEFT JOIN ".TABLE_PREFIX."userfields f ON (f.ufid=u.uid)
		WHERE a.startdate<='$time' AND (a.enddate>='$time' OR a.enddate='0') AND a.aid='$aid'
	");
	
	$announcementarray = $db->fetch_array($query);
	
	if(!$announcementarray)
	{
		error($lang->error_invalidannouncement);
	}
	
	// Gather usergroup data from the cache
	// Field => Array Key
	$data_key = array(
		'title' => 'grouptitle',
		'usertitle' => 'groupusertitle',
		'stars' => 'groupstars',
		'starimage' => 'groupstarimage',
		'image' => 'groupimage',
		'namestyle' => 'namestyle',
		'usereputationsystem' => 'usereputationsystem'
	);
	
	foreach($data_key as $field => $key)
	{
		$announcementarray[$key] = $groupscache[$announcementarray['usergroup']][$field];
	}
	
	$announcementarray['dateline'] = $announcementarray['startdate'];
	$announcementarray['userusername'] = $announcementarray['username'];
	$announcement = build_postbit($announcementarray, 3);
	$announcementarray['subject'] = $parser->parse_badwords($announcementarray['subject']);
	$lang->forum_announcement = $lang->sprintf($lang->forum_announcement, htmlspecialchars_uni($announcementarray['subject']));
	
	if($announcementarray['startdate'] > $mybb->user['lastvisit'])
	{
		$setcookie = true;
		if(isset($mybb->cookies['mybb']['announcements']) && is_scalar($mybb->cookies['mybb']['announcements']))
		{
			$cookie = my_unserialize(stripslashes($mybb->cookies['mybb']['announcements']));
	
			if(isset($cookie[$announcementarray['aid']]))
			{
				$setcookie = false;
			}
		}
	
		if($setcookie)
		{
			my_set_array_cookie('announcements', $announcementarray['aid'], $announcementarray['startdate'], -1);
		}
	}
	$user_info = get_user($announcementarray['aid']);
	$icon_url = absolute_url($user_info['avatar']);
	// prepare xmlrpc return
	$xmlrpc_post = new xmlrpcval(array(
		'topic_id'          => new xmlrpcval('ann_' . $announcementarray['aid']),
		'post_title'        => new xmlrpcval(basic_clean($announcementarray['subject']), 'base64'),
		'post_content'      => new xmlrpcval(process_post($announcementarray['message'], $input['return_html']), 'base64'),
		'post_author_id'    => new xmlrpcval($announcementarray['uid']),
		'post_author_name'  => new xmlrpcval(basic_clean($announcementarray['username']), 'base64'),
		'user_type'         => new xmlrpcval(check_return_user_type($announcementarray['username']),'base64'),
		'icon_url'          => new xmlrpcval(absolute_url($icon_url)),
		'post_time'         => new xmlrpcval(mobiquo_iso8601_encode($announcementarray['dateline']), 'dateTime.iso8601'),
        'timestamp'         => new xmlrpcval($announcementarray['dateline'], 'string'),
	), 'struct');

	$result = array (
		'total_post_num'=> new xmlrpcval(1, 'int'),
		'can_reply'     => new xmlrpcval(false, 'boolean'),
		'can_subscribe' => new xmlrpcval(false, 'boolean'),
		'posts'         => new xmlrpcval(array($xmlrpc_post), 'array'),
	);	
	return new xmlrpcresp(new xmlrpcval($result, 'struct'));	
}