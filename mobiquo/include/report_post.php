<?php
  

defined('IN_MOBIQUO') or exit;

function report_post_func($xmlrpc_params)
{
	global $db, $lang, $theme, $plugins, $mybb, $session, $settings, $cache, $time, $mybbgroups;
	
	$input = Tapatalk_Input::filterXmlInput(array(
		'post_id' => Tapatalk_Input::INT,
		'reason' => Tapatalk_Input::STRING,
	), $xmlrpc_params);
	
		
	$lang->load("report");

	if($mybb->usergroup['canview'] == 0 || !$mybb->user['uid'])
	{
		return tt_no_permission();
	}

	$post = get_post($input['post_id']);

	if(!$post['pid'])
	{
		return xmlrespfalse($lang->error_invalidpost);
	}

	$forum = get_forum($post['fid']);
	if(!$forum)
	{
		$error = $lang->error_invalidforum;
		eval("\$report_error = \"".$templates->get("report_error")."\";");
		output_page($report_error);
		exit;
	}

	tt_check_forum_password($forum['parentlist']);

	$thread = get_thread($post['tid']);
	
	if(version_compare($mybb->version, '1.8.0', '<'))
	{
		
		if($mybb->settings['reportmethod'] == "email" || $mybb->settings['reportmethod'] == "pms")
		{
			$query = $db->query("
				SELECT DISTINCT u.username, u.email, u.receivepms, u.uid
				FROM ".TABLE_PREFIX."moderators m
				LEFT JOIN ".TABLE_PREFIX."users u ON (u.uid=m.id)
				WHERE m.fid IN (".$forum['parentlist'].") AND m.isgroup = '0'
			");
			$nummods = $db->num_rows($query);
			if(!$nummods)
			{
				unset($query);
				switch($db->type)
				{
					case "pgsql":
					case "sqlite":
						$query = $db->query("
							SELECT u.username, u.email, u.receivepms, u.uid
							FROM ".TABLE_PREFIX."users u
							LEFT JOIN ".TABLE_PREFIX."usergroups g ON (((','|| u.additionalgroups|| ',' LIKE '%,'|| g.gid|| ',%') OR u.usergroup = g.gid))
							WHERE (g.cancp=1 OR g.issupermod=1)
						");
						break;
					default:
						$query = $db->query("
							SELECT u.username, u.email, u.receivepms, u.uid
							FROM ".TABLE_PREFIX."users u
							LEFT JOIN ".TABLE_PREFIX."usergroups g ON (((CONCAT(',', u.additionalgroups, ',') LIKE CONCAT('%,', g.gid, ',%')) OR u.usergroup = g.gid))
							WHERE (g.cancp=1 OR g.issupermod=1)
						");
				}
			}
			
			while($mod = $db->fetch_array($query))
			{
				$emailsubject = $lang->sprintf($lang->emailsubject_reportpost, $mybb->settings['bbname']);
				$emailmessage = $lang->sprintf($lang->email_reportpost, $mybb->user['username'], $mybb->settings['bbname'], $post['subject'], $mybb->settings['bburl'], str_replace('&amp;', '&', get_post_link($post['pid'], $thread['tid'])."#pid".$post['pid']), $thread['subject'], $input['reason']);
				
				if($mybb->settings['reportmethod'] == "pms" && $mod['receivepms'] != 0 && $mybb->settings['enablepms'] != 0)
				{
					$pm_recipients[] = $mod['uid'];
				}
				else
				{
					my_mail($mod['email'], $emailsubject, $emailmessage);
				}
			}
	
			if(count($pm_recipients) > 0)
			{
				$emailsubject = $lang->sprintf($lang->emailsubject_reportpost, $mybb->settings['bbname']);
				$emailmessage = $lang->sprintf($lang->email_reportpost, $mybb->user['username'], $mybb->settings['bbname'], $post['subject'], $mybb->settings['bburl'], str_replace('&amp;', '&', get_post_link($post['pid'], $thread['tid'])."#pid".$post['pid']), $thread['subject'], $input['reason']);
	
				require_once MYBB_ROOT."inc/datahandlers/pm.php";
				$pmhandler = new PMDataHandler();
	
				$pm = array(
					"subject" => $emailsubject,
					"message" => $emailmessage,
					"icon" => 0,
					"fromid" => $mybb->user['uid'],
					"toid" => $pm_recipients
				);
	
				$pmhandler->admin_override = true;
				$pmhandler->set_data($pm);
	
				// Now let the pm handler do all the hard work.
				if(!$pmhandler->validate_pm())
				{
					// Force it to valid to just get it out of here
					$pmhandler->is_validated = true;
					$pmhandler->errors = array();
				}
				$pminfo = $pmhandler->insert_pm();
			}
		}
		else
		{
			$reportedpost = array(
				"pid" => $input['post_id'],
				"tid" => $thread['tid'],
				"fid" => $thread['fid'],
				"uid" => $mybb->user['uid'],
				"dateline" => TIME_NOW,
				"reportstatus" => 0,
				"reason" => $db->escape_string(htmlspecialchars_uni($input['reason']))
			);
			$db->insert_query("reportedposts", $reportedpost);
			$cache->update_reportedposts();
		}
	}
	else 
	{
		require_once MYBB_ROOT.'inc/functions_modcp.php';
		$plugins->run_hooks("report_do_report_start");
		$id = $post['pid'];
		$id2 = $post['tid'];
		$id3 = $forum['fid'];
		$report_type = 'post';
		$report_type_db = "(type = 'post' OR type = '')";
		if(!empty($report_type_db))
		{
			$query = $db->simple_select("reportedcontent", "*", "reportstatus != '1' AND id = '{$id}' AND {$report_type_db}");
		
			if($db->num_rows($query))
			{
				// Existing report
				$report = $db->fetch_array($query);
				$report['reporters'] = my_unserialize($report['reporters']);
		
				if($mybb->user['uid'] == $report['uid'] || is_array($report['reporters']) && in_array($mybb->user['uid'], $report['reporters']))
				{
					$error = $lang->success_report_voted;
				}
			}
		}
		// Is this an existing report or a new offender?
		if(!empty($report))
		{
			// Existing report, add vote
			$report['reporters'][] = $mybb->user['uid'];
			update_report($report);
			//$plugins->run_hooks("report_do_report_end");

		}
		else
		{
			// Bad user!
			$new_report = array(
				'id' => $id,
				'id2' => $id2,
				'id3' => $id3,
				'uid' => $mybb->user['uid']
			);
	
			// Figure out the reason
			$reason = trim($input['reason']);

			if($reason == 'other')
			{
				// Replace the reason with the user comment
				$reason = trim($mybb->get_input('comment'));
			}
			else
			{
				$report_reason_string = "report_reason_{$reason}";
				//$reason = "\n".$lang->$report_reason_string;
			}

			if(my_strlen($reason) < 3)
			{
				$error = $lang->error_report_length;
			}
	
			if(empty($error))
			{
				$new_report['reason'] = $reason;
				add_report($new_report, $report_type);				
			}
			else 
			{
				error($error);				
			}
		}
	}
	
	return xmlresptrue();

}