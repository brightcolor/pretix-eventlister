<?php
/**
 * @var array  $events
 * @var array  $collection_meta
 * @var bool   $show_description
 * @var bool   $show_organizer
 * @var bool   $show_image
 * @var bool   $show_time
 * @var bool   $show_location
 * @var bool   $show_countdown
 * @var bool   $show_platform_notice
 * @var bool   $show_organizer_slug
 * @var bool   $show_ticket_button
 * @var bool   $feature_filters
 * @var bool   $feature_load_more
 * @var int    $page_size
 * @var bool   $feature_badges
 * @var bool   $feature_calendar
 * @var bool   $feature_schema
 * @var bool   $feature_modal
 * @var bool   $feature_tilt
 * @var string $accent_color
 * @var string $instance_id
 * @var string $layout_class
 */
?>
<?php
$shell_style = '';
if (! empty($accent_color)) {
	$shell_style = '--pretix-accent:' . esc_attr($accent_color) . ';--pretix-accent-strong:' . esc_attr($accent_color) . ';';
}

$organizer_options = array();
foreach ($events as $event) {
	if (! empty($event['organizer_slug']) && ! empty($event['organizer_name'])) {
		$organizer_options[ $event['organizer_slug'] ] = $event['organizer_name'];
	}
}
ksort($organizer_options);
?>
<section class="pretix-eventlister-shell" id="<?php echo esc_attr($instance_id); ?>" style="<?php echo esc_attr($shell_style); ?>">
	<?php if ($feature_filters) : ?>
		<div class="pretix-eventlister__filters" data-pretix-filters>
			<div class="pretix-eventlister__filters-row">
				<?php if (count($organizer_options) > 1) : ?>
					<label class="pretix-eventlister__filter">
						<span class="pretix-eventlister__filter-label"><?php echo esc_html__('Veranstalter', 'pretix-eventlister'); ?></span>
						<select class="pretix-eventlister__filter-control" data-filter="organizer">
							<option value=""><?php echo esc_html__('Alle', 'pretix-eventlister'); ?></option>
							<?php foreach ($organizer_options as $slug => $label) : ?>
								<option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($label); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>

				<label class="pretix-eventlister__filter">
					<span class="pretix-eventlister__filter-label"><?php echo esc_html__('Zeitraum', 'pretix-eventlister'); ?></span>
					<select class="pretix-eventlister__filter-control" data-filter="timeframe">
						<option value=""><?php echo esc_html__('Alle', 'pretix-eventlister'); ?></option>
						<option value="today"><?php echo esc_html__('Heute', 'pretix-eventlister'); ?></option>
						<option value="7"><?php echo esc_html__('Naechste 7 Tage', 'pretix-eventlister'); ?></option>
						<option value="30"><?php echo esc_html__('Naechste 30 Tage', 'pretix-eventlister'); ?></option>
					</select>
				</label>

				<label class="pretix-eventlister__filter">
					<span class="pretix-eventlister__filter-label"><?php echo esc_html__('Ort', 'pretix-eventlister'); ?></span>
					<input class="pretix-eventlister__filter-control" type="search" inputmode="search" placeholder="<?php echo esc_attr__('z.B. Hamburg', 'pretix-eventlister'); ?>" data-filter="location" />
				</label>

				<label class="pretix-eventlister__filter pretix-eventlister__filter--grow">
					<span class="pretix-eventlister__filter-label"><?php echo esc_html__('Suche', 'pretix-eventlister'); ?></span>
					<input class="pretix-eventlister__filter-control" type="search" inputmode="search" placeholder="<?php echo esc_attr__('Titel oder Stichwort', 'pretix-eventlister'); ?>" data-filter="search" />
				</label>
			</div>
			<div class="pretix-eventlister__filters-footer">
				<button type="button" class="pretix-eventlister__filter-reset" data-filter-reset><?php echo esc_html__('Filter zuruecksetzen', 'pretix-eventlister'); ?></button>
				<span class="pretix-eventlister__filter-count" data-filter-count></span>
			</div>
		</div>
	<?php endif; ?>

	<div
		class="pretix-eventlister <?php echo esc_attr($layout_class); ?>"
		data-pretix-events
		data-instance="<?php echo esc_attr($instance_id); ?>"
		data-load-more="<?php echo esc_attr($feature_load_more ? '1' : '0'); ?>"
		data-page-size="<?php echo esc_attr((int) $page_size); ?>"
		data-tilt="<?php echo esc_attr($feature_tilt ? '1' : '0'); ?>"
	>
		<?php foreach ($events as $index => $event) : ?>
			<?php
			$search_blob = strtolower(trim(
				(string) $event['name'] . ' ' .
				(string) $event['organizer_name'] . ' ' .
				(string) $event['location'] . ' ' .
				wp_strip_all_tags((string) $event['description'])
			));
			?>
			<article
				class="pretix-eventlister__card<?php echo ! empty($event['platform_notice']) ? ' pretix-eventlister__card--platform' : ''; ?><?php echo $show_image ? '' : ' pretix-eventlister__card--no-media'; ?>"
				style="--pretix-delay: <?php echo esc_attr(($index % 8) * 90); ?>ms;"
				data-organizer="<?php echo esc_attr($event['organizer_slug']); ?>"
				data-location="<?php echo esc_attr(strtolower((string) $event['location'])); ?>"
				data-days-until="<?php echo esc_attr(is_int($event['days_until']) ? $event['days_until'] : ''); ?>"
				data-date-from="<?php echo esc_attr(! empty($event['date_from']) ? (int) $event['date_from'] : 0); ?>"
				data-search="<?php echo esc_attr($search_blob); ?>"
			>
				<?php if ($show_image) : ?>
				<div class="pretix-eventlister__media">
					<div class="pretix-eventlister__media-badges">
						<?php if ($show_organizer && ! empty($event['organizer_name'])) : ?>
							<span class="pretix-eventlister__chip pretix-eventlister__chip--light"><?php echo esc_html($event['organizer_name']); ?></span>
						<?php endif; ?>

						<?php if ($show_platform_notice && ! empty($event['platform_notice'])) : ?>
							<span class="pretix-eventlister__chip pretix-eventlister__chip--accent"><?php echo esc_html__('HSP Plattform', 'pretix-eventlister'); ?></span>
						<?php endif; ?>

						<?php if ($feature_badges && ! empty($event['badges']) && is_array($event['badges'])) : ?>
							<?php foreach ($event['badges'] as $badge) : ?>
								<?php if (! empty($badge['label'])) : ?>
									<span class="pretix-eventlister__chip pretix-eventlister__chip--badge pretix-eventlister__chip--<?php echo esc_attr(isset($badge['key']) ? $badge['key'] : ''); ?>"><?php echo esc_html($badge['label']); ?></span>
								<?php endif; ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>

					<?php if (! empty($event['image'])) : ?>
						<img
							src="<?php echo esc_url($event['image']); ?>"
							alt="<?php echo esc_attr($event['name']); ?>"
							loading="lazy"
							class="pretix-eventlister__image"
						/>
					<?php else : ?>
						<div class="pretix-eventlister__placeholder">
							<span class="pretix-eventlister__placeholder-mark"><?php echo esc_html(function_exists('mb_substr') ? mb_substr($event['name'], 0, 1) : substr($event['name'], 0, 1)); ?></span>
							<span class="pretix-eventlister__placeholder-copy"><?php echo esc_html($event['organizer_name']); ?></span>
						</div>
					<?php endif; ?>
				</div>
				<?php endif; ?>

				<div class="pretix-eventlister__content">
					<div class="pretix-eventlister__schedule">
						<div class="pretix-eventlister__calendar" aria-hidden="true">
							<span class="pretix-eventlister__calendar-day"><?php echo esc_html($event['day_label']); ?></span>
							<span class="pretix-eventlister__calendar-month"><?php echo esc_html($event['month_label']); ?></span>
						</div>

						<div class="pretix-eventlister__schedule-copy">
							<p class="pretix-eventlister__date"><?php echo esc_html($event['date_label']); ?></p>
							<div class="pretix-eventlister__meta">
								<?php if ($show_time && ! empty($event['time_label'])) : ?>
									<span><?php echo esc_html($event['time_label']); ?></span>
								<?php endif; ?>

								<?php if ($show_location && ! empty($event['location'])) : ?>
									<span><?php echo esc_html($event['location']); ?></span>
								<?php endif; ?>
							</div>

							<?php if ($show_countdown && ! empty($event['countdown_label'])) : ?>
								<p class="pretix-eventlister__countdown"><?php echo esc_html($event['countdown_label']); ?></p>
							<?php endif; ?>
						</div>
					</div>

					<div class="pretix-eventlister__body">
						<h3 class="pretix-eventlister__title"><?php echo esc_html($event['name']); ?></h3>

						<?php if ($show_description && ! empty($event['description'])) : ?>
							<div class="pretix-eventlister__description">
								<?php echo wp_kses_post($event['description']); ?>
							</div>
						<?php endif; ?>
					</div>

					<?php if ($show_platform_notice && ! empty($event['platform_notice'])) : ?>
						<div class="pretix-eventlister__platform-note">
							<span class="pretix-eventlister__platform-label"><?php echo esc_html__('Hinweis zu HSP-Events', 'pretix-eventlister'); ?></span>
							<p><?php echo esc_html($event['platform_notice']); ?></p>
						</div>
					<?php endif; ?>

					<div class="pretix-eventlister__footer">
						<div class="pretix-eventlister__footer-actions">
							<?php if ($show_ticket_button && ! empty($event['url'])) : ?>
							<a class="pretix-eventlister__button" href="<?php echo esc_url($event['url']); ?>" target="_blank" rel="noopener noreferrer">
								<?php echo esc_html(! empty($event['button_label']) ? $event['button_label'] : __('Tickets', 'pretix-eventlister')); ?>
							</a>
							<?php endif; ?>

							<?php if ($feature_calendar && ! empty($event['date_from'])) : ?>
								<?php
								$ics_url = add_query_arg(
									array(
										'action' => 'pretix_eventlister_ics',
										'org' => $event['organizer_slug'],
										'event' => $event['slug'],
									),
									admin_url('admin-ajax.php')
								);
								$google_url = 'https://calendar.google.com/calendar/render?action=TEMPLATE'
									. '&text=' . rawurlencode((string) $event['name'])
									. '&dates=' . rawurlencode(gmdate('Ymd\\THis\\Z', (int) $event['date_from']) . '/' . gmdate('Ymd\\THis\\Z', (int) (! empty($event['date_to']) ? $event['date_to'] : ($event['date_from'] + HOUR_IN_SECONDS))))
									. '&details=' . rawurlencode(wp_strip_all_tags((string) $event['description']))
									. '&location=' . rawurlencode((string) $event['location']);
								$outlook_url = 'https://outlook.live.com/calendar/0/deeplink/compose?path=/calendar/action/compose&rru=addevent'
									. '&subject=' . rawurlencode((string) $event['name'])
									. '&startdt=' . rawurlencode(gmdate('c', (int) $event['date_from']))
									. '&enddt=' . rawurlencode(gmdate('c', (int) (! empty($event['date_to']) ? $event['date_to'] : ($event['date_from'] + HOUR_IN_SECONDS))))
									. '&body=' . rawurlencode(wp_strip_all_tags((string) $event['description']))
									. '&location=' . rawurlencode((string) $event['location']);
								?>
								<details class="pretix-eventlister__calendar-menu">
									<summary class="pretix-eventlister__calendar-trigger"><?php echo esc_html__('In Kalender', 'pretix-eventlister'); ?></summary>
									<div class="pretix-eventlister__calendar-items">
										<a href="<?php echo esc_url($ics_url); ?>" class="pretix-eventlister__calendar-item"><?php echo esc_html__('.ics', 'pretix-eventlister'); ?></a>
										<a href="<?php echo esc_url($google_url); ?>" target="_blank" rel="noopener noreferrer" class="pretix-eventlister__calendar-item"><?php echo esc_html__('Google', 'pretix-eventlister'); ?></a>
										<a href="<?php echo esc_url($outlook_url); ?>" target="_blank" rel="noopener noreferrer" class="pretix-eventlister__calendar-item"><?php echo esc_html__('Outlook', 'pretix-eventlister'); ?></a>
									</div>
								</details>
							<?php endif; ?>

							<?php if ($feature_modal && (! empty($event['description']) || ! empty($event['platform_notice']))) : ?>
								<button type="button" class="pretix-eventlister__button pretix-eventlister__button--ghost" data-pretix-modal-open>
									<?php echo esc_html__('Details', 'pretix-eventlister'); ?>
								</button>
								<div class="pretix-eventlister__modal-template" hidden>
									<h3><?php echo esc_html($event['name']); ?></h3>
									<?php if (! empty($event['description'])) : ?>
										<div class="pretix-eventlister__description"><?php echo wp_kses_post($event['description']); ?></div>
									<?php endif; ?>
									<?php if ($show_platform_notice && ! empty($event['platform_notice'])) : ?>
										<div class="pretix-eventlister__platform-note">
											<span class="pretix-eventlister__platform-label"><?php echo esc_html__('Hinweis zu HSP-Events', 'pretix-eventlister'); ?></span>
											<p><?php echo esc_html($event['platform_notice']); ?></p>
										</div>
									<?php endif; ?>
									<p class="pretix-eventlister__modal-links">
										<?php if ($show_ticket_button && ! empty($event['url'])) : ?>
											<a class="pretix-eventlister__button" href="<?php echo esc_url($event['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(! empty($event['button_label']) ? $event['button_label'] : __('Tickets', 'pretix-eventlister')); ?></a>
										<?php endif; ?>
										<?php if ($feature_calendar && ! empty($event['date_from'])) : ?>
											<a class="pretix-eventlister__button pretix-eventlister__button--ghost" href="<?php echo esc_url($ics_url); ?>"><?php echo esc_html__('ICS', 'pretix-eventlister'); ?></a>
										<?php endif; ?>
									</p>
								</div>
							<?php endif; ?>
						</div>

						<?php if ($show_organizer_slug) : ?>
							<span class="pretix-eventlister__slug"><?php echo esc_html($event['organizer_slug']); ?></span>
						<?php endif; ?>
					</div>
				</div>

				<?php if ($feature_schema) : ?>
					<?php
					$schema = array(
						'@context' => 'https://schema.org',
						'@type' => 'Event',
						'name' => (string) $event['name'],
						'startDate' => ! empty($event['date_from']) ? gmdate('c', (int) $event['date_from']) : null,
						'endDate' => ! empty($event['date_to']) ? gmdate('c', (int) $event['date_to']) : null,
						'url' => ! empty($event['url']) ? (string) $event['url'] : null,
						'image' => ! empty($event['image']) ? array((string) $event['image']) : null,
						'description' => wp_strip_all_tags((string) $event['description']),
						'organizer' => array(
							'@type' => 'Organization',
							'name' => (string) $event['organizer_name'],
						),
					);

					if (! empty($event['is_online'])) {
						$schema['eventAttendanceMode'] = 'https://schema.org/OnlineEventAttendanceMode';
						$schema['location'] = array(
							'@type' => 'VirtualLocation',
							'url' => ! empty($event['url']) ? (string) $event['url'] : '',
						);
					} elseif (! empty($event['location'])) {
						$schema['eventAttendanceMode'] = 'https://schema.org/OfflineEventAttendanceMode';
						$schema['location'] = array(
							'@type' => 'Place',
							'name' => (string) $event['location'],
						);
					}

					$schema = array_filter(
						$schema,
						function ($value) {
							return null !== $value && '' !== $value;
						}
					);
					?>
					<script type="application/ld+json"><?php echo wp_json_encode($schema); ?></script>
				<?php endif; ?>
			</article>
		<?php endforeach; ?>
	</div>

	<?php if ($feature_load_more) : ?>
		<div class="pretix-eventlister__load-more">
			<button type="button" class="pretix-eventlister__load-more-button" data-pretix-load-more><?php echo esc_html__('Mehr laden', 'pretix-eventlister'); ?></button>
		</div>
	<?php endif; ?>

	<?php if ($feature_modal) : ?>
		<div class="pretix-eventlister__modal" hidden data-pretix-modal>
			<div class="pretix-eventlister__modal-backdrop" data-pretix-modal-close></div>
			<div class="pretix-eventlister__modal-panel" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr__('Event-Details', 'pretix-eventlister'); ?>">
				<button type="button" class="pretix-eventlister__modal-close" data-pretix-modal-close><?php echo esc_html__('Schliessen', 'pretix-eventlister'); ?></button>
				<div class="pretix-eventlister__modal-body" data-pretix-modal-body></div>
			</div>
		</div>
	<?php endif; ?>
</section>
