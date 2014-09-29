<?php
defined('IN_MOBIQUO') or exit;
include_once TT_ROOT."lib/classTTJson.php";
function get_recommended_user_func()
{
	global $mybb,$db,$lang,$users,$tapatalk_users;
	$tapatalk_users = array();
	$users = array();
	// Load global language phrases
	$lang->load("memberlist");
	
	//get tapatalk users
	if(isset($_POST['mode']) && $_POST['mode'] == 2) 
	{
		$sql = "SELECT userid FROM " . TABLE_PREFIX ."tapatalk_users";
		$query = $db->query($sql);
		while($user = $db->fetch_array($query))
		{
			$tapatalk_users[] = $user['userid'];
		} 
	}
	
	// get pm users 
	$sql = "SELECT p.toid as uid
	FROM ".TABLE_PREFIX."privatemessages p 
	WHERE p.uid = ".$mybb->user['uid']."
	GROUP BY p.toid
	LIMIT 0,1000";	
	get_recommended_user_list($sql,'contact');
	
	// get pm me users 
	$sql = "SELECT p.uid as uid
	FROM ".TABLE_PREFIX."privatemessages p 
	WHERE p.toid = ".$mybb->user['uid']."
	GROUP BY p.uid
	LIMIT 0,1000";	
	get_recommended_user_list($sql,'contact');
	
	//get sub topic users 
	$sql = "SELECT t.uid as uid
	FROM " . TABLE_PREFIX . "threadsubscriptions ts 
	LEFT JOIN " . TABLE_PREFIX . "threads t ON ts.tid = t.tid 
	WHERE ts.uid = " . $mybb->user['uid'] . "
	GROUP BY t.uid
	LIMIT 0,1000";
	get_recommended_user_list($sql,'watch');
	
	//get sub me topic users 
	$sql = "SELECT ts.uid as uid
	FROM " . TABLE_PREFIX . "threadsubscriptions ts 
	RIGHT JOIN " . TABLE_PREFIX . "threads t ON ts.tid = t.tid 
	WHERE t.uid = " . $mybb->user['uid'] . "
	GROUP BY ts.uid
	LIMIT 0,1000";
	
	get_recommended_user_list($sql,'watch');
	
	//get like or thank users
	$prefix = "g33k_thankyoulike_";
	if(file_exists('thankyoulike.php') && $db->table_exists($prefix.'thankyoulike'))
	{
		$sql = "SELECT thl.puid as uid
		FROM " . TABLE_PREFIX . $prefix .  "thankyoulike thl 
		WHERE thl.uid = ".$mybb->user['uid']."
		GROUP BY thl.puid
		LIMIT 0,1000";

		get_recommended_user_list($sql,'like');
		
		$sql = "SELECT thl.uid as uid
		FROM " . TABLE_PREFIX . $prefix .  "thankyoulike thl 
		WHERE thl.puid = ".$mybb->user['uid']."
		GROUP BY thl.uid
		LIMIT 0,1000";

		get_recommended_user_list($sql,'liked');
	}
    
    $page =  intval($_POST['page']);
    $perpage = intval($_POST['perpage']);
    $start = ($page-1) * $perpage;
    $return_user_lists = array();
    $users_rank = tapa_rank_users($users);
    $total = count($users_rank);
    $users_slice = array_slice($users_rank, $start,$perpage);
    $user_id_str = implode(',', $users_slice);
    $mobi_api_key = loadAPIKey();
    
    if(!empty($user_id_str))
    {
    	$sql = "SELECT uid,username,email,avatar FROM " . TABLE_PREFIX ."users WHERE uid IN($user_id_str)";
    	$query = $db->query($sql);
        while($user = $db->fetch_array($query))
        {
        	$user['username'] = format_name($user['username'], $user['usergroup'], $user['displaygroup']);
	        if($user['avatar'] != '')
			{
				$user['avatar'] = absolute_url($user['avatar']);
			}			
            $return_user_lists[] = new xmlrpcval(array(
                'username'      => new xmlrpcval(basic_clean($user['username']), 'base64'),
                'user_id'       => new xmlrpcval($user['uid'], 'string'),
                'icon_url'      => new xmlrpcval($user['avatar'],'string'),
                'type'          => new xmlrpcval('', 'string'),
            	'enc_email'     => new xmlrpcval(base64_encode(encrypt(trim($user['email']), $mobi_api_key)), 'string'),
            ), 'struct');
        }
    }
    $suggested_users = new xmlrpcval(array(
        'total' => new xmlrpcval($total, 'int'),
        'list'         => new xmlrpcval($return_user_lists, 'array'),
    ), 'struct');

    return new xmlrpcresp($suggested_users);
}

