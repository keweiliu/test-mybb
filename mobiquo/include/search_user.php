<?php
defined('IN_MOBIQUO') or exit;

function search_user_func()
{
	global $mybb,$db,$lang,$cache;
	// Load global language phrases
	$lang->load("memberlist");
	
	if($mybb->settings['enablememberlist'] == 0)
	{
		return xmlrespfalse($lang->memberlist_disabled);
	}
	
	//$plugins->run_hooks("memberlist_start");

	if($mybb->usergroup['canviewmemberlist'] == 0)
	{
		return tt_no_permission();
	}
	
	$colspan = 5;
	$search_url = '';

	// Incoming sort field?
	$mybb->input['sort'] = $mybb->settings['default_memberlist_sortby'];
	
	switch($mybb->input['sort'])
	{
		case "regdate":
			$sort_field = "u.regdate";
			break;
		case "lastvisit":
			$sort_field = "u.lastactive";
			break;
		case "reputation":
			$sort_field = "u.reputation";
			break;
		case "postnum":
			$sort_field = "u.postnum";
			break;
		case "referrals":
			$sort_field = "u.referrals";
			break;
		default:
			$sort_field = "u.username";
			$mybb->input['sort'] = 'username';
			break;
	}
	//$sort_selected[$mybb->input['sort']] = " selected=\"selected\"";
	
	// Incoming sort order?
	$mybb->input['order'] = strtolower($mybb->settings['default_memberlist_order']);
	
	if($mybb->input['order'] == "ascending" || (!$mybb->input['order'] && $mybb->input['sort'] == 'username'))
	{
		$sort_order = "ASC";
		$mybb->input['order'] = "ascending";
	}
	else
	{
		$sort_order = "DESC";
		$mybb->input['order'] = "descending";
	}
	//$order_check[$mybb->input['order']] = " checked=\"checked\"";
	
	// Incoming results per page?
	$mybb->input['perpage'] = intval($mybb->input['perpage']);
	if($mybb->input['perpage'] > 0 && $mybb->input['perpage'] <= 500)
	{
		$per_page = $mybb->input['perpage'];
	}
	else if($mybb->settings['membersperpage'])
	{
		$per_page = $mybb->input['perpage'] = intval($mybb->settings['membersperpage']);	
	}
	else
	{
		$per_page = $mybb->input['perpage'] = 20;
	}
	
	$search_query = '1=1';
	

	// Searching for a matching username
	$search_username = htmlspecialchars_uni(trim($mybb->input['username']));
	if($search_username != '')
	{
		$username_like_query = $db->escape_string_like($search_username);

		// Name begins with
		if($mybb->input['username_match'] == "begins")
		{
			$search_query .= " AND u.username LIKE '".$username_like_query."%'";
		}
		// Just contains
		else
		{
			$search_query .= " AND u.username LIKE '%".$username_like_query."%'";
		}

	}

	

	$query = $db->simple_select("users u", "COUNT(*) AS users", "{$search_query}");
	$num_users = $db->fetch_field($query, "users");

	$page = intval($mybb->input['page']);
	if($page && $page > 0)
	{
		$start = ($page - 1) * $per_page;
	}
	else
	{
		$start = 0;
		$page = 1;
	}
	//$search_url = htmlspecialchars_uni($search_url);
	$multipage = multipage($num_users, $per_page, $page, $search_url);
	
	// Cache a few things
	$usergroups_cache = $cache->read('usergroups');
	$query = $db->simple_select("usertitles", "*", "", array('order_by' => 'posts', 'order_dir' => 'DESC'));
	while($usertitle = $db->fetch_array($query))
	{
		$usertitles_cache[$usertitle['posts']] = $usertitle;
	}
	$query = $db->query("
		SELECT u.*, f.*
		FROM ".TABLE_PREFIX."users u
		LEFT JOIN ".TABLE_PREFIX."userfields f ON (f.ufid=u.uid)
		WHERE {$search_query}
		ORDER BY {$sort_field} {$sort_order}
		LIMIT {$start}, {$per_page}
	");
	while($user = $db->fetch_array($query))
	{
		//$user = $plugins->run_hooks("memberlist_user", $user);
		if(!$user['username'])
		{
			continue;
		}

		$user['username'] = format_name($user['username'], $user['usergroup'], $user['displaygroup']);
		
		if($user['avatar'] != '')
		{
			$user['avatar'] = absolute_url($user['avatar']);
		}
		else
		{
			$user['avatar'] = "";
		}		
		
		$users[] = $user;
	}
	
	$total = $num_users;
	if(!empty($users))
        foreach ($users as $user)
            $return_user_lists[] = new xmlrpcval(array(
                'username'     => new xmlrpcval(basic_clean($user['username']), 'base64'),
                'user_id'       => new xmlrpcval($user['uid'], 'string'),
                'icon_url'      => new xmlrpcval($user['avatar'], 'string'),
            ), 'struct');

    $suggested_users = new xmlrpcval(array(
        'total' => new xmlrpcval($total, 'int'),
        'list'         => new xmlrpcval($return_user_lists, 'array'),
    ), 'struct');

    return new xmlrpcresp($suggested_users);
}