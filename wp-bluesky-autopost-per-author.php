<?php
/**
 * Plugin Name: Wilcosky Bluesky Auto-Poster
 * Plugin URI:  https://wilcosky.com
 * Description: Allows each WordPress author to connect their Bluesky account using their handle and password and auto-post published posts to Bluesky.
 * Version:     1.2
 * Author:      Billy Wilcosky
 * Author URI:  https://wilcosky.com
 * License:     GPL3
 * Text Domain: wilcosky-bsky
 */

// Block direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

// Ensure the encryption key is defined in wp-config.php like this (replace randomkeyhere with a long random key): define('WILCOSKY_BSKY_ENCRYPTION_KEY', 'randomkeyhere'); 
if (!defined('WILCOSKY_BSKY_ENCRYPTION_KEY')) {
    error_log('Wilcosky BSky: Encryption key not defined. Please define WILCOSKY_BSKY_ENCRYPTION_KEY in wp-config.php');
    return;
}

// Define what the bsky API URL is
define('WILCOSKY_BSKY_API', 'https://bsky.social/xrpc/');

/**
 * Encrypts a string using OpenSSL.
 *
 * @param string $data The data to encrypt.
 * @return string The encrypted data.
 */
function wilcosky_bsky_encrypt($data) {
    $key = WILCOSKY_BSKY_ENCRYPTION_KEY;
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
    return base64_encode($encrypted . '::' . $iv);
}

/**
 * Decrypts a string using OpenSSL.
 *
 * @param string $data The data to decrypt.
 * @return string The decrypted data.
 */
function wilcosky_bsky_decrypt($data) {
    if (empty($data) || !is_string($data)) {
        return '';
    }
    $key = WILCOSKY_BSKY_ENCRYPTION_KEY;
    $decoded = base64_decode($data, true);
    if ($decoded === false) {
        return '';
    }
    $parts = explode('::', $decoded, 2);
    if (count($parts) !== 2) {
        return '';
    }
    list($encrypted_data, $iv) = $parts;
    $result = openssl_decrypt($encrypted_data, 'aes-256-cbc', $key, 0, $iv);
    return is_string($result) ? $result : '';
}

/**
 * Shortcode: [bsky_connect]
 * Renders a connection form for the author to connect or disconnect their Bluesky account.
 */
