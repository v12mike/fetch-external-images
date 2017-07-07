<?php
/**
* This script will re-generate all thumbnails for attachments from the attachment folder (default
* "files"), useful after changing the thumbnail width (= longest edge) via acp
*/

/**
* @ignore
*/
define('IN_PHPBB', true);

$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : '../';
$phpEx = substr(strrchr(__FILE__, '.'), 1);
include($phpbb_root_path . 'common.' . $phpEx);
//include($phpbb_root_path . 'includes/functions_display.' . $phpEx);
//include($phpbb_root_path . 'includes/functions_posting.' . $phpEx);

define('EXTERNAL_IMAGES_TABLE',				$table_prefix . 'external_images');
define('EXTERNAL_IMAGE_LINKS_TABLE',		$table_prefix . 'external_image_links');
define('FILE_SAVE_PATH',					$phpbb_root_path . '/files/ext/');	

// Name of script - change if you use a different name for the script
$scriptname = 'download_external_images.php';
// Specify the number of downloads to handle in one run - reduce if you receive a timeout from server
$interval = 20;

// read id of last image downloaded
if (isset($config['last_image_id']))
{
    $last_image_id = $config['last_dl_image_id'];
}
else
{
    $last_image_id = 0;
    set_config('last_dl_image_id', 0);
}


$sql = 'SELECT * FROM ' . EXTERNAL_IMAGES_TABLE .' WHERE ext_image_id > ' . (int) $last_image_id . ' ORDER BY ext_image_id ASC';
$result = $db->sql_query_limit($sql, $interval);

$actual_num = $db->sql_affectedrows($result);
if ($actual_num == 0)
{
	// nothing to do
	$complete = true;
}
else
{
	$complete = false;
	if ($actual_num < $interval)
	{
		// this is the last run
		$complete = true;
	}
}

while ($row = $db->sql_fetchrow($result))
{
	$image_id = $row['ext_image_id'];
	$url = $row['url'];
	$host = $row['host'];
	$status = $row['status'];

	if ($status == 0)
	{
		$fetch_result = get_remote_data($url, false, true); 
		$download_status = $fetch_result['info']['http_code'];
		$size = $fetch_result['info']['size_download'];

		if ($download_status = 200)
		{
			// save our local copy of the file
			$local_file_name = md5("$url");
5			$file_path = FILE_SAVE_PATH . $local_file_name;
			file_put_contents($file_path, $fetch_result['data']);
		}

		$sql_ary = array(
			'status'	=> (string) $download_status,
			'file'		=> (string) $local_file_name,
			'size'		=> (int) $size,
			);

		$db->sql_query('UPDATE ' . EXTERNAL_IMAGES_TABLE .' SET ' . $db->sql_build_array('UPDATE', $sql_ary) . ' WHERE ext_image_id = ' . $image_id);
		echo("download status $download_status for $image_id $file_path\n");
	}
	else
	{
		echo("existing status $status for $image_id \n");
	}
}
$db->sql_freeresult($result);

// write last attachment id in config
if ($complete)
{
	$last_image_id = 0;
	set_config('last_dl_image_id', 0);
	echo("All Done!!");
}
else
{
	set_config('last_dl_image_id', $image_id);
	echo("More to do, please run the script again\n\nS");
}


