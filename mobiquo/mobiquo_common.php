<?php

defined('IN_MOBIQUO') or exit;

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

function get_error($error_message)
{
    $r = new xmlrpcresp(
            new xmlrpcval(array(
                'result'        => new xmlrpcval(false, 'boolean'),
                'result_text'   => new xmlrpcval($error_message, 'base64'),
            ),'struct')
    );
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n".$r->serialize('UTF-8');
    exit;
}


function errors(array $errors)
{
    if(empty($errors)) return;

    $error = implode("\n", $errors);

    error($error);
}


function mobi_parse_requrest()
{
    global $request_method, $request_params, $params_num;
    
    $ver = phpversion();
    if ($ver[0] >= 5) {
        $data = file_get_contents('php://input');
    } else {
        $data = isset($GLOBALS['HTTP_RAW_POST_DATA']) ? $GLOBALS['HTTP_RAW_POST_DATA'] : '';
    }
    
    if (count($_SERVER) == 0)
    {
        $r = new xmlrpcresp('', 15, 'XML-RPC: '.__METHOD__.': cannot parse request headers as $_SERVER is not populated');
        echo $r->serialize('UTF-8');
        exit;
    }
    
    if(isset($_SERVER['HTTP_CONTENT_ENCODING'])) {
        $content_encoding = str_replace('x-', '', $_SERVER['HTTP_CONTENT_ENCODING']);
    } else {
        $content_encoding = '';
    }
    
    if($content_encoding != '' && strlen($data)) {
        if($content_encoding == 'deflate' || $content_encoding == 'gzip') {
            // if decoding works, use it. else assume data wasn't gzencoded
            if(function_exists('gzinflate')) {
                if ($content_encoding == 'deflate' && $degzdata = @gzuncompress($data)) {
                    $data = $degzdata;
                } elseif ($degzdata = @gzinflate(substr($data, 10))) {
                    $data = $degzdata;
                }
            } else {
                $r = new xmlrpcresp('', 106, 'Received from client compressed HTTP request and cannot decompress');
                echo $r->serialize('UTF-8');
                exit;
            }
        }
    }
    
    $parsers = php_xmlrpc_decode_xml($data);
    $request_method = $parsers->methodname;
    $request_params = php_xmlrpc_decode(new xmlrpcval($parsers->params, 'array'));
    $params_num = count($request_params);
}

function get_mobiquo_config()
{
    $config_file = './config/config.txt';
    file_exists($config_file) or exit('config.txt does not exists');

    if(function_exists('file_get_contents')){
        $tmp = file_get_contents($config_file);
    }else{
        $handle = fopen($config_file, 'rb');
        $tmp = fread($handle, filesize($config_file));
        fclose($handle);
    }

    // remove comments by /*xxxx*/ or //xxxx
    $tmp = preg_replace('/\/\*.*?\*\/|\/\/.*?(\n)/si','$1',$tmp);
    $tmpData = preg_split("/\s*\n/", $tmp, -1, PREG_SPLIT_NO_EMPTY);

    $mobiquo_config = array();
    foreach ($tmpData as $d){
        list($key, $value) = preg_split("/=/", $d, 2); // value string may also have '='
        $key = trim($key);
        $value = trim($value);
        if ($key == 'hide_forum_id')
        {
            $value = preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY);
            count($value) and $mobiquo_config[$key] = $value;
        }
        else
        {
            strlen($value) and $mobiquo_config[$key] = $value;
        }
    }

    return $mobiquo_config;
}

function xmlresptrue()
{
    $result = new xmlrpcval(array(
        'result'        => new xmlrpcval(true, 'boolean'),
        'result_text'   => new xmlrpcval('', 'base64')
    ), 'struct');

    return new xmlrpcresp($result);
}

function xmlrespfalse($error_message)
{
    $result = new xmlrpcval(array(
        'result'        => new xmlrpcval(false, 'boolean'),
        'result_text'   => new xmlrpcval(strip_tags($error_message), 'base64')
    ), 'struct');

    return new xmlrpcresp($result);
}

function tt_error($error="", $title="")
{
    if(!empty($title))
        $error = "{$title} :: {$error}";
    $error = strip_tags($error);

    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n".(xmlresperror($error)->serialize('UTF-8'));
    exit;
}

/**
* For use via preg_replace_callback; makes urls absolute before wrapping them in [url]
*/
function parse_local_link($input){
    return "[URL=".XenForo_Link::convertUriToAbsoluteUri($input[1], true)."]{$input[2]}[/URL]";
}

function xmlresperror($error_message)
{
    $result = new xmlrpcval(array(
        'result'        => new xmlrpcval(false, 'boolean'),
        'result_text'   => new xmlrpcval($error_message, 'base64')
    ), 'struct');

    return new xmlrpcresp($result/*, 98, $error_message*/);
}



function tt_check_forum_password($fid, $pid=0, $pass='')
{
    global $mybb, $header, $footer, $headerinclude, $theme, $templates, $lang, $forum_cache;

    $mybb->input['pwverify'] = $pass;

    $showform = true;

    if(!is_array($forum_cache))
    {
        $forum_cache = cache_forums();
        if(!$forum_cache)
        {
            return false;
        }
    }

    // Loop through each of parent forums to ensure we have a password for them too
    $parents = explode(',', $forum_cache[$fid]['parentlist']);
    rsort($parents);
    if(!empty($parents))
    {
        foreach($parents as $parent_id)
        {
            if($parent_id == $fid || $parent_id == $pid)
            {
                continue;
            }

            if($forum_cache[$parent_id]['password'] != "")
            {
                tt_check_forum_password($parent_id, $fid);
            }
        }
    }

    $password = $forum_cache[$fid]['password'];
    if($password)
    {
        if($mybb->input['pwverify'] && $pid == 0)
        {
            if($password == $mybb->input['pwverify'])
            {
                my_setcookie("forumpass[$fid]", md5($mybb->user['uid'].$mybb->input['pwverify']), null, true);
                $showform = false;
            }
            else
            {
                eval("\$pwnote = \"".$templates->get("forumdisplay_password_wrongpass")."\";");
                $showform = true;
            }
        }
        else
        {
            if(!$mybb->cookies['forumpass'][$fid] || ($mybb->cookies['forumpass'][$fid] && md5($mybb->user['uid'].$password) != $mybb->cookies['forumpass'][$fid]))
            {
                $showform = true;
            }
            else
            {
                $showform = false;
            }
        }
    }
    else
    {
        $showform = false;
    }

    if($showform)
    {
        if(empty($pwnote))
        {
            global $lang;
            $pwnote = $lang->forum_password_note;
        }

        error($pwnote);
    }
}



