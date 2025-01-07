<?php
/**
 * This script lists files in FILE_SAVE_PATH that either:
 * - Have no database link (no associated URL in the database).
 * - Are duplicates of other files.
 * It hashes only files with potential duplicates and uses a portion of the file for hashing.
 * Supports limiting the number of files to process via the `--limit` command-line option.
 */

/**
 * @ignore
 */
define('IN_PHPBB', true);
$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : '../';
$phpEx = substr(strrchr(__FILE__, '.'), 1);
include($phpbb_root_path . 'common.' . $phpEx);

define('EXTERNAL_IMAGE_LINKS_TABLE', $table_prefix . 'external_image_links');
define('FILE_SAVE_PATH', '/azure/blobs/external/');
define('BASE_URL', 'https://mye28.z13.web.core.windows.net/external/'); // Base URL for the file links

// Parse command-line options
$options = getopt('', ['limit:']);
$limit = isset($options['limit']) ? (int)$options['limit'] : PHP_INT_MAX;

// Check if FILE_SAVE_PATH exists
if (!file_exists(FILE_SAVE_PATH)) {
    die("Error: FILE_SAVE_PATH does not exist.\n");
}

// Get a list of all files in FILE_SAVE_PATH
$all_files = array_diff(scandir(FILE_SAVE_PATH), ['.', '..']);
if (empty($all_files)) {
    die("No files found in FILE_SAVE_PATH.\n");
}

// Limit the number of files to process, if specified
if ($limit < count($all_files)) {
    $all_files = array_slice($all_files, 0, $limit);
    echo "Limiting to the first $limit files.\n";
}

// Build $local_files array with file metadata
$local_files = [];
foreach ($all_files as $file) {
    $file_parts = pathinfo($file);
    if (!empty($file_parts['filename']) && !empty($file_parts['extension'])) {
        $file_path = FILE_SAVE_PATH . $file;
        $local_files[$file_parts['filename']] = (object)[
            'file_path' => $file_path,
            'filename' => $file_parts['filename'],
            'extension' => $file_parts['extension'],
            'url' => null, // Placeholder for URL (to be populated later)
            'post_id' => null, // Placeholder for Post ID (to be populated later)
        ];
    }
}

// Fetch all `orig_link` entries from `phpbb3_external_image_links`
$sql = 'SELECT post_id, orig_link FROM ' . EXTERNAL_IMAGE_LINKS_TABLE;
$result = $db->sql_query($sql);

$referenced_hashes = [];
while ($row = $db->sql_fetchrow($result)) {
    // Extract the URL from `orig_link`
    if (preg_match_all('~<img src=\"(http[^\/]+?\/\/([^\/]+)?\/.+?[^\.]+?\.([a-z]+?))(\?.*?)?\">~i', $row['orig_link'], $matches)) {
        foreach ($matches[1] as $url) {
            // Calculate the MD5 hash of the URL
            $md5_hash = md5($url);
            $referenced_hashes[$md5_hash] = [
                'url' => $url,
                'post_id' => $row['post_id']
            ];
        }
    }
}
$db->sql_freeresult($result);

// Associate URLs and Post IDs with local files
foreach ($local_files as $filename => $file) {
    if (isset($referenced_hashes[$filename])) {
        $file->url = $referenced_hashes[$filename]['url'];
        $file->post_id = $referenced_hashes[$filename]['post_id'];
    }
}

// Detect duplicates and files with no links
$files_to_delete = []; // Files to delete (no links OR duplicates)
$file_hashes = [];
foreach ($local_files as $file) {
    if (file_exists($file->file_path)) {
        // Hash only the first 4KB of the file for efficiency
        $handle = fopen($file->file_path, 'rb');
        $partial_content = fread($handle, 4096);
        fclose($handle);

        $content_hash = md5($partial_content);
        if (isset($file_hashes[$content_hash])) {
            // Mark as duplicate
            $files_to_delete[] = $file;
        } else {
            $file_hashes[$content_hash] = $file;
        }

        // Also delete if there's no database link (no URL)
        if ($file->url === null) {
            $files_to_delete[] = $file;
        }
    }
}

// Deduplicate the $files_to_delete array
$files_to_delete = array_unique($files_to_delete, SORT_REGULAR);

// Output files to delete
$num_files_to_delete = count($files_to_delete);
if ($num_files_to_delete > 0) {
    echo "Files to delete ($num_files_to_delete):\n";
    foreach ($files_to_delete as $file) {
        $full_url = BASE_URL . $file->filename . '.' . $file->extension;
        echo "File Path: {$file->file_path}, URL: " . ($file->url ?? 'No URL') . ", Post ID: " . ($file->post_id ?? 'N/A') . ", Full URL: {$full_url}\n";
    }

    // Prompt for deletion
    echo "Do you want to delete these $num_files_to_delete files? (yes/no): ";
    $handle = fopen("php://stdin", "r");
    $response = trim(fgets($handle));
    fclose($handle);

    if (strtolower($response) === 'yes') {
        $deleted_count = 0;
        foreach ($files_to_delete as $file) {
            if (unlink($file->file_path)) {
                $deleted_count++;
                echo "Deleted: {$file->file_path}\n";
            } else {
                echo "Failed to delete: {$file->file_path}\n";
            }
        }
        echo "$deleted_count files deleted.\n";
    } else {
        echo "No files were deleted.\n";
    }
} else {
    echo "No files to delete.\n";
}
