<?php

defined('IN_MOBIQUO') or exit;

if (isset($_SERVER['HTTP_APP_VAR'] ) && $_SERVER['HTTP_APP_VAR'])
    @header('App-Var: '.$_SERVER['HTTP_APP_VAR']);
$mobiquo_config = get_mobiquo_config();
mobi_parse_requrest();
if ($_POST['method_name']) $request_method = $_POST['method_name'];

chdir(dirname(TT_ROOT));

$function_file_name = $request_method;

switch ($request_method)
{
    // Search related function
    case 'search':
        $include_topic_num = true;
        $search_filter = $request_params[0];
        $_GET['page'] = isset($search_filter['page']) ? $search_filter['page'] : 1;
        $_GET['perpage'] = isset($search_filter['perpage']) ? $search_filter['perpage'] : 20;
        
        if (isset($search_filter['searchid']) && !empty($search_filter['searchid']))
        {
            $_GET['action'] = 'results';
            $_GET['sortby'] = 'lastpost';
            $_GET['order'] = 'desc';
            $_GET['sid'] = $search_filter['searchid'];
        }
        else
        {
            $_POST['action'] = 'do_search';
            $_POST['postthread'] = 1;
            $_POST['matchusername'] = 1;
            $_POST['sortby'] = 'lastpost';
            $_POST['sortordr'] = 'desc';
            $_POST['submit'] = 'Search';
            $_POST['showresults'] = isset($search_filter['showposts']) && $search_filter['showposts'] ? 'posts' : 'threads';
            isset($search_filter['keywords']) && $_POST['keywords'] = $search_filter['keywords'];
            isset($search_filter['titleonly']) && $_POST['postthread'] = $search_filter['titleonly'] + 1; // 1: all, 2. title only
            isset($search_filter['searchuser']) && $_POST['author'] = $search_filter['searchuser'];
            isset($search_filter['userid']) && $_POST['uid'] = $search_filter['userid'];
            isset($search_filter['forumid']) && $_POST['forums'] = array($search_filter['forumid']);
            
            if (isset($search_filter['threadid']))
            {
                $_POST['tid'] = $search_filter['threadid'];
                $_POST['showresults'] = 'posts';
            }
            
            if (isset($search_filter['searchtime']) && is_numeric($search_filter['searchtime']))
            {
                $_POST['postdate'] = $search_filter['searchtime']/86400;
                $_POST['pddir'] = 1;
            }
            
            if (isset($search_filter['only_in']) && is_array($search_filter['only_in']))
            {
                $_POST['forums'] = array_map('intval', $search_filter['only_in']);
            }
            
            if (isset($search_filter['not_in']) && is_array($search_filter['not_in']))
            {
                $_POST['exclude'] = implode(', ', array_map('intval', $search_filter['not_in']));
            }
        }
        break;
    case 'search_topic':
        $function_file_name = 'search';
        $include_topic_num = true;
        list($start, $limit, $page) = process_page($request_params[1], $request_params[2]);
        $_GET['page'] = $page;
        $_GET['perpage'] = $limit;
        
        if (isset($request_params[3]) && $request_params[3])
        {
            $_GET['action'] = 'results';
            $_GET['sortby'] = 'lastpost';
            $_GET['order'] = 'desc';
            $_GET['sid'] = $request_params[3];
        }
        else
        {
            $_POST['action'] = 'do_search';
            $_POST['postthread'] = 1;
            $_POST['sortby'] = 'lastpost';
            $_POST['sortordr'] = 'desc';
            $_POST['submit'] = 'Search';
            $_POST['showresults'] = 'threads';
            $_POST['keywords'] = $request_params[0];
        }
        break;
    case 'search_post':
        $function_file_name = 'search';
        $include_topic_num = true;
        list($start, $limit, $page) = process_page($request_params[1], $request_params[2]);
        $_GET['page'] = $page;
        $_GET['perpage'] = $limit;
        
        if (isset($request_params[3]) && $request_params[3])
        {
            $_GET['action'] = 'results';
            $_GET['sortby'] = 'lastpost';
            $_GET['order'] = 'desc';
            $_GET['sid'] = $request_params[3];
        }
        else
        {
            $_POST['action'] = 'do_search';
            $_POST['postthread'] = 1;
            $_POST['sortby'] = 'lastpost';
            $_POST['sortordr'] = 'desc';
            $_POST['submit'] = 'Search';
            $_POST['showresults'] = 'posts';
            $_POST['keywords'] = $request_params[0];
        }
        break;
    case 'get_latest_topic':
        $function_file_name = 'search';
        $include_topic_num = true;
        list($start, $limit, $page) = process_page($request_params[0], $request_params[1]);
        $_GET['page'] = $page;
        $_GET['perpage'] = $limit;
        
        if (!empty($request_params[2]))
        {
            $_GET['action'] = 'results';
            $_GET['sid'] = $request_params[2];
        }
        else
        {
            $_GET['action'] = 'getdaily';
            $_GET['days'] = 30;
            if (isset($request_params[3]))
            {
                if (isset($request_params[3]['only_in']) && is_array($request_params[3]['only_in']))
                {
                    $_GET['fids'] = implode(',', array_map('intval', $request_params[3]['only_in']));
                }
                
                if (isset($request_params[3]['not_in']) && is_array($request_params[3]['not_in']))
                {
                    $_GET['exclude'] = implode(',', array_map('intval', $request_params[3]['not_in']));
                }
            }
        }
       
        break;
    case 'get_unread_topic':
        $function_file_name = 'search';
        $include_topic_num = true;
        list($start, $limit, $page) = process_page($request_params[0], $request_params[1]);
        $_GET['page'] = $page;
        $_GET['perpage'] = $limit;
        $_GET['action'] = 'getunread';
        if (!empty($request_params[2]))
        {
            //$_GET['action'] = 'results';
            //$_GET['sid'] = $request_params[2];
       
           
            
            if (isset($request_params[3]))
            {
                if (isset($request_params[3]['only_in']) && is_array($request_params[3]['only_in']))
                {
                    $_GET['fids'] = implode(',', array_map('intval', $request_params[3]['only_in']));
                }
                
                if (isset($request_params[3]['not_in']) && is_array($request_params[3]['not_in']))
                {
                    $_GET['exclude'] = implode(',', array_map('intval', $request_params[3]['not_in']));
                }
            }
        }
        break;
    case 'get_participated_topic':
        $function_file_name = 'search';
        $include_topic_num = true;
        $nofloodcheck = true;
        list($start, $limit, $page) = process_page($request_params[1], $request_params[2]);
        $_GET['page'] = $page;
        $_GET['perpage'] = $limit;
        
        if (isset($request_params[3]) && $request_params[3])
        {
            $_GET['action'] = 'results';
            $_GET['sortby'] = 'lastpost';
            $_GET['order'] = 'desc';
            $_GET['sid'] = $request_params[3];
        }
        else
        {
            $_POST['action'] = 'do_search';
            $_POST['postthread'] = 1;
            $_POST['sortby'] = 'lastpost';
            $_POST['sortordr'] = 'desc';
            $_POST['submit'] = 'Search';
            $_POST['showresults'] = 'threads';
            isset($search_filter['forumid']) && $_POST['forums'] = array($search_filter['forumid']);
            
            if (isset($request_params[4]) && intval($request_params[4])) {
                $_POST['uid'] = intval($request_params[4]);
            } else {
                $_POST['author'] = $request_params[0];
                $_POST['matchusername'] = 1;
            }
            
            if (isset($search_filter['searchtime']) && is_numeric($search_filter['searchtime']))
            {
                $_POST['postdate'] = $search_filter['searchtime']/86400;
                $_POST['pddir'] = 1;
            }
            
            if (isset($search_filter['only_in']) && is_array($search_filter['only_in']))
            {
                $_POST['forums'] = array_map('intval', $search_filter['only_in']);
            }
            
            if (isset($search_filter['not_in']) && is_array($search_filter['not_in']))
            {
                $_POST['exclude'] = implode(', ', array_map('intval', $search_filter['not_in']));
            }
        }
        break;
    case 'get_user_topic':
        $function_file_name = 'search';
        $include_topic_num = false;
        $_GET['page'] = 1;
        $_GET['perpage'] = 20;
        $_GET['action'] = 'finduserthreads';
        
        if (isset($request_params[1]) && intval($request_params[1])) {
            $_GET['uid'] = intval($request_params[1]);
        } else {
            $_GET['username'] = $request_params[0];
        }
        break;
    case 'get_user_reply_post':
        $function_file_name = 'search';
        $include_topic_num = false;
        $_GET['page'] = 1;
        $_GET['perpage'] = 20;
        $_GET['action'] = 'finduser';
        
        if (isset($request_params[1]) && intval($request_params[1])) {
            $_GET['uid'] = intval($request_params[1]);
        } else {
            $_GET['username'] = $request_params[0];
        }
        break;
    case 'get_subscribed_topic':
        $function_file_name = 'search';
        $include_topic_num = true;
        list($start, $limit, $page) = process_page($request_params[0], $request_params[1]);
        $_GET['page'] = $page;
        $_GET['perpage'] = $limit;
        $_GET['action'] = 'getsubs';
        break;
    
    case 'like_post':
    case 'thank_post':
        $function_file_name = 'thankyoulike';
        $_GET['pid'] = $request_params[0];
        $_GET['action'] = 'add';
        break;
    case 'unlike_post':
    case 'remove_thank_post':
        $function_file_name = 'thankyoulike';
        $_GET['pid'] = $request_params[0];
        $_GET['action'] = 'del';
        break;
    
    case 'get_config':
    case 'login':
        define('THIS_SCRIPT', 'member.php');
        $_GET['action'] = 'login';
        break;
    case 'register':
    	$_POST['action'] = 'do_register';
    	$_POST['allownotices'] = 1;
    	$_POST['dstcorrection'] = 2;
    	$_POST['password'] = $request_params[1];
    	$_POST['password2'] = $request_params[1];
    	$_POST['pmnotice'] = 1;
    	$_POST['receivepms'] = 1;
    	$_POST['referrername'] = '';
    	$_POST['regcheck1'] = '';
    	$_POST['regcheck2'] = 'true';
    	$_POST['username'] = $request_params[0];
    	$_POST['email'] = $request_params[2];
    	$_POST['email2'] = $request_params[2];
    	$_POST['step'] = 'registration';
    	$_POST['subscriptionmethod'] = 0;
        if(count($request_params) == 5)
    	{
            $_POST['tt_token'] = $request_params[3];
    	    $_POST['tt_code'] = $request_params[4];
    	}
    	break;
    case 'forget_password':
    	$_POST['action'] = 'do_lostpw';
    	$_POST['username'] = $request_params[0];
    	$_POST['tt_token'] = $request_params[1];
    	$_POST['tt_code'] = $request_params[2];
    	break;
    case 'update_password':
    	$_POST['action'] = 'do_password';
    	$_POST['oldpassword'] = !isset($request_params[2]) ? $request_params[0] : 'true';
    	$_POST['password'] = !isset($request_params[2]) ? $request_params[1] : $request_params[0] ;
    	$_POST['password2'] = !isset($request_params[2]) ? $request_params[1] : $request_params[0] ;
    	if(isset($request_params[2]))
    	{
    		$_POST['tt_token'] = $request_params[1];
    		$_POST['tt_code'] = $request_params[2]; 
    		$_POST['password'] = $request_params[0];
    		$_POST['password2'] = $request_params[0];
    	}
    	break;
    case 'update_email':
    	$_POST['action'] = 'do_email';
    	$_POST['password'] = $request_params[0];
    	$_POST['email'] =  $request_params[1];
    	$_POST['email2'] =  $request_params[1];
    	break;
    case 'sign_in':
    	$_POST['token'] = $request_params[0];
    	$_POST['code'] = $request_params[1];
    	$_POST['email'] = $request_params[2];
    	$_POST['username'] = $request_params[3];
    	$_POST['password'] = $request_params[4];
    	break;
    case 'prefetch_account':
    	$_POST['email'] = $request_params[0];
    	break;
    case 'search_user':
    	$_POST['username'] = $request_params[0];
    	$_POST['page'] = isset($request_params[1]) ? $request_params [1] : '1';
    	$_POST['perpage'] = isset($request_params[2]) ? $request_params[2] : '20';
    	break;
    case 'get_recommended_user':
    	$_POST['page'] = !empty($request_params[0]) ? $request_params [0] : '1';
    	$_POST['perpage'] = isset($request_params[1]) ? $request_params[1] : '20';
    	$_POST['mode'] = isset($request_params[2]) ? $request_params[2] : 1;
    	break;  
    case 'ignore_user':
    	$_GET['action'] = 'do_editlists';
    	$_GET['manage'] = 'ignored';
    	$_POST['user_id'] = $request_params[0];
    	$_POST['mode'] = isset($request_params[1]) ? $request_params[1] : '1';
    	break;
    case 'get_contact':
    	$_GET['action'] = "contact";
		define("ALLOWABLE_PAGE","contact");
		break;
}
if(isset($_POST['session']) && isset($_POST['api_key']) && isset($_POST['subject']) && isset($_POST['body']) || isset($_POST['email_target']))
{
	$_GET['action'] = "invitation";
	define("ALLOWABLE_PAGE","invitation");
}
error_reporting(MOBIQUO_DEBUG);
restore_error_handler();
register_shutdown_function('shutdown');
if(empty($request_params))
{
	$request_params[0]='';
}
$taptalk_from = '';
if(strpos($_SERVER['HTTP_USER_AGENT'], 'BYO') !== false)
{
	$taptalk_from = 'BYO';
}
$_SERVER['QUERY_STRING'] = 'method='.$request_method.'&params='.$request_params[0].'&from='.$taptalk_from;
define("IN_MYBB", 1);
require_once './global.php';

if (!isset($cache->cache['plugins']['active']['tapatalk']) && $request_method != 'get_config')
    get_error('Tapatalk will not work on this forum before forum admin Install & Activate tapatalk plugin on forum side!');


// hide forum option
if ($mybb->settings['tapatalk_hide_forum'])
{
    $t_hfids = array_map('intval', explode(',', $mybb->settings['tapatalk_hide_forum']));
    
    if (empty($forum_cache)) cache_forums();
    
    foreach($t_hfids as $t_hfid)
        $forum_cache[$t_hfid]['active'] = 0;
}


if ($request_method && isset($server_param[$request_method]))
{
    if ($function_file_name == 'thankyoulike' && file_exists('thankyoulike.php'))
        include('thankyoulike.php');
    else if (substr($request_method, 0, 2) == 'm_')
        include(TT_ROOT . 'include/moderation.php');
    else if(file_exists(TT_ROOT . 'include/'.$function_file_name.'.php'))
        include(TT_ROOT . 'include/'.$function_file_name.'.php');
}

error_reporting(MOBIQUO_DEBUG);