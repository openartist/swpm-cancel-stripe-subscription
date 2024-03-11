<?php
/**
 * Plugin Name: Custom SWPM Extension for Stripe Cancellation
 * Description: Extends Simple WP Membership Plugin to include direct Stripe subscription cancellation. It dynamically selects test or live Stripe keys based on settings and reassigns users to a specified free membership tier or makes their account inactive if no tier is specified.
 * Version: 1.0
 * Author: Paul Bloch
 * Author URI: http://kosmographika.com/
 */

// Ensure Stripe's PHP library is loaded. Adjust the path as necessary if you're using Composer or including the library manually.
require_once __DIR__ . '/vendor/autoload.php';

// Ensure SWPM is loaded to avoid conflicts
add_action('plugins_loaded', 'custom_swpm_wait_for_swpm_to_load');
function custom_swpm_wait_for_swpm_to_load() {
    if (class_exists('SwpmShortcodesHandler') && class_exists('SWPM_Member_Subscriptions')) {
        custom_swpm_modify_cancel_subs_behavior();
        custom_swpm_add_admin_menu();
    }
}

function custom_swpm_add_admin_menu() {
    add_options_page(
        'Custom SWPM Settings',
        'Custom SWPM Settings',
        'manage_options',
        'custom_swpm_settings',
        'custom_swpm_settings_page'
    );
}

function custom_swpm_settings_page() {
    ?>
    <div class="wrap">
        <h2>Custom SWPM Settings</h2>
        <form action="options.php" method="post">
            <?php
            settings_fields('custom_swpm_settings');
            do_settings_sections('custom_swpm_settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

add_action('admin_init', 'custom_swpm_settings_init');
function custom_swpm_settings_init() {
    register_setting('custom_swpm_settings', 'custom_swpm_free_tier_id');
    add_settings_section(
        'custom_swpm_settings_section',
        'Settings',
        'custom_swpm_settings_section_callback',
        'custom_swpm_settings'
    );
    add_settings_field(
        'custom_swpm_free_tier_id_field',
        'Free Tier Membership Level ID',
        'custom_swpm_free_tier_id_field_render',
        'custom_swpm_settings',
        'custom_swpm_settings_section'
    );
}

function custom_swpm_settings_section_callback() {
    echo 'Enter the ID of the free tier membership level. Leave blank or set to 0 to make the account inactive if no free tier is available.';
}

function custom_swpm_free_tier_id_field_render() {
    $value = get_option('custom_swpm_free_tier_id');
    echo "<input type='text' name='custom_swpm_free_tier_id' value='" . esc_attr($value) . "'>";
}

function custom_swpm_modify_cancel_subs_behavior() {
    remove_shortcode('swpm_stripe_subscription_cancel_link');
    add_shortcode('swpm_stripe_subscription_cancel_link', 'custom_swpm_stripe_cancel_subs_link_sc');
}

function custom_swpm_stripe_cancel_subs_link_sc($args) {
    if (!SwpmMemberUtils::is_member_logged_in()) {
        return SwpmUtils::_('You are not logged-in as a member');
    }
    $member_id = SwpmMemberUtils::get_logged_in_members_id();

    // Dynamically getting Stripe API keys from SWPM settings
    $settings = SwpmSettings::get_instance();
    $use_test_keys = $settings->get_value('enable-sandbox-testing') === 'checked="checked"';
    $stripe_secret_key = $use_test_keys ? $settings->get_value('stripe-test-secret-key') : $settings->get_value('stripe-live-secret-key');

    if (empty($stripe_secret_key)) {
        return SwpmUtils::_('Stripe Secret Key is not configured.');
    }

    // Initialize Stripe API with the secret key
    \Stripe\Stripe::setApiKey($stripe_secret_key);

    $subscription_id = get_member_subscription_id($member_id);
    if (empty($subscription_id)) {
        return SwpmUtils::_('No active subscriptions found.');
    }

    try {
        $subscription = \Stripe\Subscription::retrieve($subscription_id);
        $subscription->cancel();

        $free_tier_id = get_option('custom_swpm_free_tier_id', '0');
        if (!empty($free_tier_id) && is_numeric($free_tier_id) && $free_tier_id > 0) {
            SwpmMemberUtils::update_membership_level($member_id, $free_tier_id);
        } else {
            // Implement logic to make the account inactive here
        }

        return SwpmUtils::_('Your subscription has been successfully cancelled.');
    } catch (\Stripe\Exception\ApiErrorException $e) {
        return SwpmUtils::_('Failed to cancel the subscription. Please contact support. Error: ' . $e->getMessage());
    }
}

// Helper function to get the Stripe subscription ID for a member
function get_member_subscription_id($member_id) {
    $subs_manager = new SWPM_Member_Subscriptions($member_id);
    $active_subs = $subs_manager->get_subs();
    if (!empty($active_subs)) {
        foreach ($active_subs as $sub) {
            if ($subs_manager->is_active($sub['status'])) {
                return $sub['sub_id'];
            }
        }
    }
    return null;
}
