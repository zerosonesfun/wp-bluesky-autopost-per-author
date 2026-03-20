<?php
/**
 * Uninstall Wilcosky Bluesky Auto-Poster
 *
 * Runs when the plugin is deleted (not on deactivate). Removes all plugin data from the database.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// User meta keys used by the plugin
$user_meta_keys = [
    'wilcosky_bsky_token',
    'wilcosky_bsky_refresh_token',
    'wilcosky_bsky_handle',
    'wilcosky_bsky_did',
    'wilcosky_bsky_password',
    'wilcosky_bsky_last_communication',
    'wilcosky_bsky_log',
];

// Post meta keys used by the plugin
$post_meta_keys = [
    '_wilcosky_bsky_posted',
    '_wilcosky_bsky_retry_count',
];

// Remove user meta for all users
$users = get_users(['fields' => 'ID']);
foreach ($users as $user_id) {
    foreach ($user_meta_keys as $meta_key) {
        delete_user_meta($user_id, $meta_key);
    }
}

// Remove post meta for all posts
$posts = get_posts([
    'numberposts' => -1,
    'post_type'   => 'any',
    'post_status' => 'any',
    'fields'      => 'ids',
]);
foreach ($posts as $post_id) {
    foreach ($post_meta_keys as $meta_key) {
        delete_post_meta($post_id, $meta_key);
    }
}
