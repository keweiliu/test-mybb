<?php

if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

$plugins->add_hook('error', 'tapatalk_error');
$plugins->add_hook('redirect', 'tapatalk_redirect');
$plugins->add_hook('global_start', 'tapatalk_global_start');
$plugins->add_hook('fetch_wol_activity_end', 'tapatalk_fetch_wol_activity_end');
$plugins->add_hook('pre_output_page', 'tapatalk_pre_output_page');

// hook for push
$plugins->add_hook('newreply_do_newreply_end', 'tapatalk_push_reply');
$plugins->add_hook('newreply_do_newreply_end', 'tapatalk_push_quote');
$plugins->add_hook('newreply_do_newreply_end', 'tapatalk_push_tag');
$plugins->add_hook('private_do_send_end', 'tapatalk_push_pm');
$plugins->add_hook('newthread_do_newthread_end', 'tapatalk_push_newtopic');
$plugins->add_hook('newthread_do_newthread_end', 'tapatalk_push_quote');
$plugins->add_hook('newthread_do_newthread_end', 'tapatalk_push_tag');
$plugins->add_hook('online_user','tapatalk_online_user');
$plugins->add_hook('online_end','tapatalk_online_end');
$plugins->add_hook('postbit','tapatalk_postbit');
$plugins->add_hook('postbit_prev','tapatalk_postbit');
$plugins->add_hook('postbit_pm','tapatalk_postbit');
$plugins->add_hook('postbit_announcement','tapatalk_postbit');
//$plugins->add_hook('parse_message_start', "tapatalk_parse_message");
$plugins->add_hook('parse_message_end', "tapatalk_parse_message_end");
$plugins->add_hook("admin_config_settings_begin", "tapatalk_settings_update");
$plugins->add_hook('member_do_register_start', 'tt_is_spam');
function tapatalk_info()
{
    /**
     * Array of information about the plugin.
     * name: The name of the plugin
     * description: Description of what the plugin does
     * website: The website the plugin is maintained at (Optional)
     * author: The name of the author of the plugin
     * authorsite: The URL to the website of the author (Optional)
     * version: The version number of the plugin
     * guid: Unique ID issued by the MyBB Mods site for version checking
     * compatibility: A CSV list of MyBB versions supported. Ex, "121,123", "12*". Wildcards supported.
     */
    return array(
        "name"          => "Tapatalk",
        "description"   => "Tapatalk Plugin for MyBB",
        "website"       => "http://tapatalk.com",
        "author"        => "Quoord Systems Limited",
        "authorsite"    => "http://tapatalk.com",
        "version"       => "4.1.1",
        "guid"          => "e7695283efec9a38b54d8656710bf92e",
        "compatibility" => "1*"
    );
}