function wilcosky_bsky_login_shortcode() {
    if (!is_user_logged_in()) {
        return '<p>' . esc_html__('You must be logged in to connect your Bluesky account.', 'wilcosky-bsky') . '</p>';
    }

    $user_id = get_current_user_id();
    $bsky_handle = get_user_meta($user_id, 'wilcosky_bsky_handle', true);
    $last_communication = get_user_meta($user_id, 'wilcosky_bsky_last_communication', true);
    $log = get_user_meta($user_id, 'wilcosky_bsky_log', true);
    $nonce = wp_create_nonce('wilcosky_bsky_nonce');

    ob_start();
    ?>
    <style>
        .wilcosky-bsky-form {
            font-family: system-ui, arial !important;
            max-width: 100%;
            width: 100%;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            align-items: left;
        }

        .wilcosky-bsky-form label {
            display: block;
            margin-bottom: 5px;
            width: 100%;
        }
        
        .wilcosky-bsky-form input[type="text"],
        .wilcosky-bsky-form input[type="password"] {
            border-bottom: 2px solid;
            border-radius: 0;
            outline: none;
        }

        .wilcosky-bsky-form input[type="text"],
        .wilcosky-bsky-form input[type="password"],
        .wilcosky-bsky-form button {
            width: 100%;
            max-width: 300px;
            padding: 10px;
            margin-bottom: 10px;
            box-sizing: border-box;
        }

        .wilcosky-bsky-form button {
            background-color: rgb(16, 131, 254);
            color: #ffffff;
            border: none;
            cursor: pointer;
            border-radius: 50px;
            text-align: center;
            font-size: 16px;
            font-weight: bold;
        }

        .wilcosky-bsky-form button:hover {
            background-color: #005177;
        }

        .wilcosky-bsky-log-wrap {
            width: 100%;
            margin-top: 10px;
        }
        .wilcosky-bsky-log-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
        }
        .wilcosky-bsky-log-wrap .wilcosky-bsky-log-toggle {
            flex-shrink: 0;
            width: auto;
            min-width: 32px;
            height: 32px;
            padding: 4px 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: #f0f0f0;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            font-size: 13px;
            line-height: 1;
            color: #333;
            font-family: inherit;
        }
        .wilcosky-bsky-log-wrap .wilcosky-bsky-log-toggle:hover {
            background: #e0e0e0;
            border-color: #999;
        }
        .wilcosky-bsky-log-wrap .wilcosky-bsky-log-toggle .wilcosky-bsky-log-arrow {
            display: inline-block;
            transition: transform 0.2s ease;
        }
        .wilcosky-bsky-log-wrap .wilcosky-bsky-log-toggle[aria-expanded="true"] .wilcosky-bsky-log-arrow {
            transform: rotate(180deg);
        }
        .wilcosky-bsky-log-wrap #wilcosky-bsky-log {
            width: 100%;
            box-sizing: border-box;
            resize: none;
            font-family: inherit;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: #fafafa;
            transition: height 0.2s ease;
        }
        .wilcosky-bsky-log-wrap #wilcosky-bsky-log.wilcosky-bsky-log-collapsed {
            height: 2.5em !important;
            min-height: 2.5em !important;
            max-height: 2.5em !important;
            overflow: hidden !important;
        }
        .wilcosky-bsky-log-wrap #wilcosky-bsky-log.wilcosky-bsky-log-expanded {
            height: 200px;
            min-height: 120px;
        }
    </style>

    <form id="wilcosky-bsky-login-form" class="wilcosky-bsky-form" style="<?php echo !empty($bsky_handle) ? 'display:none;' : ''; ?>">
        <label for="wilcosky-bsky-handle"><?php esc_html_e('Bluesky Handle:', 'wilcosky-bsky'); ?></label>
        <input type="text" id="wilcosky-bsky-handle" name="handle" placeholder="Handle WITHOUT @" value="<?php echo esc_attr($bsky_handle); ?>" required>
        
        <label for="wilcosky-bsky-password"><?php esc_html_e('Bluesky Password:', 'wilcosky-bsky'); ?></label>
        <input type="password" id="wilcosky-bsky-password" placeholder="Password" name="password" required>
        
        <input type="hidden" name="action" value="wilcosky_bsky_login">
        <input type="hidden" name="security" value="<?php echo esc_attr($nonce); ?>">
        
        <button type="submit"><?php esc_html_e('Connect to Bluesky', 'wilcosky-bsky'); ?></button>
    </form>

    <form id="wilcosky-bsky-disconnect-form" class="wilcosky-bsky-form" style="<?php echo empty($bsky_handle) ? 'display:none;' : ''; ?>">
        <input type="hidden" name="action" value="wilcosky_bsky_disconnect">
        <input type="hidden" name="security" value="<?php echo esc_attr($nonce); ?>">
        <button type="submit"><?php esc_html_e('Disconnect from Bluesky', 'wilcosky-bsky'); ?></button>
        <?php if ($last_communication) : ?>
            <p><?php esc_html_e('Last communication with Bluesky:', 'wilcosky-bsky'); ?> <?php echo esc_html($last_communication); ?></p>
        <?php endif; ?>
    </form>

    <div id="wilcosky-bsky-response"></div>
    <div class="wilcosky-bsky-log-wrap">
        <div class="wilcosky-bsky-log-header">
            <button type="button" class="wilcosky-bsky-log-toggle" id="wilcosky-bsky-log-toggle" aria-expanded="false" aria-controls="wilcosky-bsky-log" aria-label="<?php esc_attr_e('Expand log', 'wilcosky-bsky'); ?>">
                <span class="wilcosky-bsky-log-toggle-text"><?php esc_html_e('Bluesky Posting Log', 'wilcosky-bsky'); ?></span>
                <span class="wilcosky-bsky-log-arrow" aria-hidden="true">▼</span>
            </button>
        </div>
        <textarea id="wilcosky-bsky-log" readonly class="wilcosky-bsky-log-collapsed" rows="1" aria-label="<?php esc_attr_e('Bluesky posting activity log', 'wilcosky-bsky'); ?>"><?php echo esc_textarea($log); ?></textarea>
    </div>

    <script>
    (function(){
        const loginForm = document.getElementById('wilcosky-bsky-login-form');
        const disconnectForm = document.getElementById('wilcosky-bsky-disconnect-form');
        const responseDiv = document.getElementById('wilcosky-bsky-response');
        const logArea = document.getElementById('wilcosky-bsky-log');
        const logToggle = document.getElementById('wilcosky-bsky-log-toggle');

        function scrollLogToBottom() {
            if (logArea) logArea.scrollTop = logArea.scrollHeight;
        }
        function setLogExpanded(expanded) {
            if (!logArea || !logToggle) return;
            logArea.classList.remove('wilcosky-bsky-log-collapsed', 'wilcosky-bsky-log-expanded');
            logArea.classList.add(expanded ? 'wilcosky-bsky-log-expanded' : 'wilcosky-bsky-log-collapsed');
            logToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            logToggle.setAttribute('aria-label', expanded ? '<?php echo esc_js(__('Collapse log', 'wilcosky-bsky')); ?>' : '<?php echo esc_js(__('Expand log', 'wilcosky-bsky')); ?>');
            var toggleText = logToggle.querySelector('.wilcosky-bsky-log-toggle-text');
            if (toggleText) toggleText.textContent = expanded ? '<?php echo esc_js(__('Collapse log', 'wilcosky-bsky')); ?>' : '<?php echo esc_js(__('Bluesky Posting Log', 'wilcosky-bsky')); ?>';
            if (expanded) scrollLogToBottom();
        }
        if (logToggle && logArea) {
            logToggle.addEventListener('click', function() {
                setLogExpanded(logArea.classList.contains('wilcosky-bsky-log-collapsed'));
            });
            scrollLogToBottom();
        }

        if (loginForm) {
            loginForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                var formData = new URLSearchParams(new FormData(this));
                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                    method: 'POST',
                    body: formData,
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                })
                .then(response => response.json())
                .then(data => {
                    responseDiv.textContent = data.message;
                    if (data.message && data.message.includes('successfully')) {
                        loginForm.style.display = 'none';
                        if (disconnectForm) {
                            disconnectForm.style.display = 'block';
                        }
                    }
                })
                .catch(error => {
                    responseDiv.textContent = 'Error: ' + error;
                });
            });
        }

        if (disconnectForm) {
            disconnectForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                var formData = new URLSearchParams(new FormData(this));
                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                    method: 'POST',
                    body: formData,
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                })
                .then(response => response.json())
                .then(data => {
                    responseDiv.textContent = data.message;
                    if (data.message && data.message.includes('successfully')) {
                        disconnectForm.style.display = 'none';
                        if (loginForm) {
                            loginForm.style.display = 'block';
                        }
                    }
                })
                .catch(error => {
                    responseDiv.textContent = 'Error: ' + error;
                });
            });
        }
    })();
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('bsky_connect', 'wilcosky_bsky_login_shortcode');