function tt_no_permission(){
    return xmlrespfalse('You do not have permission to view this');
}

function absolute_url($url)
{
    global $mybb;
    
    $url = trim($url);
    
    if(empty($url)) return "";
    
    $url = preg_replace('#^\.?/#', '', $url);
    
    if(!preg_match('#^https?://#', $url)) {
        $url = $mybb->settings['bburl'] . "/" . $url;
    }
    
    return $url;
}


function mobiquo_iso8601_encode($timet, $offset = 0)
{
    global $mybb;

    if(!$offset)
    {
        if($mybb->user['uid'] != 0 && array_key_exists("timezone", $mybb->user))
        {
            $offset = $mybb->user['timezone'];
            $dstcorrection = $mybb->user['dst'];
        }
        elseif(defined("IN_ADMINCP"))
        {
            $offset =  $mybbadmin['timezone'];
            $dstcorrection = $mybbadmin['dst'];
        }
        else
        {
            $offset = $mybb->settings['timezoneoffset'];
            $dstcorrection = $mybb->settings['dstcorrection'];
        }

        // If DST correction is enabled, add an additional hour to the timezone.
        if($dstcorrection == 1)
        {
            ++$offset;
            if(my_substr($offset, 0, 1) != "-")
            {
                $offset = "+".$offset;
            }
        }
    }

    $t = gmdate("Ymd\TH:i:s", $timet + $offset * 3600);
    $t .= sprintf("%+03d:%02d", intval($offset), abs($offset - intval($offset)) * 60);

    return $t;
}

function cutstr($string, $length)
{
    if(strlen($string) <= $length) {
        return $string;
    }

    $string = str_replace(array('&amp;', '&quot;', '&lt;', '&gt;'), array('&', '"', '<', '>'), $string);

    $strcut = '';

    $n = $tn = $noc = 0;
    while($n < strlen($string)) {

        $t = ord($string[$n]);
        if($t == 9 || $t == 10 || (32 <= $t && $t <= 126)) {
            $tn = 1; $n++; $noc++;
        } elseif(194 <= $t && $t <= 223) {
            $tn = 2; $n += 2; $noc += 2;
        } elseif(224 <= $t && $t <= 239) {
            $tn = 3; $n += 3; $noc += 2;
        } elseif(240 <= $t && $t <= 247) {
            $tn = 4; $n += 4; $noc += 2;
        } elseif(248 <= $t && $t <= 251) {
            $tn = 5; $n += 5; $noc += 2;
        } elseif($t == 252 || $t == 253) {
            $tn = 6; $n += 6; $noc += 2;
        } else {
            $n++;
        }

        if($noc >= $length) {
            break;
        }

    }
    if($noc > $length) {
        $n -= $tn;
    }

    $strcut = substr($string, 0, $n);

    return $strcut;
}

