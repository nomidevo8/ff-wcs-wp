<?php
namespace FFWCS\Helpers;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Helper for user creation / lookup
 */
class UserHelper {

    public function get_or_create_user($email, $first_name = '', $last_name = '') {
        if (empty($email)) return 0;
        $user_id = email_exists($email);
        if ($user_id) return $user_id;

        $password = wp_generate_password(12, false);
        $user_id = wp_create_user($email, $password, $email);
        if (is_wp_error($user_id)) {
            return 0;
        }
        // update profile
        wp_update_user([
            'ID' => $user_id,
            'display_name' => trim($first_name . ' ' . $last_name)
        ]);
        if ($first_name) update_user_meta($user_id, 'first_name', $first_name);
        if ($last_name) update_user_meta($user_id, 'last_name', $last_name);

        return $user_id;
    }
}