/**
 * AJAX handler for Bluesky login.
 */
function wilcosky_bsky_login() {
    // Verify nonce and that the user is logged in.
    check_ajax_referer('wilcosky_bsky_nonce', 'security');

    if (!is_user_logged_in() || empty($_POST['handle']) || empty($_POST['password'])) {
        wp_send_json(['message' => esc_html__('Invalid request.', 'wilcosky-bsky')], 400);
    }

    $user_id  = get_current_user_id();
    $handle   = sanitize_text_field($_POST['handle']);
    $password = sanitize_text_field($_POST['password']);

    // Prepare payload for AT Protocol authentication using the user's actual Bluesky password.
    $payload = [
        'identifier' => $handle,
        'password'   => $password,
    ];

    $response = wp_remote_post(WILCOSKY_BSKY_API . 'com.atproto.server.createSession', [
        'body'    => wp_json_encode($payload),
        'headers' => ['Content-Type' => 'application/json'],
        'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
        error_log('Wilcosky BSky: Remote post error - ' . $response->get_error_message());
        wp_send_json(['message' => esc_html__('API request failed.', 'wilcosky-bsky')], 500);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['accessJwt']) || empty($body['refreshJwt'])) {
        error_log('Wilcosky BSky: Authentication failed for handle ' . $handle . ' - Response: ' . wp_remote_retrieve_body($response));
        wp_send_json(['message' => esc_html__('Bluesky authentication failed. Please check your handle and password.', 'wilcosky-bsky')], 401);
    }

    // Store the session tokens, handle, and DID securely. createRecord requires repo=DID on bsky.social.
    update_user_meta($user_id, 'wilcosky_bsky_token', sanitize_text_field($body['accessJwt']));
    update_user_meta($user_id, 'wilcosky_bsky_refresh_token', sanitize_text_field($body['refreshJwt']));
    update_user_meta($user_id, 'wilcosky_bsky_handle', $handle);
    if (!empty($body['did'])) {
        update_user_meta($user_id, 'wilcosky_bsky_did', sanitize_text_field($body['did']));
    }
    update_user_meta($user_id, 'wilcosky_bsky_password', wilcosky_bsky_encrypt($password)); // Store encrypted password
    update_user_meta($user_id, 'wilcosky_bsky_last_communication', current_time('mysql')); // Store current time

    wp_send_json(['message' => esc_html__('Bluesky connected successfully!', 'wilcosky-bsky')]);
}
add_action('wp_ajax_wilcosky_bsky_login', 'wilcosky_bsky_login');

