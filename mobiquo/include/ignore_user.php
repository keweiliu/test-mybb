<?php
defined('IN_MOBIQUO') or exit;

function ignore_user_func()
{
	global $mybb,$lang,$db,$cache;
	// Load global language phrases
	$lang->load("usercp");
	
	if($mybb->user['uid'] == 0 || $mybb->usergroup['canusercp'] == 0)
	{
		return tt_no_permission();
	}
	
	if(!$mybb->user['pmfolders'])
	{
		$mybb->user['pmfolders'] = "1**".$lang->folder_inbox."$%%$2**".$lang->folder_sent_items."$%%$3**".$lang->folder_drafts."$%%$4**".$lang->folder_trash;
		$db->update_query("users", array('pmfolders' => $mybb->user['pmfolders']), "uid='".$mybb->user['uid']."'");
	}
	
	$existing_users = array();
	$selected_list = array();
	if($mybb->input['manage'] == "ignored")
	{
		if($mybb->user['ignorelist'])
		{
			$existing_users = explode(",", $mybb->user['ignorelist']);
		}

	}
	else
	{
		if($mybb->user['ignorelist'])
		{
			// Create a list of ignored users
			$selected_list = explode(",", $mybb->user['ignorelist']);
		}
	}
	
	$error_message = "";
	$message = "";
	
	$users = $mybb->input['user_id'];
	$mode = $mybb->input['mode'];
	
	// Adding one or more users to this list
	if(!empty($users) && $mode)
	{
		// Split up any usernames we have
		$found_users = 0;
		$adding_self = false;
		$users = explode(",", $users);
		$users = array_map("trim", $users);
		$users = array_unique($users);
		foreach($users as $key => $user_id)
		{
			$user_id = intval($user_id);
			if(empty($user_id))
			{
				continue;
			}
			$users[$key] = $user_id;
		}

		// Fetch out new users
		if(count($users) > 0)
		{
			$query = $db->simple_select("users", "uid", "uid IN ('".implode("','", $users)."')");
			while($user = $db->fetch_array($query))
			{
				++$found_users;

				// Make sure we're not adding a duplicate
				if(in_array($user['uid'], $existing_users) || in_array($user['uid'], $selected_list))
				{
					if($mybb->input['manage'] == "ignored")
					{
						$error_message = "ignore";
					}
					else
					{
						$error_message = "buddy";
					}

					// On another list?
					$string = "users_already_on_".$error_message."_list";
					if(in_array($user['uid'], $selected_list))
					{
						$string .= "_alt";
					}

					$error_message = $lang->$string;
					array_pop($users); // To maintain a proper count when we call count($users)
					continue;
				}
				
				$existing_users[] = $user['uid'];
			}
		}

		if($found_users < count($users))
		{
			if($error_message)
			{
				$error_message .= "<br />";
			}

			$error_message .= $lang->invalid_user_selected;
		}

		if(($adding_self != true || ($adding_self == true && count($users) > 0)) && ($error_message == "" || count($users) > 1))
		{
			if($mybb->input['manage'] == "ignored")
			{
				$message = $lang->users_added_to_ignore_list;
			}
			else
			{
				$message = $lang->users_added_to_buddy_list;
			}
		}

		if($adding_self == true)
		{
			if($mybb->input['manage'] == "ignored")
			{
				$error_message = $lang->cant_add_self_to_ignore_list;
			}
			else
			{
				$error_message = $lang->cant_add_self_to_buddy_list;
			}
		}

		if(count($existing_users) == 0)
		{
			$message = "";
		}
	}

	// Removing a user from this list
	else if($mode == 0 && !empty($users))
	{
		// Check if user exists on the list
		$key = array_search($users, $existing_users);
		if($key !== false)
		{
			unset($existing_users[$key]);
			$user = get_user($users);
			if($mybb->input['manage'] == "ignored")
			{
				$message = $lang->removed_from_ignore_list;
			}
			else
			{
				$message = $lang->removed_from_buddy_list;
			}
			$message = $lang->sprintf($message, $user['username']);
		}
		else
		{
			$error_message = $lang->invalid_user_selected;
		}
	}
		
	if($error_message)
	{
		return xmlrespfalse($error_message);
	}
	
	if(empty($message))
	{
		return xmlresperror($lang->invalid_user_selected);
	}
	
	
	// Now we have the new list, so throw it all back together
	$new_list = implode(",", $existing_users);

	// And clean it up a little to ensure there is no possibility of bad values
	$new_list = preg_replace("#,{2,}#", ",", $new_list);
	$new_list = preg_replace("#[^0-9,]#", "", $new_list);

	if(my_substr($new_list, 0, 1) == ",")
	{
		$new_list = my_substr($new_list, 1);
	}
	
	if(my_substr($new_list, -1) == ",")
	{
		$new_list = my_substr($new_list, 0, my_strlen($new_list)-2);
	}

	// And update
	$user = array();
	if($mybb->input['manage'] == "ignored")
	{
		$user['ignorelist'] = $db->escape_string($new_list);
		$mybb->user['ignorelist'] = $user['ignorelist'];
	}
	else
	{
		$user['buddylist'] = $db->escape_string($new_list);
		$mybb->user['buddylist'] = $user['buddylist'];
	}

	$db->update_query("users", $user, "uid='".$mybb->user['uid']."'");

	$result = new xmlrpcval(array(
        'result'        => new xmlrpcval(true, 'boolean'),
        'result_text'   => new xmlrpcval(strip_tags($message), 'base64')
    ), 'struct');

    return new xmlrpcresp($result);
		
}