function get_recommended_user_list($sql,$type)
{
	global $db,$users,$mybb,$tapatalk_users;
	$query = $db->query($sql);
	while($user = $db->fetch_array($query))
	{		
		if(empty($user['uid']))
		{
			continue;
		}	
		if($user['uid'] == $mybb->user['uid'])
		{
			continue;
		}
			
		//non tapatalk users
		if(isset($_POST['mode']) && $_POST['mode'] == 2 && in_array($user['uid'], $tapatalk_users))
		{
			continue;
		}
		
		if($type == 'contact')
		{
			$user['rank'] = 10;
		}
		else if($type == 'watch')
		{
			$user['rank'] = 5;
		}
		else 
		{
			$user['rank'] = 3;
		}

		$users[] = $user;
	}
}

function tapa_rank_users($users, $max_num = 1000)
{    
    // combine ranks for same user
    $combined_users = array();
    
    foreach($users as $user)
    {
        if(isset($combined_users[$user['uid']]))
        {
            $combined_users[$user['uid']]['rank'] += $user['rank'];
        }
        else
        {   
            $combined_users[$user['uid']] = $user;
        }
    }
    $users = $combined_users;
    
    // sort by rank
    $hash = array();
    
    foreach($users as $user)
    {
        $hash[$user['uid']] = $user['rank'];
    }
    
    arsort($hash);
    
    $users = array();
    $count = 0;
    foreach($hash as $key => $user)
    {
        if($count > $max_num || $count == $max_num)
            break;
        $users[] = $key;
        $count++;
    }
    
    return $users;
}

function keyED($txt,$encrypt_key)
{
    $encrypt_key = md5($encrypt_key);
    $ctr=0;
    $tmp = "";
    for ($i=0;$i<strlen($txt);$i++)
    {
        if ($ctr==strlen($encrypt_key)) $ctr=0;
        $tmp.= substr($txt,$i,1) ^ substr($encrypt_key,$ctr,1);
        $ctr++;
    }
    return $tmp;
}
 
function encrypt($txt,$key)
{
    srand((double)microtime()*1000000);
    $encrypt_key = md5(rand(0,32000));
    $ctr=0;
    $tmp = "";
    for ($i=0;$i<strlen($txt);$i++)
    {
        if ($ctr==strlen($encrypt_key)) $ctr=0;
        $tmp.= substr($encrypt_key,$ctr,1) .
        (substr($txt,$i,1) ^ substr($encrypt_key,$ctr,1));
        $ctr++;
    }
    return keyED($tmp,$key);
}

function loadAPIKey()
{
    global $mybb;
    $mobi_api_key = $mybb->settings['tapatalk_push_key'];
    if(empty($mobi_api_key))
    {   
        $boardurl = $mybb->settings['bburl'];
        $boardurl = urlencode($boardurl);
        $response = getContentFromRemoteServer("http://directory.tapatalk.com/au_reg_verify.php?url=$boardurl", 10, $error);
        if($response)
        {
            $result = json_decode($response, true);
            if(isset($result) && isset($result['result']))
            {
                $mobi_api_key = $result['api_key'];
                return $mobi_api_key;
            }
        } 
        return false;    
    }
    return $mobi_api_key;
}
