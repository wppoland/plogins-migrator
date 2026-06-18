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
		<?php esc_html_e('Back up your site or move it to a new host — one file, no technical setup.', 'migrator'); ?>
	</p>

	<div class="migrator__cards">
		<section class="migrator-card" aria-labelledby="migrator-export-heading">
			<h2 id="migrator-export-heading" class="migrator-card__heading">
				<?php esc_html_e('Create a backup', 'migrator'); ?>
			</h2>
			<p class="migrator-card__desc">
				<?php esc_html_e('Package your database and files into a single archive you can download and restore anywhere.', 'migrator'); ?>
			</p>

			<details class="migrator-options">
				<summary><?php esc_html_e('What to leave out (optional)', 'migrator'); ?></summary>
				<?php
				$migrator_opts = [
					'no_media'            => __('Media library (uploads)', 'migrator'),
					'no_themes'           => __('All themes', 'migrator'),
					'no_inactive_themes'  => __('Inactive themes (keep active only)', 'migrator'),
					'no_plugins'          => __('All plugins', 'migrator'),
					'no_inactive_plugins' => __('Inactive plugins (keep active only)', 'migrator'),
					'no_muplugins'        => __('Must-use plugins', 'migrator'),
					'no_cache'            => __('Cache files', 'migrator'),
					'no_spam_comments'    => __('Spam comments', 'migrator'),
					'no_post_revisions'   => __('Post revisions', 'migrator'),
					'no_transients'       => __('Transients', 'migrator'),
					'no_sessions'         => __('WooCommerce sessions', 'migrator'),
					'no_action_scheduler' => __('Action Scheduler tables', 'migrator'),
					'no_database'         => __('Database (files-only backup)', 'migrator'),
				];
				foreach ($migrator_opts as $migrator_key => $migrator_label) :
					?>
					<label class="migrator-options__opt">
						<input type="checkbox" class="migrator-export-opt" value="<?php echo esc_attr($migrator_key); ?>">
						<?php echo esc_html($migrator_label); ?>
					</label>
				<?php endforeach; ?>
			</details>

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
				<?php esc_html_e('Drop a Migrator archive here to restore it. This overwrites the current database (and files) with the archive — keep a backup of anything you want to keep.', 'migrator'); ?>
			</p>

			<div class="migrator-drop" id="migrator-drop">
				<p class="migrator-drop__hint"><?php esc_html_e('Drag a .migrator file here, or', 'migrator'); ?></p>
				<label class="button" for="migrator-file">
					<?php esc_html_e('Choose a file', 'migrator'); ?>
					<input type="file" id="migrator-file" accept=".migrator" class="migrator-drop__input">
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
				<?php esc_html_e('Large site? Restore from the command line — it has no time limit:', 'migrator'); ?>
				<br><code>wp migrator import &lt;file&gt;.migrator</code>
			</p>
		</section>
	</div>
</div>
