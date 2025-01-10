<?php
/*
Plugin Name: High Precision Content Generator
Plugin URI: https://www.gancpt.at
Description: Automatically generates highly precise blog posts with cohesive sentences, relevant images using OpenAI's DALL-E 3, and YouTube video content. Enhanced search algorithms ensure content relevance and accuracy. Schedule daily content generation based on tags using server local time.
Version: 15.3
Author: Gottfried Aumann
Author URI: https://www.gancpt.at
License: GPL2
*/

require 'vendor/autoload.php'; // Autoload for PHP-ML and other dependencies

defined('ABSPATH') or die('Direct access is not allowed.');

// Enqueue GSAP for animations on the admin page
function acg_enqueue_admin_scripts($hook) {
    if ($hook !== 'toplevel_page_acg-admin-page') {
        return;
    }

    wp_enqueue_script('gsap', 'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.9.1/gsap.min.js', array(), '3.9.1', true);

    $training_samples = get_option('acg_training_samples', '[]');
    $samples_array = json_decode($training_samples, true) ?: []; // Ensure json_decode doesn't return null

    $keywords = !empty($samples_array) ? array_merge(...$samples_array) : [];
    $keyword_js_array = json_encode($keywords);
    
    wp_add_inline_script('gsap', "
        document.addEventListener('DOMContentLoaded', function() {
            var container = document.querySelector('.acg-animation-container');
            if (!container) {
                console.error('Element .acg-animation-container not found.');
                return;
            }

            var keywords = $keyword_js_array;
            var currentIndex = 0;

            function createAnimatedText() {
                var div = document.createElement('div');
                div.innerText = keywords[currentIndex];
                div.style.position = 'absolute';
                div.style.fontSize = (Math.random() * 20 + 20) + 'px';
                div.style.color = getRandomColor();
                div.style.top = (Math.random() * 80) + '%';
                div.style.left = (Math.random() * 80) + '%';
                container.appendChild(div);

                gsap.fromTo(div, {
                    opacity: 0,
                    x: Math.random() * 200 - 100,
                    y: Math.random() * 200 - 100,
                    scale: Math.random() * 0.5 + 0.5
                }, {
                    opacity: 1,
                    x: 0,
                    y: 0,
                    scale: 1,
                    duration: 2 + Math.random() * 2,
                    repeat: 1,
                    yoyo: true,
                    ease: 'power3.inOut',
                    onComplete: function() {
                        div.remove();
                        currentIndex = (currentIndex + 1) % keywords.length;
                        createAnimatedText();
                    }
                });
            }

            function getRandomColor() {
                var letters = '0123456789ABCDEF';
                var color = '#';
                for (var i = 0; i < 6; i++) {
                    color += letters[Math.floor(Math.random() * 16)];
                }
                return color;
            }

            createAnimatedText();  // Start the first animation
        });
    ");
}
add_action('admin_enqueue_scripts', 'acg_enqueue_admin_scripts');

// Setup the admin menu
function acg_admin_menu() {
    add_menu_page(
        'High Precision Content Generator',
        'Content Generator',
        'manage_options',
        'acg-admin-page',
        'acg_admin_page'
    );
    add_action('admin_init', 'acg_register_settings');
}
add_action('admin_menu', 'acg_admin_menu');

// Register plugin settings
function acg_register_settings() {
    register_setting('acg_settings_group', 'acg_openai_api_key');
    register_setting('acg_settings_group', 'acg_youtube_api_key');
    register_setting('acg_settings_group', 'acg_daily_generation_time', 'acg_time_change_callback');
    register_setting('acg_settings_group', 'acg_language', 'acg_sanitize_language');
    register_setting('acg_settings_group', 'acg_training_samples');
    register_setting('acg_settings_group', 'acg_training_labels');
    register_setting('acg_settings_group', 'acg_last_cron_log');
}

function acg_sanitize_language($value) {
    $valid_languages = ['en', 'de', 'it', 'fr'];
    return in_array($value, $valid_languages) ? $value : 'en';
}

function acg_time_change_callback($time) {
    acg_schedule_daily_content_generation(); // Reschedule when the time changes
    return $time;
}

// Admin page content
function acg_admin_page() {
    if (isset($_POST['topic'])) {
        if (!isset($_POST['acg_nonce']) || !wp_verify_nonce($_POST['acg_nonce'], 'acg_generate_content')) {
            die('Security check failed');
        }

        $topic = sanitize_text_field($_POST['topic']);
        $content_length = intval($_POST['content_length']);
        $formatting = sanitize_key($_POST['formatting']);

        $generated = acg_generate_content($topic, $content_length, $formatting, false);
        
        // Display success message based on selected language
        if ($generated) {
            $selected_language = get_option('acg_language', 'en');
            $texts = acg_get_texts($selected_language);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($texts['post_created_success_message']) . ' ' . esc_html($topic) . '</p></div>';
        }
    }

    $selected_language = get_option('acg_language', 'en');
    $texts = acg_get_texts($selected_language);
    $examples = acg_get_examples($selected_language);

    // Last Cron Log
    $last_cron_log = get_option('acg_last_cron_log', 'No cron logs available.');

    echo '<div class="wrap">';
    echo '<div class="acg-animation-container" style="position: relative; width: 100%; height: 300px;"></div>'; // Container for GSAP animations
    echo '<h1>' . esc_html($texts['plugin_name']) . '</h1>';

    // Display settings form
    echo '<h2>' . esc_html($texts['api_keys_scheduling']) . '</h2>';
    echo '<form method="POST" action="options.php">';
    settings_fields('acg_settings_group');
    do_settings_sections('acg_settings_group');
    echo '<table class="form-table">';
    echo '<tr valign="top">';
    echo '<th scope="row">' . esc_html($texts['openai_api_key']) . '</th>';
    echo '<td><input type="text" name="acg_openai_api_key" value="' . esc_attr(get_option('acg_openai_api_key')) . '" /></td>';
    echo '</tr>';
    echo '<tr valign="top">';
    echo '<th scope="row">' . esc_html($texts['youtube_api_key']) . '</th>';
    echo '<td><input type="text" name="acg_youtube_api_key" value="' . esc_attr(get_option('acg_youtube_api_key')) . '" /></td>';
    echo '</tr>';
    echo '<tr valign="top">';
    echo '<th scope="row">' . esc_html($texts['daily_generation_time']) . '</th>';
    echo '<td><input type="time" name="acg_daily_generation_time" value="' . esc_attr(get_option('acg_daily_generation_time', '07:00')) . '" /></td>';
    echo '</tr>';
    echo '<tr valign="top">';
    echo '<th scope="row">' . esc_html($texts['language']) . '</th>';
    echo '<td>';
    echo '<input type="radio" name="acg_language" value="en" ' . checked('en', $selected_language, false) . ' /> ' . esc_html($texts['english_language']) . '<br />';
    echo '<input type="radio" name="acg_language" value="de" ' . checked('de', $selected_language, false) . ' /> ' . esc_html($texts['german_language']) . '<br />';
    echo '<input type="radio" name="acg_language" value="it" ' . checked('it', $selected_language, false) . ' /> ' . esc_html($texts['italian_language']) . '<br />';
    echo '<input type="radio" name="acg_language" value="fr" ' . checked('fr', $selected_language, false) . ' /> ' . esc_html($texts['french_language']);
    echo '</td>';
    echo '</tr>';
    echo '<tr valign="top">';
    echo '<th scope="row">' . esc_html($texts['training_samples']) . '</th>';
    echo '<td>';
    echo '<textarea name="acg_training_samples" rows="10" cols="50">' . esc_textarea(get_option('acg_training_samples', $examples['training_samples'])) . '</textarea>';
    echo '<p><em>/* ' . esc_html($examples['training_samples_example']) . ' */</em></p>';
    echo '</td>';
    echo '</tr>';
    echo '<tr valign="top">';
    echo '<th scope="row">' . esc_html($texts['training_labels']) . '</th>';
    echo '<td>';
    echo '<textarea name="acg_training_labels" rows="10" cols="50">' . esc_textarea(get_option('acg_training_labels', $examples['training_labels'])) . '</textarea>';
    echo '<p><em>/* ' . esc_html($examples['training_labels_example']) . ' */</em></p>';
    echo '</td>';
    echo '</tr>';
    echo '<tr valign="top">';
    echo '<th scope="row">' . esc_html($texts['last_cron_log']) . '</th>';
    echo '<td>';
    echo '<textarea readonly rows="10" cols="50">' . esc_textarea($last_cron_log) . '</textarea>';
    echo '</td>';
    echo '</tr>';
    echo '</table>';
    submit_button();
    echo '</form>';

    // Countdown Timer for Next Scheduled Cron Run
    $next_cron_timestamp = wp_next_scheduled('acg_daily_content_generation_event');
    if ($next_cron_timestamp) {
        $next_cron_time = date('Y-m-d H:i:s', $next_cron_timestamp);
        echo '<h2>' . esc_html($texts['next_cron_run']) . '</h2>';
        echo '<div id="cron-countdown">' . esc_html($texts['time_remaining']) . ': <span id="countdown-timer"></span></div>';
        echo "<script>
            document.addEventListener('DOMContentLoaded', function () {
                var countdownDate = new Date('$next_cron_time').getTime();
                var x = setInterval(function() {
                    var now = new Date().getTime();
                    var distance = countdownDate - now;

                    var days = Math.floor(distance / (1000 * 60 * 60 * 24));
                    var hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                    var minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                    var seconds = Math.floor((distance % (1000 * 60)) / 1000);

                    document.getElementById('countdown-timer').innerHTML = days + 'd ' + hours + 'h ' 
                    + minutes + 'm ' + seconds + 's ';

                    if (distance < 0) {
                        clearInterval(x);
                        document.getElementById('countdown-timer').innerHTML = '" . esc_js($texts['cron_run_now']) . "';
                    }
                }, 1000);
            });
        </script>";
    } else {
        echo '<h2>' . esc_html($texts['next_cron_run']) . '</h2>';
        echo '<div id="cron-countdown">' . esc_html($texts['cron_not_scheduled']) . '</div>';
    }

    echo '<h2>' . esc_html($texts['generate_content']) . '</h2>';
    echo '<form method="POST">';
    wp_nonce_field('acg_generate_content', 'acg_nonce');
    echo '<p><label for="topic">' . esc_html($texts['enter_topic']) . ': </label><input type="text" name="topic" placeholder="' . esc_html($texts['enter_topic_placeholder']) . '" required /></p>';
    echo '<p><label for="content_length">' . esc_html($texts['content_length']) . ': </label><input type="number" name="content_length" value="50" min="10" max="200" /></p>';
    echo '<p><label for="formatting">' . esc_html($texts['formatting']) . ': </label><select name="formatting">';
    echo '<option value="default">' . esc_html($texts['format_default']) . '</option>';
    echo '<option value="bold">' . esc_html($texts['format_bold']) . '</option>';
    echo '<option value="italic">' . esc_html($texts['format_italic']) . '</option>';
    echo '</select></p>';
    echo '<p><input type="submit" value="' . esc_html($texts['generate_button']) . '" /></p>';
    echo '</form>';
    echo '</div>';
}

// Function to retrieve a random topic from WordPress tags, ensuring it hasn't already been used as a post title
function acg_get_random_topic() {
    $tags = get_terms(array(
        'taxonomy' => 'post_tag',
        'orderby' => 'count',
        'order' => 'DESC',
        'number' => 100,
        'hide_empty' => false // Include tags even if they are not linked to published posts
    ));

    if (empty($tags)) {
        error_log('ACG: No tags found in the WordPress database.');
        return false;
    }

    shuffle($tags);

    foreach ($tags as $tag) {
        if (!acg_post_exists_by_title($tag->name)) {
            return $tag->name;
        }
    }

    error_log('ACG: All tags have been used as post titles.');
    return false;
}

function acg_post_exists_by_title($title) {
    $existing_post = get_page_by_title($title, OBJECT, 'post');
    return $existing_post ? true : false;
}

// Schedule the daily content generation event using server time
function acg_schedule_daily_content_generation() {
    if (!wp_next_scheduled('acg_daily_content_generation_event')) {
        $daily_time = get_option('acg_daily_generation_time', '07:00');
        $hour = intval(substr($daily_time, 0, 2));
        $minute = intval(substr($daily_time, 3, 2));

        $timestamp = acg_get_scheduled_timestamp($hour, $minute);
        wp_schedule_event($timestamp, 'daily', 'acg_daily_content_generation_event');
    }
}

function acg_get_scheduled_timestamp($hour, $minute) {
    $current_timestamp = current_time('timestamp'); // Server time
    $scheduled_time = strtotime(date('Y-m-d', $current_timestamp) . " $hour:$minute:00");

    if ($scheduled_time <= $current_timestamp) {
        $scheduled_time = strtotime('+1 day', $scheduled_time);
    }

    return $scheduled_time;
}

// Action hook for the cron event
add_action('acg_daily_content_generation_event', 'acg_generate_daily_content');

// Generate daily content based on a random topic
function acg_generate_daily_content() {
    $log_entries = [];

    try {
        $log_entries[] = acg_get_text('cron_start') . ' ' . current_time('mysql');

        $generated = false;
        $attempts = 0;
        $max_attempts = 5; 

        while (!$generated && $attempts < $max_attempts) {
            $random_topic = acg_get_random_topic();
            if ($random_topic && !acg_post_exists_by_title($random_topic)) {
                acg_generate_content($random_topic, 50, 'default', true);
                $log_entries[] = acg_get_text('content_generated') . ' ' . $random_topic;
                $generated = true;
            } else {
                $attempts++;
            }
        }

        if (!$generated) {
            $error_message = acg_get_text('no_valid_tags');
            $log_entries[] = 'ACG: ' . $error_message;
        }

        $log_entries[] = acg_get_text('cron_end') . ' ' . current_time('mysql');
    } catch (Exception $e) {
        $log_entries[] = acg_get_text('cron_error') . ' ' . $e->getMessage();
    }

    update_option('acg_last_cron_log', implode("\n", $log_entries));
}

// Generate content
function acg_generate_content($topic, $content_length = 50, $formatting = 'default', $include_error_message = false) {
    try {
        if (acg_post_exists_by_title($topic)) {
            error_log('ACG: ' . acg_get_text('post_exists') . ' "' . $topic . '"');
            return false;
        }

        $content = '';
        $result = acg_get_generated_text($topic, $content_length);
        if ($result['success']) {
            $generated_text = $result['text'];
            switch ($formatting) {
                case 'bold':
                    $generated_text = '<strong>' . nl2br(esc_html($generated_text)) . '</strong>';
                    break;
                case 'italic':
                    $generated_text = '<em>' . nl2br(esc_html($generated_text)) . '</em>';
                    break;
                case 'default':
                default:
                    $generated_text = nl2br(esc_html($generated_text));
                    break;
            }
            $content .= $generated_text;
        } else {
            $error_message = acg_get_text('error_generating_content') . ': ' . esc_html($result['error']);
            error_log('ACG: ' . $error_message);
            $content .= '<p>' . $error_message . '</p>';
        }

        $youtube_video = acg_search_youtube_video($topic, $generated_text);
        if ($youtube_video) {
            $content .= '<br><div style="max-width: 782px; margin: 0 auto;">' . $youtube_video . '</div>';
        } else {
            $error_message = acg_get_text('no_matching_video');
            error_log('ACG: ' . $error_message);
            if ($include_error_message) {
                $content .= '<p>' . $error_message . '</p>';
            }
        }

        $image_url = acg_generate_dalle_image($topic, $generated_text);
        if ($image_url) {
            $content .= '<br><div style="max-width: 782px; margin: 0 auto;"><img src="' . esc_url($image_url) . '" alt="' . esc_attr($topic) . '" style="max-width: 100%; height: auto; display: block;"></div>';
        } else {
            $keywords = acg_expand_keywords($topic, $generated_text);
            foreach ($keywords as $keyword) {
                $image_url = acg_generate_dalle_image($keyword, $generated_text);
                if ($image_url) {
                    $content .= '<br><div style="max-width: 782px; margin: 0 auto;"><img src="' . esc_url($image_url) . '" alt="' . esc_attr($keyword) . '" style="max-width: 100%; height: auto; display: block;"></div>';
                    break;
                }
            }

            if (!$image_url) {
                $error_message = acg_get_text('no_matching_image');
                error_log('ACG: ' . $error_message);
                if ($include_error_message) {
                    $content .= '<p>' . $error_message . '</p>';
                }
            }
        }

        $post_id = wp_insert_post(array(
            'post_title'   => wp_strip_all_tags($topic),
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_author'  => acg_get_default_author_id(),
        ));

        if ($post_id) {
            return true;
        } else {
            return false;
        }
    } catch (Exception $e) {
        error_log('ACG: Exception during content generation - ' . $e->getMessage());
        return false;
    }
}

// Get default author ID
function acg_get_default_author_id() {
    $default_user = get_user_by('email', get_option('admin_email'));
    return $default_user ? $default_user->ID : 1; // Default to the first user (usually the admin)
}

// Retrieve text from OpenAI and ensure it does not break mid-sentence
function acg_get_generated_text($topic, $max_tokens = 50, $retries = 3) {
    $api_key = get_option('acg_openai_api_key');
    if (!$api_key) {
        return array('success' => false, 'error' => acg_get_text('api_key_missing'));
    }

    $selected_language = get_option('acg_language', 'en');
    $prompt_prefix = acg_get_prompt_prefix($selected_language);
    $prompt = $prompt_prefix . ' ' . $topic;

    $full_text = '';
    $total_tokens = 0;
    $max_attempts = $retries;

    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'body' => json_encode(array(
                'model' => 'gpt-3.5-turbo',
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => $prompt,
                    ),
                ),
                'max_tokens' => intval($max_tokens) + 50, 
                'temperature' => 0.7,
            )),
            'headers' => array(
                'Authorization' => 'Bearer ' . esc_attr($api_key),
                'Content-Type' => 'application/json',
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => acg_get_text('request_failed') . ': ' . $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code === 200) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body);

            if (isset($data->choices[0]->message->content)) {
                $full_text .= $data->choices[0]->message->content;
                $total_tokens += str_word_count($data->choices[0]->message->content);
                if ($total_tokens >= $max_tokens) {
                    break;
                }
            } else {
                return array('success' => false, 'error' => acg_get_text('content_generation_failed') . ': ' . $body);
            }
        } elseif ($status_code === 429) {
            sleep(pow(2, $attempt));
        } else {
            return array('success' => false, 'error' => acg_get_text('http_error_code') . ': ' . $status_code);
        }
    }

    $full_text = acg_ensure_complete_sentences($full_text);
    return array('success' => true, 'text' => $full_text);
}

