<?php
/**
 * Pull and Push screen: lets another site pull this one over HTTP.
 *
 * @package Migrator
 *
 * @var bool   $enabled    Whether the endpoint is switched on.
 * @var bool   $standalone Whether another plugin already owns the endpoint.
 * @var string $serverUrl  Credentials screen.
 * @var string $siteUrl    This site's address.
 */

defined('ABSPATH') || exit;

$migrator_updated = isset($_GET['updated']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<div class="wrap migrator-schedule">
	<h1>
		<span class="dashicons dashicons-randomize" aria-hidden="true"></span>
		<?php esc_html_e('Pull and Push', 'plogins-migrator'); ?>
	</h1>
	<p class="migrator-schedule__lead">
		<?php esc_html_e('Copy a whole site to another server over HTTP, with no archive to download or upload, no SSH and no FTP. Files and the database travel in small pieces, an interrupted copy carries on where it stopped, and the next pull fetches only what changed.', 'plogins-migrator'); ?>
	</p>

	<?php if ($migrator_updated) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Saved.', 'plogins-migrator'); ?></p></div>
	<?php endif; ?>

	<div class="migrator-grid">
		<form class="migrator-card" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr(\Migrator\Reprint\Source::SAVE_ACTION); ?>">
			<?php wp_nonce_field(\Migrator\Reprint\Source::SAVE_ACTION); ?>

			<h2 class="migrator-card__heading"><?php esc_html_e('This site as the source', 'plogins-migrator'); ?></h2>

			<?php if ($standalone) : ?>
				<p><?php esc_html_e('Another plugin on this site already answers pull requests at the same address, so Migrator leaves them to that plugin and its own settings.', 'plogins-migrator'); ?></p>
			<?php else : ?>
				<label class="migrator-toggle">
					<input type="checkbox" name="enabled" value="1" <?php checked($enabled); ?>>
					<span><?php esc_html_e('Let another site pull this one', 'plogins-migrator'); ?></span>
				</label>
				<p class="description">
					<?php esc_html_e('Switching this on opens an address other sites can request, and nothing is answered until you also set a connection token or enrol a key. Anyone holding that credential can read every file and the whole database, user password hashes included. Switch it off when the move is done.', 'plogins-migrator'); ?>
				</p>
				<p><?php submit_button(__('Save', 'plogins-migrator'), 'primary', 'submit', false); ?></p>
			<?php endif; ?>

			<?php if ($enabled && ! $standalone) : ?>
				<p>
					<a class="button" href="<?php echo esc_url($serverUrl); ?>"><?php esc_html_e('Set the token or enrol a key', 'plogins-migrator'); ?></a>
				</p>
			<?php endif; ?>
		</form>

		<div class="migrator-card">
			<h2 class="migrator-card__heading"><?php esc_html_e('On the destination', 'plogins-migrator'); ?></h2>
			<p><?php esc_html_e('Install Migrator on the destination site and run these from its WordPress folder with WP-CLI. A pull replaces the destination database and copies wp-content over it; Migrator keeps its own folder and a dump of the database it replaced.', 'plogins-migrator'); ?></p>
			<p><strong><?php esc_html_e('Copy this site to the destination', 'plogins-migrator'); ?></strong></p>
			<p><code>wp migrator pull <?php echo esc_html($siteUrl); ?></code></p>
			<p class="description"><?php esc_html_e('The first run prints a key. Paste it here under Set the token or enrol a key, save, and run the command again. On a server without the OpenSSL extension, set a connection token instead and add --secret=<token> to the command.', 'plogins-migrator'); ?></p>
			<p><strong><?php esc_html_e('Fetch only what changed since the last pull', 'plogins-migrator'); ?></strong></p>
			<p><?php esc_html_e('Run the same command again.', 'plogins-migrator'); ?></p>
			<p><strong><?php esc_html_e('Send the destination\'s changes back here', 'plogins-migrator'); ?></strong></p>
			<p><code>wp migrator push <?php echo esc_html($siteUrl); ?></code></p>
			<p class="description"><?php esc_html_e('Pushing needs push access granted for that key under Set the token or enrol a key above, display_errors switched off, and a writable folder beside the web root on the same disk. While a push applies its changes this site shows a maintenance page; if the push stops part way, run it again to finish.', 'plogins-migrator'); ?></p>
		</div>
	</div>
</div>
