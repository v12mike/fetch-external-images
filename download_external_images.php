<?php
/**
 * This script will re-generate all thumbnails for attachments from the attachment folder.
 */

/**
 * @ignore
 */
define('IN_PHPBB', true);
$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : '../';
$phpEx = substr(strrchr(__FILE__, '.'), 1);
include($phpbb_root_path . 'common.' . $phpEx);

define('EXTERNAL_IMAGES_TABLE', $table_prefix . 'external_images');
define('EXTERNAL_IMAGE_LINKS_TABLE', $table_prefix . 'external_image_links');
define('FILE_SAVE_PATH', $phpbb_root_path . '/images/ext/');
define('MINIMUM_FILE_SIZE', 1024);
define('MAXIMUM_FILES_TO_FETCH', 500000);
define('MAXIMUM_REDIRECTS', 10);
define('CURL_FOLLOW_REDIRECTS', 0);
define('SKIP_BAD_SERVER', 1);
define('SKIP_PREVIOUS_4XX', 1);
define('ALLOW_FILE_DELETION', 1);
define('FILE_NAMES_WITH_EXTENSION', 1);
define('EXTERNAL_URL_BASE', 'https://mye28.z13.web.core.windows.net/external/');

// Parse command-line arguments
$options = getopt('', ['last_image_id:', 'url_filter:', 'log_level:']);
$last_image_id = isset($options['last_image_id']) ? (int)$options['last_image_id'] : null;
$url_filter = isset($options['url_filter']) ? $options['url_filter'] : null;
$log_level = isset($options['log_level']) ? strtolower($options['log_level']) : 'normal';

// Validate log level
$valid_log_levels = ['normal', 'trace', 'debug'];
if (!in_array($log_level, $valid_log_levels)) {
    echo "Invalid log level. Valid levels are: " . implode(', ', $valid_log_levels) . "\n";
    exit(1);
}

// Set defaults if command-line arguments are not provided
if ($last_image_id === null) {
    if (isset($config['last_dl_image_id'])) {
        $last_image_id = $config['last_dl_image_id'];
    } else {
        $last_image_id = 0;
        set_config('last_dl_image_id', 0);
    }
}

if ($url_filter === null) {
    define('URL_FILTER', '');
} else {
    define('URL_FILTER', $url_filter);
}

$scriptname = 'download_external_images.php';

if (!file_exists(FILE_SAVE_PATH)) {
    mkdir(FILE_SAVE_PATH, 0755, true);
}

$bad_servers = [];

$sql = 'SELECT * FROM ' . EXTERNAL_IMAGES_TABLE .
    ' WHERE (ext_image_id > ' . (int)$last_image_id .
    ' AND host LIKE \'%' . addslashes(URL_FILTER) . '%\')' .
    ' ORDER BY ext_image_id ASC';

script_log('normal', null, null, "Starting. Query = ", $sql);

$total_images = $db->sql_affectedrows($db->sql_query($sql));
$result = $db->sql_query_limit($sql, MAXIMUM_FILES_TO_FETCH);
$actual_num = $db->sql_affectedrows($result);

script_log('normal', null, null, "  Fetching rows", "$actual_num, starting at image_id $last_image_id");

if ($actual_num == 0) {
    $complete = true;
} else {
    $complete = false;
    if ($actual_num < MAXIMUM_FILES_TO_FETCH) {
        $complete = true;
        script_log('normal', null, null, "  Last run", "");
    }
}

$downloaded_this_run = 0;