function acg_get_prompt_prefix($language) {
    $prompts = array(
        'en' => 'Please write an interesting blog post about the topic:',
        'de' => 'Bitte schreiben Sie einen interessanten Blogbeitrag über das Thema:',
        'it' => 'Si prega di scrivere un interessante post sul blog sul tema:',
        'fr' => 'Veuillez écrire un article de blog intéressant sur le sujet :'
    );
    return isset($prompts[$language]) ? $prompts[$language] : $prompts['en'];
}

function acg_ensure_complete_sentences($text) {
    $sentences = preg_split('/([.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
    $complete_text = '';

    for ($i = 0; $i < count($sentences) - 1; $i += 2) {
        $complete_text .= $sentences[$i] . $sentences[$i + 1] . ' ';
    }

    return trim($complete_text);
}

function acg_expand_keywords($topic, $context_text) {
    $keywords = [$topic];
    $additional_keywords = acg_perform_semantic_analysis($topic, $context_text);
    return array_unique(array_merge($keywords, $additional_keywords));
}

function acg_perform_semantic_analysis($topic, $context_text) {
    $training_samples = get_option('acg_training_samples', '[]');
    $training_samples = json_decode($training_samples, true) ?: []; // Fix: Ensure json_decode doesn't pass null
    $expanded_keywords = [];

    foreach ($training_samples as $sample) {
        foreach ($sample as $keyword) {
            if (stripos($topic, $keyword) !== false || stripos($context_text, $keyword) !== false) {
                $expanded_keywords = array_merge($expanded_keywords, $sample);
                break;
            }
        }
    }

    return $expanded_keywords;
}

// Function to search YouTube for a more relevant video
function acg_search_youtube_video($topic, $context_text) {
    $api_key = get_option('acg_youtube_api_key');
    if (!$api_key) {
        return false;
    }

    $expanded_keywords = acg_expand_keywords($topic, $context_text);
    foreach ($expanded_keywords as $keyword) {
        $search_query = urlencode($keyword);
        $url = "https://www.googleapis.com/youtube/v3/search?part=snippet&q=$search_query&key=$api_key&type=video&order=relevance&maxResults=1";

        $response = wp_remote_get($url, array('timeout' => 15));

        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body);

            if (!empty($data->items)) {
                $video_id = $data->items[0]->id->videoId;
                return '<iframe width="560" height="315" src="https://www.youtube.com/embed/' . esc_attr($video_id) . '" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
            }
        }
    }

    return false;
}