//See Updates and explanation at: https://github.com/tazotodua/useful-php-scripts/
function get_remote_data($url, $post_paramtrs=false,               $return_full_array=false)	{
	$c = curl_init();curl_setopt($c, CURLOPT_URL, $url);
	curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
	//if parameters were passed to this function, then transform into POST method.. (if you need GET request, then simply change the passed URL)
	if($post_paramtrs){curl_setopt($c, CURLOPT_POST,TRUE);	curl_setopt($c, CURLOPT_POSTFIELDS, "var1=bla&".$post_paramtrs );}
	curl_setopt($c, CURLOPT_SSL_VERIFYHOST,false);                  
	curl_setopt($c, CURLOPT_SSL_VERIFYPEER,false);
	curl_setopt($c, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 6.1; rv:33.0) Gecko/20100101 Firefox/33.0"); 
	curl_setopt($c, CURLOPT_COOKIE, 'CookieName1=Value;');
					//We'd better to use the above command, because the following command gave some weird STATUS results..
					//$header[0]= $user_agent="User-Agent: Mozilla/5.0 (Windows NT 6.1; rv:33.0) Gecko/20100101 Firefox/33.0";  $header[]="Cookie:CookieName1=Value;"; $header[]="Accept: text/xml,application/xml,application/xhtml+xml,text/html;q=0.9,text/plain;q=0.8,image/png,*/*;q=0.5";  $header[]="Cache-Control: max-age=0"; $header[]="Connection: keep-alive"; $header[]="Keep-Alive: 300"; $header[]="Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7"; $header[] = "Accept-Language: en-us,en;q=0.5"; $header[] = "Pragma: ";  curl_setopt($c, CURLOPT_HEADER, true);     curl_setopt($c, CURLOPT_HTTPHEADER, $header);
					
	curl_setopt($c, CURLOPT_MAXREDIRS, 10); 
	//if SAFE_MODE or OPEN_BASEDIR is set,then FollowLocation cant be used.. so...
	$follow_allowed= ( ini_get('open_basedir') || ini_get('safe_mode')) ? false:true;  if ($follow_allowed){curl_setopt($c, CURLOPT_FOLLOWLOCATION, 1);}
	curl_setopt($c, CURLOPT_CONNECTTIMEOUT, 9);
	curl_setopt($c, CURLOPT_REFERER, $url);    
	curl_setopt($c, CURLOPT_TIMEOUT, 60);
	curl_setopt($c, CURLOPT_AUTOREFERER, true);  
	curl_setopt($c, CURLOPT_ENCODING, 'gzip,deflate');
	$data=curl_exec($c);$status=curl_getinfo($c);curl_close($c);
	
	preg_match('/(http(|s)):\/\/(.*?)\/(.*\/|)/si',  $status['url'],$link);	
	//correct assets URLs(i.e. retrieved url is: http://site.com/DIR/SUBDIR/page.html... then href="./image.JPG" becomes href="http://site.com/DIR/SUBDIR/image.JPG", but  href="/image.JPG" needs to become href="http://site.com/image.JPG")
	
	//inside all links(except starting with HTTP,javascript:,HTTPS,//,/ ) insert that current DIRECTORY url (href="./image.JPG" becomes href="http://site.com/DIR/SUBDIR/image.JPG")
	$data=preg_replace('/(src|href|action)=(\'|\")((?!(http|https|javascript:|\/\/|\/)).*?)(\'|\")/si','$1=$2'.$link[0].'$3$4$5', $data);     
	//inside all links(except starting with HTTP,javascript:,HTTPS,//)    insert that DOMAIN url (href="/image.JPG" becomes href="http://site.com/image.JPG")
	$data=preg_replace('/(src|href|action)=(\'|\")((?!(http|https|javascript:|\/\/)).*?)(\'|\")/si','$1=$2'.$link[1].'://'.$link[3].'$3$4$5', $data);   
	// if redirected, then get that redirected page
	if($status['http_code']==301 || $status['http_code']==302) { 
		//if we FOLLOWLOCATION was not allowed, then re-get REDIRECTED URL
		//p.s. WE dont need "else", because if FOLLOWLOCATION was allowed, then we wouldnt have come to this place, because 301 could already auto-followed by curl  :)
		if (!$follow_allowed){
			//if REDIRECT URL is found in HEADER
			if(empty($redirURL)){if(!empty($status['redirect_url'])){$redirURL=$status['redirect_url'];}}
			//if REDIRECT URL is found in RESPONSE
			if(empty($redirURL)){preg_match('/(Location:|URI:)(.*?)(\r|\n)/si', $data, $m);	                if (!empty($m[2])){ $redirURL=$m[2]; } }
			//if REDIRECT URL is found in OUTPUT
			if(empty($redirURL)){preg_match('/moved\s\<a(.*?)href\=\"(.*?)\"(.*?)here\<\/a\>/si',$data,$m); if (!empty($m[1])){ $redirURL=$m[1]; } }
			//if URL found, then re-use this function again, for the found url
			if(!empty($redirURL)){$t=debug_backtrace(); return call_user_func( $t[0]["function"], trim($redirURL), $post_paramtrs);}
		}
	}
	// if not redirected,and nor "status 200" page, then error..
	elseif ( $status['http_code'] != 200 ) { $data =  "ERRORCODE22 with $url<br/><br/>Last status codes:".json_encode($status)."<br/><br/>Last data got:$data";}
	return ( $return_full_array ? array('data'=>$data,'info'=>$status) : $data);
}