while ($row = $db->sql_fetchrow($result)) {
    $image_id = $row['ext_image_id'];
    set_config('last_dl_image_id', $image_id);

    $url = $row['url'];
    $host = $row['host'];
    $status = $row['status'];
    $size = $row['size'];
    $local_file_name = md5("$url");
    $file_path = FILE_SAVE_PATH . $local_file_name;
    $file_ext = $row['ext'];

    // Fix for data created in earlier versions of scripts with leading '.' in the ext
    if (!strncmp($file_ext, '.', 1)) {
        script_log('debug', $image_id, $host, "  Fix data created in earlier versions of script", $url);
        $file_ext = ltrim($file_ext, '.');
        $sql_ary = array(
            'ext' => (string)$file_ext
        );
        $db->sql_query('UPDATE ' . EXTERNAL_IMAGES_TABLE . ' SET ' . $db->sql_build_array('UPDATE', $sql_ary) . ' WHERE ext_image_id = ' . $image_id);
    }

    script_log('debug', $image_id, $host, "Processing URL", "$file_path, $url");

    if ((strpos($url, URL_FILTER) === false)) {
        script_log('debug', $image_id, $host, "  Skipping filtered URL", $url);
        if ($log_level == 'normal'){
            echo (" ");
        }    
        $last_image_id = $image_id;
        continue;
    }

    // Deal with cases where the file already exists and might need to be deleted
    if (file_exists($file_path)) {
        if ($status != 200) {
            if (ALLOW_FILE_DELETION) {
                unlink($file_path);
                script_log('trace', $image_id, $host, "  DELETED BAD STATUS", "$status, $url");
            } elseif ($status == 0) {
                unlink($file_path);
                script_log('trace', $image_id, $host, "  STATUS 0 for existing file. Deleted and retrying", $url);
            } else {
                script_log('trace', $image_id, $host, "  BAD STATUS", "$status, $url");
            }
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file_path);
            finfo_close($finfo);
            if ((strpos($mime_type, 'image/') !== 0)) {
                if (ALLOW_FILE_DELETION) {
                    unlink($file_path);
                    script_log('trace', $image_id, $host, "  DELETED BAD MIME_TYPE", "$mime_type, $url");
                } else {
                    script_log('trace', $image_id, $host, "  BAD MIME_TYPE", "$mime_type, $url");
                }
            }
        }
    }

    if (!file_exists($file_path)) {
        if (SKIP_BAD_SERVER && (in_array($host, $bad_servers)))
        {
            script_log('trace', $image_id, $host, "  Skipping - Bad Server", "$url");
            if ($log_level == 'normal'){
                echo ("b");
            }   
        }
        else if (SKIP_PREVIOUS_4XX && ($status >= 400 && $status <= 499))
        {
            script_log('trace', $image_id, $host, "  Skipping - Previous 4xx status", "$url");
            if ($log_level == 'normal'){
                echo ("4");
            }   
        } 
        else 
        {        
            script_log('debug', $image_id, $host, "  Fetching ", "status = $status, $url");

            $redirect_count = 0;
            $data = fetch_file($url, $redirect_count, $status);

            if ($redirect_count) {
                script_log('trace', $image_id, $host, "  Redirect count", "$redirect_count, $url");
            }

            if ($status['http_code'] != 200) {
                // Put bogus data in data
                $data = "$url: " . $status['http_code'];
                script_log('debug', $image_id, $host, "  fetch_file failed", $data);
            }

            $fetch_result = $data;
            $download_status = $status['http_code'];
            $size = $status['size_download'];

            if ($download_status == 200) {
                $finfo = new finfo(FILEINFO_MIME);
                $mime_type = strtolower($finfo->buffer($data));

                if ((strpos($mime_type, 'image/') !== 0)) {
                    script_log('trace', $image_id, $host, "  OK but BAD MIME-TYPE", "$mime_type, $url");
                    if ($log_level == 'normal'){
                        echo ("m");
                    }   
                } elseif ($size < MINIMUM_FILE_SIZE) {
                    script_log('trace', $image_id, $host, "  OK but FILE TOO SMALL", "$size, $url");
                    if ($log_level == 'normal'){
                        echo ("s");
                    }   
                } else {
                    // Determine file extension from MIME type if not already set
                    if (empty($file_ext)) {
                        $mime_to_ext = [
                            'image/jpeg' => 'jpg',
                            'image/png' => 'png',
                            'image/gif' => 'gif',
                            // Add more mappings as needed
                        ];
                        $file_ext = $mime_to_ext[$mime_type] ?? '';
                    }

                    // Create file with extension if determined
                    $downloaded_this_run = $downloaded_this_run + 1;
                    $file_path_with_ext = $file_path . ($file_ext ? '.' . $file_ext : '');
                    file_put_contents($file_path_with_ext, $data);
                    script_log('trace', $image_id, $host, "  Successfully downloaded", EXTERNAL_URL_BASE . $local_file_name . ($file_ext ? '.' . $file_ext : ''));
                    if ($log_level == 'normal'){
                        echo ("!");
                    }   
                    $last_image_id = $image_id;
                    set_config('last_dl_image_id', $last_image_id);
                }
            } else {
                script_log('trace', $image_id, $host, "  Download FAILED", "$download_status, $url");
                if ($log_level == 'normal'){
                    echo ("x");
                }   
            }

            $sql_ary = array(
                'status' => (string)$download_status,
                'file' => (string)$local_file_name,
                'size' => (int)$size,
            );
            $db->sql_query('UPDATE ' . EXTERNAL_IMAGES_TABLE . 
                ' SET ' . $db->sql_build_array('UPDATE', $sql_ary) . 
                ' WHERE ext_image_id = ' . $image_id);

            if ($download_status == 0 || $download_status >= 500) {
                $bad_server = $host;
                $bad_servers[] = $bad_server;
                script_log('trace', $image_id, $host, "  Added bad server", $bad_server);
                if ($log_level == 'normal'){
                    echo ("b");
                }   
            }
    }
    } else {
        script_log('trace', $image_id, $host, "  File already exists", $file_path);
        if ($log_level == 'normal'){
            echo (".");
        }    
        $last_image_id = $image_id;
        set_config('last_dl_image_id', $last_image_id);
    }
}

