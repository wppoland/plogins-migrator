<?php
// existing-checked: templates/schedule-page.php - another screen; every submenu has its own template.
/**
 * Deploy to a new server screen.
 *
 * @package Migrator
 *
 * @var array<int, array{file: string, bytes: int, time: int}> $backups   Backups the installer can restore.
 * @var string                                                  $accessKey Access key for the next download.
 */

defined('ABSPATH') || exit;

$migrator_date = static fn (int $ts): string => wp_date(
    get_option('date_format') . ' ' . get_option('time_format'),
    $ts,
);
?>
<div class="wrap migrator-schedule">
	<h1>
		<span class="dashicons dashicons-migrate" aria-hidden="true"></span>
		<?php esc_html_e('Deploy to a new server', 'plogins-migrator'); ?>
	</h1>
	<p class="migrator-schedule__lead">
		<?php esc_html_e('Move this site to a server that has no WordPress yet, or over an existing site. The standalone installer rebuilds the site from a backup: it can download WordPress core, extract your files, import the database and write wp-config.php. It works in short steps, so a slow host or a reload never makes it start over.', 'plogins-migrator'); ?>
	</p>

	<div class="migrator-card">
		<h2 class="migrator-card__heading"><?php esc_html_e('How it works', 'plogins-migrator'); ?></h2>
		<ol>
			<li><?php esc_html_e('On the new server, create an empty database and note its name, user and password. If a WordPress site is already there, the installer can reuse its wp-config.php instead.', 'plogins-migrator'); ?></li>
			<li><?php esc_html_e('Download the installer below. Download a backup from the Migrator screen as well, or have a link to one ready.', 'plogins-migrator'); ?></li>
			<li><?php esc_html_e('Upload the installer, and the backup if you downloaded it, into the folder where the site should live (for most hosts that is public_html).', 'plogins-migrator'); ?></li>
			<li>
				<?php
				printf(
					/* translators: %s: installer file name. */
					esc_html__('Open %s in your browser, enter the access key and fill in the form. When it finishes, it deletes itself and the backup.', 'plogins-migrator'),
					'<code>installer.php</code>'
				);
				?>
			</li>
		</ol>
		<p class="migrator-hint">
			<?php esc_html_e('Options in the installer: a new table prefix, a new administrator password, and safe mode, which restores the site with every plugin switched off and keeps the list so they can be switched back on.', 'plogins-migrator'); ?>
		</p>
		<p class="migrator-hint">
			<?php esc_html_e('Large site? Paste a link to the backup into the installer instead of uploading it, and the new server downloads it directly. A presigned Amazon S3, Cloudflare R2 or Dropbox link works.', 'plogins-migrator'); ?>
		</p>

		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="migrator_download_installer">
			<input type="hidden" name="access_key" value="<?php echo esc_attr($accessKey); ?>">
			<?php wp_nonce_field('migrator_download_installer'); ?>
			<p>
				<label for="migrator-access-key"><strong><?php esc_html_e('Access key for this download', 'plogins-migrator'); ?></strong></label><br>
				<input type="text" id="migrator-access-key" class="regular-text code" value="<?php echo esc_attr($accessKey); ?>" readonly onfocus="this.select()">
			</p>
			<p class="migrator-hint">
				<?php esc_html_e('Copy the key before you download. The installer asks for it on every visit, and it is not stored anywhere else, so a new download comes with a new key.', 'plogins-migrator'); ?>
			</p>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e('Download installer.php', 'plogins-migrator'); ?></button>
			</p>
		</form>
	</div>

	<div class="migrator-card">
		<h2 class="migrator-card__heading"><?php esc_html_e('Backups to deploy', 'plogins-migrator'); ?></h2>
		<?php if ([] === $backups) : ?>
			<p class="migrator-hint"><?php esc_html_e('No backups yet. Create one from the Migrator screen.', 'plogins-migrator'); ?></p>
		<?php else : ?>
			<table class="widefat striped migrator-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e('Backup', 'plogins-migrator'); ?></th>
						<th scope="col"><?php esc_html_e('Created', 'plogins-migrator'); ?></th>
						<th scope="col"><?php esc_html_e('Size', 'plogins-migrator'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($backups as $migrator_b) : ?>
						<tr>
							<td><code><?php echo esc_html((string) $migrator_b['file']); ?></code></td>
							<td><?php echo esc_html($migrator_date((int) $migrator_b['time'])); ?></td>
							<td><?php echo esc_html(size_format((int) $migrator_b['bytes'])); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="migrator-hint">
				<?php
				printf(
					/* translators: %s: link to the Migrator screen. */
					esc_html__('Download a backup from the %s screen, then upload it next to the installer.', 'plogins-migrator'),
					'<a href="' . esc_url(admin_url('admin.php?page=migrator')) . '">' . esc_html__('Migrator', 'plogins-migrator') . '</a>'
				);
				?>
			</p>
		<?php endif; ?>
	</div>
</div>
