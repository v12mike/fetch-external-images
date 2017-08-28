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

define('EXTERNAL_IMAGES_TABLE',				$table_prefix . 'external_images');
define('EXTERNAL_IMAGE_LINKS_TABLE',		$table_prefix . 'external_image_links');
define('FILE_SAVE_PATH',					$phpbb_root_path . '/images/ext/');	
// Reject small files, as they are probably failure icons or notices
define('MINIMUM_FILE_SIZE', 				3000);
// Specify the number of downloads to handle in one run - reduce if you receive a timeout from server
define('MAXIMUM_FILES_TO_FETCH', 			10000);
// number of redirects: set to 0 for no redirects (recommended) or 10 to allow redirects
define('MAXIMUM_REDIRECTS', 				10);
// only images with a url containing this string will be downloaded
//define('URL_FILTER', 				'http');				// any host
define('URL_FILTER', 				'.photobucket.com/');	// only photobucket.com
// Name of script - change if you use a different name for the script
$scriptname = 'download_external_images.php';

if (!file_exists(FILE_SAVE_PATH))
{
	mkdir(FILE_SAVE_PATH, 755);
}
// read id of last image downloaded
if (isset($config['last_dl_image_id']))
{
    $last_image_id = $config['last_dl_image_id'];
}
else
{
    $last_image_id = 0;
    set_config('last_dl_image_id', 0);
}
$sql = 'SELECT * FROM ' . EXTERNAL_IMAGES_TABLE .' WHERE ext_image_id > ' . (int) $last_image_id . ' ORDER BY ext_image_id ASC';
$result = $db->sql_query_limit($sql, MAXIMUM_FILES_TO_FETCH);
$actual_num = $db->sql_affectedrows($result);
if ($actual_num == 0)
{
	// nothing to do
	$complete = true;
}
else
{
	$complete = false;
	if ($actual_num < MAXIMUM_FILES_TO_FETCH)
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
	$size = $row['size'];
	$local_file_name = md5("$url");
	$file_path = FILE_SAVE_PATH . $local_file_name;

	if ((strpos($url, URL_FILTER) === false))
		continue;

	if (file_exists($file_path))
	{
		if ($status != 200) 
		{
			unlink($file_path);
			echo("DELETED BAD STATUS existing file status $status for $url \n");
		}
		else
		{
			$finfo = finfo_open(FILEINFO_MIME_TYPE); // return mime type ala mimetype extension
			$mime_type = finfo_file($finfo, $file_path);
			finfo_close($finfo);
			$temp = strpos($mime_type, 'image/');
			if ((strpos($mime_type, 'image/') !== 0))
			{
				unlink($file_path);
				echo("DELETED BAD MIME_TYPE $mime_type existing file $url \n");
			}
		}
   
//		else if ($size < MINIMUM_FILE_SIZE) 
//		{
//			unlink($file_path);
//			echo("DELETED TOO SMALL $size  existing file status $status for $url \n");
//		}
	}
	if (!file_exists($file_path)) 
	{
		$c = curl_init();curl_setopt($c, CURLOPT_URL, $url);
		curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($c, CURLOPT_SSL_VERIFYHOST,false);                  
		curl_setopt($c, CURLOPT_SSL_VERIFYPEER,false);
		curl_setopt($c, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 6.1; rv:33.0) Gecko/20100101 Firefox/33.0"); 
		curl_setopt($c, CURLOPT_COOKIE, 'CookieName1=Value;');
		curl_setopt($c, CURLOPT_MAXREDIRS, MAXIMUM_REDIRECTS); 
		curl_setopt($c, CURLOPT_FOLLOWLOCATION, 1);
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
		// if not "status 200" page, then error..
		if ( $status['http_code'] != 200 ) { $data =  "ERRORCODE22 with $url<br/><br/>Last status codes:".json_encode($status)."<br/><br/>Last data got:$data";}
		$fetch_result = $data; 
		$download_status = $status['http_code'];
		$size = $status['size_download'];
		if ($download_status == 200)
		{
			$finfo = new finfo(FILEINFO_MIME);
			$mime_type = $finfo->buffer($data);
			if ((strpos($mime_type, 'image/') !== 0))
			{
				echo("status OK $download_status : but bad mime-type : $url\n");
			}
			elseif ($size < MINIMUM_FILE_SIZE)
			{
				echo("status OK $download_status : but file too small $size : $url\n");
			}
			else
			{
				// save our local copy of the file
				file_put_contents($file_path, $data);
				echo("status OK $download_status for $image_id : $url\n");
			}
		}
		else
		{
			echo("status $download_status FAIL : $url\n");
		}
		$sql_ary = array(
			'status'	=> (string) $download_status,
			'file'		=> (string) $local_file_name,
			'size'		=> (int) $size,
			);
		$db->sql_query('UPDATE ' . EXTERNAL_IMAGES_TABLE .' SET ' . $db->sql_build_array('UPDATE', $sql_ary) . ' WHERE ext_image_id = ' . $image_id);
	}
	else
	{
		echo("existing file ID $image_id, status $status, size $size, for $url \n");
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