function tapatalk_install()
{
    global $db,$mybb;

    tapatalk_uninstall();
    if(!$db->table_exists('tapatalk_users'))
    {
        $db->query("
            CREATE TABLE IF NOT EXISTS " . TABLE_PREFIX . "tapatalk_users (
              userid int(10) NOT NULL,
              announcement smallint(5) NOT NULL DEFAULT '1',
              pm smallint(5) NOT NULL DEFAULT '1',
              subscribe smallint(5) NOT NULL DEFAULT '1',
              newtopic smallint(5) NOT NULL DEFAULT '1',
              quote smallint(5) NOT NULL DEFAULT '1',
              tag smallint(5) NOT NULL DEFAULT '1',
              updated timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (userid)
            )
        ");
    }
    if(!$db->table_exists("tapatalk_push_data"))
    {
        $db->query("
            CREATE TABLE " . TABLE_PREFIX . "tapatalk_push_data (
              push_id int(10) NOT NULL AUTO_INCREMENT,
              author varchar(100) NOT NULL,
              user_id int(10) NOT NULL DEFAULT '0',
              data_type char(20) NOT NULL DEFAULT '',
              title varchar(200) NOT NULL DEFAULT '',
              data_id int(10) NOT NULL DEFAULT '0',
              topic_id int(10) NOT NULL DEFAULT '0',
              create_time int(11) unsigned NOT NULL DEFAULT '0',
              PRIMARY KEY (push_id),
              KEY user_id (user_id),
              KEY create_time (create_time),
              KEY author (author)
            ) DEFAULT CHARSET=utf8
            
        ");
    }
    // Insert settings in to the database
    $query = $db->query("SELECT disporder FROM ".TABLE_PREFIX."settinggroups ORDER BY `disporder` DESC LIMIT 1");
    $disporder = $db->fetch_field($query, 'disporder')+1;

    $setting_group = array(
        'name'          =>    'tapatalk',
        'title'         =>    'Tapatalk General Options',
        'description'   =>    'Optional Tapatalk Settings allow you to fine-tune the app behaviour with the forum',
        'disporder'     =>    0,
        'isdefault'     =>    0
    );
    $setting_byo_group = array(
    	'name'          =>    'tapatalk_byo',
        'title'         =>    'Tapatalk BYO Options',
        'description'   =>    'Tapatalk - Build Your Own - Options',
        'disporder'     =>    0,
        'isdefault'     =>    0
    );
    $setting_register_group = array(
    	'name'          =>    'tapatalk_register',
        'title'         =>    'Tapatalk - In App Registration',
        'description'   =>    'Tapatalk - In App Registration Settings',
        'disporder'     =>    0,
        'isdefault'     =>    0
    );
    $db->insert_query('settinggroups', $setting_group);
    $gid = $db->insert_id();
    $db->insert_query('settinggroups', $setting_byo_group);
    $gid_byo = $db->insert_id();
	$db->insert_query('settinggroups', $setting_register_group);
    $gid_register = $db->insert_id();
    
    $settings = array(
        'hide_forum' => array(
            'title'         => 'Hide Forums',
            'description'   => "Hide specific sub-forums from appearing in Tapatalk. Please enter a comma-separated sub-forum ID",
            'optionscode'   => 'text',
            'value'         => ''
        ),
        
        'directory' => array(
            'title'         => 'Tapatalk Plugin Directory',
            'description'   => 'Never change it if you did not rename the Tapatalk plugin directory. And the default value is \'mobiquo\'. If you renamed the Tapatalk plugin directory, you also need to update the same setting in Tapatalk Forum Owner Area.',
            'optionscode'   => 'text',
            'value'         => 'mobiquo'
        ),
 
        'datakeep' => array(
            'title'         => 'Uninstall Behaviour',
            'description'   => "Ability to retain 'tapatalk_' tables in DB. Useful if you're re-installing Tapatalk Plugin.",
            'optionscode'   => "radio\nkeep=Keep Data\ndelete=Delete all data and tables",
            'value'         => 'keep'
        ),
        'push_key' => array(
            'title'         => 'Tapatalk API Key',
            'description'   => 'Formerly known as Push Key. This key is now required for secure connection between your community and Tapatalk server. Features such as Push Notification and Single Sign-On requires this key to work. ',
            'optionscode'   => 'text',
            'value'         => ''
        ),
        'forum_read_only' => array(
            'title'         => 'Disable New Topic',
            'description'   => "Prevent Tapatalk users to create new topic in the selected sub-forums. This feature is useful if certain forums requires additional topic fields or permission that Tapatalk does not support,Separate multiple entries with a coma.",
            'optionscode'   => 'text',
            'value'         => ''
        ),
        'ad_filter'      => array(
            'title'         => 'Disable Ads for Group',
            'description'   => "This option will prevent Tapatalk from displaying advertisements. Users in the selected groups will not be served ads when using the Tapatalk app. Please enter a comma-separated group ID",
            'optionscode'   => 'text',
            'value'         => ''
        ),
        'custom_replace'    => array(
            'title'         => 'Thread Content Replacement (Advanced)',
            'description'   => 'Ability to match and replace thread content using PHP preg_replace function(http://www.php.net/manual/en/function.preg-replace.php). E.g. "\'pattern\', \'replacement\'" . You can define more than one replace rule on each line.',
            'optionscode'   => 'textarea',
            'value'         => ''
        ),
        
        'push_slug' => array(
        	'title'         => '',
            'description'   => '',
            'optionscode'   => 'php',
            'value'         => '0',
        ),
        
        'app_ads_enable' => array(
        	'title'         => 'Mobile Welcome Screen',
            'description'   => 'Tapatalk will show a one time welcoming screen to mobile users to download the free app, with a button to get the free app. ',
            'optionscode'   => 'onoff',
            'value'         => '1',
        ),
        
        /*'app_banner_enable' => array(
        	'title'         => 'Mobile Smart Banner',
            'description'   => 'Tapatalk will show a smart banner to mobile users, when your forum is viewed by a mobile web browser. The smart banner will contain two buttons: "Open in app" and "Install".',
            'optionscode'   => 'onoff',
            'value'         => '1',
        ), */          
    ); 
	
    $settings_byo = array(
    	'app_banner_msg'    => array(
            'title'         => 'BYO App Banner Message',
            'description'   => 'E.g. "Follow {your_forum_name} with {app_name} for [os_platform]". Do not change the [os_platform] tag as it is displayed dynamically based on user\'s device platform.',
            'optionscode'   => 'textarea',
            'value'         => ''
        ),  
        'app_ios_id'    => array(
            'title'         => 'BYO iOS App ID',
            'description'   => 'Enter your BYO product ID in Apple App Store, to be used on iOS device.',
            'optionscode'   => 'text',
            'value'         => ''
        ), 
        'android_url'    => array(
            'title'         => 'Android Product ID',
            'description'   => 'Enter your BYO App ID from Google Play, to be used on Android device. E.g. "com.quoord.tapatalkpro.activity".',
            'optionscode'   => 'text',
            'value'         => ''
        ),   
        'kindle_url' => array(
            'title'         => 'Kindle Fire (Original) URL',
            'description'   => 'Enter your BYO App URL from Amazon App Store, to be used on Kindle Fire device.',
            'optionscode'   => 'text',
            'value'         => '',
        ),
    );
    
	$query = $db->simple_select("usergroups", "gid, title", "", array('order_by' => 'title'));
	$display_group_options = array();
	while($usergroup = $db->fetch_array($query))
	{
		$display_group_options[$usergroup['gid']] = $usergroup['title'];
	}
	$select_group_str = '';
	foreach ($display_group_options as $groupid => $title)
	{
		$select_group_str .= "\n".$groupid . "=" . $title;
	}
	$settings_register = array(
    	'register_status' => array(
            'title'         => 'Registration Options',
            'description'   => "Native Registration and Social Sign On (Recommended) - Facebook users can register for your forum using their Facebook credentials, and those not connected to Facebook can register for your forum via an in-app form.<br/>
Native Registration Only - No SSO available for Facebook users. All users must register for the forum via an in-app form.<br/>
Redirect to External Registration URL - All users registering for your forum will be redirected to a web browser outside of the app to continue registration.",
            'optionscode'   => 'select
            2=Native Registration and Social Sign On (Recommended)
            1=Native Registration Only 
            0=Redirect to External Registration URL',
            'value'         => 2
        ),
    	'reg_url' => array(
            'title'         => 'Registration URL',
            'description'   => "Default Registration URL: 'member.php?action=register'",
            'optionscode'   => 'text',
            'value'         => 'member.php?action=register'
        ),
        'register_group' => array(
            'title'         => 'User Group Assignment',
            'description'   => "You can assign users registered with Tapatalk to specific user groups. If you do not assign them to a specific group, they will be assigned a default group. ",
            'optionscode'   => 'select'.$select_group_str,
            'value'         => 2
        ),
        'spam_status' => array(
            'title'         => 'Spam Prevention',
            'description'   => "By enabling StopForumSpam integration, new user registration from Tapatalk app and/or from web will be screened with StopForumSpam database to prevent existing black-listed spammers.",
            'optionscode'   => 'select
            0=Disable
            1=Enable StopForumSpam in Tapatalk in-app registration
            2=Enable StopForumSpam in web registration
        	3=Enable both',
            'value'         => 1
        ),
    );
    $s_index = 0;
    foreach($settings as $name => $setting)
    {
        $s_index++;
        $insert_settings = array(
            'name'        => $db->escape_string('tapatalk_'.$name),
            'title'       => $db->escape_string($setting['title']),
            'description' => $db->escape_string($setting['description']),
            'optionscode' => $db->escape_string($setting['optionscode']),
            'value'       => $db->escape_string($setting['value']),
            'disporder'   => $s_index,
            'gid'         => $gid,
            'isdefault'   => 0
        );
        $db->insert_query('settings', $insert_settings);
    }
	
	$s_index = 0;
    foreach($settings_byo as $name => $setting)
    {
        $s_index++;
        $insert_settings = array(
            'name'        => $db->escape_string('tapatalk_'.$name),
            'title'       => $db->escape_string($setting['title']),
            'description' => $db->escape_string($setting['description']),
            'optionscode' => $db->escape_string($setting['optionscode']),
            'value'       => $db->escape_string($setting['value']),
            'disporder'   => $s_index,
            'gid'         => $gid_byo,
            'isdefault'   => 0
        );
        $db->insert_query('settings', $insert_settings);
    }
	$s_index = 0;
    foreach($settings_register as $name => $setting)
    {
        $s_index++;
        $insert_settings = array(
            'name'        => $db->escape_string('tapatalk_'.$name),
            'title'       => $db->escape_string($setting['title']),
            'description' => $db->escape_string($setting['description']),
            'optionscode' => $db->escape_string($setting['optionscode']),
            'value'       => $db->escape_string($setting['value']),
            'disporder'   => $s_index,
            'gid'         => $gid_register,
            'isdefault'   => 0
        );
        $db->insert_query('settings', $insert_settings);
    }
    rebuild_settings();
}

function tapatalk_is_installed()
{
    global $mybb, $db;

    $result = $db->simple_select('settinggroups', 'gid', "name = 'tapatalk'", array('limit' => 1));
    $group = $db->fetch_array($result);

    return !empty($group['gid']) && $db->table_exists('tapatalk_users');
}

function tapatalk_uninstall()
{
    global $mybb, $db;
    if($db->table_exists('tapatalk_push_data') && ($mybb->settings['tapatalk_datakeep'] == 'delete' || !$db->field_exists('topic_id', 'tapatalk_push_data')))
    {
        $db->drop_table('tapatalk_push_data');
    }
    if($db->table_exists('tapatalk_users') && ($mybb->settings['tapatalk_datakeep'] == 'delete' || !$db->field_exists('tag', 'tapatalk_users')))
    {
        $db->drop_table('tapatalk_users');
    }

    // Remove settings
    $result = $db->simple_select('settinggroups', 'gid', "name = 'tapatalk'", array('limit' => 1));
    $group = $db->fetch_array($result);

    if(!empty($group['gid']))
    {
        $db->delete_query('settinggroups', "gid='{$group['gid']}'");
        $db->delete_query('settings', "gid='{$group['gid']}'");
        rebuild_settings();
    }
	// Remove byo settings
    $result = $db->simple_select('settinggroups', 'gid', "name = 'tapatalk_byo'", array('limit' => 1));
    $group = $db->fetch_array($result);

    if(!empty($group['gid']))
    {
        $db->delete_query('settinggroups', "gid='{$group['gid']}'");
        $db->delete_query('settings', "gid='{$group['gid']}'");
        rebuild_settings();
    }
    
	// Remove register settings
    $result = $db->simple_select('settinggroups', 'gid', "name = 'tapatalk_register'", array('limit' => 1));
    $group = $db->fetch_array($result);

    if(!empty($group['gid']))
    {
        $db->delete_query('settinggroups', "gid='{$group['gid']}'");
        $db->delete_query('settings', "gid='{$group['gid']}'");
        rebuild_settings();
    }
}

/*
function tapatalk_activate()
{
    global $mybb, $db;

}

function tapatalk_deactivate()
{
    global $db;
}
*/
/* ============================================================================================ */

function tapatalk_error($error)
{
	if(!strstr($_SERVER['PHP_SELF'],'mobiquo.php'))
	{
		return ;		
	}
    if(defined('IN_MOBIQUO'))
    {
        global $lang, $include_topic_num, $search, $function_file_name;

        if ($error == $lang->error_nosearchresults)
        {
            if ($include_topic_num) {
                if($search['resulttype'] != 'posts') {
                    $response = new xmlrpcresp(new xmlrpcval(array(
                        'result'            => new xmlrpcval(true, 'boolean'),
                        'total_topic_num'   => new xmlrpcval(0, 'int'),
                        'topics'            => new xmlrpcval(array(), 'array'),
                    ), 'struct'));
                } else {
                    $response = new xmlrpcresp(new xmlrpcval(array(
                        'result'            => new xmlrpcval(true, 'boolean'),
                        'total_post_num'    => new xmlrpcval(0, 'int'),
                        'posts'             => new xmlrpcval(array(), 'array'),
                    ), 'struct'));
                }
            } else {
                $response = new xmlrpcresp(new xmlrpcval(array(), 'array'));
            }
        }
        else if ($function_file_name == 'thankyoulike' && strpos($error, $lang->tyl_redirect_back))
        {
            $response = new xmlrpcresp(new xmlrpcval(array(
                'result'        => new xmlrpcval(true, 'boolean'),
            ), 'struct'));
        }
        else
        {
            $response = new xmlrpcresp(new xmlrpcval(array(
                'result'        => new xmlrpcval(false, 'boolean'),
                'result_text'   => new xmlrpcval(trim(strip_tags($error)), 'base64'),
            ), 'struct'));
        }

        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n".$response->serialize('UTF-8');
        exit;
    }
}

function tapatalk_redirect($args)
{
	if(!strstr($_SERVER['PHP_SELF'],'mobiquo.php'))
	{
		return ;		
	}
    tapatalk_error($args['message']);
}

function tapatalk_global_start()
{
    global $mybb, $request_method, $function_file_name;

    header('Mobiquo_is_login: ' . ($mybb->user['uid'] > 0 ? 'true' : 'false'));

    if ($mybb->usergroup['canview'] != 1 && in_array($request_method, array('get_config', 'login','register','sign_in','prefetch_account','update_password','forget_password')))
    {
        define("ALLOWABLE_PAGE", 1);
    }

    if (isset($mybb->settings['no_proxy_global']))
    {
        $mybb->settings['no_proxy_global'] = 0;
    }

    if ($function_file_name == 'thankyoulike')
    {
        $mybb->input['my_post_key'] = md5($mybb->user['loginkey'].$mybb->user['salt'].$mybb->user['regdate']);
    }
}

function tapatalk_fetch_wol_activity_end(&$user_activity)
{
    global $uid_list, $aid_list, $pid_list, $tid_list, $fid_list, $ann_list, $eid_list, $plugins, $user, $parameters;
    if($user_activity['activity'] == 'unknown' && strpos($user_activity['location'], 'mobiquo') !== false)
    {
        //$user_activity['activity'] = 'tapatalk';
        $method = 'unknown';
        $path_arr = parse_url($user_activity['location']);
        $param = -2;
        $unviewableforums = get_unviewable_forums();
        if(!empty($path_arr['query']))
        {
            $param_arr = explode('&amp;', $path_arr['query']);
            $method = str_replace('method=', '', $param_arr[0]);
            $param = str_replace('params=', '', $param_arr[1]);
        }
        switch ($method)
        {
            case 'get_config':
            case 'get_forum':
            case 'get_participated_forum':
            case 'login_forum':
            case 'get_forum_status':
            case 'get_topic':
                if(is_numeric($param))
                {
                    $fid_list[] = $param;
                }
                $user_activity['activity'] = "forumdisplay";
                $user_activity['fid'] =  $param;
                break;
            case 'logout_user':
                $user_activity['activity'] = "member_logout";
                break;
            case 'get_user_info':
                $user_activity['activity'] = "member_profile";
                break;
            case 'register':
                $user_activity['activity'] = "member_register";
                break;
            case 'forget_password':
                $user_activity['activity'] = "member_lostpw";
                break;
            case 'login':
                $user_activity['activity'] = "member_login";
                break;
            case 'get_online_users':
                $user_activity['activity'] = "wol";
                break;
            case 'get_user_topic':
            case 'get_user_reply_post':
                $user_activity['activity'] = "usercp";
                break;
            case 'new_topic':
                if(is_numeric($param))
                {
                    $fid_list[] = $param;
                }
                $user_activity['activity'] = "newthread";
                $user_activity['fid'] = $param;
                break;
            case 'search':
            case 'search_topic':
            case 'search_post':
            case 'get_unread_topic':
            case 'get_participated_topic':
            case 'get_latest_topic':
                $user_activity['activity'] = "search";
                break;
            case 'get_quote_post':
            case 'reply_post':
                $user_activity['activity'] = "newreply";
                break;
            case 'get_thread':
                if(is_numeric($param))
                {
                    $tid_list[] = $param;
                }
                $user_activity['activity'] = "showthread";
                $user_activity['tid'] = $param;
                break;
            case 'get_thread_by_post':
                if(is_numeric($param))
                {
                    $pid_list[] = $param;
                    $user_activity['activity'] = "showpost";
                    $user_activity['pid'] = $param;
                }
                break;
            case 'create_message':
            case 'get_box_info':
            case 'get_box':
            case 'get_quote_pm':
            case 'delete_message':
            case 'mark_pm_unread':
                $user_activity['activity'] = "private";
                break;
            case 'get_message':
                $user_activity['activity'] = "private_read";
                break;
            default:
                if(strpos($method, 'm_') === 0)
                {
                    $user_activity['activity'] = "moderation";
                }
                else if(strstr($method,'_post'))
                {
                    $user_activity['activity'] = "showpost";
                }
                else if(strpos($user_activity['location'], 'mobiquo') !== false)
                {
                    $user_activity['activity'] = "index";
                }
                break;
        }

    }
}

function tapatalk_online_user()
{
    global $user;
    if((strpos($user['location'], 'mobiquo') !== false) && (strpos($user['location'], 'BYO') !== false))
    {
    	 $user['username'] = $user['username'] . '[tapatalk_byo_user]';
    }
	else if(strpos($user['location'], 'mobiquo') !== false)
    {
        $user['username'] = $user['username'] . '[tapatalk_user]';
    }
}

function tapatalk_online_end()
{
    global $online_rows,$mybb;
    $temp_online = $online_rows;
    
    $str = '&nbsp;<img src="'.$mybb->settings['bburl'].'/'.$mybb->settings['tapatalk_directory'].'/images/tapatalk-online.png?new" style="vertical-align:middle">';
    $online_rows = preg_replace('/<a href="(.*)">(.*)\[tapatalk_user\](<\/em><\/strong><\/span>|<\/strong><\/span>|<\/span>|<\/b><\/span>|<\/s>|\s*)<\/a>/Usi', '<a href="$1">$2$3</a>'.$str, $online_rows);
	if(empty($online_rows))
    {
        $online_rows = str_replace('[tapatalk_user]','',$temp_online);
    }
    $temp_online = $online_rows;
    $str_byo =  '&nbsp;<img src="'.$mybb->settings['bburl'].'/'.$mybb->settings['tapatalk_directory'].'/images/byo-online.png" style="vertical-align:middle">';
    $online_rows = preg_replace('/<a href="(.*)">(.*)\[tapatalk_byo_user\](<\/em><\/strong><\/span>|<\/strong><\/span>|<\/span>|<\/b><\/span>|\s*)<\/a>/Usi', '<a href="$1">$2$3</a>'.$str_byo, $online_rows);
	if(empty($online_rows))
    {
        $online_rows = str_replace('[tapatalk_byo_user]','',$temp_online);
    }
    
}

function tapatalk_pre_output_page(&$page)
{
    global $mybb;
    $settings = $mybb->settings;
	
    $app_forum_name = $settings['homename'];
    $board_url = $mybb->settings['bburl'];
    $tapatalk_dir = MYBB_ROOT.$mybb->settings['tapatalk_directory'];  // default as 'mobiquo'
    $tapatalk_dir_url = $board_url.'/'.$mybb->settings['tapatalk_directory'];
    $is_mobile_skin = 0;
    $app_location_url = tapatalk_get_url();
    
	preg_match('/location=(\w+)/is', $app_location_url,$matches);
    if(!empty($matches[1]))
    {
    	if($matches[1] == 'message')
    	{
    		$matches[1] = 'pm';
    	}
    	$page_type = $matches[1];
    }
    $app_location_url = str_replace("location=other", 'location=index', $app_location_url);
    $app_banner_message = $settings['tapatalk_app_banner_msg'];
    $app_ios_id = $settings['tapatalk_app_ios_id'];
    $app_android_id = $settings['tapatalk_android_url'];
    $app_kindle_url = $settings['tapatalk_kindle_url'];
    
    //full screen ads
    $api_key = $settings['tapatalk_push_key'];
    $app_ads_enable = $settings['tapatalk_app_ads_enable'];
    $app_head_include = '';
    if (file_exists($tapatalk_dir . '/smartbanner/head.inc.php'))
        include($tapatalk_dir . '/smartbanner/head.inc.php');

    $str = $app_head_include;
    $tapatalk_smart_banner_body = " 
    <!-- Tapatalk smart banner body start --> \n".
    '<script type="text/javascript">
    if(typeof(app_ios_id) != "undefined") {
		tapatalkDetect();
	}
    </script>'."\n".' 
    <!-- Tapatalk smart banner body end --> ';
    $page = str_ireplace("</head>", $str . "\n</head>", $page);
    $page = preg_replace("/<body(.*?)>/is", "<body$1>\n".$tapatalk_smart_banner_body, $page,1);
}
function tapatalk_postbit(&$post)
{
	global $mybb;
	require_once MYBB_ROOT.$mybb->settings['tapatalk_directory'].'/emoji/emoji.php';
	$post['message'] = emoji_name_to_unified($post['message']);
	$post['message'] = emoji_unified_to_html($post['message']);
	return $post;
}
function tapatalk_get_url()
{
    global $mybb;
    $location = get_current_location();
    $split_loc = explode(".php", $location);
    $parameters = $param_arr = array();
    if($split_loc[0] == $location)
    {
        $filename = '';
    }
    else
    {
        $filename = my_substr($split_loc[0], -my_strpos(strrev($split_loc[0]), "/"));
    }
    if($split_loc[1])
    {
        $temp = explode("&amp;", my_substr($split_loc[1], 1));
        foreach($temp as $param)
        {
            $temp2 = explode("=", $param, 2);
            $parameters[$temp2[0]] = $temp2[1];
        }
    }
    switch ($filename)
    {
        case "forumdisplay":
            $param_arr['fid'] = $parameters['fid'];
            $param_arr['location'] = 'forum';
            $param_arr['page'] = isset($parameters['page']) ? intval($parameters['page']) : 1;
            $param_arr['perpage'] = $mybb->settings['threadsperpage'];
            break;
        case "index":
        case '':
            $param_arr['location'] = 'index';
            break;
        case "private":
            if($parameters['action'] == "read")
            {
                $param_arr['location'] = 'message';
                $param_arr['mid'] = $parameters['pmid'];
            }
            break;
        case "search":
            $param_arr['location'] = "search";
            break;
        case "showthread":
            if(!empty($parameters['pid']))
            {
                //$param_arr['fid'] = $parameters['fid'];
                $param_arr['location'] = 'post';
                $param_arr['tid'] = $parameters['tid'];
                $param_arr['pid'] = $parameters['pid'];
            }
            else
            {
                //$param_arr['fid'] = $parameters['fid'];
                $param_arr['location'] = 'topic';
                $param_arr['tid'] = $parameters['tid'];
            }
            $param_arr['page'] = isset($parameters['page']) ? intval($parameters['page']) : 1;
            $param_arr['perpage'] = $mybb->settings['postsperpage'];
            break;
        case "member":
            if($parameters['action'] == "login" || $parameters['action'] == "do_login")
            {
                $param_arr['location'] = 'login';
            }
            elseif($parameters['action'] == "profile")
            {
                $param_arr['location'] = 'profile';
                $param_arr['uid'] = $parameters['uid'];
            }

            break;
        case "online":
            $param_arr['location'] = 'online';
            break;
        default:
            $param_arr['location'] = 'other';
            break;
    }
    $queryString = http_build_query($param_arr);
    $url = $mybb->settings['bburl'] . '/?' .$queryString;
    $url = preg_replace('/^(https|http)/isU', 'tapatalk', $url);
    return $url;
}

// push related functions
function tapatalk_push_reply()
{
    global $mybb, $db, $tid, $pid, $visible, $thread;
    if(!($tid && $pid && $visible == 1 && $db->table_exists('tapatalk_users')) )
    {
        return false;
    }
    $query = $db->query("
        SELECT ts.uid,tu.subscribe as sub
        FROM ".TABLE_PREFIX."threadsubscriptions ts
        RIGHT JOIN ".TABLE_PREFIX."tapatalk_users tu ON (ts.uid=tu.userid)
        WHERE ts.tid = '$tid'
    ");

    $ttp_push_data = array();
    while($user = $db->fetch_array($query))
    {
        if(ingnore_user_push($user)) continue;
        
        $ttp_data[] = array(
            'userid'    => $user['uid'],
            'type'      => 'sub',
            'id'        => $tid,
            'subid'     => $pid,
            'title'     => tt_push_clean($thread['subject']),
            'author'    => tt_push_clean($mybb->user['username']),
            'dateline'  => TIME_NOW,
        );
       
        tt_insert_push_data($ttp_data[count($ttp_data)-1]);
        if($user['sub'] == 1)
        {
            $ttp_push_data[] = $ttp_data[count($ttp_data)-1];
        }
    }
    
    if(!empty($ttp_push_data))
    {
        $ttp_post_data = array(
            'url'  => $mybb->settings['bburl'],
            'data' => base64_encode(serialize($ttp_push_data)),
        );

        $return_status = tt_do_post_request($ttp_post_data);
        return true;
    }
    return false;
}

function tapatalk_push_quote()
{
    global $mybb, $db, $tid, $pid, $visible, $thread ,$post,$thread_info,$new_thread;
    
    if(!empty($new_thread))
    {
        $pid = $thread_info['pid'];
        $thread = $new_thread;
        $post = $new_thread;
    }
    
    if(!($tid && $pid && $visible == 1 && $db->table_exists('tapatalk_users')) )
    {
        return false;
    }
    
    if(!empty($post['message']))
    {
        $matches = array();
        preg_match_all('/\[quote=\'(.*?)\' pid=\'(.*?)\' dateline=\'(.*?)\'\]/', $post['message'] , $matches);
        $matches = array_unique($matches[1]);
        foreach ($matches as $username)
        {
        	$username = $db->escape_string($username);
            $query = $db->query("SELECT tu.*,u.uid FROM " . TABLE_PREFIX . "tapatalk_users AS tu LEFT JOIN
            " . TABLE_PREFIX ."users AS u ON tu.userid = u.uid  WHERE u.username = '$username'");
            $user = $db->fetch_array($query);
            
            if(ingnore_user_push($user)) continue;
            $ttp_push_data = array();
            $ttp_data[] = array(
                'userid'    => $user['uid'],
                'type'      => 'quote',
                'id'        => $tid,
                'subid'     => $pid,
                'title'     => tt_push_clean($thread['subject']),
                'author'    => tt_push_clean($mybb->user['username']),
                'dateline'  => TIME_NOW,
            );
            tt_insert_push_data($ttp_data[count($ttp_data)-1]);
            if($user['quote'] == 1)
            {
                $ttp_push_data[] = $ttp_data[count($ttp_data)-1];
            }
        }
        
        if(!empty($ttp_push_data))
        {
            $ttp_post_data = array(
                'url'  => $mybb->settings['bburl'],
                'data' => base64_encode(serialize($ttp_push_data)),
            );

            $return_status = tt_do_post_request($ttp_post_data);
            return true;
        }
    }
    return false;
}

function tapatalk_push_tag()
{
    global $mybb, $db, $tid, $pid, $visible, $thread ,$post,$thread_info,$new_thread;
    if(!empty($new_thread))
    {
        $pid = $thread_info['pid'];
        $thread = $new_thread;
        $post = $new_thread;
    }
    if(!($tid && $pid && $visible == 1 && $db->table_exists('tapatalk_users')) )
    {
        return false;
    }
    if(!empty($post['message']))
    {
        $matches = tt_get_tag_list($post['message']);
        foreach ($matches as $username)
        {
        	$username = $db->escape_string($username);
            $query = $db->query("SELECT tu.*,u.uid FROM " . TABLE_PREFIX . "tapatalk_users AS tu LEFT JOIN
            " . TABLE_PREFIX ."users AS u ON tu.userid = u.uid  WHERE u.username = '$username'");
            $user = $db->fetch_array($query);
            
            if(ingnore_user_push($user)) continue;
            $ttp_push_data = array();
            $ttp_data[] = array(
                'userid'    => $user['uid'],
                'type'      => 'tag',
                'id'        => $tid,
                'subid'     => $pid,
                'title'     => tt_push_clean($thread['subject']),
                'author'    => tt_push_clean($mybb->user['username']),
                'dateline'  => TIME_NOW,
            );
            tt_insert_push_data($ttp_data[count($ttp_data)-1]);
            if($user['tag'] == 1)
            {
                $ttp_push_data[] = $ttp_data[count($ttp_data)-1];
            }
        }
        if(!empty($ttp_push_data))
        {
            $ttp_post_data = array(
                'url'  => $mybb->settings['bburl'],
                'data' => base64_encode(serialize($ttp_push_data)),
            );
			
            $return_status = tt_do_post_request($ttp_post_data);
            return true;
        }

    }
    return false;
}

function tapatalk_push_newtopic()
{
    global $mybb, $db, $tid,$visible, $thread_info,$fid,$new_thread;
    $pid = $thread_info['pid'];
    if(!($tid && $fid && $pid && $visible == 1 && $db->table_exists('tapatalk_users')) )
    {
        return false;
    }
    
    $query = $db->query("
        SELECT ts.uid,tu.newtopic as newtopic
        FROM ".TABLE_PREFIX."forumsubscriptions ts
        RIGHT JOIN ".TABLE_PREFIX."tapatalk_users tu ON (ts.uid=tu.userid)
        WHERE ts.fid = '$fid'
    ");

    $ttp_push_data = array();
    while($user = $db->fetch_array($query))
    {
        if(ingnore_user_push($user)) continue;
        $ttp_data[] = array(
            'userid'    => $user['uid'],
            'type'      => 'newtopic',
            'id'        => $tid,
            'subid'     => $pid,
            'title'     => tt_push_clean($new_thread['subject']),
            'author'    => tt_push_clean($mybb->user['username']),
            'dateline'  => TIME_NOW,
        );
        tt_insert_push_data($ttp_data[count($ttp_data)-1]);
        if($user['newtopic'] == 1)
        {
        	$ttp_push_data[] = $ttp_data[count($ttp_data)-1];
        }
    }
    if(!empty($ttp_push_data))
    {
    	$ttp_post_data = array(
            'url'  => $mybb->settings['bburl'],
            'data' => base64_encode(serialize($ttp_push_data)),
        );
        
        $return_status = tt_do_post_request($ttp_post_data);
        return true;
    }
    return false;
}


function tt_get_tag_list($str)
{
    if ( preg_match_all( '/(?<=^@|\s@)(#(.{1,50})#|\S{1,50}(?=[,\.;!\?]|\s|$))/U', $str, $tags ) )
    {
        foreach ($tags[2] as $index => $tag)
        {
            if ($tag) $tags[1][$index] = $tag;
        }

        return array_unique($tags[1]);
    }

    return array();
}

function tapatalk_push_pm()
{
    global $mybb, $db, $pm, $pminfo;
    if(!($pminfo['messagesent'] && $db->table_exists('tapatalk_users')))
    {
        return false;
    }
    $query = $db->query("
        SELECT p.pmid, p.toid ,tu.pm
        FROM ".TABLE_PREFIX."privatemessages p
        LEFT JOIN ".TABLE_PREFIX."tapatalk_users tu ON (p.toid=tu.userid)
        WHERE p.fromid = '{$mybb->user['uid']}' and p.dateline = " . TIME_NOW . " AND p.folder = 1
    ");
        
    $ttp_push_data = array();
    while($user = $db->fetch_array($query))
    {
        if ($user['toid'] == $mybb->user['uid']) continue;
        if (tt_check_ignored($user['toid'])) continue;
        
        $ttp_data[] = array(
            'userid'    => $user['toid'],
            'type'      => 'pm',
            'id'        => $user['pmid'],
            'title'     => tt_push_clean($pm['subject']),
            'author'    => tt_push_clean($mybb->user['username']),
            'dateline'  => TIME_NOW,
        );
        tt_insert_push_data($ttp_data[count($ttp_data)-1]);
        if($user['pm'] == 1)
        {
        	$ttp_push_data[] = $ttp_data[count($ttp_data)-1];
        }
    }
    
    if(!empty($ttp_push_data))
    {
        $ttp_post_data = array(
            'url'  => $mybb->settings['bburl'],
            'data' => base64_encode(serialize($ttp_push_data)),
        );
        
        $return_status = tt_do_post_request($ttp_post_data);
    }
}


function tt_do_post_request($data,$is_test=false)
{
    global $mybb;
    
    if(empty($data['data']) && !isset($data['ip']) && !isset($data['test']))
    {
        return false;
    }
    
    if(!empty($mybb->settings['tapatalk_push_key']))
    {
        $data['key'] = $mybb->settings['tapatalk_push_key'];
    }
    
    $push_url = 'http://push.tapatalk.com/push.php';
    
    //Get push_slug from db
    $push_slug = !empty($mybb->settings['tapatalk_push_slug'])? $mybb->settings['tapatalk_push_slug'] : 0;
    $slug = base64_decode($push_slug);
    $slug = push_slug($slug, 'CHECK');
    $check_res = unserialize($slug);

    //If it is valide(result = true) and it is not sticked, we try to send push
    if($check_res['result'] && !$check_res['stick'])
    {
        //Slug is initialed or just be cleared
        if($check_res['save'])
        {
            tt_update_settings(array('name' => 'tapatalk_push_slug', 'value' => base64_encode($slug)));
        }
		if(!function_exists("getContentFromRemoteServer"))
		{
			define('IN_MOBIQUO', true);
			require_once MYBB_ROOT.$mybb->settings['tapatalk_directory'].'/mobiquo_common.php';
		}
		if(isset($data['ip']) || isset($data['test']))
		{
			$hold_time = 10;
		}
		else 
		{
			$hold_time = 0;
		}
        //Send push
		$error_msg = '';
        $push_resp = getContentFromRemoteServer($push_url, $hold_time, $error_msg, 'POST', $data);
        if((trim($push_resp) === 'Invalid push notification key') && !$is_test)
        {
        	$push_resp = 1;
        }
        if(!is_numeric($push_resp) && !$is_test)
        {
            //Sending push failed, try to update push_slug to db
            $slug = push_slug($slug, 'UPDATE');
            $update_res = unserialize($slug);
            if($update_res['result'] && $update_res['save'])
            {
                tt_update_settings(array('name' => 'tapatalk_push_slug', 'value' => base64_encode($slug)));
            }
        }
        
        return $push_resp;
    }
    else 
    {
    	return 'stick ' . $check_res['stick'] . ' | result ' . $check_res['result'];
    }
    
}

function push_slug($push_v, $method = 'NEW')
{
    if(empty($push_v))
        $push_v = serialize(array());
    $push_v_data = unserialize($push_v);
    $current_time = time();
    if(!is_array($push_v_data))
        return serialize(array('result' => 0, 'result_text' => 'Invalid v data', 'stick' => 0));
    if($method != 'CHECK' && $method != 'UPDATE' && $method != 'NEW')
        return serialize(array('result' => 0, 'result_text' => 'Invalid method', 'stick' => 0));

    if($method != 'NEW' && !empty($push_v_data))
    {
        $push_v_data['save'] = $method == 'UPDATE';
        if($push_v_data['stick'] == 1)
        {
            if($push_v_data['stick_timestamp'] + $push_v_data['stick_time'] > $current_time)
                return $push_v;
            else
                $method = 'NEW';
        }
    }

    if($method == 'NEW' || empty($push_v_data))
    {
        $push_v_data = array();                       //Slug
        $push_v_data['max_times'] = 3;                //max push failed attempt times in period
        $push_v_data['max_times_in_period'] = 300;      //the limitation period
        $push_v_data['result'] = 1;                   //indicate if the output is valid of not
        $push_v_data['result_text'] = '';             //invalid reason
        $push_v_data['stick_time_queue'] = array();   //failed attempt timestamps
        $push_v_data['stick'] = 0;                    //indicate if push attempt is allowed
        $push_v_data['stick_timestamp'] = 0;          //when did push be sticked
        $push_v_data['stick_time'] = 600;             //how long will it be sticked
        $push_v_data['save'] = 1;                     //indicate if you need to save the slug into db
        return serialize($push_v_data);
    }

    if($method == 'UPDATE')
    {
        $push_v_data['stick_time_queue'][] = $current_time;
    }
    $sizeof_queue = count($push_v_data['stick_time_queue']);
    $period_queue = $push_v_data['stick_time_queue'][$sizeof_queue - 1] - $push_v_data['stick_time_queue'][0];
    $times_overflow = $sizeof_queue > $push_v_data['max_times'];
    $period_overflow = $period_queue > $push_v_data['max_times_in_period'];

    if($period_overflow)
    {
        if(!array_shift($push_v_data['stick_time_queue']))
            $push_v_data['stick_time_queue'] = array();
    }
    
    if($times_overflow && !$period_overflow)
    {
        $push_v_data['stick'] = 1;
        $push_v_data['stick_timestamp'] = $current_time;
    }

    return serialize($push_v_data);
}
function tt_insert_push_data($data)
{
	global $mybb,$db;
	
	if(!$db->table_exists("tapatalk_push_data"))
	{
		return ;
	}
	
	//delete old data
	$push_table = TABLE_PREFIX . "tapatalk_push_data";
	$nowtime = time();
    $monthtime = 30*24*60*60;
    $preMonthtime = $nowtime-$monthtime;
    $sql = 'DELETE FROM ' . $push_table . ' WHERE create_time < ' . $preMonthtime . ' and user_id = ' . $mybb->user['uid'];
    $db->query($sql);
    
	if($data['type'] == 'pm')
	{
		$data['subid'] = $data['id'];
	}
	$sql_data = array(
        'author' => $data['author'],
		'user_id' => $data['userid'],
		'data_type' => $data['type'],
		'title' => $data['title'],
		'data_id' => $data['subid'],
		'create_time' => $data['dateline']		
    );
    if($data['type'] != 'pm')
    {
    	$sql_data['topic_id'] = $data['id'];
    }
	$db->insert_query('tapatalk_push_data', $sql_data);
}

function tt_push_clean($str)
{
	global $db;
    $str = strip_tags($str);
    $str = trim($str);
    $str = html_entity_decode($str, ENT_QUOTES, 'UTF-8');
    $str = $db->escape_string($str);
    return $str;
}

function tt_update_settings($updated_setting)
{
	global $db;
	$name = $db->escape_string($updated_setting['name']);
	$updated_value = array('value' => $db->escape_string($updated_setting['value']));
	$db->update_query("settings", $updated_value, "name='$name'");
	rebuild_settings();
}

function tt_check_ignored($uid)
{
	global $mybb;
	$user = get_user($uid);
	$user_ignored_array = array();
	if(!empty($user['ignorelist']))
		$user_ignored_array = explode(',', $user['ignorelist']);

	if(in_array($mybb->user['uid'], $user_ignored_array))
	{
		return true;
	}
	return false;
}

function ingnore_user_push($user)
{
	global $mybb;
	if(empty($user['uid'])) return true;
	if ($user['uid'] == $mybb->user['uid']) return true;
    if (tt_check_ignored($user['uid'])) return true;
    if(defined("TAPATALK_PUSH".$user['uid']))
	{
	    return true;
	}
    define("TAPATALK_PUSH".$user['uid'], 1);
    return false;    
}

/*function tapatalk_parse_message(&$message)
{
	if(strstr($_SERVER['PHP_SELF'],'mobiquo.php'))
	{
		return ;		
	}
	//add tapatalk thumbnail
    $message = preg_replace_callback('/(\[img\])(http:\/\/img.tapatalk.com\/d\/[0-9]{2}\/[0-9]{2}\/[0-9]{2})(.*?)(\[\/img\])/i',
            create_function(
                '$matches',
                'return \'[url=http://tapatalk.com/tapatalk_image.php?img=\'.base64_encode($matches[2].\'/original\'.$matches[3]).\']\'.$matches[1].$matches[2].\'/thumbnail\'.$matches[3].$matches[4].\'[/url]\';'
            ),
    $message);
     
}*/

function tapatalk_parse_message_end(&$message)
{
	if(strstr($_SERVER['PHP_SELF'],'mobiquo.php'))
	{
		return ;		
	}
	$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' ? 'https' : 'http';
	$message = preg_replace('/\[emoji(\d+)\]/i', '<img style="vertical-align: middle;" src="'.$protocol.'://s3.amazonaws.com/tapatalk-emoji/emoji\1.png" />', $message);
}

function tapatalk_settings_update()
{
	global $db;
	$result = $db->simple_select('settinggroups', 'gid', "name = 'tapatalk_register'", array('limit' => 1));
    $group = $db->fetch_array($result);
    
    if(isset($_GET['gid']) && $_GET['gid'] == $group['gid'])
    {
	    $query = $db->simple_select("usergroups", "gid, title", "", array('order_by' => 'title'));
		$display_group_options = array();
		while($usergroup = $db->fetch_array($query))
		{
			$display_group_options[$usergroup['gid']] = $usergroup['title'];
		}
		$select_group_str = '';
		foreach ($display_group_options as $groupid => $title)
		{
			$select_group_str .= "\n".$groupid . "=" . $title;
		}
		$select_group_str = $db->escape_string($select_group_str);
		$db->update_query("settings",array('optionscode' => 'select'.$select_group_str),"name = 'tapatalk_register_group'");
    }
		
}

function tt_is_spam()
{
	global $mybb, $session;
	
	if(isset($mybb->settings['tapatalk_spam_status']))
	{
		$spam_status = $mybb->settings['tapatalk_spam_status'];
		if($spam_status == '0')
		{
			return ;
		}
		if($spam_status == '1' && !strstr($_SERVER['PHP_SELF'],'mobiquo.php'))
		{
			return ;
		}
		if($spam_status == '2' && strstr($_SERVER['PHP_SELF'],'mobiquo.php'))
		{
			return ;
		}
	}
	else 
	{
		return ;
	}
	$email = $mybb->input['email'];
	$ip = $session->ipaddress;
	$params = '';
    if($email || $ip)
    {
        $email = @urlencode($email);    
        if($email)
        {        
            $params = "&email=$email";
        }
    	if($ip)
        {
        	$params .= "&ip=$ip";
        }
        if(!function_exists("getContentFromRemoteServer"))
        {
        	define('IN_MOBIQUO', true);
			require_once MYBB_ROOT.$mybb->settings['tapatalk_directory'].'/mobiquo_common.php';
        }
    	$resp = @getContentFromRemoteServer("http://www.stopforumspam.com/api?f=serial".$params, 3);
        $resp = @unserialize($resp);
        if((isset($resp['email']['confidence']) && $resp['email']['confidence'] > 50) ||
           (isset($resp['ip']['confidence']) && $resp['ip']['confidence'] > 60))
        {
            error('Your email or IP address matches that of a known spammer and therefore you cannot register here. If you feel this is an error, please contact the administrator or try again later.');
        }
    }
}

if (!function_exists('http_build_query')) 
{
    function http_build_query($data, $prefix = null, $sep = '', $key = '')
    {
        $ret = array();
        foreach ((array )$data as $k => $v) {
            $k = urlencode($k);
            if (is_int($k) && $prefix != null) {
                $k = $prefix . $k;
            }
 
            if (!empty($key)) {
                $k = $key . "[" . $k . "]";
            }
 
            if (is_array($v) || is_object($v)) {
                array_push($ret, http_build_query($v, "", $sep, $k));
            } else {
                array_push($ret, $k . "=" . urlencode($v));
            }
        }
 
        if (empty($sep)) {
            $sep = ini_get("arg_separator.output");
        }
 
        return implode($sep, $ret);
    }
}