/**
 * AJAX handler for Bluesky disconnect.
 */
function wilcosky_bsky_disconnect() {
    // Verify nonce and that the user is logged in.
    check_ajax_referer('wilcosky_bsky_nonce', 'security');

    if (!is_user_logged_in()) {
        wp_send_json(['message' => esc_html__('Invalid request.', 'wilcosky-bsky')], 400);
    }

    $user_id = get_current_user_id();

    // Remove the session token, refresh token, handle, DID, and password.
    delete_user_meta($user_id, 'wilcosky_bsky_token');
    delete_user_meta($user_id, 'wilcosky_bsky_refresh_token');
    delete_user_meta($user_id, 'wilcosky_bsky_handle');
    delete_user_meta($user_id, 'wilcosky_bsky_did');
    delete_user_meta($user_id, 'wilcosky_bsky_password'); // Remove stored encrypted password
    delete_user_meta($user_id, 'wilcosky_bsky_last_communication'); // Remove last communication time

    wp_send_json(['message' => esc_html__('Bluesky disconnected successfully!', 'wilcosky-bsky')]);
}
add_action('wp_ajax_wilcosky_bsky_disconnect', 'wilcosky_bsky_disconnect');

/**
 * Allowed post types for Bluesky auto-post. Only these are sent when first published.
 */
function wilcosky_bsky_allowed_post_types() {
    return array('post', 'resource');
}

/**
 * Schedule auto-post to Bluesky only when a post or resource is first published (not on edits/updates).
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Old post status.
 * @param WP_Post $post       Post object.
 */
function wilcosky_bsky_on_publish_any_type($new_status, $old_status, $post) {
    if ($new_status !== 'publish' || $old_status === 'publish') {
        return;
    }
    if (!in_array($post->post_type, wilcosky_bsky_allowed_post_types(), true)) {
        return;
    }
    wilcosky_bsky_schedule_auto_post($post->ID);
}

/**
 * Schedule the auto-post event (shared by all post types).
 *
 * @param int $post_id
 */
function wilcosky_bsky_schedule_auto_post($post_id) {
    if (wp_is_post_revision($post_id) || get_post_status($post_id) !== 'publish') {
        return;
    }

    if (get_post_meta($post_id, '_wilcosky_bsky_posted', true)) {
        return;
    }

    // Schedule the auto-post with a delay of 1 minute
    wp_schedule_single_event(time() + 60, 'wilcosky_bsky_auto_post_event', [$post_id]);
}
add_action('transition_post_status', 'wilcosky_bsky_on_publish_any_type', 10, 3);

