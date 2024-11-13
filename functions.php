<?php
// Shortcode for Connect/Remove Connection Button with logged-out notification
function toggle_connection_button_shortcode() {
    $profile_user_id = get_queried_object_id();
    $current_user_id = get_current_user_id();

    // Check if user is logged in
    $is_logged_in = is_user_logged_in();

    // Check if a connection already exists (if user is logged in)
    $is_connected = false;
    if ($is_logged_in) {
        $connections = get_user_meta($current_user_id, 'connections', true);
        $is_connected = is_array($connections) && in_array($profile_user_id, $connections);
    }

    // Set button label based on connection status
    $button_label = $is_connected ? "Remove Connection" : "Connect";

    // Output button with JavaScript
    ob_start();
    ?>
    <button class="uk-button uk-button-primary" id="toggle-connection-button"
            onclick="<?php echo $is_logged_in ? "toggleConnection($profile_user_id)" : "notifyLoginRequired('Connect')" ?>">
        <?php echo esc_html($button_label); ?>
    </button>

    <script>
    function notifyLoginRequired(action) {
        UIkit.notification({message: `Only logged in users can ${action.toLowerCase()}.`, status: 'warning'});
    }

    function toggleConnection(profileUserId) {
        jQuery.ajax({
            url: '<?php echo admin_url('admin-ajax.php'); ?>',
            type: 'POST',
            data: {
                action: 'toggle_user_connection',
                profile_user_id: profileUserId
            },
            success: function(response) {
                if (response.success && response.data && response.data.new_label) {
                    jQuery("#toggle-connection-button").text(response.data.new_label);
                } else {
                    alert(response.data.message || "Unexpected response format.");
                }
            },
            error: function() {
                alert("An error occurred while processing the request.");
            }
        });
    }
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('toggle_connection', 'toggle_connection_button_shortcode');


// Handle AJAX request for toggling connection with response format
function handle_toggle_connection() {
    if (!is_user_logged_in() || empty($_POST['profile_user_id'])) {
        wp_send_json_error(['message' => 'Invalid request.']);
        return;
    }

    $profile_user_id = intval($_POST['profile_user_id']);
    $current_user_id = get_current_user_id();

    $connections = get_user_meta($current_user_id, 'connections', true);
    if (!$connections || !is_array($connections)) {
        $connections = [];
    }

    if (in_array($profile_user_id, $connections)) {
        $connections = array_diff($connections, [$profile_user_id]);
        $new_label = "Connect";
    } else {
        $connections[] = $profile_user_id;
        $new_label = "Remove Connection";
    }

    update_user_meta($current_user_id, 'connections', $connections);

    wp_send_json_success(['new_label' => $new_label]);
}
add_action('wp_ajax_toggle_user_connection', 'handle_toggle_connection');



//////////////////////////////////////////////////////////////////////////////


function send_message_button_shortcode() {
    // Get current user information
    $current_user = wp_get_current_user();
    $current_user_name = $current_user->display_name;
    $is_logged_in = is_user_logged_in();

    // Generate the button and modal markup
    ob_start();
    ?>

    <!-- Button to trigger modal -->
    <button class="uk-button uk-button-primary"
            onclick="<?php echo $is_logged_in ? "UIkit.modal('#send-message-modal').show()" : "notifyLoginRequired('send messages')" ?>">
        Send a Message
    </button>

    <!-- Modal Structure (UIkit) -->
    <div id="send-message-modal" uk-modal>
        <div class="uk-modal-dialog uk-modal-body">
            <h2 class="uk-modal-title">Send a Message</h2>

            <!-- Display sender's name -->
            <div class="uk-margin">
                <label class="uk-form-label">From:</label>
                <input class="uk-input" type="text" value="<?php echo esc_attr($current_user_name); ?>" readonly>
            </div>

            <!-- Subject Field -->
            <div class="uk-margin">
                <label class="uk-form-label" for="message-subject">Subject</label>
                <input class="uk-input" id="message-subject" type="text" placeholder="Enter the subject">
            </div>

            <!-- Message Textarea -->
            <div class="uk-margin">
                <label class="uk-form-label" for="message-body">Message</label>
                <textarea class="uk-textarea" id="message-body" rows="5" placeholder="Write your message here"></textarea>
            </div>

            <!-- Modal Footer with Send and Cancel Buttons -->
            <div class="uk-modal-footer uk-text-right">
                <button class="uk-button uk-button-default uk-modal-close">Cancel</button>
                <button class="uk-button uk-button-primary" onclick="sendMessage()">Send</button>
            </div>
        </div>
    </div>

    <script>
    function sendMessage() {
        // Get message fields
        const subject = document.getElementById('message-subject').value;
        const message = document.getElementById('message-body').value;
        const profileUserId = <?php echo get_queried_object_id(); ?>; // Profile page user ID

        // Perform AJAX request to send the message
        jQuery.ajax({
            url: '<?php echo admin_url('admin-ajax.php'); ?>',
            type: 'POST',
            data: {
                action: 'send_user_message',
                profile_user_id: profileUserId,
                subject: subject,
                message: message
            },
            success: function(response) {
                if (response.success) {
                    UIkit.notification({message: 'Message sent successfully!', status: 'success'});
                    UIkit.modal('#send-message-modal').hide(); // Close modal
                } else {
                    UIkit.notification({message: response.data.message || 'Failed to send message.', status: 'danger'});
                }
            },
            error: function() {
                UIkit.notification({message: 'An error occurred while sending the message.', status: 'danger'});
            }
        });
    }
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('send_message', 'send_message_button_shortcode');

function handle_send_user_message() {
    // Check if the user is logged in and required fields are provided
    if (!is_user_logged_in() || empty($_POST['profile_user_id']) || empty($_POST['subject']) || empty($_POST['message'])) {
        wp_send_json_error(['message' => 'Please complete all fields.']);
        return;
    }

    $profile_user_id = intval($_POST['profile_user_id']);
    $subject = sanitize_text_field($_POST['subject']);
    $message_body = sanitize_textarea_field($_POST['message']);
    $current_user = wp_get_current_user();

    // Get the recipient's email
    $recipient_email = get_userdata($profile_user_id)->user_email;
    if (!$recipient_email) {
        wp_send_json_error(['message' => 'Recipient not found.']);
        return;
    }

    // Prepare email
    $email_subject = "New Message: " . $subject;
    $email_message = "Message from " . $current_user->display_name . ":\n\n" . $message_body;
    $headers = ['From: Way2Go eLearning <e-learning@way2go-project.eu>'];

    // Send the email
    if (wp_mail($recipient_email, $email_subject, $email_message, $headers)) {
        wp_send_json_success();
    } else {
        wp_send_json_error(['message' => 'Failed to send email.']);
    }
}
add_action('wp_ajax_send_user_message', 'handle_send_user_message');



/////////////////////////////////////////////////////////////////////////////


// Shortcode to Display Connections List
function display_user_connections_shortcode() {
    // Get the profile user ID
    $profile_user_id = get_queried_object_id();

    // Check if there are connections for this user
    $connections = get_user_meta($profile_user_id, 'connections', true);
    if (!$connections || !is_array($connections)) {
        return '<p>No connections made.</p>'; // Display message if no connections
    }

    // Start building the list of connections
    $output = '<ul class="user-connections-list">';

    foreach ($connections as $connection_id) {
        $user_info = get_userdata($connection_id);

        // Skip if user data is not found
        if (!$user_info) continue;

        // Get the Name Surname and profile URL for each connection
        $name_surname = esc_html($user_info->first_name . ' ' . $user_info->last_name);
        $profile_url = esc_url(get_author_posts_url($connection_id));

        // Add each connection as a list item with a clickable link
        $output .= '<li><a href="' . $profile_url . '">' . $name_surname . '</a></li>';
    }

    $output .= '</ul>';

    return $output;
}
add_shortcode('user_connections', 'display_user_connections_shortcode');