function process_short_content($post_text, $parser = null, $length = 200)
{
	global $parser,$mybb;
	require_once MYBB_ROOT.$mybb->settings['tapatalk_directory'].'/emoji/emoji.class.php';
	$post_text = tapatalkEmoji::covertNameToEmpty($post_text);
    
    if($parser === null) {
        require_once MYBB_ROOT."inc/class_parser.php";
        $parser = new postParser;
    }
	$array_reg = array(
		array('reg' => '/\[color=(.*?)\](.*?)\[\/color\]/sei','replace' => "mobi_color_convert('$1','$2' ,false)"),
		array('reg' => '/\[php\](.*?)\[\/php\]/si','replace' => '[php]'),
		array('reg' => '/\[align=(.*?)\](.*?)\[\/align\]/si',replace=>" $2 "),
		array('reg' => '/\[email\](.*?)\[\/email\]/si',replace=>"[url]"),
		array('reg' => '/\[quote(.*?)\](.*?)\[\/quote\]/si','replace' => '[quote]'),
		array('reg' => '/\[code\](.*?)\[\/code\]/si','replace' => ''),
		array('reg' => '/\[url=(.*?)\](.*?)\[\/url\]/sei','replace' => "mobi_url_convert('$1','$2')"),
		array('reg' => '/\[img(.*?)\](.*?)\[\/img\]/si','replace' => '[img]'),
		array('reg' => '/\[video=(.*?)\](.*?)\[\/video\]/si','replace' => '[V]'),
		array('reg' => '/\[attachment=(.*?)\]/si','replace' => '[attach]'),
	);
	foreach ($array_reg as $arr)
	{
		$post_text = preg_replace($arr['reg'], $arr['replace'], $post_text);
	}
	//$post_text = tt_covert_list($post_text, '/\[list=1\](.*?)\[\/list\]/si', '2');
	//$post_text = tt_covert_list($post_text, '/\[list\](.*?)\[\/list\]/si', '1');
    $parser_options = array(
        'allow_html' => 0,
        'allow_mycode' => 1,
        'allow_smilies' => 0,
        'allow_imgcode' => 0,
        'filter_badwords' => 1
    );
    $post_text = strip_tags($parser->parse_message($post_text, $parser_options));
    $post_text = preg_replace('/\s+/', ' ', $post_text);
    $post_text = html_entity_decode($post_text);
    
    if(my_strlen($post_text) > $length)
    {
        $post_text = my_substr(trim($post_text), 0, $length);
    }
    return $post_text;
}
function mobi_url_convert($a,$b)
{
	if(trim($a) == trim($b))
	{
		return '[url]';
	}
	else 
	{
		return $b;
	}
}
function process_post($post, $returnHtml = false)
{
	global $mybb;
	
	require_once MYBB_ROOT.$mybb->settings['tapatalk_directory'].'/emoji/emoji.class.php';
	$post = tapatalkEmoji::covertHtmlToEmoji($post);

    if($returnHtml){
        //$post = str_replace("&", '&amp;', $post);
        //$post = str_replace("<", '&lt;', $post);
        //$post = str_replace(">", '&gt;', $post);
        // handled by post parser nl2br option
        //$post = str_replace("\r", '', $post);
        //$post = str_replace("\n", '<br />', $post);
        $post = str_replace('[hr]', '<br />____________________________________<br />', $post);
    } else {
        $post = strip_tags($post);
        $post = html_entity_decode($post, ENT_QUOTES, 'UTF-8');
        $post = str_replace('[hr]', "\n____________________________________\n", $post);
    }
   
    //mybb 1.8
	$array_reg = array(
		array('reg' => '/\[img(.*?)\](.*?)\[\/img\]/si','replace' => "[img]$2[/img]"),
		array('reg' => '/\[video=(.*?)\](.*?)\[\/video\]/si','replace' => '[url=$2]$1[/url]'),
		array('reg' => '/\[s\](.*?)\[\/s\]/si','replace' => '$1'),
	);
	foreach ($array_reg as $arr)
	{
		$post = preg_replace($arr['reg'], $arr['replace'], $post);
	}
	
	$post = str_replace("&#36;", '$', $post);
    $post = trim($post);
    // remove link on img
    //$post = preg_replace('/\[url=[^\]]*?\]\s*(\[img\].*?\[\/img\])\s*\[\/url\]/si', '$1', $post);
    if($returnHtml)
    {
    	$post = preg_replace('/\[ttcode\](.*?)\[\/ttcode\]/sei', "'[code]'.base64_decode(html_entity_decode('$1', ENT_QUOTES, 'UTF-8')).'[/code]'", $post);
    }
	else 
	{
		$post = preg_replace('/\[ttcode\](.*?)\[\/ttcode\]/sei', "'[code]'.base64_decode('$1').'[/code]'", $post);
	}

    return $post;
}

function process_page($start_num, $end)
{
    $start = intval($start_num);
    $end = intval($end);
    $start = empty($start) ? 0 : max($start, 0);
    if (empty($end) || $end < $start)
    {
        $start = 0;
        $end = 19;
    }
    elseif ($end - $start >= 50) {
        $end = $start + 49;
    }
    $limit = $end - $start + 1;
    $page = intval($start/$limit) + 1;

    return array($start, $limit, $page);
}

// redundant? __toString ;)
function get_xf_lang($lang_key, $params = array())
{
    $phrase = new XenForo_Phrase($lang_key, $params);
    return $phrase->render();
}

function get_online_status($user_id)
{
    $bridge = Tapatalk_Bridge::getInstance();
    $sessionModel = $bridge->getSessionModel();
    $userModel = $bridge->getUserModel();

    $bypassUserPrivacy = $userModel->canBypassUserPrivacy();

    $conditions = array(
        'cutOff'            => array('>', $sessionModel->getOnlineStatusTimeout()),
        'getInvisible'      => $bypassUserPrivacy,
        'getUnconfirmed'    => $bypassUserPrivacy,
        'user_id'           => XenForo_Visitor::getUserId(),
        'forceInclude'      => ($bypassUserPrivacy ? false : XenForo_Visitor::getUserId())
    );

    $onlineUsers = $sessionModel->getSessionActivityRecords($conditions);

    return empty($onlineUsers) ? false : true;
}

function basic_clean($str)
{
    $str = strip_tags($str);
    $str = trim($str);
    return html_entity_decode($str, ENT_QUOTES, 'UTF-8');
}


function process_post_attachments($id, &$post, $edit_post=false)
{
    global $attachcache, $mybb, $theme, $templates, $forumpermissions, $lang;

    $validationcount = 0;
    $tcount = 0;

    $attachment_list = array();
    if(is_array($attachcache[$id]))
    { // This post has 1 or more attachments
        foreach($attachcache[$id] as $aid => $attachment)
        {
            if($attachment['visible'])
            { // There is an attachment thats visible!
                $attachment['filename'] = htmlspecialchars_uni($attachment['filename']);
                $attachment['filesize_b'] = $attachment['filesize'];
                $attachment['filesize'] = get_friendly_size($attachment['filesize']);
                $ext = get_extension($attachment['filename']);
                if($ext == "jpeg" || $ext == "gif" || $ext == "bmp" || $ext == "png" || $ext == "jpg")
                    $type = 'image';
                elseif($ext == "pdf")
                    $type = 'pdf';
                else
                    $type = $ext;

                $attachment['icon'] = get_attachment_icon($ext);
                // Support for [attachment=id] code
                if(stripos($post['message'], "[attachment=".$attachment['aid']."]") !== false && !$edit_post)
                {
                    if($type == 'image')
                        $replace = '[img]'.absolute_url("attachment.php?aid={$attachment['aid']}").'[/img]';
                    else
                        $replace = '[url='.absolute_url("attachment.php?aid={$attachment['aid']}").']'.$attachment['filename']."[/url]({$lang->postbit_attachment_size} {$attachment['filesize']} / {$lang->postbit_attachment_downloads} {$attachment['downloads']})";

                    $post['message'] = preg_replace("#\[attachment=".$attachment['aid']."]#si", $replace, $post['message']);
                }
                else
                {
                    $url = absolute_url("attachment.php?aid={$attachment['aid']}");
                    $thumbnail_url = ($attachment['thumbnail'] != "SMALL" && $attachment['thumbnail'] != '') ? absolute_url("attachment.php?thumbnail={$attachment['aid']}") : $url;

                    $attachment_list[] = new xmlrpcval(array(
                        'filename'      => new xmlrpcval($attachment['filename'], 'base64'),
                        'filesize'      => new xmlrpcval($attachment['filesize_b'], 'int'),
                        'content_type'  => new xmlrpcval($type, 'string'),
                        'thumbnail_url' => new xmlrpcval($thumbnail_url, 'string'),
                        'url'           => new xmlrpcval($url, 'string'),
                    	'attachment_id' => new xmlrpcval($attachment['aid'], 'string'),
                    ), 'struct');
                }
            }
        }
    }

    return $attachment_list;
}