// Generate related image using DALL-E 3
function acg_generate_dalle_image($topic, $context_text = '') {
    $api_key = get_option('acg_openai_api_key');
    if (!$api_key) {
        return false;
    }

    $expanded_keywords = acg_expand_keywords($topic, $context_text);
    $selected_language = get_option('acg_language', 'en');
    $prompt_prefix = acg_get_image_prompt_prefix($selected_language);
    $prompt = $prompt_prefix . ' ' . implode(', ', $expanded_keywords) . '. Context: ' . $context_text;

    if (strlen($prompt) > 400) {
        $prompt = substr($prompt, 0, 400);
    }

    return acg_attempt_image_generation($prompt, $api_key);
}

// Function to attempt image generation with cURL
function acg_attempt_image_generation($prompt, $api_key) {
    $response_file = tempnam(sys_get_temp_dir(), 'dalle_response_');

    $command = "curl -s -X POST https://api.openai.com/v1/images/generations "
        . "-H 'Content-Type: application/json' "
        . "-H 'Authorization: Bearer $api_key' "
        . "-d '{\"model\": \"dall-e-3\", \"prompt\": \"$prompt\", \"n\": 1, \"size\": \"1024x1024\", \"response_format\": \"b64_json\"}' "
        . "> $response_file";

    exec($command);

    $response_data = file_get_contents($response_file);
    unlink($response_file);

    $response_json = json_decode($response_data, true);
    if (!empty($response_json['data'][0]['b64_json'])) {
        return acg_save_image_to_media_library($response_json['data'][0]['b64_json'], $prompt);
    }

    return false;
}

