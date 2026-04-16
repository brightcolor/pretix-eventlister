<?php
/**
 * @var array  $events
 * @var array  $collection_meta
 * @var bool   $show_description
 * @var bool   $show_organizer
 * @var string $layout_class
 */
?>
<section class="pretix-eventlister-shell">
	<div class="pretix-eventlister <?php echo esc_attr($layout_class); ?>" data-pretix-events>
		<?php foreach ($events as $index => $event) : ?>
			<article class="pretix-eventlister__card<?php echo ! empty($event['platform_notice']) ? ' pretix-eventlister__card--platform' : ''; ?>" style="--pretix-delay: <?php echo esc_attr(($index % 8) * 90); ?>ms;">
				<div class="pretix-eventlister__media">
					<div class="pretix-eventlister__media-badges">
						<?php if ($show_organizer && ! empty($event['organizer_name'])) : ?>
							<span class="pretix-eventlister__chip pretix-eventlister__chip--light"><?php echo esc_html($event['organizer_name']); ?></span>
						<?php endif; ?>

						<?php if (! empty($event['platform_notice'])) : ?>
							<span class="pretix-eventlister__chip pretix-eventlister__chip--accent"><?php echo esc_html__('HSP Plattform', 'pretix-eventlister'); ?></span>
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

				<div class="pretix-eventlister__content">
					<div class="pretix-eventlister__schedule">
						<div class="pretix-eventlister__calendar" aria-hidden="true">
							<span class="pretix-eventlister__calendar-day"><?php echo esc_html($event['day_label']); ?></span>
							<span class="pretix-eventlister__calendar-month"><?php echo esc_html($event['month_label']); ?></span>
						</div>

						<div class="pretix-eventlister__schedule-copy">
							<p class="pretix-eventlister__date"><?php echo esc_html($event['date_label']); ?></p>
							<div class="pretix-eventlister__meta">
								<?php if (! empty($event['time_label'])) : ?>
									<span><?php echo esc_html($event['time_label']); ?></span>
								<?php endif; ?>

								<?php if (! empty($event['location'])) : ?>
									<span><?php echo esc_html($event['location']); ?></span>
								<?php endif; ?>
							</div>

							<?php if (! empty($event['countdown_label'])) : ?>
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

					<?php if (! empty($event['platform_notice'])) : ?>
						<div class="pretix-eventlister__platform-note">
							<span class="pretix-eventlister__platform-label"><?php echo esc_html__('Hinweis zu HSP-Events', 'pretix-eventlister'); ?></span>
							<p><?php echo esc_html($event['platform_notice']); ?></p>
						</div>
					<?php endif; ?>

					<div class="pretix-eventlister__footer">
						<?php if (! empty($event['url'])) : ?>
							<a class="pretix-eventlister__button" href="<?php echo esc_url($event['url']); ?>" target="_blank" rel="noopener noreferrer">
								<?php echo esc_html(! empty($event['button_label']) ? $event['button_label'] : __('Tickets', 'pretix-eventlister')); ?>
							</a>
						<?php endif; ?>

						<?php if ($show_organizer) : ?>
							<span class="pretix-eventlister__slug"><?php echo esc_html($event['organizer_slug']); ?></span>
						<?php endif; ?>
					</div>
				</div>
			</article>
		<?php endforeach; ?>
	</div>
</section>
