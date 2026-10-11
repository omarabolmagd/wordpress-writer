<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings: API keys, model, prices, budget and branding.
 *
 * API keys can also be set as constants in wp-config.php, which then take priority
 * and are never shown in the admin:
 *   AAW_ANTHROPIC_API_KEY, AAW_GEMINI_API_KEY, AAW_PEXELS_API_KEY, AAW_SCREENSHOT_API_KEY
 */
final class Settings {

	public const OPTION = 'aaw_settings';
	public const PAGE   = 'aaw-settings';

	private const KEY_FIELDS = array(
		'anthropic_key'  => 'AAW_ANTHROPIC_API_KEY',
		'gemini_key'     => 'AAW_GEMINI_API_KEY',
		'pexels_key'     => 'AAW_PEXELS_API_KEY',
		'screenshot_key' => 'AAW_SCREENSHOT_API_KEY',
	);

	public static function defaults(): array {
		return array(
			'anthropic_key'            => '',
			'gemini_key'               => '',
			'pexels_key'               => '',
			'screenshot_key'           => '',
			'claude_model'             => 'claude-sonnet-5-5',
			'max_searches'             => 6,
			'monthly_cap'              => 20,
			'telegram_url'             => 'https://t.me/fyb2020',
			'telegram_text'            => 'انضم إلى قناتنا على تيليجرام ليصلك جديد الكورسات والمنح والوظائف أولًا بأول.',
			'brand_color'              => '#00AEAD',
			'cover_logo_id'            => 0,
			'gemini_model'             => 'gemini-nano-banana-2.1',
			'screenshot_url_template'  => 'https://api.screenshotone.com/take?access_key={key}&url={url}&viewport_width=1280&viewport_height=800&format=jpg&image_quality=82&block_ads=true&block_cookie_banners=true&block_banners_by_heuristics=true&block_trackers=true&timeout=60',
			// Prices in USD. Claude prices are per million tokens, from platform.claude.com/docs/en/about-claude/pricing.
			'price_claude_input'       => 2.0,
			'price_claude_output'      => 10.0,
			'price_claude_cache_write' => 2.5,
			'price_claude_cache_read'  => 0.10,
			'price_web_search'         => 0.01,
			'price_gemini_image'       => 0.0336,
			'price_screenshot'         => 0.0,
		);
	}

	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'register_setting' ) );
	}

	public static function register_setting(): void {
		register_setting(
			'aaw_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'show_in_rest'      => false,
				'default'           => self::defaults(),
			)
		);
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/** Returns an API key, preferring a wp-config.php constant when defined. */
	public static function key( string $field ): string {
		$constant = self::KEY_FIELDS[ $field ] ?? '';
		if ( $constant && defined( $constant ) && constant( $constant ) ) {
			return (string) constant( $constant );
		}
		return (string) ( self::all()[ $field ] ?? '' );
	}

	public static function key_from_constant( string $field ): bool {
		$constant = self::KEY_FIELDS[ $field ] ?? '';
		return $constant && defined( $constant ) && constant( $constant );
	}

	public static function has_key( string $field ): bool {
		return '' !== self::key( $field );
	}

	/** Missing keys that block the core flow (Claude) and optional ones that disable a feature. */
	public static function readiness(): array {
		return array(
			'anthropic'  => self::has_key( 'anthropic_key' ),
			'gemini'     => self::has_key( 'gemini_key' ),
			'pexels'     => self::has_key( 'pexels_key' ),
			'screenshot' => self::has_key( 'screenshot_key' ),
		);
	}

	public static function mask( string $key ): string {
		if ( '' === $key ) {
			return '';
		}
		return str_repeat( '•', 8 ) . substr( $key, -4 );
	}

	public static function sanitize( $input ): array {
		$current = self::all();
		$input   = is_array( $input ) ? wp_unslash( $input ) : array();
		$out     = $current;

		foreach ( array_keys( self::KEY_FIELDS ) as $field ) {
			if ( ! empty( $input[ $field . '_remove' ] ) ) {
				$out[ $field ] = '';
				continue;
			}
			$value = isset( $input[ $field ] ) ? trim( (string) $input[ $field ] ) : '';
			// Empty or the masked placeholder means "keep the saved key".
			if ( '' !== $value && ! str_contains( $value, '•' ) ) {
				$out[ $field ] = sanitize_text_field( $value );
			}
		}

		$model               = sanitize_text_field( $input['claude_model'] ?? $current['claude_model'] );
		$out['claude_model'] = preg_match( '/^claude-[a-z0-9\-\.]+$/', $model ) ? $model : $current['claude_model'];

		$gemini_model        = sanitize_text_field( $input['gemini_model'] ?? $current['gemini_model'] );
		$out['gemini_model'] = preg_match( '/^[a-z0-9\-\.]+$/', $gemini_model ) ? $gemini_model : $current['gemini_model'];

		$out['max_searches'] = max( 1, min( 20, (int) ( $input['max_searches'] ?? $current['max_searches'] ) ) );
		$out['monthly_cap']  = max( 0, round( (float) ( $input['monthly_cap'] ?? $current['monthly_cap'] ), 2 ) );

		$out['telegram_url']  = esc_url_raw( $input['telegram_url'] ?? $current['telegram_url'], array( 'https' ) );
		$out['telegram_text'] = sanitize_text_field( $input['telegram_text'] ?? $current['telegram_text'] );

		$color              = sanitize_hex_color( $input['brand_color'] ?? '' );
		$out['brand_color'] = $color ? $color : $current['brand_color'];

		$out['cover_logo_id'] = absint( $input['cover_logo_id'] ?? $current['cover_logo_id'] );

		$template = trim( (string) ( $input['screenshot_url_template'] ?? $current['screenshot_url_template'] ) );
		if ( str_starts_with( $template, 'https://' ) && str_contains( $template, '{url}' ) ) {
			$out['screenshot_url_template'] = $template;
		}

		foreach ( array( 'price_claude_input', 'price_claude_output', 'price_claude_cache_write', 'price_claude_cache_read', 'price_web_search', 'price_gemini_image', 'price_screenshot' ) as $price ) {
			if ( isset( $input[ $price ] ) && is_numeric( $input[ $price ] ) ) {
				$out[ $price ] = max( 0, (float) $input[ $price ] );
			}
		}

		return $out;
	}

	public static function render_page(): void {
		if ( ! current_user_can( Plugin::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'ana-afdal-writer' ) );
		}
		$s = self::all();
		?>
		<div class="wrap aaw-wrap">
			<h1><?php esc_html_e( 'Ana Afdal Writer settings', 'ana-afdal-writer' ); ?></h1>
			<form method="post" action="options.php" autocomplete="off">
				<?php settings_fields( 'aaw_settings_group' ); ?>

				<h2><?php esc_html_e( 'API keys', 'ana-afdal-writer' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Keys are stored in the database and never sent to the browser. To keep them out of the database entirely, define them in wp-config.php instead (AAW_ANTHROPIC_API_KEY, AAW_GEMINI_API_KEY, AAW_PEXELS_API_KEY, AAW_SCREENSHOT_API_KEY).', 'ana-afdal-writer' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					self::key_row( 'anthropic_key', __( 'Claude (Anthropic) API key', 'ana-afdal-writer' ), __( 'Required. Writes the posts and searches the web. Create one at platform.claude.com.', 'ana-afdal-writer' ) );
					self::key_row( 'gemini_key', __( 'Google Gemini API key', 'ana-afdal-writer' ), __( 'Optional. Needed for AI images. Create one at aistudio.google.com.', 'ana-afdal-writer' ) );
					self::key_row( 'pexels_key', __( 'Pexels API key', 'ana-afdal-writer' ), __( 'Optional. Needed for stock photos. Free at pexels.com/api.', 'ana-afdal-writer' ) );
					self::key_row( 'screenshot_key', __( 'Screenshot service key', 'ana-afdal-writer' ), __( 'Optional. Needed for website screenshots. The default service is ScreenshotOne (free plan available at screenshotone.com).', 'ana-afdal-writer' ) );
					?>
				</table>
				<p>
					<button type="button" class="button" id="aaw-test-connections"><?php esc_html_e( 'Test connections', 'ana-afdal-writer' ); ?></button>
					<span id="aaw-test-results" class="aaw-test-results" aria-live="polite"></span>
				</p>

				<h2><?php esc_html_e( 'Budget', 'ana-afdal-writer' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="aaw-monthly-cap"><?php esc_html_e( 'Monthly spending cap (USD)', 'ana-afdal-writer' ); ?></label></th>
						<td>
							<input type="number" step="0.01" min="0" id="aaw-monthly-cap" name="<?php echo esc_attr( self::OPTION ); ?>[monthly_cap]" value="<?php echo esc_attr( $s['monthly_cap'] ); ?>" class="small-text aaw-number">
							<p class="description"><?php esc_html_e( 'New topic suggestions and new posts stop once this month\'s spending reaches the cap. Posts already in progress can finish. Set 0 for no cap.', 'ana-afdal-writer' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Writing', 'ana-afdal-writer' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::text_row( 'claude_model', __( 'Claude model', 'ana-afdal-writer' ), $s['claude_model'], __( 'Default: claude-sonnet-5-5. If you switch models, update the Claude prices below too.', 'ana-afdal-writer' ) );
					self::number_row( 'max_searches', __( 'Web searches per research step', 'ana-afdal-writer' ), $s['max_searches'], __( 'Each search costs about $0.01.', 'ana-afdal-writer' ), '1' );
					self::text_row( 'telegram_url', __( 'Telegram channel link', 'ana-afdal-writer' ), $s['telegram_url'], __( 'Every post ends with an invitation to this channel.', 'ana-afdal-writer' ), 'url' );
					self::text_row( 'telegram_text', __( 'Telegram invitation text', 'ana-afdal-writer' ), $s['telegram_text'], '' );
					?>
				</table>

				<h2><?php esc_html_e( 'Images', 'ana-afdal-writer' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::text_row( 'brand_color', __( 'Cover background colour', 'ana-afdal-writer' ), $s['brand_color'], __( 'Used for the branded featured image.', 'ana-afdal-writer' ) );
					self::number_row( 'cover_logo_id', __( 'Cover logo (attachment ID)', 'ana-afdal-writer' ), $s['cover_logo_id'], __( 'Optional. A PNG logo from the Media Library to place on covers. Leave 0 to use the site\'s logo if it has one.', 'ana-afdal-writer' ), '1' );
					self::text_row( 'gemini_model', __( 'Gemini image model', 'ana-afdal-writer' ), $s['gemini_model'], __( 'Default: gemini-nano-banana-2.1.', 'ana-afdal-writer' ) );
					self::text_row( 'screenshot_url_template', __( 'Screenshot request URL', 'ana-afdal-writer' ), $s['screenshot_url_template'], __( 'Advanced. {key} and {url} are replaced automatically. Change this only to switch screenshot services.', 'ana-afdal-writer' ), 'url', 'large-text code' );
					?>
				</table>

				<h2><?php esc_html_e( 'Prices used for cost tracking (USD)', 'ana-afdal-writer' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::number_row( 'price_claude_input', __( 'Claude input, per million tokens', 'ana-afdal-writer' ), $s['price_claude_input'], '' );
					self::number_row( 'price_claude_output', __( 'Claude output, per million tokens', 'ana-afdal-writer' ), $s['price_claude_output'], '' );
					self::number_row( 'price_claude_cache_write', __( 'Claude cache write, per million tokens', 'ana-afdal-writer' ), $s['price_claude_cache_write'], '' );
					self::number_row( 'price_claude_cache_read', __( 'Claude cache read, per million tokens', 'ana-afdal-writer' ), $s['price_claude_cache_read'], '' );
					self::number_row( 'price_web_search', __( 'Claude web search, per search', 'ana-afdal-writer' ), $s['price_web_search'], '' );
					self::number_row( 'price_gemini_image', __( 'Gemini image, per image', 'ana-afdal-writer' ), $s['price_gemini_image'], '' );
					self::number_row( 'price_screenshot', __( 'Screenshot, per capture', 'ana-afdal-writer' ), $s['price_screenshot'], __( '0 while you are on a free plan.', 'ana-afdal-writer' ) );
					?>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	private static function key_row( string $field, string $label, string $help ): void {
		$name = self::OPTION . '[' . $field . ']';
		$id   = 'aaw-' . $field;
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php if ( self::key_from_constant( $field ) ) : ?>
					<code><?php esc_html_e( 'Set in wp-config.php', 'ana-afdal-writer' ); ?></code>
				<?php else : ?>
					<input type="password" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="" placeholder="<?php echo esc_attr( self::mask( (string) self::all()[ $field ] ) ); ?>" class="regular-text" autocomplete="new-password" spellcheck="false">
					<?php if ( self::has_key( $field ) ) : ?>
						<label class="aaw-remove-key"><input type="checkbox" name="<?php echo esc_attr( self::OPTION . '[' . $field . '_remove]' ); ?>" value="1"> <?php esc_html_e( 'Remove', 'ana-afdal-writer' ); ?></label>
					<?php endif; ?>
				<?php endif; ?>
				<p class="description"><?php echo esc_html( $help ); ?></p>
			</td>
		</tr>
		<?php
	}

	private static function text_row( string $field, string $label, $value, string $help, string $type = 'text', string $class = 'regular-text' ): void {
		$id = 'aaw-' . $field;
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( self::OPTION . '[' . $field . ']' ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" class="<?php echo esc_attr( $class ); ?>" dir="auto">
				<?php if ( $help ) : ?>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private static function number_row( string $field, string $label, $value, string $help, string $step = 'any' ): void {
		$id = 'aaw-' . $field;
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="number" min="0" step="<?php echo esc_attr( $step ); ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( self::OPTION . '[' . $field . ']' ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" class="small-text aaw-number">
				<?php if ( $help ) : ?>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
}
