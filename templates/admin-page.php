<?php
/**
 * Migrator admin screen.
 *
 * @package Migrator
 */

defined('ABSPATH') || exit;
?>
<div class="wrap migrator">
	<h1 class="migrator__title">
		<span class="dashicons dashicons-migrate" aria-hidden="true"></span>
		<?php esc_html_e('Migrator', 'migrator'); ?>
	</h1>
	<p class="migrator__lead">
		<?php esc_html_e('Back up your site or move it to a new host, one file, no technical setup.', 'migrator'); ?>
	</p>

	<div class="migrator__cards">
		<section class="migrator-card" aria-labelledby="migrator-export-heading">
			<h2 id="migrator-export-heading" class="migrator-card__heading">
				<?php esc_html_e('Create a backup', 'migrator'); ?>
			</h2>
			<p class="migrator-card__desc">
				<?php esc_html_e('Package your database and files into a single archive you can download and restore anywhere.', 'migrator'); ?>
			</p>

			<div class="migrator-presets" role="group" aria-label="<?php esc_attr_e('Backup presets', 'migrator'); ?>">
				<span class="migrator-presets__label"><?php esc_html_e('Preset:', 'migrator'); ?></span>
				<button type="button" class="button button-small migrator-preset is-active" data-preset="full"><?php esc_html_e('Full site', 'migrator'); ?></button>
				<button type="button" class="button button-small migrator-preset" data-preset="database"><?php esc_html_e('Database only', 'migrator'); ?></button>
				<button type="button" class="button button-small migrator-preset" data-preset="media"><?php esc_html_e('Media only', 'migrator'); ?></button>
			</div>

			<details class="migrator-options">
				<summary><?php esc_html_e('What to leave out (optional)', 'migrator'); ?></summary>
				<?php
				$migrator_groups = [
					__('Database', 'migrator') => [
						'no_spam_comments'    => __('Spam comments', 'migrator'),
						'no_post_revisions'   => __('Post revisions', 'migrator'),
						'no_transients'       => __('Transients', 'migrator'),
						'no_sessions'         => __('WooCommerce sessions', 'migrator'),
						'no_action_scheduler' => __('Action Scheduler tables', 'migrator'),
						'no_database'         => __('Database (files-only backup)', 'migrator'),
					],
					__('Files', 'migrator') => [
						'no_media'            => __('Media library (uploads)', 'migrator'),
						'no_themes'           => __('All themes', 'migrator'),
						'no_inactive_themes'  => __('Inactive themes (keep active only)', 'migrator'),
						'no_plugins'          => __('All plugins', 'migrator'),
						'no_inactive_plugins' => __('Inactive plugins (keep active only)', 'migrator'),
						'no_muplugins'        => __('Must-use plugins', 'migrator'),
						'no_cache'            => __('Cache files', 'migrator'),
					],
				];
				foreach ($migrator_groups as $migrator_group_label => $migrator_opts) :
					?>
					<p class="migrator-options__group"><?php echo esc_html($migrator_group_label); ?></p>
					<?php foreach ($migrator_opts as $migrator_key => $migrator_label) : ?>
						<label class="migrator-options__opt">
							<input type="checkbox" class="migrator-export-opt" value="<?php echo esc_attr($migrator_key); ?>">
							<?php echo esc_html($migrator_label); ?>
						</label>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</details>

			<?php
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$migrator_tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%'));
			$migrator_tables = is_array($migrator_tables) ? $migrator_tables : [];
			?>

			<?php if ([] !== $migrator_tables) : ?>
				<details class="migrator-options">
					<summary><?php esc_html_e('Exclude specific database tables (optional)', 'migrator'); ?></summary>
					<div class="migrator-options__grid">
						<?php foreach ($migrator_tables as $migrator_table) : ?>
							<label class="migrator-options__opt">
								<input type="checkbox" class="migrator-export-table" value="<?php echo esc_attr((string) $migrator_table); ?>">
								<code><?php echo esc_html((string) $migrator_table); ?></code>
							</label>
						<?php endforeach; ?>
					</div>
				</details>
			<?php endif; ?>

			<details class="migrator-options migrator-explorer">
				<summary><?php esc_html_e('Exclude specific files and folders (optional)', 'migrator'); ?></summary>
				<div class="migrator-explorer__bar">
					<button type="button" class="button" id="migrator-scan"><?php esc_html_e('Scan files by size', 'migrator'); ?></button>
					<span class="migrator-explorer__summary" id="migrator-scan-summary" aria-live="polite"></span>
				</div>
				<p class="migrator-explorer__hint"><?php esc_html_e('Browse your wp-content folder by size and tick anything you do not need in the backup, for example a large cache, logs, or another plugin\'s stored data.', 'migrator'); ?></p>
				<div class="migrator-tree" id="migrator-tree" role="tree" hidden></div>
			</details>

			<fieldset class="migrator-compress">
				<legend class="migrator-options__group"><?php esc_html_e('Compression', 'migrator'); ?></legend>
				<label class="migrator-options__opt">
					<input type="radio" name="migrator-compress" value="none" checked>
					<?php esc_html_e('None (fastest, largest file)', 'migrator'); ?>
				</label>
				<label class="migrator-options__opt">
					<input type="radio" name="migrator-compress" value="gzip">
					<?php esc_html_e('GZip (smaller file, a little slower)', 'migrator'); ?>
				</label>
			</fieldset>

			<?php
			/**
			 * Fires inside the backup form, below the exclusion options. An add-on
			 * can render extra controls here, such as a password-encryption option.
			 */
			do_action('migrator/backup_form_options');
			?>

			<button type="button" class="button button-primary button-hero" id="migrator-export-start">
				<?php esc_html_e('Create backup', 'migrator'); ?>
			</button>

			<div class="migrator-progress" id="migrator-export-progress" hidden>
				<div
					class="migrator-progress__bar"
					role="progressbar"
					aria-valuemin="0"
					aria-valuemax="100"
					aria-valuenow="0"
					aria-label="<?php esc_attr_e('Backup progress', 'migrator'); ?>"
				>
					<span class="migrator-progress__fill" id="migrator-export-fill"></span>
				</div>
				<p class="migrator-progress__status" id="migrator-export-status" aria-live="polite"></p>
			</div>

			<div class="migrator-result" id="migrator-export-result" hidden>
				<p class="migrator-result__msg" id="migrator-export-result-msg"></p>
				<a class="button button-primary" id="migrator-export-download" href="#" download>
					<?php esc_html_e('Download backup', 'migrator'); ?>
				</a>
			</div>
		</section>

		<section class="migrator-card" aria-labelledby="migrator-import-heading">
			<h2 id="migrator-import-heading" class="migrator-card__heading">
				<?php esc_html_e('Restore a backup', 'migrator'); ?>
			</h2>
			<p class="migrator-card__desc">
				<?php esc_html_e('Drop a Migrator archive here to restore it. This overwrites the current database (and files) with the archive, keep a backup of anything you want to keep.', 'migrator'); ?>
			</p>

			<div class="migrator-drop" id="migrator-drop">
				<p class="migrator-drop__hint"><?php esc_html_e('Drag a .migrator file here, or', 'migrator'); ?></p>
				<label class="button" for="migrator-file">
					<?php esc_html_e('Choose a file', 'migrator'); ?>
					<input type="file" id="migrator-file" accept=".migrator,.gz" class="migrator-drop__input">
				</label>
				<p class="migrator-drop__name" id="migrator-file-name" aria-live="polite"></p>
				<label class="migrator-drop__opt">
					<input type="checkbox" id="migrator-import-files" checked>
					<?php esc_html_e('Also restore files (wp-content)', 'migrator'); ?>
				</label>
			</div>

			<button type="button" class="button button-primary" id="migrator-import-start" disabled>
				<?php esc_html_e('Restore backup', 'migrator'); ?>
			</button>

			<div class="migrator-progress" id="migrator-import-progress" hidden>
				<div class="migrator-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="<?php esc_attr_e('Restore progress', 'migrator'); ?>">
					<span class="migrator-progress__fill" id="migrator-import-fill"></span>
				</div>
				<p class="migrator-progress__status" id="migrator-import-status" aria-live="polite"></p>
			</div>

			<div class="migrator-result" id="migrator-import-result" hidden>
				<p class="migrator-result__msg" id="migrator-import-result-msg"></p>
			</div>

			<p class="migrator-card__desc migrator-card__cli">
				<?php esc_html_e('Large site? Restore from the command line, it has no time limit:', 'migrator'); ?>
				<br><code>wp migrator import &lt;file&gt;.migrator</code>
			</p>
		</section>

		<section class="migrator-card migrator-backups" aria-labelledby="migrator-backups-heading">
			<h2 id="migrator-backups-heading" class="migrator-card__heading">
				<?php esc_html_e('Your backups', 'migrator'); ?>
			</h2>
			<p class="migrator-card__desc">
				<?php esc_html_e('Backups stored on this site. Download, restore or delete them here.', 'migrator'); ?>
			</p>
			<div class="migrator-backups__list" id="migrator-backups-list" aria-live="polite"></div>
		</section>
	</div>

	<?php
	/**
	 * Filters whether the "upgrade to Pro" call to action is shown. A white-label
	 * add-on hides it once the agency has bought Pro.
	 *
	 * @param bool $show Default true.
	 */
	if (apply_filters('migrator/show_pro_cta', true)) :
		/**
		 * Filters the URL the "Upgrade" call to action points at.
		 *
		 * @param string $url Default Migrator Pro page.
		 */
		$migrator_pro_url = (string) apply_filters('migrator/pro_url', 'https://plogins.com/migrator-pro/');
		?>
	<section class="migrator-pro-cta" aria-labelledby="migrator-pro-cta-heading">
		<div class="migrator-pro-cta__main">
			<p class="migrator-pro-cta__eyebrow"><?php esc_html_e('Migrator Pro', 'migrator'); ?></p>
			<h2 id="migrator-pro-cta-heading" class="migrator-pro-cta__heading">
				<?php esc_html_e('Put your backups on autopilot and move sites between servers', 'migrator'); ?>
			</h2>
			<p class="migrator-pro-cta__lead">
				<?php esc_html_e('The free plugin backs up, restores and migrates your site by hand. Pro adds the things agencies and busy site owners ask for, all in one licence:', 'migrator'); ?>
			</p>
			<ul class="migrator-pro-cta__list">
				<li><?php esc_html_e('Scheduled automatic backups, kept on a retention you choose', 'migrator'); ?></li>
				<li><?php esc_html_e('Off-site copies to a mounted drive, NAS or cloud storage', 'migrator'); ?></li>
				<li><?php esc_html_e('Direct server-to-server migration, with no manual download', 'migrator'); ?></li>
				<li><?php esc_html_e('Password-encrypted backups and full multisite migration', 'migrator'); ?></li>
			</ul>
		</div>
		<div class="migrator-pro-cta__action">
			<a class="button button-primary button-hero" href="<?php echo esc_url($migrator_pro_url); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e('Get Migrator Pro', 'migrator'); ?>
			</a>
			<p class="migrator-pro-cta__note"><?php esc_html_e('One licence covers every Pro feature.', 'migrator'); ?></p>
		</div>
	</section>
	<?php endif; ?>
</div>