// Save base64 image to WordPress media library
function acg_save_image_to_media_library($base64_image, $filename) {
    $upload_dir = wp_upload_dir();

    // Abbreviate filename to a maximum of 10 characters, and append a unique identifier
    $abbreviated_filename = substr(sanitize_file_name($filename), 0, 10) . '-' . uniqid() . '.png';

    $file_path = $upload_dir['path'] . '/' . $abbreviated_filename;

    $decoded_image = base64_decode($base64_image);
    if (file_put_contents($file_path, $decoded_image)) {
        $attachment = array(
            'post_mime_type' => 'image/png',
            'post_title' => sanitize_file_name($abbreviated_filename),
            'post_content' => '',
            'post_status' => 'inherit',
        );

        $attachment_id = wp_insert_attachment($attachment, $file_path);
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attachment_data = wp_generate_attachment_metadata($attachment_id, $file_path);
        wp_update_attachment_metadata($attachment_id, $attachment_data);

        return wp_get_attachment_url($attachment_id);
    }

    return false;
}

function acg_get_image_prompt_prefix($language) {
    $prompts = array(
        'en' => 'Generate a related image for the topic:',
        'de' => 'Erstellen Sie ein passendes Bild für das Thema:',
        'it' => 'Genera un’immagine correlata per l’argomento:',
        'fr' => 'Générez une image liée au sujet :'
    );
    return isset($prompts[$language]) ? $prompts[$language] : $prompts['en'];
}

// Function to return translated texts based on selected language
function acg_get_text($key) {
    $selected_language = get_option('acg_language', 'en');
    $texts = acg_get_texts($selected_language);
    return isset($texts[$key]) ? $texts[$key] : $key;
}

