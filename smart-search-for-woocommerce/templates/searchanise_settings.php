<?php defined('ABSPATH') || exit; ?>

<?php
$current_tab = ! empty($_REQUEST['tab']) ? sanitize_title($_REQUEST['tab']) : 'general';
$tabs        = array(
	'general' => __('General', 'woocommerce-searchanise'),
	'info'  => __('Info', 'woocommerce-searchanse'),
);
?>

<div class="wrap woocommerce">
	<nav class="nav-tab-wrapper woo-nav-tab-wrapper">
		<?php
		foreach ($tabs as $name => $label) {
			echo '<a href="' . admin_url('admin.php?page=searchanise_settings&tab=' . $name) . '" class="nav-tab ';
			if ( $current_tab == $name ) {
				echo 'nav-tab-active';
			}
			echo '">' . $label . '</a>';
		}
		?>
	</nav>
	<h1 class="screen-reader-text"><?php echo esc_html($tabs[$current_tab]); ?></h1>
    <?php
        $template_file = SE_ABSPATH . '/templates/searchanise_settings_' . $current_tab . '.php';

        if (file_exists($template_file)) {
            include(SE_ABSPATH . '/templates/searchanise_settings_' . $current_tab . '.php');
        } else {
            echo __('Unknown template:', 'woocommerce-searchanise') . ' ' . 'searchanise_settings_' . $current_tab . '.php';
        }
	?>
</div>
