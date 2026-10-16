<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Reporting;

defined( 'ABSPATH' ) || exit;

use Supertext\Polylang\PluginHealth\CallableKeyChecker;
use Supertext\Polylang\PluginHealth\InstallationReporter;
use Supertext\Polylang\PluginHealth\KeyCheck;
use Supertext\Polylang\PluginHealth\PingResponse;
use Supertext\Polylang\PluginHealth\ReporterResult;
use Supertext\Polylang\PluginHealth\ReporterState;
use Supertext\Polylang\Sdk\Http\HttpTransport;
use Supertext\Polylang\Sdk\Supertext;
use Supertext\Polylang\Sdk\V1\Client;
use Supertext\Polylang\Sdk\V1\Protocol;

/**
 * Version reports to the Supertext Commando Center, the automatic API key check
 * and the ping (Commando-Center-Supertext-Translation → docs/DEVELOPER.md →
 * Report protocol). The protocol is Supertext Plugin Health's InstallationReporter
 * (bundled in includes/PluginHealth), with the Supertext SDK's checkApiKey() as its
 * key check; this class only loads the reporter's state, runs one action and
 * stores the new state.
 * Nothing here ever throws: reporting must never break a save, a page or a
 * translation.
 *
 * Has no WordPress dependency (Commando gives it WordPress' values and storage;
 * unit-tested with a fake transport).
 *
 * @since 0.11.0
 */
final class Reporting {
	/**
	 * The plugin's GitHub repository name, as the center expects it.
	 *
	 * @var string
	 */
	const PLUGIN_NAME = 'supertext-wordpress-polylang';

	/**
	 * The system's name, in its own spelling.
	 *
	 * @var string
	 */
	const SYSTEM_NAME = 'WordPress';

	/**
	 * Loads the stored state (JSON string, or null).
	 *
	 * @var callable(): (string|null)
	 */
	private $load;

	/**
	 * Stores the state (JSON string).
	 *
	 * @var callable(string): void
	 */
	private $save;

	/**
	 * Wall-clock Unix seconds (tests).
	 *
	 * @var (callable(): float)|null
	 */
	private $now;

	/**
	 * Constructor.
	 *
	 * @param bool                     $enabled        The setting combined with SUPERTEXT_VERSION_REPORTS (see enabled()).
	 * @param string|null              $site_url       The site's base URL; null: nothing is reported (the key check still runs).
	 * @param string                   $plugin_version From the plugin header (SUPERTEXT_POLYLANG_VERSION).
	 * @param string|null              $system_version The WordPress version.
	 * @param string|null              $callback_url   The ping endpoint's absolute URL.
	 * @param string                   $api_base_url   Supertext API base URL for the key check.
	 * @param HttpTransport            $transport      HTTP for the key check and the report (the SDK's interface).
	 * @param callable                 $load           Returns the stored state (JSON) or null.
	 * @param callable                 $save           Stores the state (JSON).
	 * @param string|null              $report_url     Tests: the center's URL (default: SUPERTEXT_REPORT_URL, else Plugin Health's).
	 * @param (callable(): float)|null $now            Tests: wall-clock Unix seconds.
	 */
	public function __construct(
		private readonly bool $enabled,
		private readonly ?string $site_url,
		private readonly string $plugin_version,
		private readonly ?string $system_version,
		private readonly ?string $callback_url,
		private readonly string $api_base_url,
		private readonly HttpTransport $transport,
		callable $load,
		callable $save,
		private readonly ?string $report_url = null,
		?callable $now = null
	) {
		$this->load = $load;
		$this->save = $save;
		$this->now  = $now;
	}

	/**
	 * Whether reports are on: SUPERTEXT_VERSION_REPORTS (0/false/off/no or 1/true/on/yes)
	 * wins over the setting; the setting defaults to on.
	 *
	 * @param bool|null $setting The setting, null when not set.
	 * @return bool
	 */
	public static function enabled( ?bool $setting ): bool {
		return InstallationReporter::reportsEnabled( $setting );
	}

	/**
	 * Whether SUPERTEXT_VERSION_REPORTS on this server overrides the setting.
	 *
	 * @return bool
	 */
	public static function forced_by_environment(): bool {
		return ! InstallationReporter::reportsEnabled( true ) || InstallationReporter::reportsEnabled( false );
	}

	/**
	 * The answer of the public ping endpoint (status, headers and body as Plugin Health returns them).
	 *
	 * @param string|null $challenge The `challenge` query parameter.
	 * @param bool        $enabled   Whether reports are on.
	 * @return PingResponse
	 */
	public static function ping( ?string $challenge, bool $enabled ): PingResponse {
		return InstallationReporter::pingResponse( $challenge, self::PLUGIN_NAME, $enabled );
	}