/**
 * Create and update a frontend error log which shows up where the shortcode is placed.
 */
function wilcosky_bsky_update_log($user_id, $message, $post_title = '') {
    // Retrieve the existing log; if none exists, start with an empty array.
    $log = get_user_meta($user_id, 'wilcosky_bsky_log', true);
    $lines = $log ? explode("\n", trim($log)) : [];
    
    // If a post title is provided, append its first 30 characters to the log message.
    if (!empty($post_title)) {
        $truncated_title = substr($post_title, 0, 30);
        $message .= " | Post: " . $truncated_title;
    }
    
    // Create the new log entry with a timestamp.
    $new_line = "[" . current_time('mysql') . "] " . $message;
    $lines[] = $new_line;
    
    // Keep only the last 25 entries.
    $lines = array_slice($lines, -25);
    
    // Save the updated log back as a string with a newline between entries.
    $log = implode("\n", $lines) . "\n";
    update_user_meta($user_id, 'wilcosky_bsky_log', $log);
}

/**
 * Refresh access token with refresh token as needed or re-authenticate.
 */
function wilcosky_bsky_refresh_token($user_id) {
    $refresh_token = get_user_meta($user_id, 'wilcosky_bsky_refresh_token', true);
    if (empty($refresh_token)) {
        wilcosky_bsky_update_log($user_id, 'No refresh token found.');
        return false;
    }

    $payload = [
        'refreshJwt' => $refresh_token,
    ];

    $response = wp_remote_post(WILCOSKY_BSKY_API . 'com.atproto.server.refreshSession', [
    // Remove the 'body' parameter entirely.
    'headers' => [
        'Content-Type'  => 'application/json',
        'Authorization' => 'Bearer ' . $refresh_token,
        'Accept'        => 'application/json',
    ],
    'timeout' => 15,
]);

    // Log the payload and response for debugging
    error_log('Wilcosky BSky: Refresh token request payload - ' . wp_json_encode($payload));
    error_log('Wilcosky BSky: Refresh token response - ' . wp_remote_retrieve_body($response));

    if (is_wp_error($response)) {
        wilcosky_bsky_update_log($user_id, 'Refresh token request error: ' . $response->get_error_message());
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['accessJwt']) || empty($body['refreshJwt'])) {
        wilcosky_bsky_update_log($user_id, 'Invalid refresh token response: ' . wp_remote_retrieve_body($response));
        return false;
    }

    update_user_meta($user_id, 'wilcosky_bsky_token', sanitize_text_field($body['accessJwt']));
    update_user_meta($user_id, 'wilcosky_bsky_refresh_token', sanitize_text_field($body['refreshJwt']));
    if (!empty($body['did'])) {
        update_user_meta($user_id, 'wilcosky_bsky_did', sanitize_text_field($body['did']));
    }
    update_user_meta($user_id, 'wilcosky_bsky_last_communication', current_time('mysql')); // Update communication time immediately
    wilcosky_bsky_update_log($user_id, 'Token refreshed successfully.');
    return $body['accessJwt'];
}

/**
 * Resolve a Bluesky handle to a DID (for createRecord repo). Used when user has no stored DID.
 *
 * @param string $handle The handle (without @).
 * @return string|null The DID or null on failure.
 */
function wilcosky_bsky_resolve_handle_to_did($handle) {
    if (empty($handle)) {
        return null;
    }
    $url = add_query_arg('handle', $handle, WILCOSKY_BSKY_API . 'com.atproto.identity.resolveHandle');
    $response = wp_remote_get($url, ['timeout' => 10]);
    if (is_wp_error($response)) {
        return null;
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);
    return isset($body['did']) ? sanitize_text_field($body['did']) : null;
}

/**
 * Helper function to schedule a retry with a maximum of 3 attempts.
 *
 * @param int    $post_id The ID of the post.
 * @param int    $user_id The ID of the post author.
 * @param string $title   The post title.
 */
