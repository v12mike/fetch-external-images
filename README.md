# fetch-external-images
Scripts to catalogue and fetch phpBB in-line images that are externally hosted.
These scripts should be compatible with phpBB v3.3.x and have been tested with phpBB v3.3.12
with php v8.2.22.

Useage:
* Create a scripts/ directory in your phpbb home directory
* Download the scripts to the scripts directory
* Run the script extract_external_links.php
  e.g. php ./extract_external_links.php
  This script will create 2 new database tables: external_images and external_image_links
  and scan the posts.post_text table for inline images matching the configured host
  it will display the number of in-line images found in each candidate post.
  For very large boards it may be neccessary to re-run the script multipe times to
  extract all of the image data from the database.
  Re-running the script after completion will start a new run that will pick up any
  subsequently added image links.
* Run the script download_external_images.php
  e.g. php ./download_external_images.php
  This script will create a directory images/ext and populate it by downloading all
  of the image files in the external_images_table.  The files are named with the MD5 of 
  the original URL.
  re-running this script is non-destructive, it will not erase any existing files even
  if the remote file has been removed or replased by a failure link or thumbnail.
  
  There are several ways that the phpBB board could be made to serve these files using
  the information in the new database tables:
  1) A script could be written to upload the files as in-line attachments and update the
  posts.post_text table accordingly
  2) A script could be written to update the links in posts.post_text to point to the 
  locally hosted files.
  3) A phpBB extension can be used to redirect image links to the   locally hosted files 
  without modifying posts.post_text.
  
  I have implemented solution 3 as a phpBB 3.x extension v12mike/imageredirect 
  ( https://github.com/v12mike/imageredirect ).
