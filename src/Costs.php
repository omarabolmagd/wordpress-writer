<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Logs what every API call cost and enforces the monthly cap.
 */
final class Costs {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aaw_costs';
	}

	public static function log( int $job_id, string $provider, string $item, float $amount_usd, array $details = array() ): void {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'job_id'      => $job_id,
				'created_gmt' => gmdate( 'Y-m-d H:i:s' ),
				'provider'    => substr( $provider, 0, 32 ),
				'item'        => substr( $item, 0, 64 ),
				'amount_usd'  => round( max( 0, $amount_usd ), 6 ),
				'details'     => $details ? wp_json_encode( $details ) : null,
			),
			array( '%d', '%s', '%s', '%s', '%f', '%s' )
		);
	}

	/** Start of the current month in the site's timezone, as a GMT datetime string. */
	public static function month_start_gmt(): string {
		$tz    = wp_timezone();
		$start = new \DateTimeImmutable( 'first day of this month 00:00:00', $tz );
		return $start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	public static function month_total(): float {
		global $wpdb;
		$table = self::table();
		return (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(amount_usd),0) FROM {$table} WHERE created_gmt >= %s", self::month_start_gmt() ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	public static function job_total( int $job_id ): float {
		global $wpdb;
		$table = self::table();
		return (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(amount_usd),0) FROM {$table} WHERE job_id = %d", $job_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/** What one step of a job cost, for example its research. */
	public static function job_item_total( int $job_id, string $item ): float {
		global $wpdb;
		$table = self::table();
		return (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(amount_usd),0) FROM {$table} WHERE job_id = %d AND item = %s", $job_id, $item ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/** How many web searches one step of a job ran, across all its calls. */
	public static function job_item_searches( int $job_id, string $item ): int {
		global $wpdb;
		$table = self::table();
		$total = 0;
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT details FROM {$table} WHERE job_id = %d AND item = %s", $job_id, $item ) ) as $json ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$d      = json_decode( (string) $json, true );
			$total += is_array( $d ) ? (int) ( $d['searches'] ?? 0 ) : 0;
		}
		return $total;
	}

	public static function cap(): float {
		return (float) Settings::get( 'monthly_cap' );
	}

	public static function cap_reached(): bool {
		$cap = self::cap();
		return $cap > 0 && self::month_total() >= $cap;
	}

	public static function recent( int $limit = 50 ): array {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/** Totals per job for the current month, newest first. */
	public static function month_by_job(): array {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT job_id, SUM(amount_usd) AS total, MAX(created_gmt) AS last_gmt FROM {$table} WHERE created_gmt >= %s GROUP BY job_id ORDER BY last_gmt DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::month_start_gmt()
			),
			ARRAY_A
		);
	}

	/** Cost of one Claude response from its usage block. */
	public static function claude_cost( array $usage ): float {
		$s       = Settings::all();
		$million = 1000000;
		$cost    = ( (int) ( $usage['input_tokens'] ?? 0 ) ) * (float) $s['price_claude_input'] / $million;
		$cost   += ( (int) ( $usage['output_tokens'] ?? 0 ) ) * (float) $s['price_claude_output'] / $million;
		$cost   += ( (int) ( $usage['cache_creation_input_tokens'] ?? 0 ) ) * (float) $s['price_claude_cache_write'] / $million;
		$cost   += ( (int) ( $usage['cache_read_input_tokens'] ?? 0 ) ) * (float) $s['price_claude_cache_read'] / $million;
		$cost   += ( (int) ( $usage['server_tool_use']['web_search_requests'] ?? 0 ) ) * (float) $s['price_web_search'];
		return $cost;
	}

	public static function format( float $usd ): string {
		return '$' . number_format_i18n( $usd, $usd > 0 && $usd < 0.1 ? 3 : 2 );
	}
}
