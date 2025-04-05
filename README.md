# Wilcosky Bluesky Auto-Poster

Let each author at your WordPress website connect to their Bluesky account. When they publish a post, it is sent to their Bluesky account. (There is a 1 minute delay.)

## Plugin Overview

This plugin allows each WordPress author to connect their Bluesky account using their handle and password. It then automatically posts published posts to their Bluesky account. 

## How It Works

### 1. Connecting Bluesky Account

Each author can connect their Bluesky account by using the provided shortcode `[bsky_connect]`. This renders a connection form for the author to connect or disconnect their Bluesky account.

### 2. Scheduling Auto-Post

When a post is published, the plugin schedules an auto-post to Bluesky with a delay of 1 minute using the `wilcosky_bsky_schedule_auto_post` function. 

### 3. Auto-Posting to Bluesky

The `wilcosky_bsky_auto_post` function is triggered to perform the following steps:

1. **Check Post Status**: Ensure the post is published and not already posted.
2. **Retrieve Post and User Meta**: Get the post details and user meta information.
3. **Check Open Graph Data**: Retrieve Open Graph data (title, description, and image) from the post link.
4. **Upload Image (if available)**: If an Open Graph image is found, upload it to Bluesky.
5. **Prepare Post Data**: Prepare the data for posting, including text and embed (if image is uploaded).
6. **Post to Bluesky**: Attempt to post the data to Bluesky.

### 4. Retry Mechanism

If the initial posting attempt fails:

1. **Refresh Token**: The plugin will try to refresh the access token using the refresh token and retry the post.
2. **Re-Authenticate**: If refreshing the token fails, the plugin will re-authenticate using the stored credentials and retry the post.

### 5. Disconnecting Bluesky Account

Authors can disconnect their Bluesky account using the disconnect form rendered by the `[bsky_connect]` shortcode. This will remove all stored session tokens and credentials.

### 6. Uninstalling the Plugin

When the plugin is uninstalled, it will clean up all relevant data, including user metadata and post metadata, and clear any scheduled events related to auto-posting to Bluesky.

## File Structure

- `wp-bluesky-autopost-per-author.php`: The main plugin file containing all the functionality.

## Installation

1. Upload the plugin files to the `/wp-content/plugins/wp-bluesky-autopost-per-author` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Add the `[bsky_connect]` shortcode to a page or post where authors can connect their Bluesky accounts.

## Usage

- **Connecting Account**: Authors can connect their Bluesky account via the `[bsky_connect]` shortcode form.
- **Auto-Posting**: The plugin automatically handles auto-posting when a post is published.
- **Disconnecting Account**: Authors can disconnect their Bluesky account via the `[bsky_connect]` shortcode form.

## License

This plugin is licensed under the GPL3 license.

## Support

For support, contact [Billy Wilcosky](https://wilcosky.com).