function shutdown()
{
    if (!headers_sent())
    {
        header("HTTP/1.0 200 OK");
    }
    
    $error = error_get_last();
    if(!empty($error)){
        switch($error['type']){
            case E_ERROR:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
            case E_USER_ERROR:
            case E_PARSE:
                echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n".(xmlresperror("Server error occurred: '{$error['message']} (".basename($error['file']).":{$error['line']})'")->serialize('UTF-8'));
                break;
        }
    }
}

function tp_get_forum_icon($id, $type = 'forum', $lock = false, $new = false)
{
    if ($type == 'link')
    {
        if ($filename = tp_get_forum_icon_by_name('link'))
            return $filename;
    }
    else
    {
        if ($lock && $new && $filename = tp_get_forum_icon_by_name('lock_new_'.$id))
            return $filename;
        if ($lock && $filename = tp_get_forum_icon_by_name('lock_'.$id))
            return $filename;
        if ($new && $filename = tp_get_forum_icon_by_name('new_'.$id))
            return $filename;
        if ($filename = tp_get_forum_icon_by_name($id))
            return $filename;
        
        if ($type == 'category')
        {
            if ($lock && $new && $filename = tp_get_forum_icon_by_name('category_lock_new'))
                return $filename;
            if ($lock && $filename = tp_get_forum_icon_by_name('category_lock'))
                return $filename;
            if ($new && $filename = tp_get_forum_icon_by_name('category_new'))
                return $filename;
            if ($filename = tp_get_forum_icon_by_name('category'))
                return $filename;
        }
        else
        {
            if ($lock && $new && $filename = tp_get_forum_icon_by_name('forum_lock_new'))
                return $filename;
            if ($lock && $filename = tp_get_forum_icon_by_name('forum_lock'))
                return $filename;
            if ($new && $filename = tp_get_forum_icon_by_name('forum_new'))
                return $filename;
            if ($filename = tp_get_forum_icon_by_name('forum'))
                return $filename;
        }
        
        if ($lock && $new && $filename = tp_get_forum_icon_by_name('lock_new'))
            return $filename;
        if ($lock && $filename = tp_get_forum_icon_by_name('lock'))
            return $filename;
        if ($new && $filename = tp_get_forum_icon_by_name('new'))
            return $filename;
    }
    
    return tp_get_forum_icon_by_name('default');
}

function tp_get_forum_icon_by_name($icon_name)
{
    $tapatalk_forum_icon_dir = TT_ROOT.'forum_icons/';
    
    if (file_exists($tapatalk_forum_icon_dir.$icon_name.'.png'))
        return $icon_name.'.png';
    
    if (file_exists($tapatalk_forum_icon_dir.$icon_name.'.jpg'))
        return $icon_name.'.jpg';
    
    return '';
}

function post_bbcode_clean($str)
{
	global $mybb;
	$str = preg_replace('/\[php\](.*?)\[\/php\]/sei', "'[ttcode]'.base64_encode('$1').'[/ttcode]'", $str);
	$str = preg_replace('/\[code\](.*?)\[\/code\]/sei', "'[ttcode]'.base64_encode('$1').'[/ttcode]'", $str);
	$array_reg = array(
		array('reg' => '/\[color=(.*?)\](.*?)\[\/color\]/sei','replace' => "mobi_color_convert('$1','$2' ,false)"),
		array('reg' => '/\[align=(.*?)\](.*?)\[\/align\]/si',replace=>" $2 "),
		array('reg' => '/\[email\](.*?)\[\/email\]/si',replace=>"[url]$1[/url]"),
		
	);
	foreach ($array_reg as $arr)
	{
		$str = preg_replace($arr['reg'], $arr['replace'], $str);
	}
	$str = tt_covert_list($str, '/\[list=1\](.*?)\[\/list\]/si', '2');
	$str = tt_covert_list($str, '/\[list\](.*?)\[\/list\]/si', '1');
	if(!empty($mybb->settings['tapatalk_custom_replace']))
	{
		$replace_arr = explode("\n", $mybb->settings['tapatalk_custom_replace']);
		foreach ($replace_arr as $replace)
		{
			preg_match('/^\s*(\'|")((\#|\/|\!).+\3[ismexuADUX]*?)\1\s*,\s*(\'|")(.*?)\4\s*$/', $replace,$matches);
			if(count($matches) == 6)
			{
				$temp_post = $str;
				$str = @preg_replace($matches[2], $matches[5], $str);
				if(empty($str))
				{
					$str = $temp_post;
				}
			}	
		}
	}
	return $str;
}

