<?php
/**
* This script will scan all of the text in posts looking for 
* inline images and add them to new tables in the database. 
*/
/**
* @ignore
*/
define('IN_PHPBB', true);
	$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : '../';
	$phpEx = substr(strrchr(__FILE__, '.'), 1);
	include($phpbb_root_path . 'common.' . $phpEx);
	include($phpbb_root_path . 'includes/functions_display.' . $phpEx);
	include($phpbb_root_path . 'includes/functions_posting.' . $phpEx);
	include($phpbb_root_path . 'phpbb/db/tools/tools_interface.' . $phpEx);
define('EXTERNAL_IMAGES_TABLE',				$table_prefix . 'external_images');
define('EXTERNAL_IMAGE_LINKS_TABLE',		$table_prefix . 'external_image_links');
define('MAX_URL_LEN',				500);
define('SUPPORT_PHPBB_31_FORMAT',	0);

	// Name of script - change if you use a different name for the script
	$scriptname = 'extract_external_links.php';
	// Specify the number of attachments to handle in one run - reduce if you receive a timeout from server
	$interval = 20000;
	// create the database tables if they don't exist
	$sql = 'CREATE TABLE IF NOT EXISTS ' . EXTERNAL_IMAGE_LINKS_TABLE . ' (
		ext_link_id INTEGER PRIMARY KEY AUTO_INCREMENT,
		post_id INT(10),
		ext_image_id INT(10),
		orig_link VARCHAR(500)
		)';
	$db->sql_query($sql);
	$sql = 'CREATE TABLE IF NOT EXISTS ' . EXTERNAL_IMAGES_TABLE . ' (
		ext_image_id INTEGER PRIMARY KEY AUTO_INCREMENT,
		status INT(10),
		size INT(10),
		url VARCHAR(' . MAX_URL_LEN . '),
		host VARCHAR(100),
		file VARCHAR(100),
		ext VARCHAR(10)
		)';
	$db->sql_query($sql);
	// read id of last post checked
	if (isset($config['last_ext_image_post_id']))
	{
		$last_post_id = $config['last_ext_image_post_id'];
	}
	else
	{
		$last_post_id = 0;
		set_config('last_ext_image_post_id', 0);
	}
	// count number of posts with external links to process
	$sql = 'SELECT COUNT(post_id) AS num_attach FROM ' . POSTS_TABLE . ' WHERE post_id > ' . (int)$last_post_id . ' AND (LOWER(post_text) LIKE (\'%[img:%]http%\') OR LOWER(post_text) LIKE \'%<img %src=\"http%\' )';
	$result = $db->sql_query($sql);
	$posts_count = (int) $db->sql_fetchfield('num_attach', false, $result);
	$db->sql_freeresult($result);
	$links_found = 0;
	$links_added = 0;
	$images_added = 0;
	$post_id = 0;
	echo("Posts with external links=$posts_count, maximum number to check per run=$interval, starting after post_id=$last_post_id\n");
	// read required information from posts table
    if (SUPPORT_PHPBB_31_FORMAT)
    {
        $sql = 'SELECT post_id, post_text FROM ' . POSTS_TABLE . ' WHERE post_id > ' . (int) $last_post_id . ' AND (LOWER(post_text) LIKE \'%[img:%]http%\' OR LOWER(post_text)  LIKE \'%<img %src=\"http%\') ORDER BY post_id ASC';
    }
    else
    {
        $sql = 'SELECT post_id, post_text FROM ' . POSTS_TABLE . ' WHERE post_id > ' . (int) $last_post_id . ' AND (LOWER(post_text)  LIKE \'%<img %src=\"http%\') ORDER BY post_id ASC';
    }
	$posts_result = $db->sql_query_limit($sql, $interval);
	// how many in this run?
	$actual_num = $db->sql_affectedrows($posts_result);
	echo("Actual posts to this run=$actual_num\n");
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
        while ($row = $db->sql_fetchrow($posts_result))
        {
			$stat = '0';
			//remember id
			$post_id = $row['post_id'];
			$post_text = $row['post_text'];
			// Check to see if this post has already been done
			$sql = 'SELECT COUNT(post_id) AS post_done FROM ' . EXTERNAL_IMAGE_LINKS_TABLE .' WHERE post_id = ' . (int) $post_id ;
			$res = $db->sql_query($sql);
			if ($db->sql_fetchfield('post_done', false, $res))
			{
				echo(' ');
				continue;
			}
			$db->sql_freeresult($res);
            if (SUPPORT_PHPBB_31_FORMAT)
            {
                // check for image links in the phpBB v3.1.x format
                $post_text = html_entity_decode($post_text, ENT_QUOTES);
                if (preg_match_all('~\[img:([^\]]+?)\](http[^\/]+?\/\/([^\[|^\/]+?)\/[^\.]+?\.([a-z]+?))\[\/img:\1\]~i', $post_text, $matches))
                {
                    $num_links = count($matches[0]);
                    for ($loop = 0; $loop < $num_links; $loop++)
                    {
                        $stat++;
                        $links_found++;
                        $link = $matches[0][$loop]; 
                        $id = $matches[1][$loop]; 
                        $url = $matches[2][$loop];
                        $host = $matches[3][$loop];
                        $ext = $matches[4][$loop];
                        
                        // skip this link if url exceeds database capacity
                        if (strlen($url) > MAX_URL_LEN)
                        {
                            echo('X');
                            continue;
                        }

						// skip this link if extension is too long
						if (strlen($ext) > 10)
						{
							echo('X');
							continue;
						}
                        // See if the image url is already in the database
                        $sql = 'SELECT ext_image_id FROM ' . EXTERNAL_IMAGES_TABLE .' WHERE url LIKE \'' . htmlentities($url, ENT_QUOTES) . '\'';
                        $result1 = $db->sql_query_limit($sql, 1);
                        if ($row = $db->sql_fetchrow($result1))
                        {
                            $image_id = $row['ext_image_id'];
                            $db->sql_freeresult($result1);
                        }
                        else
                        {
                            $db->sql_freeresult($result1);
                            // add it
                            $sql = 'INSERT INTO ' . EXTERNAL_IMAGES_TABLE . $db->sql_build_array('INSERT', array(
                                'url'       => htmlentities($url, ENT_QUOTES),
                                'ext'       => $ext,
                                'host'      => $host,
                                'status'    => 0,
                                ));
                            $db->sql_query($sql);
                            $images_added++;
                            // fetch again to get the id
                            $sql = 'SELECT ext_image_id FROM ' . EXTERNAL_IMAGES_TABLE .' WHERE url LIKE \'' . htmlentities($url, ENT_QUOTES) . '\'';
                            $result2 = $db->sql_query_limit($sql, 1);
                            if ($row = $db->sql_fetchrow($result2))
                            {
                                $image_id = $row['ext_image_id'];
                            }
                            $db->sql_freeresult($result2);
                        }
                        $sql = 'INSERT INTO ' . EXTERNAL_IMAGE_LINKS_TABLE . $db->sql_build_array('INSERT', array(
                            'ext_image_id'  => $image_id,
                            'orig_link' => $link,
                            'post_id'   => $post_id,
                            ));
                        $db->sql_query($sql);
                        $links_added++;
                    }
                }
            }
			// check for image links in the phpBB v3.2.x format
			$post_text = html_entity_decode($post_text, ENT_QUOTES);
			if (preg_match_all('~<img src=\"(http[^\/]+?\/\/([^\/]+)?\/.+?[^\.]+?\.([a-z]+?))\">~i', $post_text, $matches))
			{
				$num_links = count($matches[0]);
				for ($loop = 0; $loop < $num_links; $loop++)
				{
					$stat++;
					$links_found++;
					$link = $matches[0][$loop]; 
					$url = $matches[1][$loop];
					$host = $matches[2][$loop];
					$ext = $matches[3][$loop];
					
					// skip this link if url exceeds database capacity
					if (strlen($url) > MAX_URL_LEN)
                    {
                        echo('X');
						continue;
                    }

					
					// skip this link if extension is too long
					if (strlen($ext) > 10)
					{
						echo('X');
						continue;
					}
					// See if the image url is already in the database
					$sql = 'SELECT ext_image_id FROM ' . EXTERNAL_IMAGES_TABLE .' WHERE url LIKE \'' . $url . '\'';
					$result3 = $db->sql_query_limit($sql, 1);
					if ($row = $db->sql_fetchrow($result3))
					{
						$image_id = $row['ext_image_id'];
						$db->sql_freeresult($result3);
					}
					else
					{
						$db->sql_freeresult($result3);
						// add it
						$sql = 'INSERT INTO ' . EXTERNAL_IMAGES_TABLE . $db->sql_build_array('INSERT', array(
							'url'       => $url,
							'ext'       => $ext,
							'host'      => $host,
							'status'    => 0,
							));
						$db->sql_query($sql);
						$images_added++;
						// fetch again to get the id
						$sql = 'SELECT ext_image_id FROM ' . EXTERNAL_IMAGES_TABLE .' WHERE url LIKE \'' . htmlentities($url, ENT_QUOTES) . '\'';
						$result4 = $db->sql_query_limit($sql, 1);
						if ($row = $db->sql_fetchrow($result4))
						{
							$image_id = $row['ext_image_id'];
						}
						$db->sql_freeresult($result4);
					}
					$sql = 'INSERT INTO ' . EXTERNAL_IMAGE_LINKS_TABLE . $db->sql_build_array('INSERT', array(
						'ext_image_id'  => $image_id,
						'orig_link' => $link,
						'post_id'   => $post_id,
						));
					$db->sql_query($sql);
					$links_added++;
				}
			}
			if ($stat)
			{
				if ($stat > 9)
				{
					echo('*');
				}
				else
				{
					echo($stat);
				}
			}
			else
			{
				echo('.');
			}
		}
		$db->sql_freeresult($posts_result);
		// write last attachment id in config
		if ($complete)
		{
			$last_post_id = 0;
			set_config('last_ext_image_post_id', 0);
			echo("\nAll Done!!\n");
		}
		else
		{
			set_config('last_ext_image_post_id', $post_id);
			echo("\nMore to do, please run the script again\n");
		}
	}
	// finished

