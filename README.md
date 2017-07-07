# fetch-external-images
Scripts to catalogue and fetch phpBB in-line images that are externally hosted

Useage:
* Create a scripts directory in your phpbb home directory
* Run the script extract_external_links.php
  This script will create 2 new database tables: external_images and external_image_links
  and scan the posts.post_text table for inline images matching the configured host
  it will display the number of in-line images found in each candidate post.
* Run the script download_external_images.php
  This script will create a directory images/ext and populate it by downloading all
  of the image files in the external_images_table.  The files are named with the MD5 of 
  the original URL.
  
  There are several ways that the phpBB board could be made to serve these files using
  the information in the new database tables:
  1) A script could be written to upload the files as in-line attachments and update the
  posts.post_text table accordingly
  2) A script could be written to update the links in posts.post_text to point to the 
  locally hosted files.
  3) An extension (like camoimageproxy) coupld be used to redirect image links to the
  locally hosted files without modifying posts.post_text.
  
  I have implemented solution 3 as a modified version of camoimageproxy, which I will 
  commit later.