function mobi_color_convert($color, $str , $is_background)
{
    static $colorlist;
    
    if (preg_match('/#[\da-fA-F]{6}/is', $color))
    {
        if (empty($colorlist))
        {
            $colorlist = array(
                '#000000' => 'Black',             '#708090' => 'SlateGray',       '#C71585' => 'MediumVioletRed', '#FF4500' => 'OrangeRed',
                '#000080' => 'Navy',              '#778899' => 'LightSlateGrey',  '#CD5C5C' => 'IndianRed',       '#FF6347' => 'Tomato',
                '#00008B' => 'DarkBlue',          '#778899' => 'LightSlateGray',  '#CD853F' => 'Peru',            '#FF69B4' => 'HotPink',
                '#0000CD' => 'MediumBlue',        '#7B68EE' => 'MediumSlateBlue', '#D2691E' => 'Chocolate',       '#FF7F50' => 'Coral',
                '#0000FF' => 'Blue',              '#7CFC00' => 'LawnGreen',       '#D2B48C' => 'Tan',             '#FF8C00' => 'Darkorange',
                '#006400' => 'DarkGreen',         '#7FFF00' => 'Chartreuse',      '#D3D3D3' => 'LightGrey',       '#FFA07A' => 'LightSalmon',
                '#008000' => 'Green',             '#7FFFD4' => 'Aquamarine',      '#D3D3D3' => 'LightGray',       '#FFA500' => 'Orange',
                '#008080' => 'Teal',              '#800000' => 'Maroon',          '#D87093' => 'PaleVioletRed',   '#FFB6C1' => 'LightPink',
                '#008B8B' => 'DarkCyan',          '#800080' => 'Purple',          '#D8BFD8' => 'Thistle',         '#FFC0CB' => 'Pink',
                '#00BFFF' => 'DeepSkyBlue',       '#808000' => 'Olive',           '#DA70D6' => 'Orchid',          '#FFD700' => 'Gold',
                '#00CED1' => 'DarkTurquoise',     '#808080' => 'Grey',            '#DAA520' => 'GoldenRod',       '#FFDAB9' => 'PeachPuff',
                '#00FA9A' => 'MediumSpringGreen', '#808080' => 'Gray',            '#DC143C' => 'Crimson',         '#FFDEAD' => 'NavajoWhite',
                '#00FF00' => 'Lime',              '#87CEEB' => 'SkyBlue',         '#DCDCDC' => 'Gainsboro',       '#FFE4B5' => 'Moccasin',
                '#00FF7F' => 'SpringGreen',       '#87CEFA' => 'LightSkyBlue',    '#DDA0DD' => 'Plum',            '#FFE4C4' => 'Bisque',
                '#00FFFF' => 'Aqua',              '#8A2BE2' => 'BlueViolet',      '#DEB887' => 'BurlyWood',       '#FFE4E1' => 'MistyRose',
                '#00FFFF' => 'Cyan',              '#8B0000' => 'DarkRed',         '#E0FFFF' => 'LightCyan',       '#FFEBCD' => 'BlanchedAlmond',
                '#191970' => 'MidnightBlue',      '#8B008B' => 'DarkMagenta',     '#E6E6FA' => 'Lavender',        '#FFEFD5' => 'PapayaWhip',
                '#1E90FF' => 'DodgerBlue',        '#8B4513' => 'SaddleBrown',     '#E9967A' => 'DarkSalmon',      '#FFF0F5' => 'LavenderBlush',
                '#20B2AA' => 'LightSeaGreen',     '#8FBC8F' => 'DarkSeaGreen',    '#EE82EE' => 'Violet',          '#FFF5EE' => 'SeaShell',
                '#228B22' => 'ForestGreen',       '#90EE90' => 'LightGreen',      '#EEE8AA' => 'PaleGoldenRod',   '#FFF8DC' => 'Cornsilk',
                '#2E8B57' => 'SeaGreen',          '#9370D8' => 'MediumPurple',    '#F08080' => 'LightCoral',      '#FFFACD' => 'LemonChiffon',
                '#2F4F4F' => 'DarkSlateGrey',     '#9400D3' => 'DarkViolet',      '#F0E68C' => 'Khaki',           '#FFFAF0' => 'FloralWhite',
                '#2F4F4F' => 'DarkSlateGray',     '#98FB98' => 'PaleGreen',       '#F0F8FF' => 'AliceBlue',       '#FFFAFA' => 'Snow',
                '#32CD32' => 'LimeGreen',         '#9932CC' => 'DarkOrchid',      '#F0FFF0' => 'HoneyDew',        '#FFFF00' => 'Yellow',
                '#3CB371' => 'MediumSeaGreen',    '#9ACD32' => 'YellowGreen',     '#F0FFFF' => 'Azure',           '#FFFFE0' => 'LightYellow',
                '#40E0D0' => 'Turquoise',         '#A0522D' => 'Sienna',          '#F4A460' => 'SandyBrown',      '#FFFFF0' => 'Ivory',
                '#4169E1' => 'RoyalBlue',         '#A52A2A' => 'Brown',           '#F5DEB3' => 'Wheat',           '#FFFFFF' => 'White',
                '#4682B4' => 'SteelBlue',         '#A9A9A9' => 'DarkGrey',        '#F5F5DC' => 'Beige',
                '#483D8B' => 'DarkSlateBlue',     '#A9A9A9' => 'DarkGray',        '#F5F5F5' => 'WhiteSmoke',
                '#48D1CC' => 'MediumTurquoise',   '#ADD8E6' => 'LightBlue',       '#F5FFFA' => 'MintCream',
                '#4B0082' => 'Indigo',            '#ADFF2F' => 'GreenYellow',     '#F8F8FF' => 'GhostWhite',
                '#556B2F' => 'DarkOliveGreen',    '#AFEEEE' => 'PaleTurquoise',   '#FA8072' => 'Salmon',
                '#5F9EA0' => 'CadetBlue',         '#B0C4DE' => 'LightSteelBlue',  '#FAEBD7' => 'AntiqueWhite',
                '#6495ED' => 'CornflowerBlue',    '#B0E0E6' => 'PowderBlue',      '#FAF0E6' => 'Linen',
                '#66CDAA' => 'MediumAquaMarine',  '#B22222' => 'FireBrick',       '#FAFAD2' => 'LightGoldenRodYellow',
                '#696969' => 'DimGrey',           '#B8860B' => 'DarkGoldenRod',   '#FDF5E6' => 'OldLace',
                '#696969' => 'DimGray',           '#BA55D3' => 'MediumOrchid',    '#FF0000' => 'Red',
                '#6A5ACD' => 'SlateBlue',         '#BC8F8F' => 'RosyBrown',       '#FF00FF' => 'Fuchsia',
                '#6B8E23' => 'OliveDrab',         '#BDB76B' => 'DarkKhaki',       '#FF00FF' => 'Magenta',
                '#708090' => 'SlateGrey',         '#C0C0C0' => 'Silver',          '#FF1493' => 'DeepPink',
            );
        }
        
        if (isset($colorlist[strtoupper($color)])) $color = $colorlist[strtoupper($color)];
    }
    if($is_background)
    	return "[color=$color][b]".$str.'[/b][/color]';
    else 
        return "[color=$color]".$str.'[/color]';
}
function tt_covert_list($message,$preg,$type)
{
	while(preg_match($preg, $message, $blocks))
    {
    	$list_str = "";
    	$list_arr = explode('[*]', $blocks[1]);
    	foreach ($list_arr as $key => $value)
    	{
    		$value = trim($value);
    		if(!empty($value) && $key != 0)
    		{
    			if($type == '1')
    			{
    				$key = ' * ';
    			}
    			else 
    			{
    				$key = $key.'.';
    			}
    			$list_str .= $key.$value ."\n";
    		}
    		else if(!empty($value))
    		{
    			$list_str .= $value ."\n";
    		}    		
    	}
    	$message = str_replace($blocks[0], $list_str, $message);
    }
    return $message;
}

