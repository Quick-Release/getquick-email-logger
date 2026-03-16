<?php
/**
 * Main admin page template.
 *
 * @var string $currentTab
 * @var array  $tabs
 * @var string $title
 * @var array  $notices
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1><?php echo esc_html($title); ?></h1>

    <nav class="nav-tab-wrapper">
        <?php foreach ($tabs as $tab => $label) : ?>
            <?php
            $url = add_query_arg(
                [
                    'page' => 'getquick-email-logger',
                    'tab' => $tab,
                ],
                admin_url('options-general.php')
            );
            $className = $currentTab === $tab ? 'nav-tab nav-tab-active' : 'nav-tab';
            ?>
            <a href="<?php echo esc_url($url); ?>" class="<?php echo esc_attr($className); ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if (isset($_GET['getquick_email_logger_notice'])) : ?>
        <?php
        $noticeKey = sanitize_key((string) $_GET['getquick_email_logger_notice']);
        if (isset($notices[$noticeKey])) :
            [$className, $message] = $notices[$noticeKey];
            ?>
            <div class="<?php echo esc_attr($className); ?>"><p><?php echo esc_html($message); ?></p></div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="getquick-email-logger-content" style="margin-top: 20px;">
        <?php
        switch ($currentTab) {
            case 'settings':
                \GetQuick\EmailLogger\Utils\View::render('admin/tabs/settings');
                break;
            case 'test-email':
                \GetQuick\EmailLogger\Utils\View::render('admin/tabs/test-email');
                break;
            case 'logs':
            default:
                \GetQuick\EmailLogger\Utils\View::render('admin/tabs/logs', $tabArgs ?? []);
                break;
        }
        ?>
    </div>
</div>