function acg_get_texts($language) {
    $texts_en = array(
        'plugin_name' => 'High Precision Content Generator',
        'api_keys_scheduling' => 'API Keys and Scheduling',
        'openai_api_key' => 'OpenAI API Key',
        'youtube_api_key' => 'YouTube API Key',
        'daily_generation_time' => 'Daily Content Generation Time (Server Time)',
        'language' => 'Language',
        'english_language' => 'English Language',
        'german_language' => 'German Language',
        'italian_language' => 'Italian Language',
        'french_language' => 'French Language',
        'scheduling_error' => 'Scheduling Error',
        'generate_content' => 'Generate Content',
        'enter_topic' => 'Enter a topic',
        'enter_topic_placeholder' => 'Enter a topic',
        'content_length' => 'Content Length (number of words)',
        'formatting' => 'Formatting',
        'format_default' => 'Default',
        'format_bold' => 'Bold',
        'format_italic' => 'Italic',
        'generate_button' => 'Generate Content',
        'no_valid_tags' => 'No valid tags found to generate content.',
        'post_exists' => 'A post with the title already exists.',
        'error_generating_content' => 'Error generating content',
        'no_matching_video' => 'Sorry, no matching video found.',
        'no_matching_image' => 'Sorry, no matching image found.',
        'post_created' => 'Post created successfully with ID',
        'post_created_success_message' => 'Content created successfully for topic:',
        'post_creation_failed' => 'Failed to create post.',
        'api_key_missing' => 'API key missing.',
        'prompt_prefix' => 'Please write an interesting blog post about the topic:',
        'image_prompt_prefix' => 'Generate a related image for the topic:',
        'request_failed' => 'Request failed',
        'content_generation_failed' => 'Content generation failed',
        'rate_limit_exceeded' => 'Rate limit exceeded, retrying...',
        'http_error_code' => 'HTTP error code',
        'training_samples' => 'Training Samples (JSON format)',
        'training_labels' => 'Training Labels (JSON format)',
        'last_cron_log' => 'Last Cron Job Log',
        'cron_start' => 'Cron job started at',
        'cron_end' => 'Cron job ended at',
        'cron_error' => 'Error occurred during cron job',
        'content_generated' => 'Content generated for topic',
        'next_cron_run' => 'Next Scheduled Cron Run',
        'time_remaining' => 'Time Remaining',
        'cron_run_now' => 'Running Now...',
        'cron_not_scheduled' => 'No cron job scheduled.',
        
        // Premium License Texts
        'premium_licenses' => 'Premium Licenses',
        'choose_plan' => 'Choose a licensing plan that works best for you.',
        'bronze_plan' => 'Bronze Plan',
        'silver_plan' => 'Silver Plan',
        'gold_plan' => 'Gold Plan',
        'platinum_plan' => 'Platinum Plan',
        '500_posts_plan' => '500 Blog Posts Plan',
        'lifetime' => 'lifetime',
        'bronze_features' => '1 License, 500 Blog Posts, Support, 1-year License Transfer',
        'silver_features' => '3 Licenses, 500 Blog Posts per License, Support, 1-year License Transfer',
        'gold_features' => '5 Licenses, 500 Blog Posts per License, Support, 1-year License Transfer',
        'platinum_features' => '10 Licenses, 500 Blog Posts per License, Support, 1-year License Transfer',
        '500_posts_features' => 'One-time purchase, 500 Blog Posts, Full Support',
        'buy_now' => 'Buy Now',
        'product_preview' => 'Product Preview',
        'one_time' => 'One-time'
    );

    $texts_de = array(
        'plugin_name' => 'Hochpräziser Inhaltsersteller',
        'api_keys_scheduling' => 'API-Schlüssel und Zeitplan',
        'openai_api_key' => 'OpenAI API-Schlüssel',
        'youtube_api_key' => 'YouTube API-Schlüssel',
        'daily_generation_time' => 'Tägliche Inhaltserstellungszeit (Serverzeit)',
        'language' => 'Sprache',
        'english_language' => 'Englische Sprache',
        'german_language' => 'Deutsche Sprache',
        'italian_language' => 'Italienische Sprache',
        'french_language' => 'Französische Sprache',
        'generate_content' => 'Inhalt generieren',
        'enter_topic' => 'Geben Sie ein Thema ein',
        'enter_topic_placeholder' => 'Geben Sie ein Thema ein',
        'content_length' => 'Inhaltslänge (Anzahl der Wörter)',
        'formatting' => 'Formatierung',
        'format_default' => 'Standard',
        'format_bold' => 'Fett',
        'format_italic' => 'Kursiv',
        'generate_button' => 'Inhalt generieren',
        'no_valid_tags' => 'Keine gültigen Tags gefunden, um Inhalte zu erstellen.',
        'post_exists' => 'Ein Beitrag mit diesem Titel existiert bereits.',
        'error_generating_content' => 'Fehler bei der Inhaltserstellung',
        'no_matching_video' => 'Leider kein passendes Video gefunden.',
        'no_matching_image' => 'Leider kein passendes Bild gefunden.',
        'post_created' => 'Beitrag erfolgreich erstellt mit ID',
        'post_created_success_message' => 'Inhalt erfolgreich für das Thema erstellt:',
        'post_creation_failed' => 'Beitrag konnte nicht erstellt werden.',
        'api_key_missing' => 'API-Schlüssel fehlt.',
        'prompt_prefix' => 'Bitte schreiben Sie einen interessanten Blogbeitrag über das Thema:',
        'image_prompt_prefix' => 'Erstellen Sie ein passendes Bild für das Thema:',
        'request_failed' => 'Anfrage fehlgeschlagen',
        'content_generation_failed' => 'Inhaltserstellung fehlgeschlagen',
        'rate_limit_exceeded' => 'Ratenlimit überschritten, versuche erneut...',
        'http_error_code' => 'HTTP-Fehlercode',
        'training_samples' => 'Trainingsbeispiele (JSON-Format)',
        'training_labels' => 'Trainingslabels (JSON-Format)',
        'last_cron_log' => 'Letztes Cron-Job-Protokoll',
        'cron_start' => 'Cron-Job gestartet um',
        'cron_end' => 'Cron-Job beendet um',
        'cron_error' => 'Fehler beim Cron-Job aufgetreten',
        'content_generated' => 'Inhalt generiert für das Thema',
        'next_cron_run' => 'Nächster geplanter Cron-Lauf',
        'time_remaining' => 'Verbleibende Zeit',
        'cron_run_now' => 'Jetzt laufen...',
        'cron_not_scheduled' => 'Kein Cron-Job geplant.',
        
        // Premium License Texts
        'premium_licenses' => 'Premium-Lizenzen',
        'choose_plan' => 'Wählen Sie den Lizenzplan, der am besten zu Ihnen passt.',
        'bronze_plan' => 'Bronze-Plan',
        'silver_plan' => 'Silber-Plan',
        'gold_plan' => 'Gold-Plan',
        'platinum_plan' => 'Platin-Plan',
        '500_posts_plan' => '500 Blog-Beiträge Plan',
        'lifetime' => 'lebenslang',
        'bronze_features' => '1 Lizenz, 500 Blog-Beiträge, Unterstützung, 1 Jahr Lizenzübertragung',
        'silver_features' => '3 Lizenzen, 500 Blog-Beiträge pro Lizenz, Unterstützung, 1 Jahr Lizenzübertragung',
        'gold_features' => '5 Lizenzen, 500 Blog-Beiträge pro Lizenz, Unterstützung, 1 Jahr Lizenzübertragung',
        'platinum_features' => '10 Lizenzen, 500 Blog-Beiträge pro Lizenz, Unterstützung, 1 Jahr Lizenzübertragung',
        '500_posts_features' => 'Einmaliger Kauf, 500 Blog-Beiträge, Voller Support',
        'buy_now' => 'Jetzt kaufen',
        'product_preview' => 'Produktvorschau',
        'one_time' => 'Einmalig'
    );

    $texts_it = array(
        'plugin_name' => 'Generatore di contenuti ad alta precisione',
        'api_keys_scheduling' => 'Chiavi API e pianificazione',
        'openai_api_key' => 'Chiave API OpenAI',
        'youtube_api_key' => 'Chiave API YouTube',
        'daily_generation_time' => 'Orario di generazione dei contenuti giornalieri (ora del server)',
        'language' => 'Lingua',
        'english_language' => 'Lingua inglese',
        'german_language' => 'Lingua tedesca',
        'italian_language' => 'Lingua italiana',
        'french_language' => 'Lingua francese',
        'generate_content' => 'Genera contenuti',
        'enter_topic' => 'Inserisci un argomento',
        'enter_topic_placeholder' => 'Inserisci un argomento',
        'content_length' => 'Lunghezza del contenuto (numero di parole)',
        'formatting' => 'Formattazione',
        'format_default' => 'Predefinito',
        'format_bold' => 'Grassetto',
        'format_italic' => 'Corsivo',
        'generate_button' => 'Genera contenuti',
        'no_valid_tags' => 'Nessun tag valido trovato per generare contenuti.',
        'post_exists' => 'Un post con questo titolo esiste già.',
        'error_generating_content' => 'Errore nella generazione dei contenuti',
        'no_matching_video' => 'Spiacente, nessun video corrispondente trovato.',
        'no_matching_image' => 'Spiacente, nessuna immagine corrispondente trovata.',
        'post_created' => 'Post creato con successo con ID',
        'post_created_success_message' => 'Contenuto creato con successo per l\'argomento:',
        'post_creation_failed' => 'Impossibile creare il post.',
        'api_key_missing' => 'Chiave API mancante.',
        'prompt_prefix' => 'Si prega di scrivere un interessante post sul blog sul tema:',
        'image_prompt_prefix' => 'Genera un’immagine correlata per l’argomento:',
        'request_failed' => 'Richiesta fallita',
        'content_generation_failed' => 'Generazione di contenuti fallita',
        'rate_limit_exceeded' => 'Limite di velocità superato, ritentare...',
        'http_error_code' => 'Codice di errore HTTP',
        'training_samples' => 'Esempi di formazione (formato JSON)',
        'training_labels' => 'Etichette di formazione (formato JSON)',
        'last_cron_log' => 'Ultimo registro del cron job',
        'cron_start' => 'Cron job iniziato alle',
        'cron_end' => 'Cron job terminato alle',
        'cron_error' => 'Errore durante il cron job',
        'content_generated' => 'Contenuto generato per argomento',
        'next_cron_run' => 'Prossima esecuzione pianificata del Cron',
        'time_remaining' => 'Tempo rimanente',
        'cron_run_now' => 'In esecuzione ora...',
        'cron_not_scheduled' => 'Nessun lavoro cron programmato.',
        
        // Premium License Texts
        'premium_licenses' => 'Licenze Premium',
        'choose_plan' => 'Scegli il piano di licenza che funziona meglio per te.',
        'bronze_plan' => 'Piano Bronzo',
        'silver_plan' => 'Piano Argento',
        'gold_plan' => 'Piano Oro',
        'platinum_plan' => 'Piano Platino',
        '500_posts_plan' => 'Piano da 500 Post sul Blog',
        'lifetime' => 'a vita',
        'bronze_features' => '1 Licenza, 500 Post sul Blog, Supporto, Trasferimento Licenza di 1 anno',
        'silver_features' => '3 Licenze, 500 Post sul Blog per Licenza, Supporto, Trasferimento Licenza di 1 anno',
        'gold_features' => '5 Licenze, 500 Post sul Blog per Licenza, Supporto, Trasferimento Licenza di 1 anno',
        'platinum_features' => '10 Licenze, 500 Post sul Blog per Licenza, Supporto, Trasferimento Licenza di 1 anno',
        '500_posts_features' => 'Acquisto singolo, 500 Post sul Blog, Supporto completo',
        'buy_now' => 'Acquista ora',
        'product_preview' => 'Anteprima del prodotto',
        'one_time' => 'Una tantum'
    );

    $texts_fr = array(
        'plugin_name' => 'Générateur de contenu de haute précision',
        'api_keys_scheduling' => 'Clés API et planification',
        'openai_api_key' => 'Clé API OpenAI',
        'youtube_api_key' => 'Clé API YouTube',
        'daily_generation_time' => 'Heure de génération de contenu quotidienne (heure du serveur)',
        'language' => 'Langue',
        'english_language' => 'Langue anglaise',
        'german_language' => 'Langue allemande',
        'italian_language' => 'Langue italienne',
        'french_language' => 'Langue française',
        'generate_content' => 'Générer du contenu',
        'enter_topic' => 'Entrez un sujet',
        'enter_topic_placeholder' => 'Entrez un sujet',
        'content_length' => 'Longueur du contenu (nombre de mots)',
        'formatting' => 'Formatage',
        'format_default' => 'Défaut',
        'format_bold' => 'Gras',
        'format_italic' => 'Italique',
        'generate_button' => 'Générer du contenu',
        'no_valid_tags' => 'Aucun tag valide trouvé pour générer du contenu.',
        'post_exists' => 'Un article avec ce titre existe déjà.',
        'error_generating_content' => 'Erreur lors de la génération du contenu',
        'no_matching_video' => 'Désolé, aucune vidéo correspondante trouvée.',
        'no_matching_image' => 'Désolé, aucune image correspondante trouvée.',
        'post_created' => 'Post créé avec succès avec ID',
        'post_created_success_message' => 'Contenu créé avec succès pour le sujet:',
        'post_creation_failed' => 'Échec de la création du post.',
        'api_key_missing' => 'Clé API manquante.',
        'prompt_prefix' => 'Veuillez écrire un article de blog intéressant sur le sujet :',
        'image_prompt_prefix' => 'Générez une image liée au sujet :',
        'request_failed' => 'Échec de la demande',
        'content_generation_failed' => 'Échec de la génération de contenu',
        'rate_limit_exceeded' => 'Limite de débit dépassée, nouvelle tentative...',
        'http_error_code' => 'Code d\'erreur HTTP',
        'training_samples' => 'Exemples de formation (format JSON)',
        'training_labels' => 'Étiquettes de formation (format JSON)',
        'last_cron_log' => 'Dernier journal des tâches Cron',
        'cron_start' => 'Tâche Cron commencée à',
        'cron_end' => 'Tâche Cron terminée à',
        'cron_error' => 'Erreur survenue pendant la tâche Cron',
        'content_generated' => 'Contenu généré pour le sujet',
        'next_cron_run' => 'Prochaine exécution planifiée du Cron',
        'time_remaining' => 'Temps restant',
        'cron_run_now' => 'En cours d\'exécution...',
        'cron_not_scheduled' => 'Aucun cron job programmé.',
        
        // Premium License Texts
        'premium_licenses' => 'Licences Premium',
        'choose_plan' => 'Choisissez un plan de licence adapté à vos besoins.',
        'bronze_plan' => 'Plan Bronze',
        'silver_plan' => 'Plan Argent',
        'gold_plan' => 'Plan Or',
        'platinum_plan' => 'Plan Platine',
        '500_posts_plan' => 'Plan 500 Articles de Blog',
        'lifetime' => 'à vie',
        'bronze_features' => '1 Licence, 500 Articles de Blog, Support, Transfert de Licence d\'un an',
        'silver_features' => '3 Licences, 500 Articles de Blog par Licence, Support, Transfert de Licence d\'un an',
        'gold_features' => '5 Licences, 500 Articles de Blog par Licence, Support, Transfert de Licence d\'un an',
        'platinum_features' => '10 Licences, 500 Articles de Blog par Licence, Support, Transfert de Licence d\'un an',
        '500_posts_features' => 'Achat unique, 500 Articles de Blog, Support complet',
        'buy_now' => 'Achetez maintenant',
        'product_preview' => 'Aperçu du produit',
        'one_time' => 'Une fois'
    );

    switch ($language) {
        case 'de':
            return $texts_de;
        case 'it':
            return $texts_it;
        case 'fr':
            return $texts_fr;
        default:
            return $texts_en;
    }
}

