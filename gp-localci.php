<?php
/**
 *  LocalCI is a Github-oriented localization continuous integration
 *  add-on to GlotPress. LocalCI provides string coverage management
 *  and associated messaging coordination between Github and an external
 *  CI build system (eg, CircleCI, TravisCI).
 *
 *  Requires PHP 7.0.0 or greater.
 *
 *  Put this plugin in the folder: /glotpress/plugins/
 */

require __DIR__ . '/includes/ci-adapters.php';
require __DIR__ . '/includes/db-adapter.php';

define( 'LOCALCI_DESIRED_LOCALES', '' );
define( 'LOCALCI_DEBUG_EMAIL', '' );
define( 'LOCALCI_GITHUB_API_URL', 'https://api.github.com' );
define( 'LOCALCI_GITHUB_API_MANAGEMENT_TOKEN', '' );


class GP_Route_LocalCI extends GP_Route_Main {
	public function __construct() {
		$this->template_path = __DIR__ . '/templates/';
	}

	public function relay_new_strings_to_gh() {
		if ( ! $this->api ) {
			$this->die_with_error( __( "Yer not 'spose ta be here." ), 403 );
		}

		$build_ci  = $this->get_ci_adapter( LOCALCI_BUILD_CI );
		$db        = $this->get_gp_db_adapter();

		$owner = $build_ci->get_build_owner();
		$repo  = $build_ci->get_build_repo();
		$sha   = $build_ci->get_build_sha();

		if ( $this->is_locked( $sha ) ) {
			$this->die_with_error( "Rate limit exceeded.", 429 );
		}

		$po          = $build_ci->get_new_strings_po();
		$project_id  = $build_ci->get_gp_project_id();

		$coverage    = $db->get_string_coverage( $po, $project_id );
		$stats       = $db->generate_coverage_stats( $coverage );
		$suggestions = $db->generate_string_suggestions( $coverage );

		$response = $this->post_to_gh_status_api( $owner, $repo, $sha, $stats );

		if ( is_wp_error( $response ) || 201 != $response['status_code'] ) {
			$this->die_with_error( "GH status update failed.", 400 );
		}

		$this->tmpl( 'status-ok' );
	}

	public function relay_string_freeze_from_gh() {
	}

	public function invoke_ci_build() {
	}

	public function post_to_gh_status_api( $owner, $repo, $sha, $stats ) {
		return wp_safe_remote_post( GITHUB_API_URL . "/repos/$owner/$repo/statuses/$sha", $stats );
	}



	/**
	 * The nitty gritty details
	 */
	private function get_gp_db_adapter() {
		return new GP_LocalCI_DB_Adapter();
	}

	private function get_ci_adapter( $ci ) {
		$ci_adapter = 'GP_LocalCI_' . $ci . '_Adapter';
		return new $ci_adapter;
	}

	private function is_locked( $sha ) {
		if ( get_transient( 'localci_sha_lock') === $sha ) {
			return true;
		}

		set_transient( 'localci_sha_lock', $sha, HOUR_IN_SECONDS );
		return false;
	}

	private function safe_get( $url ) {
		$safe = false;
		$whitelisted_domains = array(
			'https://circleci.com'
		);

		foreach ( $whitelisted_domains as $domain ) {
			if ( gp_startswith( $url, $domain ) ) {
				$safe = true;
				break;
			}
		}

		if ( ! $safe ) {
			return new WP_Error;
		}

		return wp_remote_get( $url, array() );
	}
}

class GP_LocalCI_API_Loader {
	function init() {
		$this->init_new_routes();
	}

	function init_new_routes() {
		GP::$router->add( '/localci/-relay-new-strings-to-gh', array( 'GP_Route_LocalCI', 'relay_new_strings_to_gh' ), 'post' );
	}
}

$gp_localci_api = new GP_LocalCI_API_Loader();
add_action( 'gp_init', array( $gp_localci_api, 'init' ) );