function schedule_retry($post_id, $user_id, $title) {
    $retry_count = (int) get_post_meta($post_id, '_wilcosky_bsky_retry_count', true);
    if ($retry_count < 3) {
        $retry_count++;
        update_post_meta($post_id, '_wilcosky_bsky_retry_count', $retry_count);
        wilcosky_bsky_update_log($user_id, 'Scheduling retry attempt ' . $retry_count . ' of 3.', $title);
        wp_schedule_single_event(time() + 60, 'wilcosky_bsky_auto_post_event', [$post_id]);
    } else {
        wilcosky_bsky_update_log($user_id, 'Maximum retry attempts reached. No further retries will be scheduled.', $title);
    }
}

/**
 * Compress an image to reduce its size before uploading.
 *
 * @param string $image_url The URL of the image to compress.
 * @return string|false The path to the compressed image, or false on failure.
 */
function wilcosky_bsky_compress_image($image_url) {
    // Download the image to a temporary location
    $response = wp_remote_get($image_url);
    if (is_wp_error($response)) {
        return false; // Return false if the image couldn't be downloaded
    }

    $image_data = wp_remote_retrieve_body($response);
    $temp_file = wp_tempnam($image_url);

    if (!$temp_file || !file_put_contents($temp_file, $image_data)) {
        return false; // Return false if the image couldn't be saved locally
    }

    // Load the image into GD or Imagick
    $image = wp_get_image_editor($temp_file);
    if (is_wp_error($image)) {
        unlink($temp_file); // Clean up the temporary file
        return false;
    }

    // Resize or compress the image
    $image->set_quality(80); // Set quality (lower = more compression, e.g., 80%)
    $image->resize(1024, 1024, false); // Resize to a max of 1024x1024 (optional)

    // Save the compressed image to a new temporary file
    $compressed_file = $temp_file . '-compressed.jpg';
    $result = $image->save($compressed_file);

    // Clean up the original temporary file
    unlink($temp_file);

    if (is_wp_error($result)) {
        return false; // Return false if compression failed
    }

    // Return the path to the compressed image
    return $result['path'];
}

/**
 * Auto-posts a WordPress post to Bluesky.
 *
 * This function attempts to post a given post to Bluesky. It first checks the token validity,
 * tries to refresh tokens or re-authenticate as needed. If the token is refreshed or re-authenticated,
 * it schedules a posting event 60 seconds away. After 60 seconds, it attempts the image upload and posting.
 * In case of failure, it schedules retries up to 3 times for any part of the process.
 *
 * @param int $post_id The ID of the post to auto-post.
 */