// Add a new tab for "Premium" in the admin menu
function acg_premium_menu() {
    add_submenu_page(
        'acg-admin-page',      // Parent slug
        __('Premium Licenses', 'acg'),    // Page title
        __('Premium', 'acg'),             // Menu title
        'manage_options',      // Capability
        'acg-premium-page',    // Menu slug
        'acg_premium_page'     // Callback function
    );
}
add_action('admin_menu', 'acg_premium_menu');

// Display the content of the "Premium" page
function acg_premium_page() {
    // Get the selected language for translations
    $selected_language = get_option('acg_language', 'en');
    $texts = acg_get_texts($selected_language);

    // Define YouTube links for product previews in each language
    $preview_links = array(
        'en' => 'https://youtu.be/2FmzrAwwICQ',
        'it' => 'https://youtu.be/AdFfIgM0NCU',
        'de' => 'https://youtu.be/nrhY8htcoZU',
        'fr' => 'https://youtu.be/TvrVLrGY-4k',
    );

    $preview_link = isset($preview_links[$selected_language]) ? $preview_links[$selected_language] : $preview_links['en'];
    ?>
    <div class="wrap">
        <h1><?php esc_html_e($texts['premium_licenses'], 'acg'); ?></h1>
        <p><?php esc_html_e($texts['choose_plan'], 'acg'); ?></p>

    <div class="acg-license-options">
        <!-- Bronze License Box -->
        <div class="acg-license-box" style="background-color: #cd7f32;">
            <img src="https://gancpt.at/wordpress/wp-content/uploads/2024/10/acg-590-300.png" class="acg-thumbnail" alt="Bronze Plan Thumbnail">
            <h2><?php esc_html_e($texts['bronze_plan'], 'acg'); ?></h2>
            <p>$49.99 / <?php esc_html_e($texts['lifetime'], 'acg'); ?></p>
            <ul>
                <li><?php esc_html_e($texts['bronze_features'], 'acg'); ?></li>
            </ul>
            <a href="<?php echo esc_url($preview_link); ?>" class="acg-preview-button" target="_blank"><?php esc_html_e($texts['product_preview'], 'acg'); ?></a>
            <a href="https://www.gancpt.at/wordpress/home/auto-content-generator/" class="acg-license-button">
                <?php esc_html_e($texts['buy_now'], 'acg'); ?>
            </a>
        </div>

        <!-- Silver License Box -->
        <div class="acg-license-box" style="background-color: #C0C0C0;">
            <img src="https://gancpt.at/wordpress/wp-content/uploads/2024/10/acg-590-300.png" class="acg-thumbnail" alt="Silver Plan Thumbnail">
            <h2><?php esc_html_e($texts['silver_plan'], 'acg'); ?></h2>
            <p>$125.00 / <?php esc_html_e($texts['lifetime'], 'acg'); ?></p>
            <ul>
                <li><?php esc_html_e($texts['silver_features'], 'acg'); ?></li>
            </ul>
            <a href="<?php echo esc_url($preview_link); ?>" class="acg-preview-button" target="_blank"><?php esc_html_e($texts['product_preview'], 'acg'); ?></a>
            <a href="https://www.gancpt.at/wordpress/home/auto-content-generator/" class="acg-license-button">
                <?php esc_html_e($texts['buy_now'], 'acg'); ?>
            </a>
        </div>

        <!-- Gold License Box -->
        <div class="acg-license-box" style="background-color: #FFD700;">
            <img src="https://gancpt.at/wordpress/wp-content/uploads/2024/10/acg-590-300.png" class="acg-thumbnail" alt="Gold Plan Thumbnail">
            <h2><?php esc_html_e($texts['gold_plan'], 'acg'); ?></h2>
            <p>$175.00 / <?php esc_html_e($texts['lifetime'], 'acg'); ?></p>
            <ul>
                <li><?php esc_html_e($texts['gold_features'], 'acg'); ?></li>
            </ul>
            <a href="<?php echo esc_url($preview_link); ?>" class="acg-preview-button" target="_blank"><?php esc_html_e($texts['product_preview'], 'acg'); ?></a>
            <a href="https://www.gancpt.at/wordpress/home/auto-content-generator/" class="acg-license-button">
                <?php esc_html_e($texts['buy_now'], 'acg'); ?>
            </a>
        </div>

        <!-- Platinum License Box -->
        <div class="acg-license-box" style="background-color: #E5E4E2;">
            <img src="https://gancpt.at/wordpress/wp-content/uploads/2024/10/acg-590-300.png" class="acg-thumbnail" alt="Platinum Plan Thumbnail">
            <h2><?php esc_html_e($texts['platinum_plan'], 'acg'); ?></h2>
            <p>$300.00 / <?php esc_html_e($texts['lifetime'], 'acg'); ?></p>
            <ul>
                <li><?php esc_html_e($texts['platinum_features'], 'acg'); ?></li>
            </ul>
            <a href="<?php echo esc_url($preview_link); ?>" class="acg-preview-button" target="_blank"><?php esc_html_e($texts['product_preview'], 'acg'); ?></a>
            <a href="https://www.gancpt.at/wordpress/home/auto-content-generator/" class="acg-license-button">
                <?php esc_html_e($texts['buy_now'], 'acg'); ?>
            </a>
        </div>
        
        <!-- 500 Blog Post Plan -->
            <div class="acg-license-box" style="background-color: #4CAF50;">
                <img src="https://gancpt.at/wordpress/wp-content/uploads/2024/10/acg-590-300.png" class="acg-thumbnail" alt="500 Blog Posts Thumbnail">
                <h2><?php esc_html_e($texts['500_posts_plan'], 'acg'); ?></h2>
                <p>$69.99 / <?php esc_html_e($texts['one_time'], 'acg'); ?></p>
                <ul>
                    <li><?php esc_html_e($texts['500_posts_features'], 'acg'); ?></li>
                </ul>
                <a href="<?php echo esc_url($preview_link); ?>" class="acg-preview-button" target="_blank"><?php esc_html_e($texts['product_preview'], 'acg'); ?></a>
                <a href="https://www.gancpt.at/wordpress/home/auto-content-generator/" class="acg-license-button">
                    <?php esc_html_e($texts['buy_now'], 'acg'); ?>
                </a>
            </div>
    </div>

<style>
    .acg-license-options {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
    }
    
    .acg-license-box {
        padding: 20px;
        border-radius: 12px;
        box-shadow: 0 6px 12px rgba(0, 0, 0, 0.1);
        color: #333;
        background-color: #f9f9f9;
        flex-basis: 22%;
        display: flex;
        flex-direction: column;
        align-items: center;
        transition: transform 0.3s, box-shadow 0.3s;
    }

    .acg-license-box:hover {
        transform: scale(1.05);
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15);
    }

    .acg-license-box h2 {
        margin-bottom: 15px;
        font-size: 1.5rem;
        color: inherit;
    }

    .acg-license-box ul {
        padding-left: 20px;
        margin-bottom: 20px;
        list-style-type: disc;
        color: inherit;
    }

    .acg-license-box ul li {
        margin-bottom: 5px;
    }

    .acg-preview-button,
    .acg-license-button {
        background-color: #0073aa;
        color: white;
        padding: 10px 20px;
        margin-bottom: 15px;
        border-radius: 4px;
        font-weight: bold;
        text-decoration: none;
        transition: background-color 0.3s ease;
    }

    .acg-preview-button:hover,
    .acg-license-button:hover {
        background-color: #005f87;
        text-decoration: underline;
        color: white; /* Keeps color the same on hover */
    }