function check_return_user_type($username)
{
	global $mybb, $db, $cache;
	$sql = "SELECT u.uid,g.gid 
		FROM ".TABLE_PREFIX."users u 
		LEFT JOIN ".TABLE_PREFIX . "usergroups g
		ON u.usergroup = g.gid
		WHERE u.username = '" . $username."'
		LIMIT 1";
	$query = $db->query($sql);
	$is_ban = false;
	// Read the banned cache
	$bannedcache = $cache->read("banned");	
	$user_groups = $db->fetch_array($query);
	if(empty($user_groups))
	{
		return new xmlrpcval(basic_clean('guest'), 'base64');;
	}	
	// If the banned cache doesn't exist, update it and re-read it
	if(!is_array($bannedcache))
	{
		$cache->update_banned();
		$bannedcache = $cache->read("banned");
	}
	if(!empty($bannedcache[$user_groups['uid']]) || ($user_groups['gid'] == 7))
	{
		$is_ban = true;
	}
	if($is_ban)
	{
		$user_type = 'banned';
	}
	else if($user_groups['gid'] == 4)
	{
		$user_type = 'admin';
	}
	else if($user_groups['gid'] == 6 || $user_groups['gid'] == 3)
	{
		$user_type = 'mod';
	}
	else if($user_groups['gid'] == 5)
	{
		if($mybb->settings['regtype'] == "admin")
		{
			$user_type = 'unapproved';
		}
		else 
		{
			$user_type = 'inactive';
		}
	}
	else
    {
		$user_type = 'normal';
	}
	return new xmlrpcval(basic_clean($user_type), 'base64');
}