	/**
	 * After the settings were saved: checks the key, reports `installed` or `settings_saved`.
	 *
	 * @param string $api_key The API key.
	 * @return ReporterResult|null
	 */
	public function after_settings_saved( #[\SensitiveParameter] string $api_key ): ?ReporterResult {
		return $this->run( static fn( InstallationReporter $r, ReporterState $s ) => $r->afterSettingsSaved( $s, $api_key ) );
	}

	/**
	 * Back-end requests that run the plugin's code: checks a changed key, reports what changed.
	 *
	 * @param string $api_key The API key.
	 * @return ReporterResult|null
	 */
	public function on_request( #[\SensitiveParameter] string $api_key ): ?ReporterResult {
		return $this->run( static fn( InstallationReporter $r, ReporterState $s ) => $r->onRequest( $s, $api_key ) );
	}

	/**
	 * A translation got 401/403: the key is invalid (reported once, when the status changes).
	 *
	 * @param string $api_key     The API key.
	 * @param int    $http_status 401 or 403.
	 * @return ReporterResult|null
	 */
	public function authentication_failed( #[\SensitiveParameter] string $api_key, int $http_status = 401 ): ?ReporterResult {
		return $this->run( static fn( InstallationReporter $r, ReporterState $s ) => $r->authenticationFailed( $s, $api_key, $http_status ) );
	}

	/**
	 * *Test connection*: checks the key, reports a change.
	 *
	 * @param string $api_key The API key.
	 * @return ReporterResult|null
	 */
	public function test_connection( #[\SensitiveParameter] string $api_key ): ?ReporterResult {
		return $this->run( static fn( InstallationReporter $r, ReporterState $s ) => $r->testConnection( $s, $api_key ) );
	}

	/**
	 * Sends a `check` report now (tests, diagnostics).
	 *
	 * @return ReporterResult|null
	 */
	public function report_check(): ?ReporterResult {
		return $this->run( static fn( InstallationReporter $r, ReporterState $s ) => $r->reportCheck( $s ) );
	}

	/**
	 * The stored state (empty if none or unreadable).
	 *
	 * @return ReporterState
	 */
	public function state(): ReporterState {
		try {
			return ReporterState::from( ( $this->load )() );
		} catch ( \Throwable $e ) {
			return ReporterState::empty();
		}
	}

	/**
	 * The latest stored key check, for the settings page.
	 *
	 * @return KeyCheck|null
	 */
	public function last_check(): ?KeyCheck {
		return $this->state()->keyCheck;
	}

	/**
	 * Plugin Health's reporter with this installation's values; the key check is the
	 * SDK's, against the Supertext environment chosen in the settings.
	 *
	 * @return InstallationReporter
	 */
	public function reporter(): InstallationReporter {
		$transport = $this->transport;
		$base_url  = $this->api_base_url;
		$agent     = self::PLUGIN_NAME . '/' . $this->plugin_version;
		$now       = $this->now;

		return new InstallationReporter(
			enabled: $this->enabled && null !== $this->site_url,
			siteUrl: $this->site_url ?? '',
			pluginName: self::PLUGIN_NAME,
			pluginVersion: $this->plugin_version,
			systemName: self::SYSTEM_NAME,
			keyChecker: new CallableKeyChecker(
				static fn( #[\SensitiveParameter] string $key ) => ( new Client( $key, baseUrl: $base_url, userAgent: $agent, transport: $transport, now: $now ) )->checkApiKey(),
				array( Protocol::class, 'apiKeyFingerprint' )
			),
			systemVersion: $this->system_version,
			sdkVersion: Supertext::SDK_VERSION,
			callbackUrl: $this->callback_url,
			reportUrl: $this->report_url,
			transport: new Report_Transport( $transport ),
			now: $this->now,
		);
	}

	/**
	 * Runs one reporter action on the stored state and stores the new state.
	 *
	 * @param \Closure(InstallationReporter, ReporterState): ReporterResult $action The action.
	 * @return ReporterResult|null Null only if the reporter failed unexpectedly.
	 */
	private function run( \Closure $action ): ?ReporterResult {
		try {
			$result = $action( $this->reporter(), $this->state() );
		} catch ( \Throwable $e ) {
			return null;
		}
		try {
			( $this->save )( (string) json_encode( $result->state, JSON_UNESCAPED_SLASHES ) );
		} catch ( \Throwable $e ) {
			// The state couldn't be stored: it is just empty next time.
			unset( $e );
		}

		return $result;
	}
}