</style>


    <?php
}

// Function to return language-specific examples for training samples and labels
function acg_get_examples($language) {
    $examples_en = array(
        'training_samples' => '[["AI", "Revolutionary Developments", "Artificial Intelligence"], ["AI", "Stock Trends", "AI Stocks", "2025"]]',
        'training_labels' => '["Revolutionary AI Developments", "AI Stock Trends"]',
        'training_samples_example' => 'Example: [["AI", "Revolutionary Developments", "Artificial Intelligence"], ["AI", "Stock Trends", "AI Stocks", "2025"]]',
        'training_labels_example' => 'Example: ["Revolutionary AI Developments", "AI Stock Trends"]'
    );

    $examples_de = array(
        'training_samples' => '[["KI", "Revolutionäre Entwicklungen", "Künstliche Intelligenz"], ["KI", "Aktientrends", "KI-Aktien", "2025"]]',
        'training_labels' => '["Revolutionäre Entwicklungen der KI", "Aktientrends der KI"]',
        'training_samples_example' => 'Beispiel: [["KI", "Revolutionäre Entwicklungen", "Künstliche Intelligenz"], ["KI", "Aktientrends", "KI-Aktien", "2025"]]',
        'training_labels_example' => 'Beispiel: ["Revolutionäre Entwicklungen der KI", "Aktientrends der KI"]'
    );

    $examples_it = array(
        'training_samples' => '[["IA", "Sviluppi rivoluzionari", "Intelligenza artificiale"], ["IA", "Tendenze di mercato", "Azioni IA", "2025"]]',
        'training_labels' => '["Sviluppi rivoluzionari di IA", "Tendenze di mercato delle azioni IA"]',
        'training_samples_example' => 'Esempio: [["IA", "Sviluppi rivoluzionari", "Intelligenza artificiale"], ["IA", "Tendenze di mercato", "Azioni IA", "2025"]]',
        'training_labels_example' => 'Esempio: ["Sviluppi rivoluzionari di IA", "Tendenze di mercato delle azioni IA"]'
    );

    $examples_fr = array(
        'training_samples' => '[["IA", "Développements révolutionnaires", "Intelligence artificielle"], ["IA", "Tendances boursières", "Actions IA", "2025"]]',
        'training_labels' => '["Développements révolutionnaires de l\'IA", "Tendances boursières de l\'IA"]',
        'training_samples_example' => 'Exemple: [["IA", "Développements révolutionnaires", "Intelligence artificielle"], ["IA", "Tendances boursières", "Actions IA", "2025"]]',
        'training_labels_example' => 'Exemple: ["Développements révolutionnaires de l\'IA", "Tendances boursières de l\'IA"]'
    );

    switch ($language) {
        case 'de':
            return $examples_de;
        case 'it':
            return $examples_it;
        case 'fr':
            return $examples_fr;
        default:
            return $examples_en;
    }
}