/**
 * Get content from remote server
 *
 * @param string $url      NOT NULL          the url of remote server, if the method is GET, the full url should include parameters; if the method is POST, the file direcotry should be given.
 * @param string $holdTime [default 0]       the hold time for the request, if holdtime is 0, the request would be sent and despite response.
 * @param string $error_msg                  return error message
 * @param string $method   [default GET]     the method of request.
 * @param string $data     [default array()] post data when method is POST.
 *
 * @exmaple: getContentFromRemoteServer('http://push.tapatalk.com/push.php', 0, $error_msg, 'POST', $ttp_post_data)
 * @return string when get content successfully|false when the parameter is invalid or connection failed.
*/
function getContentFromRemoteServer($url, $holdTime = 0, &$error_msg, $method = 'GET', $data = array())
{
    //Validate input.
    $vurl = parse_url($url);
    if ($vurl['scheme'] != 'http' && $vurl['scheme'] != 'https')
    {
        $error_msg = 'Error: invalid url given: '.$url;
        return false;
    }
    if($method != 'GET' && $method != 'POST')
    {
        $error_msg = 'Error: invalid method: '.$method;
        return false;//Only POST/GET supported.
    }
    if($method == 'POST' && empty($data))
    {
        $error_msg = 'Error: data could not be empty when method is POST';
        return false;//POST info not enough.
    }

    if(!empty($holdTime) && function_exists('file_get_contents') && $method == 'GET')
    {
        $opts = array(
            $vurl['scheme'] => array(
                'method' => "GET",
                'timeout' => $holdTime,
            )
        );

        $context = stream_context_create($opts);
        $response = file_get_contents($url,false,$context);
    }
    else if (@ini_get('allow_url_fopen'))
    {
        if(empty($holdTime))
        {
            // extract host and path:
            $host = $vurl['host'];
            $path = $vurl['path'];

            if($method == 'POST')
            {
                $fp = @fsockopen($host, 80, $errno, $errstr, 5);

                if(!$fp)
                {
                    $error_msg = 'Error: socket open time out or cannot connet.';
                    return false;
                }

                $data =  http_build_query($data);

                fputs($fp, "POST $path HTTP/1.1\r\n");
                fputs($fp, "Host: $host\r\n");
                fputs($fp, "Content-type: application/x-www-form-urlencoded\r\n");
                fputs($fp, "Content-length: ". strlen($data) ."\r\n");
                fputs($fp, "Connection: close\r\n\r\n");
                fputs($fp, $data);
                fclose($fp);
                return 1;
            }
            else
            {
                $error_msg = 'Error: 0 hold time for get method not supported.';
                return false;
            }
        }
        else
        {
            if($method == 'POST')
            {
                $params = array(
                    $vurl['scheme'] => array(
                        'method' => 'POST',
                        'content' => http_build_query($data, '', '&'),
                    )
                );
               
                $ctx = stream_context_create($params);
                $old = ini_set('default_socket_timeout', $holdTime);
                $fp = @fopen($url, 'rb', false, $ctx);
            }
            else
            {
                $fp = @fopen($url, 'rb', false);
            }
            if (!$fp)
            {
                $error_msg = 'Error: fopen failed.';
                return false;
            }
            ini_set('default_socket_timeout', $old);
            stream_set_timeout($fp, $holdTime);
            stream_set_blocking($fp, 0);

            $response = @stream_get_contents($fp);
        }
    }
    elseif (function_exists('curl_init'))
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        if($method == 'POST')
        {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        if(empty($holdTime))
        {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT,1);
        }
        $response = curl_exec($ch);
        curl_close($ch);
    }
    else
    {
        $error_msg = 'CURL is disabled and PHP option "allow_url_fopen" is OFF. You can enable CURL or turn on "allow_url_fopen" in php.ini to fix this problem.';
        return false;
    }
    if(!empty($error_msg))
    {
    	return $error_msg;
    }
    return $response;
}

function tt_register_verify($tt_token,$tt_code)
{
	global $mybb;
	if(empty($mybb->settings['tapatalk_push_key']))
	{
		$mybb->settings['tapatalk_push_key'] = '';
	}
	
	$url = "http://directory.tapatalk.com/au_reg_verify.php";
    $data = array(
        'token' => $tt_token,
        'code' => $tt_code,
        'key' => $mybb->settings['tapatalk_push_key'],
        'url' => $mybb->settings['bburl']
    );
    $error_msg = '';
    $response = getContentFromRemoteServer($url, 10, $error_msg, 'POST', $data);
    
	if(!empty($error_msg))
	{
		$response = '{"result":false,"result_text":"'.$error_msg.'"}';
	}
	if(empty($response))
	{
		$response = '{"result":false,"result_text":"Connect timeout , please try again"}';
	}
	include_once TT_ROOT."lib/classTTJson.php";
	$result = json_decode($response);
	return $result;
}

function tt_get_user_push_type($userid)
{
    global $db;
    if(!$db->table_exists('tapatalk_users')) return array();
    $sql = "SELECT pm,subscribe as sub,newtopic,quote,tag FROM " . TABLE_PREFIX . "tapatalk_users WHERE userid = '".$userid."'";
    $result = $db->query($sql);
    $row = $db->fetch_array($result);
    return $row;
}

function tt_get_sforums($fids)
{
	global $db;
	$fids_temp = array();
	foreach($fids as $key => $fid)
    {
        $fid = intval($fid);
    	switch($db->type)
 		{
 			case "pgsql":
				$query = $db->simple_select("forums", "DISTINCT fid", "(','||parentlist||',' LIKE ',%{$fid}%,') = true AND active != 0");
 				break;
 			case "sqlite":
				$query = $db->simple_select("forums", "DISTINCT fid", "(','||parentlist||',' LIKE ',%{$fid}%,') > 0 AND active != 0");
 				break;
 			default:
				$query = $db->simple_select("forums", "DISTINCT fid", "INSTR(CONCAT(',',parentlist,','),',{$fid},') > 0 AND active != 0");
 		}
        while($sforum = $db->fetch_array($query))
        {
            $fids_temp[] = $sforum['fid'];
        }
    }
    $fids_temp = array_unique($fids_temp);
    return $fids_temp;
}

function tt_get_user_by_email($email)
{
	global $mybb, $db;

	$query = $db->simple_select("users", "*", "email = '".$db->escape_string($email)."'");
	$user_info = $db->fetch_array($query);
	if(empty($user_info))
	{
		return false;
	}
	return $user_info;
}

function tt_get_user_id_by_name($username)
{
	global $mybb, $db;

	$query = $db->simple_select("users", "*", "username = '".$db->escape_string($username)."'");
	$user_info = $db->fetch_array($query);
	if(empty($user_info))
	{
		return false;
	}
	return $user_info;
}

