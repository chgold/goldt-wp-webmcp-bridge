<?php
/**
 * Unit test (1.2.15): Confirmation::strip_confirm_from_schema() for both shapes of `properties`.
 *
 * 1.2.14 assumed `properties` is always an array. An add-on that declares a tool without
 * arguments as `'properties' => (object) array()` (so the JSON manifest says {} and not [])
 * made `$schema['properties']['confirm']` a fatal "Cannot use object of type stdClass as
 * array" — WP DEV went down on 2026-11-19. No WordPress needed: only the class file is loaded.
 *
 *   /workspace/plugins-manager/vendor/bin/phpunit tests/unit/ConfirmationSchemaTest.php
 */

use GoldtWebMCP\Core\Confirmation;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}
require_once dirname( __DIR__, 2 ) . '/includes/core/class-confirmation.php';

final class ConfirmationSchemaTest extends TestCase {

	public function test_array_properties_lose_confirm_and_keep_the_rest() {
		$out = Confirmation::strip_confirm_from_schema(
			array(
				'type'       => 'object',
				'properties' => array(
					'id'      => array( 'type' => 'integer' ),
					'confirm' => array( 'type' => 'boolean' ),
				),
				'required'   => array( 'id', 'confirm' ),
			)
		);
		$this->assertSame( array( 'id' => array( 'type' => 'integer' ) ), $out['properties'] );
		$this->assertSame( array( 'id' ), $out['required'] );
	}

	public function test_array_properties_without_confirm_are_unchanged() {
		$in  = array(
			'type'       => 'object',
			'properties' => array( 'id' => array( 'type' => 'integer' ) ),
		);
		$this->assertSame( $in, Confirmation::strip_confirm_from_schema( $in ) );
	}

	public function test_empty_object_properties_do_not_crash_and_stay_an_object() {
		$out = Confirmation::strip_confirm_from_schema( array( 'type' => 'object', 'properties' => (object) array() ) );
		$this->assertIsObject( $out['properties'] );
		// Still encodes as {} in the manifest, not [].
		$this->assertSame( '{"type":"object","properties":{}}', json_encode( $out ) );
	}

	public function test_object_properties_lose_confirm() {
		$props = (object) array(
			'id'      => array( 'type' => 'integer' ),
			'confirm' => array( 'type' => 'boolean' ),
		);
		$out   = Confirmation::strip_confirm_from_schema(
			array( 'type' => 'object', 'properties' => $props, 'required' => array( 'confirm', 'id' ) )
		);
		$this->assertFalse( property_exists( $out['properties'], 'confirm' ) );
		$this->assertTrue( property_exists( $out['properties'], 'id' ) );
		$this->assertSame( array( 'id' ), $out['required'] );
	}

	public function test_object_properties_of_the_caller_are_not_changed() {
		$props = (object) array( 'confirm' => array( 'type' => 'boolean' ) );
		Confirmation::strip_confirm_from_schema( array( 'properties' => $props ) );
		$this->assertTrue( property_exists( $props, 'confirm' ), 'the add-on\'s own definition must stay intact' );
	}

	public function test_schema_without_properties_or_required() {
		$this->assertSame( array( 'type' => 'object' ), Confirmation::strip_confirm_from_schema( array( 'type' => 'object' ) ) );
	}

	public function test_non_array_required_is_left_alone() {
		$out = Confirmation::strip_confirm_from_schema( array( 'properties' => array(), 'required' => 'confirm' ) );
		$this->assertSame( 'confirm', $out['required'] );
	}
}
