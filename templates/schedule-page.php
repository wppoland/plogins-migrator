<?php
/**
 * Scheduled Backups settings screen.
 *
 * @package Migrator
 *
 * @var \Migrator\Backup\Schedule            $schedule     Current settings.
 * @var array<string, mixed>|null            $last         Last run status.
 * @var array<int, array<string, mixed>>     $archives     Existing scheduled archives, newest first.
 * @var int|false                            $nextRun      Next scheduled run (unix) or false.
 * @var int|false                            $nextDbRun    Next database-only run (unix) or false.
 * @var array<string, mixed>|null            $lastDb       Last database-only run status.
 * @var array<string, string>                $labels       Exclusion flag => label.
 * @var \Migrator\Storage\OffsiteSettings    $offsite      Off-site copy settings.
 * @var array<string, array<string, mixed>>  $destinations Registered destinations.
 */

defined('ABSPATH') || exit;

$migrator_notice = isset($_GET['migrator_notice']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    ? sanitize_key(wp_unslash($_GET['migrator_notice'])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    : '';

$migrator_notices = [
    'saved'  => ['updated', __('Schedule saved.', 'plogins-migrator')],
    'ran'    => ['updated', __('Backup created.', 'plogins-migrator')],
    'failed' => ['error', __('Backup failed. See the status below.', 'plogins-migrator')],
    'badfolder' => ['error', __('Schedule saved, but no copies will go to the folder: it must be an absolute path outside the website and outside Migrator\'s own backups folder, and the web server must be able to write to it.', 'plogins-migrator')],
];

$migrator_frequencies = [
	\Migrator\Backup\Schedule::FREQ_2H     => __('Every 2 hours', 'plogins-migrator'),
	\Migrator\Backup\Schedule::FREQ_4H     => __('Every 4 hours', 'plogins-migrator'),
	\Migrator\Backup\Schedule::FREQ_12H    => __('Every 12 hours', 'plogins-migrator'),
	\Migrator\Backup\Schedule::FREQ_DAILY  => __('Every day', 'plogins-migrator'),
	\Migrator\Backup\Schedule::FREQ_WEEKLY => __('Every week', 'plogins-migrator'),
];

$migrator_date = static fn (int $ts): string => wp_date(
    get_option('date_format') . ' ' . get_option('time_format'),
    $ts,
);
?>
<div class="wrap migrator-schedule">
	<h1>
		<span class="dashicons dashicons-backup" aria-hidden="true"></span>
		<?php esc_html_e('Scheduled Backups', 'plogins-migrator'); ?>
	</h1>
	<p class="migrator-schedule__lead">
		<?php esc_html_e('Back up this site automatically on a schedule and keep the most recent copies. Backups run unattended through WordPress cron, using the same engine as a manual backup.', 'plogins-migrator'); ?>
	</p>

	<?php if (isset($migrator_notices[$migrator_notice])) : ?>
		<div class="notice notice-<?php echo esc_attr($migrator_notices[$migrator_notice][0]); ?> is-dismissible">
			<p><?php echo esc_html($migrator_notices[$migrator_notice][1]); ?></p>
		</div>
	<?php endif; ?>

	<div class="migrator-grid">
		<form class="migrator-card" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="migrator_save_schedule">
			<?php wp_nonce_field('migrator_save_schedule'); ?>

			<h2 class="migrator-card__heading"><?php esc_html_e('Schedule', 'plogins-migrator'); ?></h2>

			<label class="migrator-toggle">
				<input type="checkbox" name="enabled" value="1" <?php checked($schedule->enabled); ?>>
				<span><?php esc_html_e('Run automatic backups', 'plogins-migrator'); ?></span>
			</label>

			<p class="migrator-field">
				<label for="migrator-frequency"><?php esc_html_e('How often', 'plogins-migrator'); ?></label>
				<select id="migrator-frequency" name="frequency">
					<?php foreach ($migrator_frequencies as $migrator_value => $migrator_label) : ?>
						<option value="<?php echo esc_attr($migrator_value); ?>" <?php selected($schedule->frequency, $migrator_value); ?>><?php echo esc_html($migrator_label); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="migrator-hint"><?php esc_html_e('WordPress cron runs when the site gets a visit. For backups every few hours on a quiet site, have the server call wp-cron.php on a real cron.', 'plogins-migrator'); ?></span>
			</p>

			<p class="migrator-field">
				<label for="migrator-retention"><?php esc_html_e('Backups to keep', 'plogins-migrator'); ?></label>
				<input id="migrator-retention" name="retention" type="number" min="1" max="60" step="1" value="<?php echo esc_attr((string) $schedule->retention); ?>">
				<span class="migrator-hint"><?php esc_html_e('Older scheduled backups beyond this count are deleted automatically.', 'plogins-migrator'); ?></span>
			</p>

			<details class="migrator-exclude">
				<summary><?php esc_html_e('What to leave out (optional)', 'plogins-migrator'); ?></summary>
				<div class="migrator-exclude__grid">
					<?php foreach ($labels as $migrator_key => $migrator_label) : ?>
						<label class="migrator-exclude__opt">
							<input type="checkbox" name="exclude[<?php echo esc_attr($migrator_key); ?>]" value="1" <?php checked(! empty($schedule->exclude[$migrator_key])); ?>>
							<?php echo esc_html($migrator_label); ?>
						</label>
					<?php endforeach; ?>
				</div>
				<p class="migrator-field">
					<label for="migrator-exclude-patterns"><?php esc_html_e('Leave out files named like', 'plogins-migrator'); ?></label>
					<input id="migrator-exclude-patterns" name="exclude_patterns" type="text" class="regular-text code" placeholder="*.log, *.zip, *.mp4" value="<?php echo esc_attr(implode(', ', $schedule->excludePatterns)); ?>">
					<span class="migrator-hint"><?php esc_html_e('Comma separated. * matches any characters, so *.log leaves out every log file in any folder.', 'plogins-migrator'); ?></span>
				</p>
				<p class="migrator-field">
					<label for="migrator-max-file-mb"><?php esc_html_e('Leave out files larger than (MB)', 'plogins-migrator'); ?></label>
					<input id="migrator-max-file-mb" name="max_file_mb" type="number" min="0" step="1" class="small-text" value="<?php echo esc_attr((string) $schedule->maxFileMb); ?>">
					<span class="migrator-hint"><?php esc_html_e('0 or empty keeps files of any size.', 'plogins-migrator'); ?></span>
				</p>
			</details>

			<h2 class="migrator-card__heading migrator-card__heading--spaced"><?php esc_html_e('Database only', 'plogins-migrator'); ?></h2>
			<label class="migrator-toggle">
				<input type="checkbox" name="db_enabled" value="1" <?php checked($schedule->dbEnabled); ?>>
				<span><?php esc_html_e('Also back up the database on its own schedule', 'plogins-migrator'); ?></span>
			</label>
			<p class="migrator-hint"><?php esc_html_e('A store takes orders all day. Back up the database every few hours and the files less often, with separate counts of backups to keep, so the frequent database copies never push out the full backups.', 'plogins-migrator'); ?></p>
			<p class="migrator-field">
				<label for="migrator-db-frequency"><?php esc_html_e('How often', 'plogins-migrator'); ?></label>
				<select id="migrator-db-frequency" name="db_frequency">
					<?php foreach ($migrator_frequencies as $migrator_value => $migrator_label) : ?>
						<option value="<?php echo esc_attr($migrator_value); ?>" <?php selected($schedule->dbFrequency, $migrator_value); ?>><?php echo esc_html($migrator_label); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="migrator-field">
				<label for="migrator-db-retention"><?php esc_html_e('Database backups to keep', 'plogins-migrator'); ?></label>
				<input id="migrator-db-retention" name="db_retention" type="number" min="1" max="60" step="1" value="<?php echo esc_attr((string) $schedule->dbRetention); ?>">
			</p>

			<h2 class="migrator-card__heading migrator-card__heading--spaced"><?php esc_html_e('Compression', 'plogins-migrator'); ?></h2>
			<label class="migrator-toggle">
				<input type="checkbox" name="compress" value="1" <?php checked($schedule->compress); ?>>
				<span><?php esc_html_e('Compress backups with gzip (smaller files)', 'plogins-migrator'); ?></span>
			</label>

			<h2 class="migrator-card__heading migrator-card__heading--spaced"><?php esc_html_e('Before updates', 'plogins-migrator'); ?></h2>
			<label class="migrator-toggle">
				<input type="checkbox" name="before_update" value="1" <?php checked($schedule->beforeUpdate); ?>>
				<span><?php esc_html_e('Back up the site before plugin, theme and WordPress updates', 'plogins-migrator'); ?></span>
			</label>
			<p class="migrator-hint migrator-hint--block">
				<?php esc_html_e('Works for manual and automatic updates, whether or not automatic backups are switched on. Several updates within ten minutes share one backup. It is kept with the scheduled backups and counts towards "Backups to keep".', 'plogins-migrator'); ?>
			</p>

			<h2 class="migrator-card__heading migrator-card__heading--spaced"><?php esc_html_e('Failure email', 'plogins-migrator'); ?></h2>
			<label class="migrator-toggle">
				<input type="checkbox" name="notify_failure" value="1" <?php checked($schedule->notifyFailure); ?>>
				<span><?php esc_html_e('Email me when an automatic backup fails', 'plogins-migrator'); ?></span>
			</label>
			<p class="migrator-field">
				<label for="migrator-notify-email"><?php esc_html_e('Send to', 'plogins-migrator'); ?></label>
				<input id="migrator-notify-email" name="notify_email" type="email" class="regular-text" placeholder="<?php echo esc_attr((string) get_option('admin_email')); ?>" value="<?php echo esc_attr($schedule->notifyEmail); ?>">
				<span class="migrator-hint"><?php esc_html_e('Leave empty to use the site admin email.', 'plogins-migrator'); ?></span>
			</p>

			<?php
			/**
			 * Render extra settings inside the schedule form.
			 *
			 * @param \Migrator\Backup\Schedule $schedule
			 */
			do_action('migrator/schedule_form', $schedule);
			?>

			<h2 class="migrator-card__heading migrator-card__heading--spaced"><?php esc_html_e('Off-site copy', 'plogins-migrator'); ?></h2>
			<p class="migrator-hint migrator-hint--block">
				<?php esc_html_e('Copy every backup somewhere other than this server. A backup that lives only on the machine it protects is not a backup.', 'plogins-migrator'); ?>
			</p>

			<label class="migrator-toggle">
				<input type="checkbox" name="offsite_enabled" value="1" <?php checked($offsite->enabled); ?>>
				<span><?php esc_html_e('Copy each backup off this server', 'plogins-migrator'); ?></span>
			</label>

			<p class="migrator-field">
				<label for="migrator-offsite-type"><?php esc_html_e('Where to', 'plogins-migrator'); ?></label>
				<select id="migrator-offsite-type" name="offsite_type" data-migrator-offsite-type>
					<?php foreach ($destinations as $migrator_type => $migrator_spec) : ?>
						<option value="<?php echo esc_attr((string) $migrator_type); ?>" <?php selected($offsite->type, (string) $migrator_type); ?>>
							<?php echo esc_html((string) $migrator_spec['label']); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<?php foreach ($destinations as $migrator_type => $migrator_spec) : ?>
				<div class="migrator-dest" data-migrator-dest="<?php echo esc_attr((string) $migrator_type); ?>"<?php echo $offsite->type === (string) $migrator_type ? '' : ' hidden'; ?>>
					<?php if (empty($migrator_spec['available'])) : ?>
						<p class="migrator-hint migrator-hint--block migrator-hint--warn">
							<?php echo esc_html((string) ($migrator_spec['requirement'] ?? '')); ?>
						</p>
					<?php endif; ?>

					<?php foreach (($migrator_spec['fields'] ?? []) as $migrator_field => $migrator_meta) : ?>
						<?php
						$migrator_id    = 'migrator-' . $migrator_type . '-' . $migrator_field;
						$migrator_name  = 'dest[' . $migrator_type . '][' . $migrator_field . ']';
						$migrator_kind  = (string) ($migrator_meta['type'] ?? 'text');
						$migrator_value = (string) ($offsite->config[$migrator_type][$migrator_field] ?? ($migrator_meta['default'] ?? ''));
						?>
						<?php if ('checkbox' === $migrator_kind) : ?>
							<label class="migrator-toggle">
								<input type="checkbox" name="<?php echo esc_attr($migrator_name); ?>" value="1" <?php checked('' !== $migrator_value); ?>>
								<span><?php echo esc_html((string) $migrator_meta['label']); ?></span>
							</label>
						<?php elseif ('textarea' === $migrator_kind) : ?>
							<p class="migrator-field migrator-field--wide">
								<label for="<?php echo esc_attr($migrator_id); ?>"><?php echo esc_html((string) $migrator_meta['label']); ?></label>
								<textarea id="<?php echo esc_attr($migrator_id); ?>" name="<?php echo esc_attr($migrator_name); ?>" rows="4" class="large-text code" placeholder="<?php echo '' !== $migrator_value ? esc_attr__('Stored. Leave blank to keep it.', 'plogins-migrator') : ''; ?>"></textarea>
							</p>
						<?php elseif ('password' === $migrator_kind) : ?>
							<p class="migrator-field">
								<label for="<?php echo esc_attr($migrator_id); ?>"><?php echo esc_html((string) $migrator_meta['label']); ?></label>
								<input id="<?php echo esc_attr($migrator_id); ?>" name="<?php echo esc_attr($migrator_name); ?>" type="password" autocomplete="new-password" class="regular-text" placeholder="<?php echo '' !== $migrator_value ? esc_attr__('Stored. Leave blank to keep it.', 'plogins-migrator') : ''; ?>">
							</p>
						<?php else : ?>
							<p class="migrator-field">
								<label for="<?php echo esc_attr($migrator_id); ?>"><?php echo esc_html((string) $migrator_meta['label']); ?></label>
								<input id="<?php echo esc_attr($migrator_id); ?>" name="<?php echo esc_attr($migrator_name); ?>" type="<?php echo esc_attr('number' === $migrator_kind ? 'number' : 'text'); ?>" class="regular-text<?php echo 'text' === $migrator_kind ? ' code' : ''; ?>" value="<?php echo esc_attr($migrator_value); ?>">
							</p>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e('Save schedule', 'plogins-migrator'); ?></button>
			</p>
		</form>

		<div class="migrator-card migrator-card--status">
			<h2 class="migrator-card__heading"><?php esc_html_e('Status', 'plogins-migrator'); ?></h2>

			<ul class="migrator-status">
				<li>
					<span class="migrator-status__label"><?php esc_html_e('Automatic backups', 'plogins-migrator'); ?></span>
					<span class="migrator-status__value">
						<?php echo $schedule->enabled
							? '<strong class="is-on">' . esc_html__('On', 'plogins-migrator') . '</strong>'
							: esc_html__('Off', 'plogins-migrator'); ?>
					</span>
				</li>
				<li>
					<span class="migrator-status__label"><?php esc_html_e('Next run', 'plogins-migrator'); ?></span>
					<span class="migrator-status__value">
						<?php echo $nextRun ? esc_html($migrator_date((int) $nextRun)) : esc_html__('Not scheduled', 'plogins-migrator'); ?>
					</span>
				</li>
				<li>
					<span class="migrator-status__label"><?php esc_html_e('Last backup', 'plogins-migrator'); ?></span>
					<span class="migrator-status__value">
						<?php
						if (null === $last) {
							esc_html_e('Never', 'plogins-migrator');
						} elseif (! empty($last['started'])) {
							echo '<span class="migrator-failed">' . esc_html(sprintf(
								/* translators: %s: date */
								__('Started on %s and has not finished', 'plogins-migrator'),
								$migrator_date((int) $last['time']),
							)) . '</span>';
						} elseif (! empty($last['ok'])) {
							echo esc_html(sprintf(
								/* translators: 1: date, 2: human file size */
								__('%1$s (%2$s)', 'plogins-migrator'),
								$migrator_date((int) $last['time']),
								size_format((int) $last['bytes']),
							));
						} else {
							echo '<span class="migrator-failed">' . esc_html(sprintf(
								/* translators: %s: date */
								__('Failed on %s', 'plogins-migrator'),
								$migrator_date((int) $last['time']),
							)) . '</span>';
						}
						?>
					</span>
				</li>
				<li>
					<span class="migrator-status__label"><?php esc_html_e('Off-site copy', 'plogins-migrator'); ?></span>
					<span class="migrator-status__value">
						<?php
						$migrator_off = is_array($last['offsite'] ?? null) ? $last['offsite'] : ['enabled' => $offsite->enabled];
						if (empty($migrator_off['enabled'])) {
							esc_html_e('Off', 'plogins-migrator');
						} elseif (! empty($migrator_off['ok'])) {
							echo '<strong class="is-on">' . esc_html__('Copied', 'plogins-migrator') . '</strong>';
						} elseif (isset($migrator_off['ok'])) {
							echo '<span class="migrator-failed">' . esc_html__('Copy failed', 'plogins-migrator') . '</span>';
						} else {
							esc_html_e('On (no run yet)', 'plogins-migrator');
						}
						?>
					</span>
				</li>
				<?php if ($schedule->dbEnabled || null !== $lastDb) : ?>
					<li>
						<span class="migrator-status__label"><?php esc_html_e('Next database backup', 'plogins-migrator'); ?></span>
						<span class="migrator-status__value">
							<?php echo $nextDbRun ? esc_html($migrator_date((int) $nextDbRun)) : esc_html__('Not scheduled', 'plogins-migrator'); ?>
						</span>
					</li>
					<li>
						<span class="migrator-status__label"><?php esc_html_e('Last database backup', 'plogins-migrator'); ?></span>
						<span class="migrator-status__value">
							<?php
							if (null === $lastDb) {
								esc_html_e('Never', 'plogins-migrator');
							} elseif (! empty($lastDb['ok'])) {
								echo esc_html(sprintf(
									/* translators: 1: date, 2: human file size */
									__('%1$s (%2$s)', 'plogins-migrator'),
									$migrator_date((int) $lastDb['time']),
									size_format((int) $lastDb['bytes']),
								));
							} else {
								echo '<span class="migrator-failed">' . esc_html(sprintf(
									/* translators: %s: date */
									__('Failed on %s', 'plogins-migrator'),
									$migrator_date((int) $lastDb['time']),
								)) . '</span>';
							}
							?>
						</span>
					</li>
				<?php endif; ?>
				<?php
				/**
				 * Add rows to the status list.
				 *
				 * @param array<string, mixed>|null $last
				 */
				do_action('migrator/schedule_status', $last);
				?>
			</ul>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="migrator_run_backup_now">
				<?php wp_nonce_field('migrator_run_backup_now'); ?>
				<button type="submit" class="button"><?php esc_html_e('Back up now', 'plogins-migrator'); ?></button>
			</form>
		</div>
	</div>

	<div class="migrator-card">
		<h2 class="migrator-card__heading">
			<?php esc_html_e('Kept backups', 'plogins-migrator'); ?>
			<span class="migrator-count"><?php echo esc_html((string) count($archives)); ?></span>
		</h2>

		<?php if ([] === $archives) : ?>
			<p class="migrator-empty"><?php esc_html_e('No scheduled backups yet. The first one appears here after a run.', 'plogins-migrator'); ?></p>
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
					<?php foreach ($archives as $migrator_archive) : ?>
						<tr>
							<td><code><?php echo esc_html((string) $migrator_archive['file']); ?></code></td>
							<td><?php echo esc_html($migrator_date((int) $migrator_archive['time'])); ?></td>
							<td><?php echo esc_html(size_format((int) $migrator_archive['bytes'])); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>