function tt_login_success()
{
	global $db, $lang, $theme, $plugins, $mybb, $session, $settings, $cache, $time, $mybbgroups, $mobiquo_config,$user,$register;
	if($user['coppauser'])
    {
		error($lang->error_awaitingcoppa);
	}

	my_setcookie('loginattempts', 1);
	$db->delete_query("sessions", "ip='".$db->escape_string($session->ipaddress)."' AND sid != '".$session->sid."'");
	$newsession = array(
		"uid" => $user['uid'],
	);
	$db->update_query("sessions", $newsession, "sid='".$session->sid."'");

	$db->update_query("users", array("loginattempts" => 1), "uid='{$user['uid']}'");

	my_setcookie("mybbuser", $user['uid']."_".$user['loginkey'], null, true);
	my_setcookie("sid", $session->sid, -1, true);

	$mybb->cookies['sid'] = $session->sid;
	$session = new session;
	$session->init();

	$mybbgroups = $mybb->user['usergroup'];
	if($mybb->user['additionalgroups'])
	{
		$mybbgroups .= ','.$mybb->user['additionalgroups'];
	}
	$groups = explode(",", $mybbgroups);
	$xmlgroups = array();
	foreach($groups as $group){
		$xmlgroups[] = new xmlrpcval($group, "string");
	}
	tt_update_push();
	if ($settings['maxattachments'] == 0) $settings['maxattachments'] = 100;
	
	$userPushType = array('pm' => 1,'newtopic' => 1,'sub' => 1,'tag' => 1,'quote' => 1);
    $push_type = array();
    
 	foreach ($userPushType as $name=>$value)
    {
    	$push_type[] = new xmlrpcval(array(
            'name'  => new xmlrpcval($name,'string'),
    		'value' => new xmlrpcval($value,'boolean'),                    
            ), 'struct');
    }   
    
    if($mybb->settings['postfloodsecs'] && !is_moderator(0, "", $mybb->user['uid']))
    {
    	$flood_interval = $mybb->settings['postfloodsecs'];
    }
	$result = array(
		'result'            => new xmlrpcval(true, 'boolean'),
		'result_text'       => new xmlrpcval('', 'base64'),
		'user_id'           => new xmlrpcval($mybb->user['uid'], 'string'),
		'username'          => new xmlrpcval(basic_clean($mybb->user['username']), 'base64'),
		'login_name'        => new xmlrpcval(basic_clean($mybb->user['username']), 'base64'),
		'user_type' 	    => check_return_user_type($mybb->user['username']),
		//'tapatalk'          => new xmlrpcval(is_tapatalk_user($mybb->user['uid'])),
		'email'             => new xmlrpcval(basic_clean($mybb->user['email']), 'base64'),
		'icon_url'          => new xmlrpcval(absolute_url($mybb->user['avatar']), 'string'),
		'post_count'        => new xmlrpcval(intval($mybb->user['postnum']), 'int'),
		'usergroup_id'      => new xmlrpcval($xmlgroups, 'array'),
	    'ignored_uids'      => new xmlrpcval($mybb->user['ignorelist'],'string'),
		'max_png_size'      => new xmlrpcval(10000000, "int"),
		'max_jpg_size'      => new xmlrpcval(10000000, "int"),
		'max_attachment'    => new xmlrpcval($mybb->usergroup['canpostattachments'] == 1 ? $settings['maxattachments'] : 0, "int"),
		'can_upload_avatar' => new xmlrpcval($mybb->usergroup['canuploadavatars'] == 1, "boolean"),
		'can_pm'            => new xmlrpcval($mybb->usergroup['canusepms'] == 1 && !$mobiquo_config['disable_pm'], "boolean"),
		'can_send_pm'       => new xmlrpcval($mybb->usergroup['cansendpms'] == 1 && !$mobiquo_config['disable_pm'], "boolean"),
		'can_moderate'      => new xmlrpcval($mybb->usergroup['canmodcp'] == 1, "boolean"),
		'can_search'        => new xmlrpcval($mybb->usergroup['cansearch'] == 1, "boolean"),
		'can_whosonline'    => new xmlrpcval($mybb->usergroup['canviewonline'] == 1, "boolean"),
		'register'          => new xmlrpcval($register, "boolean"),
		'push_type'         => new xmlrpcval($push_type, 'array'), 
		'post_countdown'    => new xmlrpcval($flood_interval,'int'),
	);
	
	if($mybb->usergroup['isbannedgroup'] == 1)
	{
		// Fetch details on their ban
		$query = $db->simple_select("banned", "*", "uid='{$mybb->user['uid']}'", array('limit' => 1));
		$ban = $db->fetch_array($query);
		if($ban['uid'])
		{
			// Format their ban lift date and reason appropriately
			if($ban['lifted'] > 0)
			{
				$banlift = my_date($mybb->settings['dateformat'], $ban['lifted']) . ", " . my_date($mybb->settings['timeformat'], $ban['lifted']);
			}
			else 
			{
				$banlift = $lang->banned_lifted_never;
			}
			$reason = htmlspecialchars_uni($ban['reason']);
		}
		if(empty($reason))
		{
			$reason = $lang->unknown;
		}
		if(empty($banlift))
		{
			$banlift = $lang->unknown;
		}
		$result_text = $lang->banned_warning . $lang->banned_warning2.": ". $reason."\n".$lang->banned_warning3.": ".$banlift;
		$result['result_text'] = new xmlrpcval($result_text, 'base64');
	}
	
	return new xmlrpcresp(new xmlrpcval($result, 'struct'));
}

function tt_update_push()
{
    global $mybb, $db;
    
    $uid = $mybb->user['uid'];
    
    if ($uid && $db->table_exists('tapatalk_users'))
    {
        $db->write_query("INSERT IGNORE INTO " . TABLE_PREFIX . "tapatalk_users (userid) VALUES ('$uid')", 1);
        
        if ($db->affected_rows() == 0)
        {
            $db->write_query("UPDATE " . TABLE_PREFIX . "tapatalk_users SET updated = CURRENT_TIMESTAMP WHERE userid = '$uid'", 1);
        }
    }
}

function is_tapatalk_user($uid)
{
	global $db;
	
	$uid = intval($uid);
	if($db->table_exists('tapatalk_users'))
	{
		$query = $db->simple_select("tapatalk_users", "*", "userid = '".$uid."'");
		$user_info = $db->fetch_array($query);
		if(!empty($user_info))
		{
			return true;
		}
	}
	return false;
}
