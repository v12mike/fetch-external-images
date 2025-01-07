<?php
/**
 * This script updates files in FILE_SAVE_PATH that don't have an extension by adding the correct extension based on the database entries.
 * It copies the file to a new file with the correct extension and then deletes the original file.
 */

/**
 * @ignore
 */
define('IN_PHPBB', true);
$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : '../';
$phpEx = substr(strrchr(__FILE__, '.'), 1);
include($phpbb_root_path . 'common.' . $phpEx);

define('EXTERNAL_IMAGES_TABLE', $table_prefix . 'external_images');
define('FILE_SAVE_PATH', '/azure/blobs/external/');
define('BASE_URL', 'https://mye28.z13.web.core.windows.net/external/'); // Base URL for the file links

// Parse command-line options
$options = getopt('', ['list', 'rename']);
$list_files = isset($options['list']);
$rename_files = isset($options['rename']);

// Check if FILE_SAVE_PATH exists
if (!file_exists(FILE_SAVE_PATH)) {
    die("Error: FILE_SAVE_PATH does not exist.\n");
}

// Get a list of all files in FILE_SAVE_PATH without extensions
$all_files = array_diff(scandir(FILE_SAVE_PATH), ['.', '..']);
$files_without_extension = array_filter($all_files, function($file) {
    return strpos($file, '.') === false;
});

if (empty($files_without_extension)) {
    die("No files without extensions found in FILE_SAVE_PATH.\n");
}

// Fetch all `file` and `ext` entries from `phpbb3_external_images`
$sql = 'SELECT file, ext FROM ' . EXTERNAL_IMAGES_TABLE;
$result = $db->sql_query($sql);

$referenced_hashes = [];
while ($row = $db->sql_fetchrow($result)) {
    $file_hash = $row['file'];
    $extension = $row['ext'];
    $referenced_hashes[$file_hash] = $extension;
}
$db->sql_freeresult($result);

// Process files without extensions
$total_files = count($files_without_extension);

if ($rename_files) {
    foreach ($files_without_extension as $file) {
        $file_path = FILE_SAVE_PATH . $file;
        $md5_hash = $file;

        if (isset($referenced_hashes[$md5_hash])) {
            $extension = $referenced_hashes[$md5_hash];
            $new_file_path = $file_path . '.' . $extension;
            if (copy($file_path, $new_file_path)) {
                if (unlink($file_path)) {
                    echo "Copied and deleted: $file_path to $new_file_path\n";
                } else {
                    echo "Failed to delete: $file_path\n";
                }
            } else {
                echo "Failed to copy: $file_path\n";
            }
        }
    }
} else {
    echo "Total files without extensions: $total_files\n";

    if ($list_files) {
        echo "\nFiles to be renamed:\n";
        foreach ($files_without_extension as $file) {
            $file_path = FILE_SAVE_PATH . $file;
            $md5_hash = $file;
            if (isset($referenced_hashes[$md5_hash])) {
                $extension = $referenced_hashes[$md5_hash];
                $new_file_path = $file_path . '.' . $extension;
                echo "$file_path -> $new_file_path\n";
            }
        }
    }
}