function wilcosky_bsky_auto_post($post_id) {
    // Check if the post is published, not a revision, and not already posted.
    if (wp_is_post_revision($post_id) || get_post_status($post_id) !== 'publish') {
        return;
    }
    if (get_post_meta($post_id, '_wilcosky_bsky_posted', true)) {
        return;
    }

    $post = get_post($post_id);
    if (!$post) {
        return;
    }

    $user_id = $post->post_author;
    $title   = html_entity_decode(get_the_title($post_id));
    $token   = get_user_meta($user_id, 'wilcosky_bsky_token', true);
    $handle  = get_user_meta($user_id, 'wilcosky_bsky_handle', true);
    $did     = get_user_meta($user_id, 'wilcosky_bsky_did', true);
    $last_communication = get_user_meta($user_id, 'wilcosky_bsky_last_communication', true);

    // createRecord requires repo = DID on bsky.social; handle may be rejected (e.g. from Feb 2026).
    if (empty($did) && !empty($handle)) {
        $did = wilcosky_bsky_resolve_handle_to_did($handle);
        if (!empty($did)) {
            update_user_meta($user_id, 'wilcosky_bsky_did', $did);
        }
    }
    $repo = !empty($did) ? $did : $handle;

    if (empty($token) || empty($repo) || empty($title)) {
        wilcosky_bsky_update_log($user_id, 'Missing token, repo (DID/handle), or title.', $title);
        schedule_retry($post_id, $user_id, $title);
        return;
    }

    // Check if token is older than 15 minutes.
    if (strtotime($last_communication) < strtotime('-15 minutes')) {
        wilcosky_bsky_update_log($user_id, 'Token expired, attempting to refresh.', $title);
        $token = wilcosky_bsky_refresh_token($user_id);
        if (!$token) {
            wilcosky_bsky_update_log($user_id, 'Token refresh failed, attempting re-authentication.', $title);

            // Re-authenticate using stored credentials.
            $encrypted_password = get_user_meta($user_id, 'wilcosky_bsky_password', true);
            $password = wilcosky_bsky_decrypt($encrypted_password);
            if (!empty($handle) && !empty($password)) {
                $payload = [
                    'identifier' => $handle,
                    'password'   => $password,
                ];
                $response = wp_remote_post(WILCOSKY_BSKY_API . 'com.atproto.server.createSession', [
                    'body'    => wp_json_encode($payload),
                    'headers' => ['Content-Type' => 'application/json'],
                    'timeout' => 15,
                ]);
                if (!is_wp_error($response)) {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    if (!empty($body['accessJwt']) && !empty($body['refreshJwt'])) {
                        update_user_meta($user_id, 'wilcosky_bsky_token', sanitize_text_field($body['accessJwt']));
                        update_user_meta($user_id, 'wilcosky_bsky_refresh_token', sanitize_text_field($body['refreshJwt']));
                        if (!empty($body['did'])) {
                            update_user_meta($user_id, 'wilcosky_bsky_did', sanitize_text_field($body['did']));
                        }
                        update_user_meta($user_id, 'wilcosky_bsky_last_communication', current_time('mysql')); // Update communication time immediately
                        $token = $body['accessJwt'];
                        wilcosky_bsky_update_log($user_id, 'Re-authentication successful. Scheduling new posting event.', $title);
                        wilcosky_bsky_schedule_auto_post($post_id);
                        return;
                    } else {
                        wilcosky_bsky_update_log($user_id, 'Re-authentication failed: Response: ' . wp_remote_retrieve_body($response), $title);
                        schedule_retry($post_id, $user_id, $title);
                        return;
                    }
                } else {
                    wilcosky_bsky_update_log($user_id, 'Re-authentication error: ' . $response->get_error_message(), $title);
                    schedule_retry($post_id, $user_id, $title);
                    return;
                }
            } else {
                wilcosky_bsky_update_log($user_id, 'Re-authentication credentials missing.', $title);
                schedule_retry($post_id, $user_id, $title);
                return;
            }
        } else {
            wilcosky_bsky_update_log($user_id, 'Token refreshed successfully. Scheduling new posting event.', $title);
            wilcosky_bsky_schedule_auto_post($post_id);
            return;
        }
    }

    // Proceed with image upload and posting to Bluesky.
    $link = get_permalink($post_id);

    if (empty($link)) {
        wilcosky_bsky_update_log($user_id, 'Missing post URL.', $title);
        schedule_retry($post_id, $user_id, $title);
        return;
    }

    // Check Open Graph data
    $og_title = $og_description = $og_image = '';
    $response = wp_remote_get($link);
    if (!is_wp_error($response)) {
        $html = wp_remote_retrieve_body($response);
        preg_match('/<meta property="og:title" content="([^"]+)"/', $html, $matches);
        $og_title = html_entity_decode($matches[1] ?? $title);
        preg_match('/<meta property="og:description" content="([^"]+)"/', $html, $matches);
        $og_description = html_entity_decode($matches[1] ?? '');
        preg_match('/<meta property="og:image" content="([^"]+)"/', $html, $matches);
        $og_image = $matches[1] ?? '';
    }

    $embed = null;
    if (!empty($og_image)) {
        // Compress the image before uploading
        $compressed_image_path = wilcosky_bsky_compress_image($og_image);
        if (!$compressed_image_path) {
            wilcosky_bsky_update_log($user_id, 'Failed to compress image before uploading.', $title);
            schedule_retry($post_id, $user_id, $title);
            return;
        }

        $image_body = file_get_contents($compressed_image_path);
        unlink($compressed_image_path); // Clean up the compressed image file

        $upload_response = wp_remote_post(WILCOSKY_BSKY_API . 'com.atproto.repo.uploadBlob', [
            'body'    => $image_body,
            'headers' => [
                'Content-Type'  => 'image/jpeg',
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);

        if (!is_wp_error($upload_response)) {
            $upload_body = json_decode(wp_remote_retrieve_body($upload_response), true);
            if (!empty($upload_body['blob'])) {
                $embed = [
                    '$type'    => 'app.bsky.embed.external',
                    'external' => [
                        'uri'         => $link,
                        'title'       => $og_title,
                        'description' => $og_description,
                        'thumb'       => $upload_body['blob'],
                    ],
                ];
            } else {
                wilcosky_bsky_update_log($user_id, 'Image upload failed: Response Body: ' . wp_remote_retrieve_body($upload_response), $title);
            }
        } else {
            wilcosky_bsky_update_log($user_id, 'Image upload error: ' . $upload_response->get_error_message(), $title);
        }
    }

    $post_data = [
        'repo'       => $repo,
        'collection' => 'app.bsky.feed.post',
        'record'     => [
            '$type'     => 'app.bsky.feed.post',
            'text'      => $title,
            'createdAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ],
    ];
    if ($embed) {
        $post_data['record']['embed'] = $embed;
    }

    $post_response = wp_remote_post(WILCOSKY_BSKY_API . 'com.atproto.repo.createRecord', [
        'body'    => wp_json_encode($post_data),
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ],
    ]);

    if (!is_wp_error($post_response) && wp_remote_retrieve_response_code($post_response) == 200) {
        update_post_meta($post_id, '_wilcosky_bsky_posted', 1);
        delete_post_meta($post_id, '_wilcosky_bsky_retry_count');
        update_user_meta($user_id, 'wilcosky_bsky_last_communication', current_time('mysql'));
        wilcosky_bsky_update_log($user_id, 'Post successfully auto-posted to Bluesky.', $title);
    } else {
        $message = is_wp_error($post_response)
            ? $post_response->get_error_message()
            : 'Response Code: ' . wp_remote_retrieve_response_code($post_response) . ', Body: ' . wp_remote_retrieve_body($post_response);
        wilcosky_bsky_update_log($user_id, 'Failed to auto-post to Bluesky. ' . $message, $title);
        schedule_retry($post_id, $user_id, $title);
    }
}
add_action('wilcosky_bsky_auto_post_event', 'wilcosky_bsky_auto_post');

// Uninstall function to clean up plugin data.
function wilcosky_bsky_uninstall() {
    // Remove user metadata
    $users = get_users();
    foreach ($users as $user) {
        delete_user_meta($user->ID, 'wilcosky_bsky_token');
        delete_user_meta($user->ID, 'wilcosky_bsky_refresh_token');
        delete_user_meta($user->ID, 'wilcosky_bsky_handle');
        delete_user_meta($user->ID, 'wilcosky_bsky_did');
        delete_user_meta($user->ID, 'wilcosky_bsky_password');
        delete_user_meta($user->ID, 'wilcosky_bsky_last_communication');
        delete_user_meta($user->ID, 'wilcosky_bsky_log');
    }

    // Remove post metadata
    $posts = get_posts(array('numberposts' => -1, 'post_type' => 'any', 'post_status' => 'any'));
    foreach ($posts as $post) {
        delete_post_meta($post->ID, '_wilcosky_bsky_posted');
        delete_post_meta($post->ID, '_wilcosky_bsky_retry_count');
    }
}

/**
 * On plugin activation, add a one-time message to every user's front-end log.
 */
function wilcosky_bsky_on_activation() {
    $users = get_users(['fields' => 'ID']);
    foreach ($users as $user_id) {
        wilcosky_bsky_update_log($user_id, 'Update made to bsky connection feature! 🎉', '');
    }
}
register_activation_hook(__FILE__, 'wilcosky_bsky_on_activation');

// Hook the uninstall function
register_uninstall_hook(__FILE__, 'wilcosky_bsky_uninstall');