$db->sql_freeresult($result);

if ($complete) {
    script_log('normal', null, null, "All Done ($downloaded_this_run downloaded; last image = $last_image_id)", "last_dl_image_id reset to 0.");
    set_config('last_dl_image_id', 0);
} else {
    set_config('last_dl_image_id', $image_id);
    script_log('normal', null, null, "More to do ($downloaded_this_run downloaded; at $last_image_id)", "Run the script again");
}

function script_log($level, $image_id, $host, $message, $details) {
    global $log_level;

    // Determine if the log should be written based on the current level
    $levels = ['debug', 'trace', 'normal'];
    if (array_search($log_level, $levels) > array_search($level, $levels)) {
        return;
    }

    // // Shorten $details to a maximum of 40 characters, keeping at least the last 10
    // if (strlen($details) > 80) {
    //     $details = substr($details, 0, 30) . '...' . substr($details, -10);
    // }

    $output = "";
    if ($image_id !== null) {
        $output .= "$image_id, ";
    }
    if ($host !== null) {
        $output .= "$host: ";
    }
    $output .= "$message - $details";

    // Use error_log for logging
    error_log($output);
}

function is_bad_url($url) {
    // Check for invalid characters
    if (preg_match('/[<>{}\[\]\^`\\]|["\']|[^\x20-\x7E]/', $url)) {
        return true; // Contains invalid or suspicious characters
    }

    // Check for embedded HTML or BBCode tags
    if (preg_match('/<[^>]+>|\[.*?\]/', $url)) {
        return true; // Contains embedded HTML or BBCode
    }

    // Check for double protocols
    if (preg_match('/https?:\/\/.*https?:\/\//i', $url)) {
        return true; // Contains repeated protocol
    }

    // Validate URL structure
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return true; // Invalid URL structure
    }

    // Check for other suspicious patterns (e.g., unfinished encoding sequences)
    if (preg_match('/%[^\dA-Fa-f]{2}|%[^\dA-Fa-f]?$/', $url)) {
        return true; // Contains invalid percent-encoding
    }

    // If all checks pass, it's not a bad URL
    return false;
}


function fetch_file($link, &$redirect_count, &$status) {
    $c = curl_init();
    curl_setopt($c, CURLOPT_URL, $link);
    curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($c, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($c, CURLOPT_USERAGENT, "Mozilla/5.0");
    curl_setopt($c, CURLOPT_MAXREDIRS, MAXIMUM_REDIRECTS);
    curl_setopt($c, CURLOPT_FOLLOWLOCATION, CURL_FOLLOW_REDIRECTS);
    curl_setopt($c, CURLOPT_CONNECTTIMEOUT, 5); 
    curl_setopt($c, CURLOPT_TIMEOUT, 10); //timeout in seconds
    $data = curl_exec($c);
    $status = curl_getinfo($c);
    curl_close($c);

    if ($status['http_code'] == 0)
    {
        if (is_bad_url($link)){
            $status['http_code'] = 400;
            return $data;
        }
        if ($status['total_time_us'] > 100000){
            $status['http_code'] = 504;
            return $data;

        }
    }

    return $data;
}

function get_host($url) {
    $matches = [];
    if (preg_match('((https?:\/\/[^\/]+)\/)', $url, $matches)) {
        return $matches[1];
    }
}
