<?php
/**
 * Plugin Name: Zibll 前台资源下载模块
 * Description: 为子比主题前台投稿增加资源下载配置和权限显示。
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ZIBLL_FFQTTG_FILE', __FILE__);
define('ZIBLL_FFQTTG_URL', plugin_dir_url(__FILE__));
define('ZIBLL_FFQTTG_META_KEY', '_zibll_ffqttg_download_resources');

function zibll_ffqttg_is_newposts_page()
{
    return function_exists('is_page_template') && is_page_template('pages/newposts.php');
}

function zibll_ffqttg_visibility_options()
{
    $options = array(
        'reply'   => '评论后可查看',
        'logged'  => '登录后可查看',
        'vip1'    => function_exists('_pz') ? (_pz('pay_user_vip_1_name', '会员') . '可查看') : '会员可查看',
        'payshow' => '付费后可查看',
    );

    return $options;
}

function zibll_ffqttg_get_edit_post()
{
    $edit_id = !empty($_REQUEST['edit']) ? absint($_REQUEST['edit']) : 0;
    if (!$edit_id) {
        return null;
    }

    $post = get_post($edit_id);
    if (!$post || 'post' !== $post->post_type) {
        return null;
    }

    if (function_exists('zib_current_user_can') && !zib_current_user_can('new_post_edit', $post)) {
        return null;
    }

    return $post;
}

function zibll_ffqttg_normalize_resources($resources)
{
    if (!is_array($resources)) {
        return array();
    }

    $allowed_visibility = array_keys(zibll_ffqttg_visibility_options());
    $normalized         = array();

    foreach ($resources as $resource) {
        if (!is_array($resource)) {
            continue;
        }

        $raw_url     = isset($resource['url']) && is_scalar($resource['url']) ? trim((string) $resource['url']) : '';
        $extract     = isset($resource['extract_code']) && is_scalar($resource['extract_code']) ? sanitize_text_field($resource['extract_code']) : '';
        $unzip       = isset($resource['unzip_code']) && is_scalar($resource['unzip_code']) ? sanitize_text_field($resource['unzip_code']) : '';
        $visibility  = isset($resource['visibility']) && is_scalar($resource['visibility']) ? sanitize_key($resource['visibility']) : 'logged';
        $url         = $raw_url ? esc_url_raw($raw_url) : '';
        $has_content = '' !== $raw_url || '' !== $extract || '' !== $unzip;

        if (!$has_content) {
            continue;
        }

        if (!$url || !preg_match('#^https?://#i', $url)) {
            return new WP_Error('invalid_url', '请输入有效的 HTTP 或 HTTPS 下载链接。');
        }

        if (!in_array($visibility, $allowed_visibility, true)) {
            return new WP_Error('invalid_visibility', '资源可见权限不正确。');
        }

        if (strlen($extract) > 128 || strlen($unzip) > 128) {
            return new WP_Error('code_too_long', '提取码或解压码不能超过 128 个字符。');
        }

        if (count($normalized) >= 20) {
            return new WP_Error('too_many_resources', '最多只能添加 20 个资源。');
        }

        $normalized[] = array(
            'url'          => $url,
            'extract_code' => $extract,
            'unzip_code'   => $unzip,
            'visibility'   => $visibility,
        );
    }

    return $normalized;
}

function zibll_ffqttg_submission_action()
{
    $action = isset($_REQUEST['action']) && is_scalar($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';

    return in_array($action, array('posts_save', 'posts_draft'), true);
}

function zibll_ffqttg_send_error($message)
{
    if (function_exists('zib_send_json_error')) {
        zib_send_json_error($message);
    }

    wp_send_json_error(array('message' => $message));
}

function zibll_ffqttg_validate_submission($postarr)
{
    if (!is_array($postarr) || 'post' !== ($postarr['post_type'] ?? '') || !zibll_ffqttg_submission_action()) {
        return;
    }

    if (!isset($_POST['zibll_ffqttg_download'])) {
        return;
    }

    $raw_resources = wp_unslash($_POST['zibll_ffqttg_download']);
    if (!is_array($raw_resources)) {
        zibll_ffqttg_send_error('资源下载配置格式不正确。');
    }

    $resources = zibll_ffqttg_normalize_resources($raw_resources);
    if (is_wp_error($resources)) {
        zibll_ffqttg_send_error($resources->get_error_message());
    }

    if ($resources && !is_user_logged_in()) {
        zibll_ffqttg_send_error('请登录后配置资源下载。');
    }

    if ($resources) {
        $nonce = isset($_POST['zibll_ffqttg_nonce']) && is_scalar($_POST['zibll_ffqttg_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['zibll_ffqttg_nonce']))
            : '';

        if (!$nonce || !wp_verify_nonce($nonce, 'zibll_ffqttg_newpost')) {
            zibll_ffqttg_send_error('资源下载配置验证失败，请刷新页面后重试。');
        }
    }

    $has_paid_resource = false;
    foreach ($resources as $resource) {
        if ('payshow' === $resource['visibility']) {
            $has_paid_resource = true;
            break;
        }
    }

    if ($has_paid_resource) {
        if (!function_exists('zib_current_user_can') || !zib_current_user_can('new_post_pay')) {
            zibll_ffqttg_send_error('您没有设置付费内容的权限。');
        }

        $pay_data = isset($_POST['posts_zibpay']) && is_array($_POST['posts_zibpay']) ? $_POST['posts_zibpay'] : array();
        if (empty($_POST['zibpay_s']) || !array_key_exists('pay_modo', $pay_data)) {
            zibll_ffqttg_send_error('使用付费后可见前，请先配置主题的付费内容。');
        }
    }
}

function zibll_ffqttg_save_resources($post)
{
    if (!is_object($post) || empty($post->ID) || 'post' !== $post->post_type || !zibll_ffqttg_submission_action()) {
        return;
    }

    if (!isset($_POST['zibll_ffqttg_download'])) {
        return;
    }

    $resources = zibll_ffqttg_normalize_resources(wp_unslash($_POST['zibll_ffqttg_download']));
    if (is_wp_error($resources)) {
        return;
    }

    if ($resources) {
        update_post_meta($post->ID, ZIBLL_FFQTTG_META_KEY, $resources);
    } else {
        delete_post_meta($post->ID, ZIBLL_FFQTTG_META_KEY);
    }
}

function zibll_ffqttg_copy_button($value, $label)
{
    if ('' === $value) {
        return '';
    }

    return '<a href="javascript:;" class="but zibll-ffqttg-copy" data-toggle="tooltip" title="' . esc_attr(sprintf('点击复制%s', $label)) . '" data-clipboard-tag="' . esc_attr($label) . '" data-clipboard-text="' . esc_attr($value) . '">' . esc_html($label . '：' . $value) . '</a>';
}

function zibll_ffqttg_render_resource_card($resource, $index)
{
    $items = '';
    if (!empty($resource['extract_code'])) {
        $items .= zibll_ffqttg_copy_button($resource['extract_code'], '提取码');
    }
    if (!empty($resource['unzip_code'])) {
        $items .= zibll_ffqttg_copy_button($resource['unzip_code'], '解压码');
    }

    $card  = '<div class="hidden-box show zibll-ffqttg-resource-card">';
    $card .= '<div class="hidden-text"><i class="fa fa-download mr6" aria-hidden="true"></i>资源下载 <span class="badg badg-sm ml6">资源 ' . (int) $index . '</span></div>';
    $card .= '<div class="flex ac hh"><div class="but-download flex ac">';
    $card .= '<a target="_blank" rel="nofollow noopener" href="' . esc_url($resource['url']) . '" class="mr10 but b-theme"><i class="fa fa-download" aria-hidden="true"></i> 资源 ' . (int) $index . '</a>';
    $card .= $items;
    $card .= '</div></div>';
    $card .= '</div>';

    return $card;
}

function zibll_ffqttg_render_resources_before_content($post)
{
    if (!is_singular('post') || !is_object($post) || empty($post->ID) || 'post' !== $post->post_type || !shortcode_exists('hidecontent')) {
        return;
    }

    if (function_exists('zib_get_post_allow_view_data')) {
        $post_view = zib_get_post_allow_view_data($post);
        if (is_array($post_view) && empty($post_view['allow'])) {
            return;
        }
    }

    $resources = get_post_meta($post->ID, ZIBLL_FFQTTG_META_KEY, true);
    if (!is_array($resources)) {
        return;
    }

    if ($resources && !headers_sent()) {
        nocache_headers();
    }

    foreach ($resources as $index => $resource) {
        if (!is_array($resource) || empty($resource['url'])) {
            continue;
        }

        $visibility = !empty($resource['visibility']) && array_key_exists($resource['visibility'], zibll_ffqttg_visibility_options())
            ? $resource['visibility']
            : 'logged';
        $card       = zibll_ffqttg_render_resource_card($resource, $index + 1);
        $shortcode  = '[hidecontent type="' . esc_attr($visibility) . '"]' . $card . '[/hidecontent]';

        echo do_shortcode($shortcode);
    }
}

function zibll_ffqttg_enqueue_assets()
{
    if (!zibll_ffqttg_is_newposts_page() || !is_user_logged_in()) {
        return;
    }

    $edit_post = zibll_ffqttg_get_edit_post();
    $resources = $edit_post ? get_post_meta($edit_post->ID, ZIBLL_FFQTTG_META_KEY, true) : array();
    $resources = is_array($resources) ? zibll_ffqttg_normalize_resources($resources) : array();
    if (is_wp_error($resources)) {
        $resources = array();
    }

    wp_enqueue_style(
        'zibll-ffqttg',
        ZIBLL_FFQTTG_URL . 'assets/css/zibll-ffqttg.css',
        array(),
        '1.0.0'
    );
    wp_enqueue_script(
        'zibll-ffqttg',
        ZIBLL_FFQTTG_URL . 'assets/js/zibll-ffqttg.js',
        array('jquery'),
        '1.0.0',
        true
    );
    wp_localize_script(
        'zibll-ffqttg',
        'ZibllFfqttg',
        array(
            'nonce'      => wp_create_nonce('zibll_ffqttg_newpost'),
            'resources'  => $resources,
            'visibility' => zibll_ffqttg_visibility_options(),
            'max'        => 20,
        )
    );
}

function zibll_ffqttg_boot()
{
    if (!function_exists('zib_current_user_can')) {
        return;
    }

    add_action('zib_pre_insert_post', 'zibll_ffqttg_validate_submission', 10, 1);
    add_action('new_add_posts', 'zibll_ffqttg_save_resources', 20, 1);
    add_action('new_edit_posts', 'zibll_ffqttg_save_resources', 20, 1);
    add_action('zib_posts_content_before', 'zibll_ffqttg_render_resources_before_content', 20, 1);
}

add_action('zib_require_end', 'zibll_ffqttg_boot', 20);
add_action('wp_enqueue_scripts', 'zibll_ffqttg_enqueue_assets', 20);
