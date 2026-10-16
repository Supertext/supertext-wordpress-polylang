<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Reporting;

defined( 'ABSPATH' ) || exit;

use Supertext\Polylang\PluginHealth\Http\HttpRequest;
use Supertext\Polylang\PluginHealth\Http\HttpResponse;
use Supertext\Polylang\PluginHealth\Http\HttpTransport;
use Supertext\Polylang\Sdk\Http\HttpRequest as SdkRequest;
use Supertext\Polylang\Sdk\Http\HttpTransport as SdkTransport;

/**
 * Sends Supertext Plugin Health's reports through the same transport as the
 * SDK's requests (WordPress' HTTP API, Machine_Translation\Wp_Http_Transport;
 * a fake in tests). The two libraries have the same HTTP shapes in their own
 * namespaces; this only converts between them. A missing answer stays an
 * exception, which Plugin Health records as a failed report.
 *
 * @since 0.11.0
 */
final class Report_Transport implements HttpTransport {
	/**
	 * Constructor.
	 *
	 * @param SdkTransport $transport The SDK transport that sends the request.
	 */
	public function __construct( private readonly SdkTransport $transport ) {
	}

	/**
	 * Sends one report.
	 *
	 * @param HttpRequest $request Plugin Health's request.
	 * @return HttpResponse
	 */
	public function send( HttpRequest $request ): HttpResponse {
		$response = $this->transport->send(
			new SdkRequest( $request->method, $request->url, $request->headers, $request->body, $request->timeout )
		);

		return new HttpResponse( $response->status, $response->body, $response->headers );
	}
}
