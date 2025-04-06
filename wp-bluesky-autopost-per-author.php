<?php
/**
 * Plugin Name: Wilcosky Bluesky Auto-Poster
 * Plugin URI:  https://wilcosky.com
 * Description: Allows each WordPress author to connect their Bluesky account using their handle and password and auto-post published posts to Bluesky.
 * Version:     0.8
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
    $key = WILCOSKY_BSKY_ENCRYPTION_KEY;
    list($encrypted_data, $iv) = explode('::', base64_decode($data), 2);
    return openssl_decrypt($encrypted_data, 'aes-256-cbc', $key, 0, $iv);
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
    <label for="wilcosky-bsky-log"><?php esc_html_e('Bluesky Posting Log:', 'wilcosky-bsky'); ?></label>
    <textarea id="wilcosky-bsky-log" readonly style="width: 100%; height: 200px;"><?php echo esc_textarea($log); ?></textarea>

    <script>
    (function(){
        const loginForm = document.getElementById('wilcosky-bsky-login-form');
        const disconnectForm = document.getElementById('wilcosky-bsky-disconnect-form');
        const responseDiv = document.getElementById('wilcosky-bsky-response');
        
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

    // Store the session tokens and handle securely.
    update_user_meta($user_id, 'wilcosky_bsky_token', sanitize_text_field($body['accessJwt']));
    update_user_meta($user_id, 'wilcosky_bsky_refresh_token', sanitize_text_field($body['refreshJwt']));
    update_user_meta($user_id, 'wilcosky_bsky_handle', $handle);
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

    // Remove the session token and handle.
    delete_user_meta($user_id, 'wilcosky_bsky_token');
    delete_user_meta($user_id, 'wilcosky_bsky_handle');
    delete_user_meta($user_id, 'wilcosky_bsky_password'); // Remove stored encrypted password
    delete_user_meta($user_id, 'wilcosky_bsky_last_communication'); // Remove last communication time

    wp_send_json(['message' => esc_html__('Bluesky disconnected successfully!', 'wilcosky-bsky')]);
}
add_action('wp_ajax_wilcosky_bsky_disconnect', 'wilcosky_bsky_disconnect');

/**
 * Schedule auto-post to Bluesky when a post, page, or custom post type is published.
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
add_action('publish_post', 'wilcosky_bsky_schedule_auto_post');

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
        'body'    => wp_json_encode($payload),
        'headers' => ['Content-Type' => 'application/json'],
        'timeout' => 15,
    ]);

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
    update_user_meta($user_id, 'wilcosky_bsky_last_communication', current_time('mysql')); // Update communication time
    wilcosky_bsky_update_log($user_id, 'Token refreshed successfully.');
    return $body['accessJwt'];
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
 * Auto-posts a WordPress post to Bluesky.
 *
 * This function attempts to post a given post to Bluesky. It logs each step,
 * tries to refresh tokens or re-authenticate as needed, and schedules retries
 * (up to 3 attempts) in case of any failure.
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
    
    if (empty($token) || empty($handle)) {
        wilcosky_bsky_update_log($user_id, 'Missing token or handle.', $title);
        schedule_retry($post_id, $user_id, $title);
        return;
    }
    
    $link = get_permalink($post_id);

    // Helper: Check Open Graph data and populate variables by reference.
    function check_og_data($link, $title, &$og_title, &$og_description, &$og_image) {
        $response = wp_remote_get($link);
        if (is_wp_error($response)) {
            return false;
        }
        $html = wp_remote_retrieve_body($response);
        preg_match('/<meta property="og:title" content="([^"]+)"/', $html, $og_title);
        preg_match('/<meta property="og:description" content="([^"]+)"/', $html, $og_description);
        preg_match('/<meta property="og:image" content="([^"]+)"/', $html, $og_image);
        
        $og_title = html_entity_decode($og_title[1] ?? $title);
        $og_description = html_entity_decode($og_description[1] ?? '');
        $og_image = $og_image[1] ?? '';
        
        return (!empty($og_title) && !empty($og_description) && !empty($og_image) && !empty($link));
    }
    
    // Initial Open Graph data check.
    if (!check_og_data($link, $title, $og_title, $og_description, $og_image)) {
        wilcosky_bsky_update_log($user_id, 'Open Graph data not ready, scheduling retry.', $title);
        schedule_retry($post_id, $user_id, $title);
        return;
    }
    
    // Attempt to upload the OG image.
    $embed = null;
    if (!empty($og_image)) {
        $image_data = wp_remote_get($og_image);
        if (!is_wp_error($image_data)) {
            $image_body = wp_remote_retrieve_body($image_data);
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
                    wilcosky_bsky_update_log($user_id, 'Image uploaded successfully.', $title);
                } else {
                    wilcosky_bsky_update_log($user_id, 'Image upload did not return a blob.', $title);
                }
            } else {
                wilcosky_bsky_update_log($user_id, 'Error uploading image: ' . $upload_response->get_error_message(), $title);
            }
        }
    }
    
    // Prepare the data for posting.
    $post_data = [
        'repo'       => $handle,
        'collection' => 'app.bsky.feed.post',
        'record'     => [
            'text'      => $title,
            'createdAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ],
    ];
    if ($embed) {
        $post_data['record']['embed'] = $embed;
    }
    
    // Helper: Post data to Bluesky.
    function post_to_bluesky($post_data, $token) {
        return wp_remote_post(WILCOSKY_BSKY_API . 'com.atproto.repo.createRecord', [
            'body'    => wp_json_encode($post_data),
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);
    }
    
    wilcosky_bsky_update_log($user_id, 'Attempting to post to Bluesky.', $title);
    $post_response = post_to_bluesky($post_data, $token);
    
    // If the post attempt fails, try to refresh token or re-authenticate.
    if (is_wp_error($post_response) || wp_remote_retrieve_response_code($post_response) != 200) {
        wilcosky_bsky_update_log($user_id, 'Initial post attempt failed. Trying to refresh token.', $title);
        $token = wilcosky_bsky_refresh_token($user_id);
        if ($token) {
            wilcosky_bsky_update_log($user_id, 'Token refreshed successfully, retrying post.', $title);
            // Re-check Open Graph data after token refresh.
            if (!check_og_data($link, $title, $og_title, $og_description, $og_image)) {
                wilcosky_bsky_update_log($user_id, 'OG data not ready after token refresh, scheduling retry.', $title);
                schedule_retry($post_id, $user_id, $title);
                return;
            }
            // Re-upload the image with the new token.
            $embed = null;
            if (!empty($og_image)) {
                $image_data = wp_remote_get($og_image);
                if (!is_wp_error($image_data)) {
                    $image_body = wp_remote_retrieve_body($image_data);
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
                            wilcosky_bsky_update_log($user_id, 'Image re-uploaded successfully after token refresh.', $title);
                        }
                    }
                }
            }
            // Prepare the post data again with the (re-uploaded) embed.
            $post_data = [
                'repo'       => $handle,
                'collection' => 'app.bsky.feed.post',
                'record'     => [
                    'text'      => $title,
                    'createdAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
                ],
            ];
            if ($embed) {
                $post_data['record']['embed'] = $embed;
            }
            $post_response = post_to_bluesky($post_data, $token);
        } else {
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
                        $token = $body['accessJwt'];
                        wilcosky_bsky_update_log($user_id, 'Re-authentication successful.', $title);
                    } else {
                        wilcosky_bsky_update_log($user_id, 'Re-authentication failed: invalid response.', $title);
                    }
                } else {
                    wilcosky_bsky_update_log($user_id, 'Re-authentication error: ' . $response->get_error_message(), $title);
                }
            }
            // Re-attempt the post if we now have a token.
            if (!empty($token)) {
                wilcosky_bsky_update_log($user_id, 'Retrying post after re-authentication.', $title);
                $post_response = post_to_bluesky($post_data, $token);
            }
        }
    }
    
    // Final outcome.
    if (!is_wp_error($post_response) && wp_remote_retrieve_response_code($post_response) == 200) {
        update_post_meta($post_id, '_wilcosky_bsky_posted', 1);
        // Reset the retry count on success.
        delete_post_meta($post_id, '_wilcosky_bsky_retry_count');
        update_user_meta($user_id, 'wilcosky_bsky_last_communication', current_time('mysql'));
        wilcosky_bsky_update_log($user_id, 'Post successfully auto-posted to Bluesky.', $title);
    } else {
        wilcosky_bsky_update_log($user_id, 'Failed to auto-post to Bluesky, scheduling final retry.', $title);
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
        delete_user_meta($user->ID, 'wilcosky_bsky_password');
        delete_user_meta($user->ID, 'wilcosky_bsky_last_communication');
        
        // Remove the frontend logs for each user
        delete_user_meta($user->ID, 'wilcosky_bsky_frontend_logs');
    }

    // Remove post metadata
    $posts = get_posts(array('numberposts' => -1, 'post_type' => 'any', 'post_status' => 'any'));
    foreach ($posts as $post) {
        delete_post_meta($post->ID, '_wilcosky_bsky_posted');
    }
}

// Hook the uninstall function
register_uninstall_hook(__FILE__, 'wilcosky_bsky_uninstall');
