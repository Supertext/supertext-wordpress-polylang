<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Tests\Cases;

use Supertext\Polylang\Integrations\GravityForms\Fields;
use Supertext\Polylang\Tests\TestCase;

/**
 * Regression coverage for multi-input sub-labels (Name, Email-with-confirm, …).
 *
 * Gravity Forms renders an input's `customLabel` when the admin set one, and only
 * falls back to the built-in `label` otherwise. It also stores unused sub-inputs
 * (a Name field's prefix/middle/suffix) as `isHidden`. Collecting or applying the
 * wrong property is invisible on the front end — which is exactly the bug this
 * guards against: translations that were stored but never displayed.
 */
class GravityFormsFieldsTest extends TestCase {
	/**
	 * A form array whose inputs mirror the real demo form: a Name field with hidden
	 * prefix/middle/suffix plus customised First/Last, and an Email-with-confirm
	 * field whose displayed sub-label differs from its built-in label.
	 */
	private function sampleForm(): array {
		return array(
			'fields' => array(
				array(
					'id'     => 1,
					'type'   => 'name',
					'inputs' => array(
						array( 'id' => '1.2', 'label' => 'Vorspann', 'isHidden' => true ),
						array( 'id' => '1.3', 'label' => 'Vorname', 'customLabel' => 'Vorname' ),
						array( 'id' => '1.4', 'label' => 'zweiter Vorname', 'isHidden' => true ),
						array( 'id' => '1.6', 'label' => 'Nachname', 'customLabel' => 'Nachname' ),
						array( 'id' => '1.8', 'label' => 'Nachspann', 'isHidden' => true ),
					),
				),
				array(
					'id'     => 2,
					'type'   => 'email',
					'inputs' => array(
						array( 'id' => '2', 'label' => 'E-Mail eingeben', 'customLabel' => 'E-Mail' ),
						array( 'id' => '2.2', 'label' => 'E-Mail bestätigen', 'customLabel' => 'E-Mail bestätigen' ),
					),
				),
				array(
					'id'     => 3,
					'type'   => 'address',
					'inputs' => array(
						// No customLabel: the built-in label is what shows and must be collected.
						array( 'id' => '3.1', 'label' => 'Straße' ),
					),
				),
			),
		);
	}

	public function test_collect_uses_customlabel_over_label(): void {
		$out = Fields::collect( $this->sampleForm() );

		// The *displayed* sub-label (customLabel) is what gets registered…
		$this->assertSame( 'Vorname', $out['field.1.input.1.customLabel'] ?? null );
		$this->assertSame( 'Nachname', $out['field.1.input.3.customLabel'] ?? null );
		$this->assertSame( 'E-Mail', $out['field.2.input.0.customLabel'] ?? null );
		$this->assertSame( 'E-Mail bestätigen', $out['field.2.input.1.customLabel'] ?? null );

		// …and the built-in `label` is NOT registered when a customLabel exists.
		$this->assertArrayNotHasKey( 'field.1.input.1.label', $out );
		$this->assertArrayNotHasKey( 'field.2.input.0.label', $out );
		$this->assertNotContains( 'E-Mail eingeben', $out, 'The non-displayed built-in label must not be collected.' );
	}

	public function test_collect_falls_back_to_label_without_customlabel(): void {
		$out = Fields::collect( $this->sampleForm() );

		// Address input has no customLabel, so its built-in label is collected.
		$this->assertSame( 'Straße', $out['field.3.input.0.label'] ?? null );
	}

	public function test_collect_skips_hidden_inputs(): void {
		$out = Fields::collect( $this->sampleForm() );

		// The prefix/middle/suffix sub-inputs are isHidden — never rendered, never collected.
		$this->assertArrayNotHasKey( 'field.1.input.0.label', $out );
		$this->assertArrayNotHasKey( 'field.1.input.2.label', $out );
		$this->assertArrayNotHasKey( 'field.1.input.4.label', $out );
		$this->assertNotContains( 'Vorspann', $out );
		$this->assertNotContains( 'zweiter Vorname', $out );
		$this->assertNotContains( 'Nachspann', $out );
	}

	/**
	 * Builds an object-based form (as Gravity Forms passes at render time). This
	 * matters because {@see Fields::apply_callback()} writes back via a setter that
	 * only mutates objects, exactly like the live GF_Field objects.
	 */
	private function objectForm(): array {
		$name          = new \stdClass();
		$name->id      = 1;
		$name->type    = 'name';
		$name->inputs  = array(
			array( 'id' => '1.2', 'label' => 'Vorspann', 'isHidden' => true ),
			array( 'id' => '1.3', 'label' => 'Vorname', 'customLabel' => 'Vorname' ),
		);

		$email         = new \stdClass();
		$email->id     = 2;
		$email->type   = 'email';
		$email->inputs = array(
			array( 'id' => '2', 'label' => 'E-Mail eingeben', 'customLabel' => 'E-Mail' ),
		);

		return array(
			'fields' => array( $name, $email ),
		);
	}

	public function test_apply_callback_translates_displayed_customlabel(): void {
		$form   = $this->objectForm();
		$fields = $form['fields'];

		Fields::apply_callback( $form, static fn( $s ) => strtoupper( (string) $s ) );

		// The displayed sub-label (customLabel) is swapped…
		$this->assertSame( 'VORNAME', $fields[0]->inputs[1]['customLabel'] );
		$this->assertSame( 'E-MAIL', $fields[1]->inputs[0]['customLabel'] );

		// …while the built-in `label` behind it is left untouched (translating it
		// would be wasted work — GF never shows it once a customLabel is set).
		$this->assertSame( 'Vorname', $fields[0]->inputs[1]['label'] );
		$this->assertSame( 'E-Mail eingeben', $fields[1]->inputs[0]['label'] );
	}

	public function test_apply_callback_skips_hidden_inputs(): void {
		$form   = $this->objectForm();
		$fields = $form['fields'];

		Fields::apply_callback( $form, static fn( $s ) => strtoupper( (string) $s ) );

		// The hidden prefix input is never passed to the callback.
		$this->assertSame( 'Vorspann', $fields[0]->inputs[0]['label'] );
	}
}
